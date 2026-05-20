<?php

namespace Modular\Connector\Manifest\Contracts;

/**
 * Generic contract for any per-disk manifest writer driven by CalculateManifestJob.
 * Implementations decide the on-disk format (Iron uses CSV, scan uses CSV per-disk
 * pre-merge then NDJSON post-consolidation).
 */
interface ManifestContract
{
    /**
     * @return void
     */
    public function calculate();

    /**
     * @return int
     */
    public function count();
}
