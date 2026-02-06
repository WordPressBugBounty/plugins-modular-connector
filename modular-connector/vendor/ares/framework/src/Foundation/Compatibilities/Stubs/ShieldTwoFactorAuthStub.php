<?php

namespace Modular\ConnectorDependencies;

/**
 * Stub class for Shield Security 2FA processor.
 *
 * This dummy class prevents Shield Security from enforcing 2FA
 * during Modular's authenticated login flow.
 *
 * @see LoginCompatibilities::fixWpSimpleFirewall()
 */
class ICWP_WPSF_Processor_LoginProtect_TwoFactorAuth
{
    public function run()
    {
        // Intentionally empty - stub to bypass 2FA checks
    }
}
/**
 * Stub class for Shield Security 2FA processor.
 *
 * This dummy class prevents Shield Security from enforcing 2FA
 * during Modular's authenticated login flow.
 *
 * @see LoginCompatibilities::fixWpSimpleFirewall()
 */
\class_alias('Modular\ConnectorDependencies\ICWP_WPSF_Processor_LoginProtect_TwoFactorAuth', 'ICWP_WPSF_Processor_LoginProtect_TwoFactorAuth', \false);
