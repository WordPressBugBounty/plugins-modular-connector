<?php

namespace Modular\Connector\Models;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Models\Meta\Meta;

class PostMeta extends Meta
{
    protected $table = 'postmeta';

    protected $fillable = ['meta_key', 'meta_value', 'post_id'];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
