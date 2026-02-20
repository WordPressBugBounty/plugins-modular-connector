<?php

namespace Modular\Connector\Models;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Concerns\ResolvesCustomTable;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Models\Meta\Meta;

class UserMeta extends Meta
{
    use ResolvesCustomTable;

    protected $table = 'usermeta';

    protected $primaryKey = 'umeta_id';

    /**
     * Resolve the table name, respecting WordPress CUSTOM_USER_META_TABLE constant.
     *
     * @return string
     */
    public function getTable()
    {
        if (defined('CUSTOM_USER_META_TABLE')) {
            return $this->resolveCustomTable(CUSTOM_USER_META_TABLE);
        }

        return parent::getTable();
    }

    protected $fillable = ['meta_key', 'meta_value', 'user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
