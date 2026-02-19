<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ui.php';

requireAuth();

const BUILDINGS_FILE = __DIR__ . '/data/buildings.json';

function readJson(string $f, array $d): array { if (!file_exists($f)) return $d; $r=file_get_contents($f); $j=is_string($r)?json_decode($r,true):null; return is_array($j)?$j:$d; }
function flattenApartments(array $buildings): array { $rows=[]; foreach($buildings as $b){ foreach(($b['apartments']??[]) as $a){$rows[]=['building_id'=>(string)$b['id'],'building_name'=>(string)$b['name'],'id'=>(string)$a['id'],'name'=>(string)$a['name']];}} return $rows; }

$buildings = readJson(BUILDINGS_FILE, []);
$apartments = flattenApartments($buildings);
$from = (string) ($_GET['from'] ?? date('Y-m-01'));
$to = (string) ($_GET['to'] ?? date('Y-m-d'));
$scopeType = (string) ($_GET['scope_type'] ?? 'overall');
$scopeValue = (string) ($_GET['scope_value'] ?? '');

$rows = [];
$series = [];
$granularity = 'day';
$pdo = getDbPdo();
if ($pdo && $from !== '' && $to !== '') {
    $diff = (strtotime($to) ?: time()) - (strtotime($from) ?: time());
    $days = max(1, (int) floor($diff / 86400));
    $granularity = $days > 120 ? 'month' : ($days > 35 ? 'week' : 'day');

    $dateExpr = $granularity === 'month'
        ? "DATE_FORMAT(start_date, '%Y-%m')"
        : ($granularity === 'week' ? "DATE_FORMAT(start_date, '%x-W%v')" : "DATE_FORMAT(start_date, '%Y-%m-%d')");

    $sql = "SELECT {$dateExpr} as bucket, COUNT(*) as c FROM reservations WHERE archived_flag=0 AND start_date BETWEEN :from AND :to";
    $params = [':from' => $from, ':to' => $to];

    if ($scopeType === 'building' && $scopeValue !== '') {
        $aptIds = array_map(static fn(array $a): string => $a['id'], array_filter($apartments, static fn(array $a): bool => $a['building_id'] === $scopeValue));
        if ($aptIds) {
            $tokens = [];
            foreach (array_values($aptIds) as $i => $aptId) {
                $key = ':apt' . $i;
                $tokens[] = $key;
                $params[$key] = $aptId;
            }
            $sql .= ' AND apartment_id IN (' . implode(',', $tokens) . ')';
        } else {
            $sql .= ' AND 1=0';
        }
    }

    if ($scopeType === 'apartment' && $scopeValue !== '') {
        $sql .= " AND apartment_id = :apt";
        $params[':apt'] = $scopeValue;
    }

    $sql .= ' GROUP BY bucket ORDER BY bucket ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as $r) {
        $series[] = ['label' => (string) $r['bucket'], 'value' => (int) $r['c']];
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reports</title><link rel="stylesheet" href="assets/styles.css"></head>
<body>
<?php renderSiteHeader('Reports'); ?>
<main class="container page-with-header">
<section class="panel">
<h2>Reservation reports</h2>
<form class="feed-row reservation-form-grid" method="get">
<label>From <input type="date" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES, 'UTF-8') ?>"></label>
<label>To <input type="date" name="to" value="<?= htmlspecialchars($to, ENT_QUOTES, 'UTF-8') ?>"></label>
<select name="scope_type" id="scope_type">
<option value="overall" <?= $scopeType==='overall'?'selected':'' ?>>Overall</option>
<option value="building" <?= $scopeType==='building'?'selected':'' ?>>Building</option>
<option value="apartment" <?= $scopeType==='apartment'?'selected':'' ?>>Apartment</option>
</select>
<select name="scope_value" id="scope_value">
<option value="">All</option>
<?php foreach ($buildings as $b): ?><option value="<?= htmlspecialchars((string)$b['id'], ENT_QUOTES, 'UTF-8') ?>" <?= $scopeValue===(string)$b['id']?'selected':'' ?>>Building: <?= htmlspecialchars((string)$b['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
<?php foreach ($apartments as $a): ?><option value="<?= htmlspecialchars((string)$a['id'], ENT_QUOTES, 'UTF-8') ?>" <?= $scopeValue===(string)$a['id']?'selected':'' ?>>Apartment: <?= htmlspecialchars((string)($a['building_name'].' / '.$a['name']), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
</select>
<button class="btn accent" type="submit">Generate report</button>
</form>
<p class="tiny">Granularity: <?= htmlspecialchars($granularity, ENT_QUOTES, 'UTF-8') ?></p>
<div id="report-chart" class="feed-building"></div>
</section>
</main>
<script>
const data = <?= json_encode($series, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?: '[]' ?>;
const chart = document.getElementById('report-chart');
if (chart) {
  if (!Array.isArray(data) || data.length === 0) {
    chart.innerHTML = '<p class="tiny">No data for selected range/scope.</p>';
  } else {
    const max = Math.max(...data.map(d => Number(d.value || 0)), 1);
    chart.innerHTML = data.map(d => `<div class="feed-row"><strong>${d.label}</strong><div style="height:16px;background:#e8efe8;border-radius:8px;overflow:hidden"><div style="height:16px;width:${Math.max(4, (Number(d.value||0)/max)*100)}%;background:linear-gradient(90deg,#4b8f5f,#79bd8c)"></div></div><span>${d.value} reservation(s)</span></div>`).join('');
  }
}
</script>
</body></html>
