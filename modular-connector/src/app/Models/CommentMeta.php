<?php

namespace Modular\Connector\Models;

use Modular\Connector\Models\Meta\Meta;

class CommentMeta extends Meta
{
    protected $table = 'commentmeta';

    protected $fillable = ['meta_key', 'meta_value', 'comment_id'];

    public function comment()
    {
        return $this->belongsTo(Comment::class, 'comment_id');
    }
}
