<?php
// Authentication and authorization MUST run before any database writes or DDL.
require_once __DIR__ . '/auth.php';
require_roles(['Admin', 'HR', 'Manager']);
require_once __DIR__ . '/db.php';

function pe($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function perfDate(string $v): bool { $d = DateTime::createFromFormat('Y-m-d', $v); return $d && $d->format('Y-m-d') === $v; }

// Additive setup: creates only the two new performance tables; existing HRMS tables/data are untouched.
$conn->query("CREATE TABLE IF NOT EXISTS performance_indicators (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 indicator_name VARCHAR(150) NOT NULL,
 description VARCHAR(500) NULL,
 scope ENUM('Employee','Department','Company') NOT NULL DEFAULT 'Employee',
 unit VARCHAR(40) NOT NULL DEFAULT '%',
 target_value DECIMAL(12,2) NOT NULL DEFAULT 100,
 review_period ENUM('Weekly','Monthly','Quarterly','Yearly') NOT NULL DEFAULT 'Monthly',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_indicator_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$conn->query("CREATE TABLE IF NOT EXISTS performance_results (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 indicator_id INT UNSIGNED NOT NULL,
 employee_id INT NULL,
 department VARCHAR(100) NULL,
 period_start DATE NOT NULL,
 period_end DATE NOT NULL,
 actual_value DECIMAL(12,2) NOT NULL DEFAULT 0,
 notes VARCHAR(500) NULL,
 recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_perf_result_indicator FOREIGN KEY (indicator_id) REFERENCES performance_indicators(id) ON DELETE RESTRICT,
 KEY idx_perf_period (period_start, period_end), KEY idx_perf_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if (empty($_SESSION['perf_csrf'])) $_SESSION['perf_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['perf_csrf'];
$message = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedToken = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals($csrf, $postedToken)) {
    $error = 'Security token expired. Refresh the page and try again.';
  } else {
    $action = (string)($_POST['action'] ?? '');
    try {
      if ($action === 'add_indicator') {
        $name = trim((string)($_POST['indicator_name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $scope = (string)($_POST['scope'] ?? 'Employee');
        $unit = trim((string)($_POST['unit'] ?? '%'));
        $target = filter_var($_POST['target_value'] ?? null, FILTER_VALIDATE_FLOAT);
        $period = (string)($_POST['review_period'] ?? 'Monthly');
        if ($name === '' || strlen($name) > 150 || $unit === '' || strlen($unit) > 40 || $target === false || $target < 0) throw new RuntimeException('Enter a name, unit, and non-negative numeric target.');
        if (!in_array($scope, ['Employee','Department','Company'], true)) throw new RuntimeException('Select a valid scope.');
        if (!in_array($period, ['Weekly','Monthly','Quarterly','Yearly'], true)) throw new RuntimeException('Select a valid review period.');
        $stmt = $conn->prepare('INSERT INTO performance_indicators (indicator_name, description, scope, unit, target_value, review_period) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssssds', $name, $description, $scope, $unit, $target, $period); $stmt->execute(); $stmt->close();
        $message = 'Performance indicator created.';
      } elseif ($action === 'add_result') {
        $indicatorId = filter_var($_POST['indicator_id'] ?? null, FILTER_VALIDATE_INT);
        $employeeRaw = trim((string)($_POST['employee_id'] ?? ''));
        $employeeId = $employeeRaw === '' ? null : filter_var($employeeRaw, FILTER_VALIDATE_INT);
        $department = trim((string)($_POST['department'] ?? ''));
        $start = (string)($_POST['period_start'] ?? ''); $end = (string)($_POST['period_end'] ?? '');
        $actual = filter_var($_POST['actual_value'] ?? null, FILTER_VALIDATE_FLOAT);
        $notes = trim((string)($_POST['notes'] ?? ''));
        if (!$indicatorId || $indicatorId < 1 || ($employeeRaw !== '' && (!$employeeId || $employeeId < 1)) || !perfDate($start) || !perfDate($end) || $end < $start || $actual === false || $actual < 0) throw new RuntimeException('Check the indicator, dates, employee ID, and non-negative actual value.');
        if (strlen($department) > 100 || strlen($notes) > 500) throw new RuntimeException('Department or notes are too long.');
        $check = $conn->prepare('SELECT id FROM performance_indicators WHERE id = ? AND is_active = 1'); $check->bind_param('i', $indicatorId); $check->execute(); $exists = $check->get_result()->fetch_assoc(); $check->close();
        if (!$exists) throw new RuntimeException('Choose an active indicator first.');
        if ($employeeId !== null) { $ec = $conn->prepare('SELECT id FROM employees WHERE id = ?'); $ec->bind_param('i', $employeeId); $ec->execute(); $employeeExists = $ec->get_result()->fetch_assoc(); $ec->close(); if (!$employeeExists) throw new RuntimeException('Employee ID was not found in the employees table.'); }
        $stmt = $conn->prepare('INSERT INTO performance_results (indicator_id, employee_id, department, period_start, period_end, actual_value, notes) VALUES (?, ?, NULLIF(?, \'\'), ?, ?, ?, ?)');
        $employeeForDb = $employeeId === null ? '' : (string)$employeeId;
        $stmt->bind_param('iisssds', $indicatorId, $employeeForDb, $department, $start, $end, $actual, $notes);
        // NULLIF handles empty department; employee ID is stored as NULL when not provided below.
        $stmt->execute(); $stmt->close();
        if ($employeeId === null) { $conn->query('UPDATE performance_results SET employee_id = NULL WHERE id = '.(int)$conn->insert_id); }
        $message = 'Performance result saved.';
      } else { throw new RuntimeException('Unknown action.'); }
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save the record. Check the fields and database configuration.'; error_log('HRMS performance: '.$e->getMessage()); }
  }
}

$indicators = [];
$r = $conn->query('SELECT id, indicator_name, scope, unit, target_value, review_period, is_active FROM performance_indicators ORDER BY is_active DESC, indicator_name');
while ($row = $r->fetch_assoc()) $indicators[] = $row;
$employees = [];
$r = $conn->query('SELECT id, employee_code, full_name, department FROM employees ORDER BY full_name');
while ($row = $r->fetch_assoc()) $employees[] = $row;
$totalIndicators = count($indicators); $resultCount = 0; $onTrack = 0;
$r = $conn->query("SELECT COUNT(*) total, SUM(CASE WHEN (CASE WHEN i.target_value=0 THEN 0 ELSE (pr.actual_value/i.target_value)*100 END)>=100 THEN 1 ELSE 0 END) on_track FROM performance_results pr JOIN performance_indicators i ON i.id=pr.indicator_id");
$summary = $r->fetch_assoc(); $resultCount = (int)($summary['total'] ?? 0); $onTrack = (int)($summary['on_track'] ?? 0);
$results = [];
$r = $conn->query('SELECT pr.id, i.indicator_name, i.unit, i.target_value, pr.actual_value, pr.period_start, pr.period_end, pr.employee_id, pr.department, pr.notes, e.full_name, e.employee_code FROM performance_results pr JOIN performance_indicators i ON i.id=pr.indicator_id LEFT JOIN employees e ON e.id=pr.employee_id ORDER BY pr.period_end DESC, pr.id DESC LIMIT 100');
while ($row = $r->fetch_assoc()) $results[] = $row;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Management Indicators | HRMS</title><link rel="stylesheet" href="assets.css"><style>.perf-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:20px}.perf-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.perf-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px;box-shadow:var(--shadow)}.perf-card label{display:block;font-size:13px;font-weight:700;margin:0 0 6px}.perf-card input,.perf-card select,.perf-card textarea{width:100%;margin-bottom:12px}.perf-card button{background:var(--brand);color:white;border:0;padding:11px 15px;cursor:pointer}.progress{height:8px;background:#e8eaf2;border-radius:999px;overflow:hidden;min-width:90px}.progress span{display:block;height:100%;background:#4f46e5}.metric{font-size:30px;font-weight:800;letter-spacing:-.04em;color:var(--brand)}@media(max-width:800px){.perf-grid{grid-template-columns:1fr}.perf-form-grid{grid-template-columns:1fr}}</style></head><body>
<header><h1>PeopleOS <span style="font-size:12px;font-weight:600;background:#ffffff20;padding:5px 8px;border-radius:7px">HRMS</span></h1><p>Management indicators and performance setup</p></header><?php include __DIR__.'/nav.php'; ?>
<main><div class="page-head"><div><h2>Management indicators</h2><p>Define targets, record results, and track progress against each KPI.</p></div><a class="btn" href="#setup">＋ Set up indicator</a></div>
<?php if ($message): ?><div class="alert"><?=pe($message)?></div><?php endif; ?><?php if ($error): ?><div class="alert error"><?=pe($error)?></div><?php endif; ?>
<section class="perf-grid"><div class="perf-card"><div class="muted">Indicators configured</div><div class="metric"><?=$totalIndicators?></div><small class="muted">Active and inactive KPIs</small></div><div class="perf-card"><div class="muted">Results recorded</div><div class="metric"><?=$resultCount?></div><small class="muted">Most recent 100 displayed below</small></div><div class="perf-card"><div class="muted">Results meeting target</div><div class="metric"><?=$onTrack?></div><small class="muted">Recorded results at or above target</small></div></section>
<section class="grid" style="margin-bottom:20px"><div class="col-6 perf-card" id="setup"><h3>1. Set up an indicator</h3><p class="muted">Example: attendance rate, task completion, or customer response time.</p><form method="post"><input type="hidden" name="csrf_token" value="<?=pe($csrf)?>"><input type="hidden" name="action" value="add_indicator"><label>Indicator name</label><input name="indicator_name" maxlength="150" required placeholder="e.g. Task completion rate"><label>Description (optional)</label><textarea name="description" maxlength="500" rows="2" placeholder="What does this KPI measure?"></textarea><div class="perf-form-grid"><div><label>Applies to</label><select name="scope"><option>Employee</option><option>Department</option><option>Company</option></select></div><div><label>Unit</label><input name="unit" maxlength="40" required value="%" placeholder="%, tasks, hours"></div><div><label>Target value</label><input name="target_value" type="number" min="0" step="0.01" value="90" required></div><div><label>Review period</label><select name="review_period"><option>Weekly</option><option selected>Monthly</option><option>Quarterly</option><option>Yearly</option></select></div></div><button type="submit">Save indicator</button></form></div>
<div class="col-6 perf-card"><h3>2. Record performance result</h3><p class="muted">Enter the actual result for an indicator and period.</p><?php if (!$indicators): ?><p>Create an indicator first, then this form will be ready.</p><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?=pe($csrf)?>"><input type="hidden" name="action" value="add_result"><label>Indicator</label><select name="indicator_id" required><?php foreach($indicators as $i): if (!(int)$i['is_active']) continue; ?><option value="<?=$i['id']?>"><?=pe($i['indicator_name'])?> — target <?=pe($i['target_value'].' '.$i['unit'])?></option><?php endforeach; ?></select><div class="perf-form-grid"><div><label>Employee (optional)</label><select name="employee_id"><option value="">Not employee-specific</option><?php foreach($employees as $emp): ?><option value="<?=$emp['id']?>"><?=pe($emp['employee_code'].' · '.$emp['full_name'])?></option><?php endforeach; ?></select></div><div><label>Department (optional)</label><input name="department" maxlength="100" placeholder="e.g. IT"></div><div><label>Period start</label><input type="date" name="period_start" required value="<?=date('Y-m-01')?>"></div><div><label>Period end</label><input type="date" name="period_end" required value="<?=date('Y-m-t')?>"></div></div><label>Actual value</label><input type="number" name="actual_value" min="0" step="0.01" required placeholder="e.g. 92"><label>Notes (optional)</label><textarea name="notes" maxlength="500" rows="2" placeholder="Context or manager comments"></textarea><button type="submit">Save result</button></form><?php endif; ?></div></section>
<section class="panel" style="margin-bottom:20px"><h3>Configured indicators</h3><div class="table-wrap"><table><thead><tr><th>Indicator</th><th>Scope</th><th>Target</th><th>Review</th><th>Status</th></tr></thead><tbody><?php foreach($indicators as $i): ?><tr><td><strong><?=pe($i['indicator_name'])?></strong></td><td><?=pe($i['scope'])?></td><td><?=pe($i['target_value'].' '.$i['unit'])?></td><td><?=pe($i['review_period'])?></td><td><span class="pill"><?=$i['is_active']?'Active':'Inactive'?></span></td></tr><?php endforeach; ?><?php if(!$indicators): ?><tr><td colspan="5">No indicators configured yet. Use the setup form above.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel"><h3>Recent performance results</h3><div class="table-wrap"><table><thead><tr><th>Indicator</th><th>Employee / Department</th><th>Period</th><th>Actual</th><th>Target</th><th>Achievement</th><th>Status</th></tr></thead><tbody><?php foreach($results as $rr): $pct=((float)$rr['target_value']>0)?((float)$rr['actual_value']/(float)$rr['target_value'])*100:0; $bar=max(0,min(100,$pct)); ?><tr><td><strong><?=pe($rr['indicator_name'])?></strong><?php if($rr['notes']): ?><br><small class="muted"><?=pe($rr['notes'])?></small><?php endif; ?></td><td><?=pe($rr['full_name'] ?: ($rr['department'] ?: 'Company-wide'))?><?php if($rr['employee_code']): ?><br><small class="muted"><?=pe($rr['employee_code'])?></small><?php endif; ?></td><td><?=pe($rr['period_start'])?> – <?=pe($rr['period_end'])?></td><td><?=pe($rr['actual_value'].' '.$rr['unit'])?></td><td><?=pe($rr['target_value'].' '.$rr['unit'])?></td><td><div><?=number_format($pct,1)?>%</div><div class="progress"><span style="width:<?=$bar?>%"></span></div></td><td><span class="pill"><?=$pct>=100?'On target':'Below target'?></span></td></tr><?php endforeach; ?><?php if(!$results): ?><tr><td colspan="7">No results recorded yet.</td></tr><?php endif; ?></tbody></table></div><p class="muted" style="margin-top:14px;font-size:12px">Achievement = actual value ÷ target × 100. Use comparable units and targets. This starter module does not automatically grade employees or change salary.</p></section><footer style="text-align:center;color:var(--muted);padding:25px">PeopleOS HRMS · Performance module</footer></main></body></html>
