<?php

namespace Modular\ConnectorDependencies\Illuminate\Http\Resources;

interface PotentiallyMissing
{
    /**
     * Determine if the object should be considered "missing".
     *
     * @return bool
     */
    public function isMissing();
}
