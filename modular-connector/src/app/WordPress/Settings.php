<?php

namespace Modular\Connector\WordPress;

use Modular\Connector\Facades\WhiteLabel;
use Modular\Connector\Helper\OauthClient;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\View;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use function Modular\ConnectorDependencies\request;

class Settings
{
    /**
     * The slug name to refer to this menu by. Should be unique for this
     * menu page and only include lowercase alphanumeric,
     * dashes, and underscores characters to be
     * compatible with sanitize_key().
     *
     * @var string
     */
    public string $slug = 'modular-connector';

    /**
     * The text to be used for the menu.
     *
     * @return  string
     */
    public function title(): string
    {
        $data = WhiteLabel::getWhiteLabeledData();
        $isEnabled = WhiteLabel::isEnabled();

        return sprintf(esc_attr__('%s - Connection manager', 'modular-connector'), $isEnabled ? $data['Name'] : 'Modular DS');
    }

    /**
     * Returns true if white label is active.
     *
     * @return bool
     */
    public function isWhiteLabelActive(): bool
    {
        return WhiteLabel::isEnabled();
    }

    /**
     * Returns true there is a established connection.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        $connection = OauthClient::getClient();

        return !empty($connection->getClientId()) && !empty($connection->getConnectedAt());
    }

    /**
     * The theme chosen depending on white label or not.
     *
     * @return  string
     */
    public function theme(): string
    {
        return $this->isWhiteLabelActive() ? 'theme-flat' : 'theme-default';
    }

    /**
     * The function to be called to output the content for this page.
     *
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function show(): void
    {
        $title = $this->title();
        $theme = $this->theme();
        $isWhiteLabelActive = $this->isWhiteLabelActive();
        $isConnected = $this->isConnected();

        $connections = OauthClient::getClients();
        $connection = OauthClient::getClient();

        $method = Str::lower(Request::method());

        if ($method === 'get') {
            if ($isConnected) {
                $view = 'settings.connected';
            } elseif (!empty($connection->getClientId()) && empty($connection->getConnectedAt())) {
                $view = 'settings.pending';
            } else {
                $view = 'settings.disconnected';
            }

            // TODO Move styles to a separate file
            echo View::make($view, compact('title', 'theme', 'isWhiteLabelActive', 'connections', 'connection', 'isConnected'))
                ->render();
        } elseif ($method === 'post') {
            $this->store();
        }
    }

    /**
     * @param bool $success
     * @return void
     */
    protected function redirect(bool $success): void
    {
        ob_start();
        $response = Response::redirectTo(menu_page_url('modular-connector', false) . '&success=' . intval($success));
        $response->send();
        ob_end_flush();
        die();
    }

    /**
     * The function to save connection data
     *
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function store(): void
    {
        $request = request();

        $nonce = sanitize_key(wp_unslash($request->get('_wpnonce')));

        if (!wp_verify_nonce($nonce, '_modular_connector_connection')) {
            wp_nonce_ays('_modular_connector_connection');
        }

        $success = true;

        $clientId = $request->get('client_id');

        if (empty($clientId) || !Str::isUuid($clientId)) {
            $success = false;
            $clientId = '';
        }

        $clientSecret = $request->get('client_secret');

        if (empty($clientSecret)) {
            $success = false;
            $clientSecret = '';
        }

        // TODO allow multiple connection
        $client = OauthClient::mapClient([]);

        $client->setClientId($clientId)
            ->setClientSecret($clientSecret)
            ->save();

        $this->redirect($success);
    }
}
