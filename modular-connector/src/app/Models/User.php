<?php

namespace Modular\Connector\Models;

use Modular\Connector\Models\Concerns\Aliases;
use Modular\Connector\Models\Concerns\MetaFields;
use Modular\Connector\Models\Concerns\OrderScopes;
use Modular\ConnectorDependencies\Illuminate\Contracts\Auth\Authenticatable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Auth\CanResetPassword;
use Modular\ConnectorDependencies\Illuminate\Database\Eloquent\Model;

class User extends Model implements Authenticatable, CanResetPassword
{
    const CREATED_AT = 'user_registered';
    const UPDATED_AT = null;

    use Aliases;
    use MetaFields;
    use OrderScopes;

    protected $table = 'users';

    protected $primaryKey = 'ID';

    protected $hidden = ['user_pass'];

    protected $dates = ['user_registered'];

    protected $with = ['meta'];

    protected static $aliases = [
        'login'         => 'user_login',
        'email'         => 'user_email',
        'slug'          => 'user_nicename',
        'url'           => 'user_url',
        'nickname'      => ['meta' => 'nickname'],
        'first_name'    => ['meta' => 'first_name'],
        'last_name'     => ['meta' => 'last_name'],
        'description'   => ['meta' => 'description'],
        'created_at'    => 'user_registered',
    ];

    protected $appends = [
        'login',
        'email',
        'slug',
        'url',
        'nickname',
        'first_name',
        'last_name',
        'avatar',
        'created_at',
    ];

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($user) {
            $user->meta()->delete();
        });
    }

    public function setUpdatedAtAttribute($value) {}

    public function posts()
    {
        return $this->hasMany(Post::class, 'post_author');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class, 'user_id');
    }

    public function getAuthIdentifierName()
    {
        return $this->primaryKey;
    }

    public function getAuthIdentifier()
    {
        return $this->attributes[$this->primaryKey];
    }

    public function getAuthPassword()
    {
        $authPasswordName = $this->getAuthPasswordName();

        return $this->{$authPasswordName};
    }

    public function getAuthPasswordName()
    {
        return 'user_pass';
    }

    public function getRememberToken()
    {
        $tokenName = $this->getRememberTokenName();

        return $this->meta->{$tokenName};
    }

    public function setRememberToken($value)
    {
        $tokenName = $this->getRememberTokenName();

        $this->saveMeta($tokenName, $value);
    }

    public function getRememberTokenName()
    {
        return 'remember_token';
    }

    public function getEmailForPasswordReset()
    {
        return $this->user_email;
    }

    public function sendPasswordResetNotification($token) {}

    public function getAvatarAttribute()
    {
        $hash = !empty($this->email) ? md5(strtolower(trim($this->email))) : '';

        return sprintf('//secure.gravatar.com/avatar/%s?d=mm', $hash);
    }
    
    public function setUpdatedAt($value)
    {
        //
    }
}
