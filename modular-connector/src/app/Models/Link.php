<?php

namespace Modular\Connector\Models;

use Modular\ConnectorDependencies\Illuminate\Database\Eloquent\Model;

class Link extends Model
{
    protected $table = 'links';

    protected $primaryKey = 'link_id';
}
