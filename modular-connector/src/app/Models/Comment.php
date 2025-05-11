<?php

namespace Modular\Connector\Models;

use Modular\Connector\Models\Concerns\CustomTimestamps;
use Modular\Connector\Models\Concerns\MetaFields;
use Modular\ConnectorDependencies\Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    use MetaFields;
    use CustomTimestamps;

    const CREATED_AT = 'comment_date';
    const UPDATED_AT = null;

    protected $table = 'comments';

    protected $primaryKey = 'comment_ID';

    protected $dates = ['comment_date'];

    protected static function boot()
    {
        parent::boot();

        static::deleting(function($comment) {
            $comment->meta()->delete();
            $comment->replies()->delete();
        });
    }

    public static function findByPostId($postId)
    {
        return (new static())
            ->where('comment_post_ID', $postId)
            ->get();
    }

    public function post()
    {
        return $this->belongsTo(Post::class, 'comment_post_ID');
    }

    public function parent()
    {
        return $this->original();
    }

    public function original()
    {
        return $this->belongsTo(Comment::class, 'comment_parent');
    }

    public function replies()
    {
        return $this->hasMany(Comment::class, 'comment_parent');
    }

    public function isApproved()
    {
        return $this->attributes['comment_approved'] == 1;
    }

    public function isReply()
    {
        return $this->attributes['comment_parent'] > 0;
    }

    public function hasReplies()
    {
        return $this->replies->count() > 0;
    }

    public function setUpdatedAt($value)
    {
        //
    }
}
