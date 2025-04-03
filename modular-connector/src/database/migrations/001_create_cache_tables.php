<?php

namespace Modular\Connector\database\migrations;

use Modular\ConnectorDependencies\Illuminate\Database\Migrations\Migration;
use Modular\ConnectorDependencies\Illuminate\Database\Schema\Blueprint;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * @var string
     */
    public string $version = '1.13.0';

    /**
     * @var string
     */
    protected $connection = 'modular';

    public function up()
    {
        Schema::connection($this->connection)->dropIfExists('cache');

        Schema::connection($this->connection)->create('cache', function (Blueprint $table) {
            $table->string('key', 191)->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::connection($this->connection)->dropIfExists('cache_locks');

        Schema::connection($this->connection)->create('cache_locks', function (Blueprint $table) {
            $table->string('key', 191)->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    public function down()
    {
        Schema::connection($this->connection)->dropIfExists('cache');
        Schema::connection($this->connection)->dropIfExists('cache_locks');
    }
};
