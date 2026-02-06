<?php

namespace Modular\Connector\Models;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Models\Meta\Meta;

class TermMeta extends Meta
{
    protected $table = 'termmeta';

    protected $fillable = ['meta_key', 'meta_value', 'term_id'];

    public function term()
    {
        return $this->belongsTo(Term::class);
    }
}
