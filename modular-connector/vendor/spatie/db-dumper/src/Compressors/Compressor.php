<?php

namespace Modular\ConnectorDependencies\Spatie\DbDumper\Compressors;

interface Compressor
{
    public function useCommand(): string;
    public function useExtension(): string;
}
