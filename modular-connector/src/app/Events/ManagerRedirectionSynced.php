<?php

namespace Modular\Connector\Events;

use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Emitted by {@see \Modular\Connector\Jobs\RedirectsSyncJob} after the redirect
 * payload from the manager has been processed. Class name maps to webhook type
 * `manager.redirection.synced` via Str::snake in HookSendEventJob.
 *
 * Payload shape:
 *  - status: 'success' | 'error'
 *  - error:  null on success, exception message on error
 *  - On success: synced/created/updated/deleted/rejected keys from RedirectService::sync()
 */
class ManagerRedirectionSynced extends AbstractEvent implements ShouldQueue
{
}
