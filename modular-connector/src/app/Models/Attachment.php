<?php

namespace Modular\Connector\Models;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Concerns\Aliases;

class Attachment extends Post
{
    use Aliases;

    protected $postType = 'attachment';

    protected $appends = [
        'title',
        'url',
        'type',
        'description',
        'caption',
        'alt',
    ];

    protected static $aliases = [
        'title' => 'post_title',
        'url' => 'guid',
        'type' => 'post_mime_type',
        'description' => 'post_content',
        'caption' => 'post_excerpt',
        'alt' => ['meta' => '_wp_attachment_image_alt'],
    ];
}
