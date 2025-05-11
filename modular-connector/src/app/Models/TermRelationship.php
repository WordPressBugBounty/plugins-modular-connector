<?php

namespace Modular\Connector\Models;

use Modular\ConnectorDependencies\Illuminate\Database\Eloquent\Model;

class TermRelationship extends Model
{
    protected $table = 'term_relationships';

    protected $primaryKey = ['object_id', 'term_taxonomy_id'];

    public $timestamps = false;

    public $incrementing = false;

    public function post()
    {
        return $this->belongsTo(Post::class, 'object_id');
    }

    public function taxonomy()
    {
        return $this->belongsTo(Taxonomy::class, 'term_taxonomy_id');
    }
}
