<?php

namespace Modular\Connector\database\migrations;

use Modular\ConnectorDependencies\Illuminate\Database\Migrations\Migration;
use Modular\ConnectorDependencies\Illuminate\Database\Schema\Blueprint;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * @var string
     */
    public string $version = '3.0.0';

    /**
     * @var string
     */
    protected $connection = 'modular';

    public function up()
    {
        Schema::connection($this->connection)->dropIfExists('redirects');

        // Column names mirror the manager-side contract 1:1 except for `match`,
        // which is a MySQL reserved keyword (full-text search) — we store it as
        // `match_type` locally and map at the sync boundary.
        Schema::connection($this->connection)->create('redirects', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('source_id')->unique();
            $table->text('source_pattern');
            $table->text('destination_url');
            $table->unsignedSmallInteger('type')->default(301);
            $table->string('match_type', 10)->default('exact');
            $table->string('status', 10)->default('enabled');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::connection($this->connection)->dropIfExists('redirects');
    }
};
