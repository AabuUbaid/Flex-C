<?php
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/handlers_core.php';
require __DIR__ . '/../lib/handlers_sales.php';
require __DIR__ . '/../lib/handlers_office.php';
require __DIR__ . '/../lib/handlers_reports.php';
require __DIR__ . '/../lib/handlers_dash.php';
require __DIR__ . '/../lib/handlers_customers.php';
require __DIR__ . '/../lib/handlers_recon.php';
require __DIR__ . '/../lib/handlers_tax.php';
require __DIR__ . '/../lib/handlers_verify.php';
require __DIR__ . '/../lib/handlers_stocktake.php';
require_once __DIR__ . '/../lib/migrate.php';
require_once __DIR__ . '/../lib/autofp.php';


// route => [HTTP method, handler]
$routes = [
    'login'                 => ['POST', 'h_login'],
    'forgot'                => ['POST', 'h_forgot'],
    'logout'                => ['POST', 'h_logout'],
    'org'                   => ['GET',  'h_org'],
    'master'                => ['GET',  'h_master'],
    'version'               => ['GET',  'h_version'],
    'sync_sales'            => ['POST', 'h_sync_sales'],
    'authorize'             => ['POST', 'h_authorize'],
    'day_summary'           => ['GET',  'h_day_summary'],
    'day_sales'             => ['GET',  'h_day_sales'],
    'close_day'             => ['POST', 'h_close_day'],
    'closings_list'         => ['GET',  'h_closings_list'],
    'returns_list'          => ['GET',  'h_returns_list'],
    'stock_ledger'          => ['GET',  'h_stock_ledger'],
    'notifications_list'    => ['GET',  'h_notifications_list'],
    'notification_action'   => ['POST', 'h_notification_action'],
    'settings_get'          => ['GET',  'h_settings_get'],
    'settings_save'         => ['POST', 'h_settings_save'],
    'logo_save'             => ['POST', 'h_logo_save'],
    'users_list'            => ['GET',  'h_users_list'],
    'user_save'             => ['POST', 'h_user_save'],
    'user_reset'            => ['POST', 'h_user_reset'],
    'brand'                 => ['GET',  'h_brand'],
    'sales_report'          => ['GET',  'h_sales_report'],
    'items_list'            => ['GET',  'h_items_list'],
    'items_save'            => ['POST', 'h_items_save'],
    'stock_levels'          => ['GET',  'h_stock_levels'],
    'stock_adjust'          => ['POST', 'h_stock_adjust'],
    'pos_stock_add'         => ['POST', 'h_pos_stock_add'],
    'stock_transfer'        => ['POST', 'h_stock_transfer'],
    'suppliers_list'        => ['GET',  'h_suppliers_list'],
    'suppliers_save'        => ['POST', 'h_suppliers_save'],
    'purchase_save'         => ['POST', 'h_purchase_save'],
    'purchases_list'        => ['GET',  'h_purchases_list'],
    'purchase_attach'       => ['POST', 'h_purchase_attach'],
    'purchase_file'         => ['GET',  'h_purchase_file'],
    'vat_return'            => ['GET',  'h_vat_return'],
    'preturn_save'          => ['POST', 'h_preturn_save'],
    'preturns_list'         => ['GET',  'h_preturns_list'],
    'labels_log'            => ['POST', 'h_labels_log'],
    'labels_history'        => ['GET',  'h_labels_history'],
    'export'                => ['GET',  'h_export'],
    'reconcile'             => ['GET',  'h_reconcile'],
    'stock_import'          => ['POST', 'h_stock_import'],
    'stock_lookup'          => ['GET',  'h_stock_lookup'],
    'stock_count'           => ['POST', 'h_stock_count'],
    'stock_count_batch'     => ['POST', 'h_stock_count_batch'],
    'item_barcode_link'     => ['POST', 'h_item_barcode_link'],
    'item_delete'           => ['POST', 'h_item_delete'],
    'stock_report'          => ['GET',  'h_stock_report'],
    'profit_report'         => ['GET',  'h_profit_report'],
    'items_remove_samples'  => ['POST', 'h_items_remove_samples'],
    'company_update'        => ['POST', 'h_company_update'],
    'count_item_search'     => ['GET',  'h_count_item_search'],
    'purchase_get'          => ['GET',  'h_purchase_get'],
    'supplier_payment_save' => ['POST', 'h_supplier_payment_save'],
    'expense_save'          => ['POST', 'h_expense_save'],
    'expenses_list'         => ['GET',  'h_expenses_list'],
    'expense_delete'        => ['POST', 'h_expense_delete'],
    'verify'                => ['GET',  'h_verify'],
    'customers_list'        => ['GET',  'h_customers_list'],
    'customer_update'       => ['POST', 'h_customer_update'],
    'dashboard'             => ['GET',  'h_dashboard'],
    'count_list'            => ['GET',  'h_count_list'],
    'count_start'           => ['POST', 'h_count_start'],
    'count_get'             => ['GET',  'h_count_get'],
    'count_scan'            => ['POST', 'h_count_scan'],
    'count_line'            => ['POST', 'h_count_line'],
    'count_action'          => ['POST', 'h_count_action'],
    'stockers_create'       => ['POST', 'h_stockers_create'],
];

try {
    if (is_file(__DIR__ . '/../lib/storage/maintenance.flag') && time() - (int)filemtime(__DIR__ . '/../lib/storage/maintenance.flag') < 600) {
        fail('updating', 503);   // a new version is being applied; registers keep their sales and retry
    }
    migrate_if_needed(db(), __DIR__ . '/../sql/migrations', __DIR__ . '/../lib/storage');
    autofp_if_needed(dirname(__DIR__), __DIR__ . '/../lib/storage');   // files changed by hand? re-stamp ?v= and app versions
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
    error_log('api db error: ' . $e->getMessage());
    json_out(['error' => 'server_error'], 500);
} catch (Throwable $e) {
    rollback_if_open();
    error_log('api error: ' . $e->getMessage());
    json_out(['error' => 'server_error'], 500);
}
