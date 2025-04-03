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
        Schema::connection($this->connection)->dropIfExists('jobs');

        Schema::connection($this->connection)->create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue', 191)->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down()
    {
        Schema::connection($this->connection)->dropIfExists('jobs');
    }
};
