<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Models;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Concerns\Aliases;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Concerns\MetaFields;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Concerns\OrderScopes;
use Modular\ConnectorDependencies\Illuminate\Contracts\Auth\Authenticatable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Auth\CanResetPassword;
use Modular\ConnectorDependencies\Illuminate\Database\Eloquent\Model;
class User extends Model implements Authenticatable, CanResetPassword
{
    use Aliases;
    use MetaFields;
    use OrderScopes;
    const CREATED_AT = 'user_registered';
    const UPDATED_AT = null;
    /**
     * @var string
     */
    protected $table = 'users';
    /**
     * @var string
     */
    protected $primaryKey = 'ID';
    /**
     * @var string[]
     */
    protected $hidden = ['ID', 'user_pass', 'meta', 'user_login', 'user_email', 'user_nicename', 'user_url', 'user_activation_key'];
    /**
     * @var string[]
     */
    protected $dates = ['user_registered'];
    /**
     * @var string[]
     */
    protected $with = ['meta'];
    /**
     * @var array
     */
    protected static $aliases = ['id' => 'ID', 'username' => 'user_login', 'email' => 'user_email', 'slug' => 'user_nicename', 'url' => 'user_url', 'nickname' => ['meta' => 'nickname'], 'first_name' => ['meta' => 'first_name'], 'last_name' => ['meta' => 'last_name'], 'description' => ['meta' => 'description'], 'created_at' => 'user_registered'];
    /**
     * @var string[]
     */
    protected $appends = ['id', 'username', 'email', 'slug', 'url', 'nickname', 'first_name', 'last_name', 'avatar', 'created_at'];
    /**
     * @return void
     */
    protected static function boot()
    {
        parent::boot();
        static::deleting(function ($user) {
            $user->meta()->delete();
        });
    }
    /**
     * @param $value
     * @return void
     */
    public function setUpdatedAtAttribute($value)
    {
    }
    /**
     * @return string
     */
    public function getAuthIdentifierName()
    {
        return $this->primaryKey;
    }
    /**
     * @return mixed
     */
    public function getAuthIdentifier()
    {
        return $this->attributes[$this->primaryKey];
    }
    /**
     * @return mixed|string
     */
    public function getAuthPassword()
    {
        $authPasswordName = $this->getAuthPasswordName();
        return $this->{$authPasswordName};
    }
    /**
     * @return string
     */
    public function getAuthPasswordName()
    {
        return 'user_pass';
    }
    /**
     * @return string
     */
    public function getRememberToken()
    {
        $tokenName = $this->getRememberTokenName();
        return $this->meta->{$tokenName};
    }
    /**
     * @param $value
     * @return void
     */
    public function setRememberToken($value)
    {
        $tokenName = $this->getRememberTokenName();
        $this->saveMeta($tokenName, $value);
    }
    /**
     * @return string
     */
    public function getRememberTokenName()
    {
        return 'remember_token';
    }
    /**
     * @return mixed|string
     */
    public function getEmailForPasswordReset()
    {
        return $this->user_email;
    }
    /**
     * @param $token
     * @return void
     */
    public function sendPasswordResetNotification($token)
    {
    }
    /**
     * @return string
     */
    public function getAvatarAttribute()
    {
        $hash = !empty($this->email) ? md5(strtolower(trim($this->email))) : '';
        return sprintf('//secure.gravatar.com/avatar/%s?d=mm', $hash);
    }
}
