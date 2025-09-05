<?php

namespace Modular\Connector\Services\Manager;

use Automattic\WooCommerce\Admin\API\Reports\Categories\DataStore as CategoriesDataStore;
use Automattic\WooCommerce\Admin\API\Reports\Coupons\DataStore as CouponsDataStore;
use Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore as OrdersDataStore;
use Automattic\WooCommerce\Admin\API\Reports\Products\DataStore as ProductsDataStore;
use Automattic\WooCommerce\Admin\API\Reports\Variations\Stats\DataStore as VariationsDataStore;
use Modular\ConnectorDependencies\Carbon\Carbon;
use function Modular\ConnectorDependencies\data_get;

/**
 * Handles all functionality related to the WooCommerce plugin
 */
class ManagerWooCommerce
{
    /**
     * Returns if the site has the minimum WooCommerce version
     *
     * @param string $version The minimum version to check
     *
     * @return bool `true` if the site's WooCommerce version is equal or higher than the given. `false` otherwise.
     */
    public function hasMinimumVersion(string $version = '3.0.0')
    {
        return defined('WC_VERSION') ? version_compare(\WC_VERSION, $version, '>=') : false;
    }

    /**
     * Returns if the WooCommerce plugin is currently active
     *
     * @return bool `true` if active, `false` otherwise
     */
    public function isActive(): bool
    {
        return \is_plugin_active('woocommerce/woocommerce.php');
    }

    /**
     * Gets the total sales stats, and the data separated by the given interval
     *
     * @param string $after Total Sales after a given date in Y-m-d format
     * @param string $before Total Sales before a given date in Y-m-d format
     * @param string $interval Chosen interval to separate the stats
     * @param array $fields The stat fields which will be obtained from WooCommerce
     *
     * @return array            An array with the total stats in a `totals` index, and the stats separated in a `intervals` index
     */
    public function getSalesStats(string $after, string $before, string $interval, array $fields = []): array
    {
        $defaultArgs = [
            'per_page' => 100,
            'order' => 'asc',
            'orderby' => 'date',
            'before' => $before,
            'after' => $after,
            'interval' => $interval,
        ];

        if (!empty($fields)) {
            $defaultArgs['fields'] = $fields;
        }

        $args = apply_filters('woocommerce_analytics_revenue_query_args', $defaultArgs);

        $results = ['intervals' => []];

        if (class_exists('Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore')) {
            $ordersDataStore = new OrdersDataStore();
            $orderPages = 1;

            for ($page = 1; $page <= $orderPages; $page++) {
                $args['page'] = $page;
                $pageResults = apply_filters(
                    'woocommerce_analytics_revenue_select_query',
                    (array)$ordersDataStore->get_data($args),
                    $args
                );

                if (!isset($results['totals'])) {
                    $results['totals'] = $pageResults['totals'];
                }

                $results['intervals'] = array_merge($results['intervals'], $pageResults['intervals']);

                $orderPages = data_get($pageResults, 'pages', 0);
            }
        }

        $variationResults = ['intervals' => []];

        if (class_exists('Automattic\WooCommerce\Admin\API\Reports\Variations\Stats\DataStore')) {
            $variationsDataStore = new VariationsDataStore();
            $variationPages = 1;

            for ($page = 1; $page <= $variationPages; $page++) {
                $args['page'] = $page;
                $pageResults = apply_filters(
                    'woocommerce_analytics_variations_select_query',
                    (array)$variationsDataStore->get_data($args),
                    $args
                );

                if (!isset($variationResults['totals'])) {
                    $variationResults['totals'] = $pageResults['totals'];
                }

                $variationResults['intervals'] = array_merge($variationResults['intervals'], $pageResults['intervals']);

                $variationPages = data_get($pageResults, 'pages', 0);
            }
        }

        // If no orders have been found, return empty results
        if (!isset($results['totals'])) {
            return $results;
        }

        // Handle edge case in which orders have been found, but no variation data
        if (empty($fields) || in_array('variations_count', $fields)) {
            if (isset($variationResults['totals'])) {
                $results['totals']->variations_count = data_get($variationResults['totals'], 'variations_count', 0);
            } else {
                $results['totals']->variations_count = 0;
            };
        }

        // Add variations count to interval subtotals, format WooCommerce prices and remove non-included always-queried fields
        $alwaysQueriedFields = ['products', 'coupons_count', 'segments'];
        $priceFields = ['gross_sales', 'total_sales', 'coupons', 'refunds', 'taxes', 'shipping', 'net_revenue', 'avg_order_value'];
        $results['intervals'] = array_map(function ($orderInterval, $variationInterval) use ($priceFields, $fields, $alwaysQueriedFields) {
            // Leave date_start and date_end as _gmt without the prefix
            $orderInterval['start_date'] = data_get($orderInterval, 'date_start_gmt', Carbon::now()->toDateString());
            $orderInterval['end_date'] = data_get($orderInterval, 'date_end_gmt', Carbon::now()->toDateString());
            unset($orderInterval['date_start_gmt'], $orderInterval['date_end_gmt']);

            if (empty($fields) || in_array('variations_count', $fields)) {
                $orderInterval['subtotals']->variations_count = data_get($variationInterval['subtotals'], 'variations_count', 0);
            }

            foreach ($alwaysQueriedFields as $queriedField) {
                if (!empty($fields) && !in_array($queriedField, $fields) && isset($orderInterval['subtotals']->{$queriedField})) {
                    unset($orderInterval['subtotals']->{$queriedField});
                }
            }

            array_walk($orderInterval['subtotals'], function (&$item, $key) use ($priceFields) {
                if (in_array($key, $priceFields)) {
                    $item = (int)round($item * 100);
                }
            });

            return $orderInterval;
        }, $results['intervals'], $variationResults['intervals']);

        array_walk($results['totals'], function (&$item, $key) use ($priceFields) {
            if (in_array($key, $priceFields)) {
                $item = (int)round($item * 100);
            }
        });

        foreach ($alwaysQueriedFields as $queriedField) {
            if (!empty($fields) && !in_array($queriedField, $fields) && isset($results['totals']->{$queriedField})) {
                unset($results['totals']->{$queriedField});
            }
        }

        return $results;
    }

    /**
     * Gets the data for the most bought product categories
     *
     * @param string $after Categories after a given date in Y-m-d H:i:s format
     * @param string $before Categories before a given date in Y-m-d H:i:s format
     * @param int $number Number of categories. Defaults to `5`
     *
     * @return array            An array with the `$number` most bought categories
     */
    public function getCategoriesLeaderboard(string $after, string $before, int $number = 5): array
    {
        $categoriesDataStore = new CategoriesDataStore();
        $categoriesData = data_get($categoriesDataStore->get_data(
            apply_filters('woocommerce_analytics_categories_query_args', [
                'orderby' => 'items_sold',
                'order' => 'desc',
                'after' => $after,
                'before' => $before,
                'per_page' => $number,
                'extended_info' => true,
            ])
        ), 'data', []);

        return array_map(function ($category) {
            $categoryId = data_get($category, 'category_id', 0);
            $term = get_term($categoryId);
            $categoryName = data_get($term, 'name', '-');
            $categoryUrl = get_category_link($categoryId);

            return [
                'id' => $categoryId,
                'name' => $categoryName,
                'url' => $categoryUrl,
                'items_sold' => data_get($category, 'items_sold', 0),
                'orders_count' => data_get($category, 'orders_count', 0),
                'products_count' => data_get($category, 'products_count', 0),
                'net_revenue' => (int)round(data_get($category, 'net_revenue', 0) * 100),
            ];
        }, $categoriesData);
    }

    /**
     * Gets the data for the most bought products
     *
     * @param string $after Products after a given date in Y-m-d H:i:s format
     * @param string $before Products before a given date in Y-m-d H:i:s format
     * @param int $number Number of products. Defaults to `5`
     *
     * @return array            An array with the `$number` most bought products
     */
    public function getProductsLeaderboard(string $after, string $before, int $number = 5): array
    {
        $productsDataStore = new ProductsDataStore();
        $productsData = data_get($productsDataStore->get_data(
            apply_filters('woocommerce_analytics_products_query_args', [
                'orderby' => 'items_sold',
                'order' => 'desc',
                'after' => $after,
                'before' => $before,
                'per_page' => $number,
                'extended_info' => true,
            ])
        ), 'data', []);

        return array_map(function ($product) {
            $productId = data_get($product, 'product_id', 0);
            $productUrl = get_permalink($productId);
            $productName = isset($product['extended_info']) ? data_get($product['extended_info'], 'name', '-') : '-';

            return [
                'id' => $productId,
                'name' => $productName,
                'url' => $productUrl,
                'items_sold' => data_get($product, 'items_sold', 0),
                'orders_count' => data_get($product, 'orders_count', 0),
                'net_revenue' => (int)round(data_get($product, 'net_revenue', 0) * 100),
            ];
        }, $productsData);
    }

    /**
     * Gets the data for the most used coupons
     *
     * @param string $after Products after a given date in Y-m-d H:i:s format
     * @param string $before Products before a given date in Y-m-d H:i:s format
     * @param int $number Number of coupons. Defaults to `5`
     *
     * @return array            An array with the `$number` most used coupons
     */
    public function getCouponsLeaderboard(string $after, string $before, int $number = 5): array
    {
        $couponsDataStore = new CouponsDataStore();
        $couponsData = data_get($couponsDataStore->get_data(
            apply_filters('woocommerce_analytics_coupons_query_args', [
                'orderby' => 'orders_count',
                'order' => 'desc',
                'after' => $after,
                'before' => $before,
                'per_page' => $number,
                'extended_info' => true,
            ])
        ), 'data', []);

        return array_map(function ($coupon) {
            $couponId = data_get($coupon, 'coupon_id', 0);
            $couponUrl = get_permalink($couponId);
            $couponCode = isset($coupon['extended_info']) ? data_get($coupon['extended_info'], 'code', '-') : '-';

            return [
                'id' => $couponId,
                'code' => $couponCode,
                'url' => $couponUrl,
                'orders_count' => $coupon['orders_count'],
                'coupon_discounts' => (int)round(data_get($coupon, 'amount', 0) * 100),
            ];
        }, $couponsData);
    }

    /**
     * Gets the most relevant metrics of WooCommerce Analytics
     *
     * @param stdClass $payload The request payload. Includes filters
     *
     * @return array            The filtered WooCommerce Analytics metrics
     */
    public function getAnalytics($payload): array
    {
        // Complete WooCommerce required actions before returning data
        if (!did_action('woocommerce_after_register_taxonomy')) {
            \WC_Post_Types::register_taxonomies();
        }

        if (!did_action('woocommerce_after_register_post_type')) {
            \WC_Post_Types::register_post_types();
        }

        // Get filter values
        $after = Carbon::now()->subMonth()->toDateString();

        if (isset($payload->from) && $payload->from) {
            $after = Carbon::parse($payload->from)->format('Y-m-d H:i:s');
        }

        $before = Carbon::parse(data_get($payload, 'to', 'today 23:59:59'))->format('Y-m-d H:i:s');
        $interval = data_get($payload, 'interval', 'day');
        $included = data_get($payload, 'included', false);

        $analytics = [];

        if (is_object($included)) {
            if (isset($included->currency) && $included->currency) {
                $analytics['currency'] = strtolower(get_woocommerce_currency());
            }

            $salesStatsFields = data_get($included, 'sale_stats', false);
            if (is_array($salesStatsFields)) {
                $analytics['sale_stats'] = $this->getSalesStats($after, $before, $interval, $salesStatsFields);
            }

            if (isset($included->categories_leaderboard) && $included->categories_leaderboard) {
                $analytics['categories_leaderboard'] = $this->getCategoriesLeaderboard($after, $before);
            }

            if (isset($included->products_leaderboard) && $included->products_leaderboard) {
                $analytics['products_leaderboard'] = $this->getProductsLeaderboard($after, $before);
            }

            if (isset($included->coupons_leaderboard) && $included->coupons_leaderboard) {
                $analytics['coupons_leaderboard'] = $this->getCouponsLeaderboard($after, $before);
            }
        } else {
            $analytics = [
                // Level 1 && 3 Metrics
                'currency' => strtolower(get_woocommerce_currency()),
                'sale_stats' => $this->getSalesStats($after, $before, $interval),

                // Level 2 Metrics
                'categories_leaderboard' => $this->getCategoriesLeaderboard($after, $before),
                'products_leaderboard' => $this->getProductsLeaderboard($after, $before),
                'coupons_leaderboard' => $this->getCouponsLeaderboard($after, $before),
            ];
        }

        return $analytics;
    }
}
