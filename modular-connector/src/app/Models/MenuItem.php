<?php

namespace Modular\Connector\Models;

use Modular\ConnectorDependencies\Illuminate\Support\Arr;

class MenuItem extends Post
{
    protected $postType = 'nav_menu_item';

    protected $instanceRelations = [
        'post' => Post::class,
        'page' => Page::class,
        'custom' => CustomLink::class,
        'category' => Taxonomy::class,
    ];

    /**
     * Cached className to avoid multiple meta lookups
     */
    protected $cachedClassName = null;

    /**
     * Get the parent menu item
     * Note: Returns the actual instance, not a relationship
     */
    public function parent()
    {
        $className = $this->getClassName();

        if ($className) {
            return $className::find($this->meta->_menu_item_menu_item_parent);
        }

        return null;
    }

    /**
     * Get the menu item instance (post, page, custom link, category, etc.)
     * Note: Returns the actual instance, not a relationship
     */
    public function instance()
    {
        $className = $this->getClassName();

        if ($className) {
            return $className::find($this->meta->_menu_item_object_id);
        }

        return null;
    }

    /**
     * Get the class name for this menu item's object type
     * Caches result to avoid multiple meta lookups
     */
    protected function getClassName()
    {
        if ($this->cachedClassName === null) {
            $this->cachedClassName = Arr::get(
                $this->instanceRelations,
                $this->meta->_menu_item_object
            ) ?: false;
        }

        return $this->cachedClassName ?: null;
    }
}
