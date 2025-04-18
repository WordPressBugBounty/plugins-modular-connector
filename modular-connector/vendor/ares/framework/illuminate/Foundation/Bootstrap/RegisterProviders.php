<?php

namespace Modular\ConnectorDependencies\Illuminate\Foundation\Bootstrap;

use Modular\ConnectorDependencies\Illuminate\Contracts\Foundation\Application;
class RegisterProviders
{
    /**
     * Bootstrap the given application.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public function bootstrap(Application $app)
    {
        $app->registerConfiguredProviders();
    }
}
