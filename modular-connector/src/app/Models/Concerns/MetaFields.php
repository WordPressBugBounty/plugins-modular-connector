<?php

namespace Modular\Connector\Models\Concerns;

use Modular\Connector\Models\Comment;
use Modular\Connector\Models\CommentMeta;
use Modular\Connector\Models\Post;
use Modular\Connector\Models\PostMeta;
use Modular\Connector\Models\Term;
use Modular\Connector\Models\TermMeta;
use Modular\connector\Models\User;
use Modular\connector\Models\UserMeta;
use UnexpectedValueException;
use function Modular\ConnectorDependencies\class_basename;

trait MetaFields
{
    protected $builtInClasses = [
        Comment::class  => CommentMeta::class,
        Post::class     => PostMeta::class,
        Term::class     => TermMeta::class,
        User::class     => UserMeta::class
    ];

    public function fields()
    {
        return $this->meta();
    }

    public function meta()
    {
        return $this->hasMany($this->getMetaClass(), $this->getMetaForeignKey());
    }

    protected function getMetaClass()
    {
        foreach ($this->builtInClasses as $model => $meta) {
            if ($this instanceof $model) {
                return $meta;
            }
        }

        throw new UnexpectedValueException(sprintf(
            '%s must extend one of ModularDS built-in models: Comment, Post, Term or User.',
            static::class
        ));
    }

    protected function getMetaForeignKey(): string
    {
        foreach ($this->builtInClasses as $model => $_) {
            if ($this instanceof $model) {
                return sprintf('%s_id', strtolower(class_basename($model)));
            }
        }

        throw new UnexpectedValueException(sprintf(
            '%s must extend one of ModularDS built-in models: Comment, Post, Term or User.',
            static::class
        ));
    }

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

                return is_null($value) ? $query :
                    $query->where('meta_value', $operator, $value);
            });
        }

        return $query;
    }

    public function scopeHasMetaLike($query, $meta, $value = null)
    {
        return $this->scopeHasMeta($query, $meta, $value, 'like');
    }

    public function saveField($key, $value)
    {
        return $this->saveMeta($key, $value);
    }

    public function saveMeta($key, $value = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->saveOneMeta($k, $v);
            }
            $this->load('meta');

            return true;
        }

        return $this->saveOneMeta($key, $value);
    }

    private function saveOneMeta($key, $value)
    {
        $meta = $this->meta()->where('meta_key', $key)
            ->firstOrNew(['meta_key' => $key]);

        $result = $meta->fill(['meta_value' => $value])->save();
        $this->load('meta');

        return $result;
    }

    public function createField($key, $value)
    {
        return $this->createMeta($key, $value);
    }

    public function createMeta($key, $value = null)
    {
        if (is_array($key)) {
            return collect($key)->map(function ($value, $key) {
                return $this->createOneMeta($key, $value);
            });
        }

        return $this->createOneMeta($key, $value);
    }

    private function createOneMeta($key, $value)
    {
        $meta =  $this->meta()->create([
            'meta_key' => $key,
            'meta_value' => $value,
        ]);
        $this->load('meta');

        return $meta;
    }

    public function getMeta($attribute)
    {
        if ($meta = $this->meta->{$attribute}) {
            return $meta;
        }

        return null;
    }
}
