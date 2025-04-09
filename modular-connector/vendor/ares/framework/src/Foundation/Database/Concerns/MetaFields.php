<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Concerns;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Models\User;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Models\UserMeta;
trait MetaFields
{
    /**
     * The built-in classes that can be used for meta.
     *
     * @var array
     */
    protected $builtInClasses = [User::class => UserMeta::class];
    /**
     * The meta key for the model.
     *
     * @return mixed
     */
    public function fields()
    {
        return $this->meta();
    }
    /**
     * @return mixed
     */
    public function meta()
    {
        return $this->hasMany($this->getMetaClass(), $this->getMetaForeignKey());
    }
    /**
     * @return mixed
     */
    protected function getMetaClass()
    {
        foreach ($this->builtInClasses as $model => $meta) {
            if ($this instanceof $model) {
                return $meta;
            }
        }
        throw new \UnexpectedValueException(sprintf('%s must extend one of Modular DS built-in models: Comment, Post, Term or User.', static::class));
    }
    /**
     * @return string
     */
    protected function getMetaForeignKey(): string
    {
        foreach ($this->builtInClasses as $model => $_) {
            if ($this instanceof $model) {
                return sprintf('%s_id', strtolower(\Modular\ConnectorDependencies\class_basename($model)));
            }
        }
        throw new UnexpectedValueException(sprintf('%s must extend one of ModularDS built-in models: Comment, Post, Term or User.', static::class));
    }
    /**
     * @param $query
     * @param $meta
     * @param $value
     * @return mixed
     */
    public function scopeHasMetaLike($query, $meta, $value = null)
    {
        return $this->scopeHasMeta($query, $meta, $value, 'like');
    }
    /**
     * @param $query
     * @param $meta
     * @param null $value
     * @param string $operator
     * @return mixed
     */
    public function scopeHasMeta($query, $meta, $value = null, string $operator = '=')
    {
        if (!is_array($meta)) {
            $meta = [$meta => $value];
        }
        foreach ($meta as $key => $value) {
            $query->whereHas('meta', function ($query) use ($key, $value, $operator) {
                if (!is_string($key)) {
                    return $query->where('meta_key', $operator, $value);
                }
                $query->where('meta_key', $operator, $key);
                return is_null($value) ? $query : $query->where('meta_value', $operator, $value);
            });
        }
        return $query;
    }
    /**
     * @param $key
     * @param $value
     * @return true
     */
    public function saveField($key, $value)
    {
        return $this->saveMeta($key, $value);
    }
    /**
     * @param $key
     * @param $value
     * @return true
     */
    public function saveMeta($key, $value = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->saveOneMeta($k, $v);
            }
            $this->load('meta');
            return \true;
        }
        return $this->saveOneMeta($key, $value);
    }
    /**
     * @param $key
     * @param $value
     * @return mixed
     */
    private function saveOneMeta($key, $value)
    {
        $meta = $this->meta()->where('meta_key', $key)->firstOrNew(['meta_key' => $key]);
        $result = $meta->fill(['meta_value' => $value])->save();
        $this->load('meta');
        return $result;
    }
    /**
     * @param $key
     * @param $value
     * @return mixed
     */
    public function createField($key, $value)
    {
        return $this->createMeta($key, $value);
    }
    /**
     * @param $key
     * @param $value
     * @return \Illuminate\Support\Collection
     */
    public function createMeta($key, $value = null)
    {
        if (is_array($key)) {
            return \Modular\ConnectorDependencies\collect($key)->map(function ($value, $key) {
                return $this->createOneMeta($key, $value);
            });
        }
        return $this->createOneMeta($key, $value);
    }
    /**
     * @param $key
     * @param $value
     * @return mixed
     */
    private function createOneMeta($key, $value)
    {
        $meta = $this->meta()->create(['meta_key' => $key, 'meta_value' => $value]);
        $this->load('meta');
        return $meta;
    }
    /**
     * @param $attribute
     * @return null
     */
    public function getMeta($attribute)
    {
        if ($meta = $this->meta->{$attribute}) {
            return $meta;
        }
        return null;
    }
}
