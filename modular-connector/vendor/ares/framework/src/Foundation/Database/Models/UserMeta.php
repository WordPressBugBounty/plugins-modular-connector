<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Models;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Concerns\ResolvesCustomTable;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Models\Meta\Meta;
class UserMeta extends Meta
{
    use ResolvesCustomTable;
    /**
     * @var string
     */
    protected $table = 'usermeta';
    /**
     * @var string
     */
    protected $primaryKey = 'umeta_id';
    /**
     * Resolve the table name, respecting WordPress CUSTOM_USER_META_TABLE constant.
     *
     * @return string
     */
    public function getTable()
    {
        if (defined('CUSTOM_USER_META_TABLE')) {
            return $this->resolveCustomTable(\CUSTOM_USER_META_TABLE);
        }
        return parent::getTable();
    }
    /**
     * @var string[]
     */
    protected $fillable = ['meta_key', 'meta_value', 'user_id'];
    /**
     * @return mixed
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
