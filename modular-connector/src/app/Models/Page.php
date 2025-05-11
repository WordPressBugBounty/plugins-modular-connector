<?php

namespace Modular\Connector\Models;

class Page extends Post
{
    protected $postType = 'page';

    public function scopeHome($query)
    {
        return $query
            ->where('ID', '=', Option::get('page_on_front'))
            ->limit(1);
    }
}
