<?php

namespace Modular\Connector\Models;

use Modular\Connector\Models\Concerns\MetaFields;
use Modular\ConnectorDependencies\Illuminate\Database\Eloquent\Model;

class Term extends Model
{

    use MetaFields;

    protected $table = 'terms';

    protected $primaryKey = 'term_id';

    public $timestamps = false;

    public function taxonomy()
    {
        return $this->hasOne(Taxonomy::class, 'term_id');
    }
}
