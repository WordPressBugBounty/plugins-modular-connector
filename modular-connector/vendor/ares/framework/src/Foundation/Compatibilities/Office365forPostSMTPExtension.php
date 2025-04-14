<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class Office365forPostSMTPExtension
{
    public static function fix()
    {
        add_action('plugins_loaded', function () {
            if (!class_exists('Post_Smtp_Office365')) {
                return;
            }
            // Disabled "Authorization code should be in the "code" query param"
            Compatibilities::removeFilterByClassName('post_smtp_handle_oauth', \Post_Smtp_Office365::class, 'handle_oauth');
        });
    }
}
