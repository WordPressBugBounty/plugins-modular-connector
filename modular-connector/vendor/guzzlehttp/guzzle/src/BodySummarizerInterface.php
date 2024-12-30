<?php

namespace Modular\ConnectorDependencies\GuzzleHttp;

use Modular\ConnectorDependencies\Psr\Http\Message\MessageInterface;
interface BodySummarizerInterface
{
    /**
     * Returns a summarized message body.
     */
    public function summarize(MessageInterface $message): ?string;
}
