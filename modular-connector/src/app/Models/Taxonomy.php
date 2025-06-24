<?php

namespace Modular\Connector\Models;

use Modular\ConnectorDependencies\Illuminate\Database\Eloquent\Model;

class Taxonomy extends Model
{
    protected $table = 'term_taxonomy';

    protected $primaryKey = 'term_taxonomy_id';

    protected $with = ['term'];

    public $timestamps = false;

    public function meta()
    {
        return $this->hasMany(TermMeta::class, 'term_id');
    }

    public function term()
    {
        return $this->belongsTo(Term::class, 'term_id');
    }

    public function parent()
    {
        return $this->belongsTo(Taxonomy::class, 'parent');
    }

    public function posts()
    {
        return $this->belongsToMany(
            Post::class,
            'term_relationships',
            'term_taxonomy_id',
            'object_id'
        );
    }

    public function newQuery()
    {
        return isset($this->taxonomy) && $this->taxonomy ?
            parent::newQuery()->where('taxonomy', $this->taxonomy) :
            parent::newQuery();
    }

    public function __get($key)
    {
        if (!isset($this->$key)) {
            if (isset($this->term->$key)) {
                return $this->term->$key;
            }
        }

        return parent::__get($key);
    }
}
