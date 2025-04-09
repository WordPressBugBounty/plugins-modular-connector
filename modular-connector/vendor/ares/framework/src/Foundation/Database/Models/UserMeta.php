<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Models;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Meta\Meta;
class UserMeta extends Meta
{
    /**
     * @var string
     */
    protected $table = 'usermeta';
    /**
     * @var string
     */
    protected $primaryKey = 'umeta_id';
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
