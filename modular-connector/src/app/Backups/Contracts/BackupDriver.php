<?php

namespace Modular\Connector\Backups\Contracts;

interface BackupDriver
{
    public function options($requestId, $payload): BackupDriver;

    public function listeners(): void;

    public function make(): void;
}
