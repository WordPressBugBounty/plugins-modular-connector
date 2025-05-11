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

    public function parent()
    {
        if ($className = $this->getClassName()) {
            return (new $className)->newQuery()
                ->find($this->meta->_menu_item_menu_item_parent);
        }

        return null;
    }

    public function instance()
    {
        if ($className = $this->getClassName()) {
            return (new $className)->newQuery()
                ->find($this->meta->_menu_item_object_id);
        }

        return null;
    }

    protected function getClassName()
    {
        return Arr::get(
            $this->instanceRelations,
            $this->meta->_menu_item_object
        );
    }
}
