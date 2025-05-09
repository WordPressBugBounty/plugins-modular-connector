<?php

declare (strict_types=1);
namespace Modular\ConnectorDependencies\League\MimeTypeDetection;

interface ExtensionLookup
{
    public function lookupExtension(string $mimetype): ?string;
    /**
     * @return string[]
     */
    public function lookupAllExtensions(string $mimetype): array;
}
