<?php

if (!defined('ABSPATH')) {
    exit;
}

use Modular\Connector\Http\Controllers\AuthController;
use Modular\Connector\Http\Controllers\BackupController;
use Modular\Connector\Http\Controllers\CacheController;
use Modular\Connector\Http\Controllers\ManagerController;
use Modular\Connector\Http\Controllers\OptimizationController;
use Modular\Connector\Http\Controllers\SafeUpgradeController;
use Modular\Connector\Http\Controllers\ServerController;
use Modular\Connector\Http\Controllers\WooCommerceController;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Route;
use function Modular\ConnectorDependencies\abort;

Route::get('/oauth', [AuthController::class, 'postConfirmOauth'])
    ->name('modular-connector.oauth');

Route::get('/schedule/run', [ServerController::class, 'getLoopback'])
    ->name('schedule.run');

# Modular Connector Routes
Route::get('/login/{modular_request}', [AuthController::class, 'getLogin'])
    ->name('login');

Route::get('/users/{modular_request}', [AuthController::class, 'getUsers'])
    ->name('manager.users.index');

Route::get('/server-information/{modular_request}', [ServerController::class, 'getInformation'])
    ->name('manager.server.information');

Route::get('/server-health/{modular_request}', [ServerController::class, 'getHealth'])
    ->name('manager.server.health');

Route::get('/white-label/{modular_request}', [ServerController::class, 'getWhiteLabel'])
    ->name('manager.whiteLabel.update');

Route::get('/manager/maintenance/{modular_request}', [ServerController::class, 'maintenance'])
    ->name('manager.maintenance.update');

#region Database
Route::get('/manager/optimization/{modular_request}', [OptimizationController::class, 'index'])
    ->name('manager.optimization.information');

Route::get('/database/optimize/{modular_request}', [OptimizationController::class, 'optimizeDatabase'])
    ->name('manager.optimization.process');
#endregion

#region Cache
Route::get('/cache/clear/{modular_request}', [CacheController::class, 'clear'])
    ->name('manager.cache.clear');
#endregion

#region Manager
Route::get('/manager/{modular_request}', [ManagerController::class, 'index'])
    ->name('manager.update');

Route::get('/manager/{modular_request}/install', [ManagerController::class, 'store'])
    ->name('manager.install');

Route::get('/manager/{modular_request}/rollback', [ManagerController::class, 'store'])
    ->name('manager.upgrade.rollback');

Route::get('/manager/{modular_request}/upgrade', [ManagerController::class, 'update'])
    ->name('manager.upgrade');

Route::get('/manager/{modular_request}/activate', [ManagerController::class, 'update'])
    ->name('manager.activate');

Route::get('/manager/{modular_request}/deactivate', [ManagerController::class, 'update'])
    ->name('manager.deactivate');

Route::get('/manager/{modular_request}/delete', [ManagerController::class, 'update'])
    ->name('manager.delete');
#endregion


# region Safe Upgrade
Route::get('/manager/{modular_request}/safe-upgrade/backup', [SafeUpgradeController::class, 'getSafeUpgradeBackup'])
    ->name('manager.safe-upgrade.backup');

Route::get('/manager/{modular_request}/safe-upgrade/cleanup', [SafeUpgradeController::class, 'getSafeUpgradeCleanup'])
    ->name('manager.safe-upgrade.cleanup');

Route::get('/manager/{modular_request}/safe-upgrade/rollback', [SafeUpgradeController::class, 'getSafeUpgradeRollback'])
    ->name('manager.safe-upgrade.rollback');
# endregion

#region Backup
Route::get('/tree/directory/{modular_request}', [BackupController::class, 'getDirectoryTree'])
    ->name('manager.directory.tree');

Route::get('/tree/database/{modular_request}', [BackupController::class, 'getDatabaseTree'])
    ->name('manager.database.tree');

Route::get('/backup/information/{modular_request}', [BackupController::class, 'getBackupInformation'])
    ->name('manager.backup.information');

Route::get('/backup/{modular_request}', [BackupController::class, 'store'])
    ->name('manager.backup.make');

Route::get('/backup/{modular_request}/remove', [BackupController::class, 'destroy'])
    ->name('manager.backup.remove');
#endregion

Route::get('/woocommerce/{modular_request}', WooCommerceController::class)
    ->name('manager.woocommerce.stats');
#endregion

Route::get('default', function () {
    if (!HttpUtils::isCron()) {
        abort(404);
    }

    while (ob_get_level()) {
        ob_end_clean();
    }
})->name('default');
