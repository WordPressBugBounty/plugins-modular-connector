<?php

namespace Modular\ConnectorDependencies\Egulias\EmailValidator\Warning;

/** @internal */
class IPV6MaxGroups extends Warning
{
    const CODE = 75;
    public function __construct()
    {
        $this->message = 'Reached the maximum number of IPV6 groups allowed';
        $this->rfcNumber = 5321;
    }
}
