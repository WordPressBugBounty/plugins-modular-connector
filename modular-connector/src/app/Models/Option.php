<?php

namespace Modular\Connector\Models;

use Exception;
use Modular\ConnectorDependencies\Illuminate\Database\Eloquent\Model;

class Option extends Model
{
    protected $table = 'options';

    protected $primaryKey = 'option_id';

    protected $fillable = [
        'option_name',
        'option_value',
        'autoload',
    ];

    protected $appends = ['value'];

    public function getValueAttribute()
    {
        try {
            $value = unserialize($this->option_value);

            return $value === false && $this->option_value !== false ?
                $this->option_value :
                $value;
        } catch (Exception $ex) {
            return $this->option_value;
        }
    }

    public static function add($key, $value)
    {
        return static::create([
            'option_name' => $key,
            'option_value' => is_array($value) ? serialize($value) : $value,
        ]);
    }

    public static function get($name)
    {
        if ($option = self::where('option_name', $name)->first()) {
            return $option->value;
        }

        return null;
    }

    public static function getAll()
    {
        return static::asArray();
    }

    public static function asArray($keys = [])
    {
        $query = static::query();

        if (!empty($keys)) {
            $query->whereIn('option_name', $keys);
        }

        return $query->get()
            ->pluck('value', 'option_name')
            ->toArray();
    }

    public function toArray()
    {
        if ($this instanceof Option) {
            return [$this->option_name => $this->value];
        }

        return parent::toArray();
    }
}
