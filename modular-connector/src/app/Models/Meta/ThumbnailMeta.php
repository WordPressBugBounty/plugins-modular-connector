<?php

namespace Modular\Connector\Models\Meta;

use Modular\Connector\Models\Attachment;
use Modular\ConnectorDependencies\Illuminate\Support\Arr;

class ThumbnailMeta extends PostMeta
{
    const SIZE_THUMBNAIL = 'thumbnail';
    const SIZE_MEDIUM = 'medium';
    const SIZE_LARGE = 'large';
    const SIZE_FULL = 'full';

    protected $with = ['attachment'];

    public function attachment()
    {
        return $this->belongsTo(Attachment::class, 'meta_value');
    }

    public function size($size)
    {
        if ($size == self::SIZE_FULL) {
            return $this->attachment->url;
        }

        $meta = unserialize($this->attachment->meta->_wp_attachment_metadata);
        $sizes = Arr::get($meta, 'sizes');

        if (!isset($sizes[$size])) {
            return $this->attachment->url;
        }

        $data = Arr::get($sizes, $size);

        return array_merge($data, [
            'url' => dirname($this->attachment->url) . '/' . $data['file'],
        ]);
    }

    public function __toString()
    {
        return $this->attachment->guid;
    }
}
