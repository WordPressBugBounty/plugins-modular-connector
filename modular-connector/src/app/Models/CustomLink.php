<?php

namespace Modular\Connector\Models;

class CustomLink extends Post
{
    public function __get($key)
    {
        if ($key === 'url') {
            return $this->meta->_menu_item_url;
        }

        if ($key === 'link_text') {
            return $this->post_title;
        }

        return parent::__get($key);
    }
}
