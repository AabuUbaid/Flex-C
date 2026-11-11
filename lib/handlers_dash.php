<?php
declare(strict_types=1);

/**
 * Owner "quick look": every company in the tenant on one screen.
 * Sales numbers come from daily_summary (pre-aggregated at sync time), so this stays fast.
 * Gross profit = sales excluding VAT minus cost of goods sold.
 */
function h_dashboard(): void
{
    $u = auth(['owner', 'manager', 'accountant'], true);
    $pdo = db();
    $t = $u['tenant_id'];

    $today = date('Y-m-d');
    $wk = date('Y-m-d', strtotime('-6 days'));
    $mo = date('Y-m-01');
    $min = min($wk, $mo);

    // The dates below come from PHP date(), never from user input.
    $st = $pdo->prepare(
        "SELECT company_id,
                SUM(IF(day = '$today', sales_total, 0)) AS t_sales, SUM(IF(day = '$today', txn_count, 0)) AS t_txn,
                SUM(IF(day = '$today', net_total - cogs, 0)) AS t_profit,
                SUM(IF(day = '$today', cash_total, 0)) AS t_cash, SUM(IF(day = '$today', card_total, 0)) AS t_card,
                SUM(IF(day >= '$wk', sales_total, 0)) AS w_sales, SUM(IF(day >= '$wk', net_total - cogs, 0)) AS w_profit,
                SUM(IF(day >= '$mo', sales_total, 0)) AS m_sales, SUM(IF(day >= '$mo', net_total - cogs, 0)) AS m_profit,
                SUM(IF(day >= '$mo', vat_total, 0)) AS m_vat
           FROM daily_summary WHERE tenant_id = ? AND day >= '$min' GROUP BY company_id"
    );
    $st->execute([$t]);
    // Last 7 days per company for the trend chart (missing days count as zero).
    $trendRows = $pdo->prepare("SELECT company_id, day, SUM(sales_total) AS s FROM daily_summary WHERE tenant_id = ? AND day >= '$wk' GROUP BY company_id, day");
    $trendRows->execute([$t]);
    $trendBy = [];
    foreach ($trendRows->fetchAll() as $tr) {
        $trendBy[(int)$tr['company_id']][$tr['day']] = (float)$tr['s'];
    }
    $sales = [];
    foreach ($st->fetchAll() as $r) {
        $sales[(int)$r['company_id']] = $r;
    }

    $st = $pdo->prepare(
        "SELECT s.id, s.company_id, s.name, s.code, s.trade, s.group_code,
                COALESCE(d.sales_total, 0) AS sales, COALESCE(d.txn_count, 0) AS txn,
                (SELECT MAX(created_at) FROM sales x WHERE x.shop_id = s.id) AS last_sync
           FROM shops s LEFT JOIN daily_summary d ON d.shop_id = s.id AND d.day = '$today'
          WHERE s.tenant_id = ? ORDER BY s.name"
    );
    $st->execute([$t]);
    $shops = [];
    foreach ($st->fetchAll() as $r) {
        $shops[(int)$r['company_id']][] = $r;
    }

    $threshold = (float)settings_for($t)['low_stock_threshold'];
    $st = $pdo->prepare(
        'SELECT i.company_id,
                SUM(COALESCE(q.q, 0) * i.cost) AS stock_value,
                SUM(COALESCE(q.q, 0) <= IF(i.reorder_level > 0, i.reorder_level, ?)) AS low_stock,
                SUM(COALESCE(q.q, 0) < 0) AS negative_stock
           FROM items i
           LEFT JOIN (SELECT item_id, SUM(qty) AS q FROM stock_ledger WHERE tenant_id = ? GROUP BY item_id) q ON q.item_id = i.id
          WHERE i.tenant_id = ? AND i.active = 1 GROUP BY i.company_id'
    );
    $st->execute([$threshold, $t, $t]);
    $stock = [];
    foreach ($st->fetchAll() as $r) {
        $stock[(int)$r['company_id']] = $r;
    }

    $st = $pdo->prepare(
        'SELECT s.company_id, SUM(s.opening_balance + COALESCE(p.t, 0) - COALESCE(x.t, 0)) AS payable
           FROM suppliers s
           LEFT JOIN (SELECT supplier_id, SUM(total) AS t FROM purchases WHERE tenant_id = ? GROUP BY supplier_id) p ON p.supplier_id = s.id
           LEFT JOIN (SELECT supplier_id, SUM(amount) AS t FROM supplier_payments WHERE tenant_id = ? GROUP BY supplier_id) x ON x.supplier_id = s.id
          WHERE s.tenant_id = ? GROUP BY s.company_id'
    );
    $st->execute([$t, $t, $t]);
    $pay = [];
    foreach ($st->fetchAll() as $r) {
        $pay[(int)$r['company_id']] = (float)$r['payable'];
    }

    $st = $pdo->prepare('SELECT company_id, SUM(amount) AS e FROM expenses WHERE tenant_id = ? AND expense_date >= ? GROUP BY company_id');
    $st->execute([$t, $mo]);
    $exp = [];
    foreach ($st->fetchAll() as $r) {
        $exp[(int)$r['company_id']] = (float)$r['e'];
    }

    $st = $pdo->prepare(
        'SELECT s.company_id, i.name, SUM(l.line_total) AS revenue, SUM(l.qty) AS qty
           FROM sale_lines l JOIN sales s ON s.id = l.sale_id JOIN items i ON i.id = l.item_id
          WHERE s.tenant_id = ? AND s.sold_at >= ?
          GROUP BY s.company_id, i.id, i.name ORDER BY revenue DESC LIMIT 500'
    );
    $st->execute([$t, $wk . ' 00:00:00']);
    $top = [];
    foreach ($st->fetchAll() as $r) {
        $cid = (int)$r['company_id'];
        if (count($top[$cid] ?? []) < 5) {
            $top[$cid][] = ['name' => $r['name'], 'revenue' => (float)$r['revenue'], 'qty' => (float)$r['qty']];
        }
    }

    $st = $pdo->prepare('SELECT id, name FROM companies WHERE tenant_id = ? ORDER BY name');
    $st->execute([$t]);

    $tot = ['today' => 0.0, 'today_profit' => 0.0, 'week' => 0.0, 'month' => 0.0, 'month_profit' => 0.0,
            'stock_value' => 0.0, 'payables' => 0.0, 'expenses_mtd' => 0.0, 'txn_today' => 0];
    $companies = [];
    foreach ($st->fetchAll() as $c) {
        $cid = (int)$c['id'];
        $s = $sales[$cid] ?? [];
        $k = $stock[$cid] ?? [];
        $row = [
            'id' => $cid, 'name' => $c['name'],
            'today' => ['sales' => (float)($s['t_sales'] ?? 0), 'txn' => (int)($s['t_txn'] ?? 0), 'profit' => (float)($s['t_profit'] ?? 0),
                        'cash' => (float)($s['t_cash'] ?? 0), 'card' => (float)($s['t_card'] ?? 0)],
            'week' => ['sales' => (float)($s['w_sales'] ?? 0), 'profit' => (float)($s['w_profit'] ?? 0)],
            'month' => ['sales' => (float)($s['m_sales'] ?? 0), 'profit' => (float)($s['m_profit'] ?? 0), 'vat' => (float)($s['m_vat'] ?? 0)],
            'stock_value' => round((float)($k['stock_value'] ?? 0), 2),
            'low_stock' => (int)($k['low_stock'] ?? 0),
            'negative_stock' => (int)($k['negative_stock'] ?? 0),
            'payables' => round($pay[$cid] ?? 0, 2),
            'expenses_mtd' => round($exp[$cid] ?? 0, 2),
            'trend' => array_map(function ($i) use ($trendBy, $cid) {
                $d = date('Y-m-d', strtotime("-$i days"));
                return ['day' => $d, 'sales' => round($trendBy[$cid][$d] ?? 0, 2)];
            }, range(6, 0)),
            'shops' => $shops[$cid] ?? [],
            'top_items' => $top[$cid] ?? [],
        ];
        $companies[] = $row;
        $tot['today'] += $row['today']['sales'];
        $tot['today_profit'] += $row['today']['profit'];
        $tot['txn_today'] += $row['today']['txn'];
        $tot['week'] += $row['week']['sales'];
        $tot['month'] += $row['month']['sales'];
        $tot['month_profit'] += $row['month']['profit'];
        $tot['stock_value'] += $row['stock_value'];
        $tot['payables'] += $row['payables'];
        $tot['expenses_mtd'] += $row['expenses_mtd'];
    }
    foreach ($tot as $k => $v) {
        $tot[$k] = is_int($v) ? $v : round($v, 2);
    }

    // Returns today / this month and how many cash-ups are done today.
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(IF(DATE(sold_at) = '$today', -total, 0)), 0) AS returns_today,
                COALESCE(SUM(IF(sold_at >= '$mo', -total, 0)), 0) AS returns_month,
                COALESCE(SUM(IF(DATE(sold_at) = '$today', 1, 0)), 0) AS returns_today_count
           FROM sales WHERE tenant_id = ? AND type = 'return'"
    );
    $st->execute([$t]);
    $ret = $st->fetch() ?: [];

    $st = $pdo->prepare("SELECT COUNT(*) FROM daily_closings WHERE tenant_id = ? AND close_date = '$today'");
    $st->execute([$t]);
    $closingsToday = (int)$st->fetchColumn();

    $st = $pdo->prepare(
        "SELECT n.id, n.level, n.title, n.detail, n.qty, n.status, n.created_at, sh.name AS shop
           FROM notifications n LEFT JOIN shops sh ON sh.id = n.shop_id
          WHERE n.tenant_id = ? AND n.status IN ('new','read') ORDER BY n.id DESC LIMIT 12"
    );
    $st->execute([$t]);
    $alerts = $st->fetchAll();
    $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE tenant_id = ? AND status = 'new'");
    $st->execute([$t]);
    $newAlerts = (int)$st->fetchColumn();

    $tot['returns_today'] = round((float)($ret['returns_today'] ?? 0), 2);
    $tot['returns_month'] = round((float)($ret['returns_month'] ?? 0), 2);
    $tot['returns_today_count'] = (int)($ret['returns_today_count'] ?? 0);
    $tot['closings_today'] = $closingsToday;
    $tot['new_alerts'] = $newAlerts;

    json_out([
        'generated_at' => date('Y-m-d H:i:s'), 'today' => $today, 'totals' => $tot,
        'companies' => $companies, 'alerts' => $alerts, 'low_stock_threshold' => $threshold,
    ]);
}
