<?php

namespace Modular\Connector\Concerns;

trait FormatsErrors
{
    /**
     * Formats a throwable into a standardized error array.
     *
     * @param \Throwable $error
     * @return array{code: mixed, message: string}
     */
    protected function formatError(\Throwable $error): array
    {
        return [
            'code' => $error->getCode(),
            'message' => sprintf(
                '%s in %s on line %s',
                $error->getMessage(),
                $error->getFile(),
                $error->getLine()
            ),
        ];
    }
}
