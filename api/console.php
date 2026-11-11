<?php
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/handlers_core.php';      // release_state()
require __DIR__ . '/../lib/handlers_sales.php';     // rollback_if_open()
require __DIR__ . '/../lib/handlers_console.php';
require __DIR__ . '/../lib/handlers_demo.php';
require_once __DIR__ . '/../lib/migrate.php';
require_once __DIR__ . '/../lib/updater.php';


$routes = [
    'login'           => ['POST', 'c_login'],
    'logout'          => ['POST', 'c_logout'],
    'overview'        => ['GET',  'c_overview'],
    'tenant_get'      => ['GET',  'c_tenant_get'],
    'tenant_create'   => ['POST', 'c_tenant_create'],
    'tenant_update'   => ['POST', 'c_tenant_update'],
    'company_save'    => ['POST', 'c_company_save'],
    'shop_save'       => ['POST', 'c_shop_save'],
    'register_save'   => ['POST', 'c_register_save'],
    'user_save'       => ['POST', 'c_user_save'],
    'user_action'     => ['POST', 'c_user_action'],
    'releases'        => ['GET',  'c_releases'],
    'release_publish' => ['POST', 'c_release_publish'],
    'release_update'  => ['POST', 'c_release_update'],
    'staff_list'      => ['GET',  'c_staff_list'],
    'staff_save'      => ['POST', 'c_staff_save'],
    'staff_action'    => ['POST', 'c_staff_action'],
    'audit'           => ['GET',  'c_audit'],
    'update_status'   => ['GET',  'c_update_status'],
    'update_key_set'  => ['POST', 'c_update_key_set'],
    'update_upload'   => ['POST', 'c_update_upload'],
    'update_rollback' => ['POST', 'c_update_rollback'],
    'demo_create'     => ['POST', 'c_demo_create'],
    'clear_stock'     => ['POST', 'c_clear_stock'],
    'rollout'         => ['POST', 'c_rollout'],
];

try {
    if (is_file(__DIR__ . '/../lib/storage/maintenance.flag') && time() - (int)filemtime(__DIR__ . '/../lib/storage/maintenance.flag') < 600) {
        fail('updating', 503);   // a new version is being applied; registers keep their sales and retry
    }
    migrate_if_needed(db(), __DIR__ . '/../sql/migrations', __DIR__ . '/../lib/storage');
    $r = (string)($_GET['r'] ?? '');
    if (!isset($routes[$r])) {
        fail('not_found', 404);
    }
    [$method, $fn] = $routes[$r];
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        fail('method_not_allowed', 405);
    }
    $fn();
} catch (ApiError $e) {
    rollback_if_open();
    json_out(['error' => $e->getMessage()], $e->status);
} catch (PDOException $e) {
    rollback_if_open();
    if (($e->errorInfo[1] ?? 0) === 1062) {
        json_out(['error' => 'duplicate'], 409);
    }
    error_log('console db error: ' . $e->getMessage());
    json_out(['error' => 'server_error'], 500);
} catch (Throwable $e) {
    rollback_if_open();
    error_log('console error: ' . $e->getMessage());
    json_out(['error' => 'server_error'], 500);
}
