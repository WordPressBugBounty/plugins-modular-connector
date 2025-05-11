<?php

namespace Modular\Connector\Models;

class Menu extends Taxonomy
{
    protected $taxonomy = 'nav_menu';

    protected $with = ['term', 'items'];

    public function items()
    {
        return $this->belongsToMany(
            MenuItem::class,
            'term_relationships',
            'term_taxonomy_id',
            'object_id'
        )->orderBy('menu_order');
    }
}
