<?php

namespace Modular\Connector\Models;

use Modular\Connector\Models\Concerns\Aliases;
use Modular\Connector\Models\Concerns\CustomTimestamps;
use Modular\Connector\Models\Concerns\MetaFields;
use Modular\Connector\Models\Concerns\OrderScopes;
use Modular\Connector\Models\Meta\ThumbnailMeta;
use Modular\ConnectorDependencies\Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use Aliases;
    use MetaFields;
    use OrderScopes;
    use CustomTimestamps;

    const CREATED_AT = 'post_date';
    const UPDATED_AT = 'post_modified';

    protected $table = 'posts';

    protected $primaryKey = 'ID';

    protected $dates = ['post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt'];

    protected $with = ['meta'];

    protected static $postTypes = [];

    protected $fillable = [
        'post_content',
        'post_title',
        'post_excerpt',
        'post_type',
        'to_ping',
        'pinged',
        'post_content_filtered',
    ];

    protected $appends = [
        'title',
        'slug',
        'content',
        'type',
        'mime_type',
        'url',
        'author_id',
        'parent_id',
        'created_at',
        'updated_at',
        'excerpt',
        'status',
        'image',
        'terms',
        'main_category',
        'keywords',
        'keywords_str',
    ];

    protected static $aliases = [
        'title'         => 'post_title',
        'content'       => 'post_content',
        'excerpt'       => 'post_excerpt',
        'slug'          => 'post_name',
        'type'          => 'post_type',
        'mime_type'     => 'post_mime_type',
        'url'           => 'guid',
        'author_id'     => 'post_author',
        'parent_id'     => 'post_parent',
        'created_at'    => 'post_date',
        'updated_at'    => 'post_modified',
        'status'        => 'post_status',
    ];

    protected static function boot()
    {
        parent::boot();

        static::deleting(function($post) {
            $post->postmeta()->delete();
            $post->termRelationships()->delete();
            $post->comments()->delete();
        });
    }

    public function newQuery()
    {
        return $this->postType ?
            parent::newQuery()->type($this->postType) :
            parent::newQuery();
    }

    public function thumbnail()
    {
        return $this->hasOne(ThumbnailMeta::class, 'post_id')
            ->where('meta_key', '_thumbnail_id');
    }

    public function taxonomies()
    {
        return $this->belongsToMany(
            Taxonomy::class,
            'term_relationships',
            'object_id',
            'term_taxonomy_id'
        );
    }

    public function postmeta()
    {
        return $this->hasMany(PostMeta::class, 'post_id');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class, 'comment_post_ID');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'post_author');
    }

    public function parent()
    {
        return $this->belongsTo(Post::class, 'post_parent');
    }

    public function children()
    {
        return $this->hasMany(Post::class, 'post_parent');
    }

    public function attachment()
    {
        return $this->hasMany(Post::class, 'post_parent')
            ->where('post_type', 'attachment');
    }

    public function revisions()
    {
        return $this->hasMany(Post::class, 'post_parent')
            ->where('post_type', 'revision');
    }

    public function getPostType()
    {
        return $this->postType;
    }

    public function getImageAttribute()
    {
        if ($this->thumbnail and $this->thumbnail->attachment) {
            return $this->thumbnail->attachment->guid;
        }
    }

    public function getTermsAttribute()
    {
        return $this->taxonomies->groupBy(function ($taxonomy) {
            return $taxonomy->taxonomy == 'post_tag' ?
            'tag' : $taxonomy->taxonomy;
        })->map(function ($group) {
            return $group->mapWithKeys(function ($item) {
                return [$item->term->slug => $item->term->name];
            });
        })->toArray();
    }

    public function getMainCategoryAttribute()
    {
        $mainCategory = 'Uncategorized';

        if (!empty($this->terms)) {
            $taxonomies = array_values($this->terms);

            if (!empty($taxonomies[0])) {
                $terms = array_values($taxonomies[0]);
                $mainCategory = $terms[0];
            }
        }

        return $mainCategory;
    }

    public function getKeywordsAttribute()
    {
        return collect($this->terms)->map(function ($taxonomy) {
            return collect($taxonomy)->values();
        })->collapse()->toArray();
    }

    public function getKeywordsStrAttribute()
    {
        return implode(',', (array) $this->keywords);
    }

    public static function registerPostType($name, $class)
    {
        static::$postTypes[$name] = $class;
    }

    public static function clearRegisteredPostTypes()
    {
        static::$postTypes = [];
    }

    public function getFormat()
    {
        $taxonomy = $this->taxonomies()
            ->where('taxonomy', 'post_format')
            ->first();

        if ($taxonomy && $taxonomy->term) {
            return str_replace(
                'post-format-',
                '',
                $taxonomy->term->slug
            );
        }

        return false;
    }

    public function hasTerm($taxonomy, $term)
    {
        return isset($this->terms[$taxonomy]) && isset($this->terms[$taxonomy][$term]);
    }

    public function termRelationships()
    {
        return $this->hasMany(TermRelationship::class, 'object_id');
    }

    public function __get($key)
    {
        $value = parent::__get($key);

        if ($value === null && !property_exists($this, $key)) {
            return $this->meta->$key;
        }

        return $value;
    }
}
