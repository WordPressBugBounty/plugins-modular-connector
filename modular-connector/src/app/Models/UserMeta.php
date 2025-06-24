<?php

namespace Modular\Connector\Models;

use Modular\Connector\Models\Meta\Meta;

class UserMeta extends Meta
{
    protected $table = 'usermeta';

    protected $primaryKey = 'umeta_id';

    protected $fillable = ['meta_key', 'meta_value', 'user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
