<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ui.php';

requireAuth();

const BUILDINGS_FILE = __DIR__ . '/data/buildings.json';

function readJson(string $file, array $default): array
{
    if (!file_exists($file)) {
        return $default;
    }
    $raw = file_get_contents($file);
    $json = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($json) ? $json : $default;
}

function flattenApartments(array $buildings): array
{
    $rows = [];
    foreach ($buildings as $building) {
        foreach (($building['apartments'] ?? []) as $apartment) {
            $rows[] = [
                'building_id' => (string) $building['id'],
                'building_name' => (string) $building['name'],
                'id' => (string) $apartment['id'],
                'name' => (string) $apartment['name'],
            ];
        }
    }

    return $rows;
}

function applyScopeCondition(string $scopeType, string $scopeValue, array $apartments, string &$sql, array &$params): void
{
    if ($scopeType === 'building' && $scopeValue !== '') {
        $aptIds = array_map(
            static fn(array $a): string => $a['id'],
            array_filter($apartments, static fn(array $a): bool => $a['building_id'] === $scopeValue)
        );
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
        $sql .= ' AND apartment_id = :apt_scope';
        $params[':apt_scope'] = $scopeValue;
    }
}

$buildings = readJson(BUILDINGS_FILE, []);
$apartments = flattenApartments($buildings);
$from = (string) ($_GET['from'] ?? date('Y-m-01'));
$to = (string) ($_GET['to'] ?? date('Y-m-d'));
$scopeType = (string) ($_GET['scope_type'] ?? 'overall');
$scopeValue = (string) ($_GET['scope_value'] ?? '');

$series = [];
$topApartments = [];
$topBuildings = [];
$granularity = 'day';
$pdo = getDbPdo();

if ($pdo && $from !== '' && $to !== '') {
    $diff = (strtotime($to) ?: time()) - (strtotime($from) ?: time());
    $days = max(1, (int) floor($diff / 86400));
    $granularity = $days > 120 ? 'month' : ($days > 35 ? 'week' : 'day');

    $dateExpr = $granularity === 'month'
        ? "DATE_FORMAT(start_date, '%Y-%m')"
        : ($granularity === 'week' ? "DATE_FORMAT(start_date, '%x-W%v')" : "DATE_FORMAT(start_date, '%Y-%m-%d')");

    $sql = "SELECT {$dateExpr} AS bucket, COUNT(*) AS c
            FROM reservations
            WHERE archived_flag = 0 AND start_date BETWEEN :from AND :to";
    $params = [':from' => $from, ':to' => $to];
    applyScopeCondition($scopeType, $scopeValue, $apartments, $sql, $params);
    $sql .= ' GROUP BY bucket ORDER BY bucket ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    foreach ($rows as $row) {
        $series[] = ['label' => (string) $row['bucket'], 'value' => (int) $row['c']];
    }

    $aptNameById = [];
    $aptBuildingById = [];
    foreach ($apartments as $apt) {
        $aptNameById[$apt['id']] = $apt['name'];
        $aptBuildingById[$apt['id']] = $apt['building_name'];
    }

    $sqlTopApt = 'SELECT apartment_id, COUNT(*) AS c FROM reservations WHERE archived_flag = 0 AND start_date BETWEEN :from AND :to';
    $paramsTopApt = [':from' => $from, ':to' => $to];
    applyScopeCondition($scopeType, $scopeValue, $apartments, $sqlTopApt, $paramsTopApt);
    $sqlTopApt .= ' GROUP BY apartment_id ORDER BY c DESC LIMIT 8';
    $stmtTopApt = $pdo->prepare($sqlTopApt);
    $stmtTopApt->execute($paramsTopApt);
    foreach (($stmtTopApt->fetchAll() ?: []) as $row) {
        $aptId = (string) ($row['apartment_id'] ?? '');
        $topApartments[] = [
            'name' => ($aptBuildingById[$aptId] ?? 'Unknown building') . ' / ' . ($aptNameById[$aptId] ?? $aptId),
            'count' => (int) $row['c'],
        ];
    }

    $buildingCountMap = [];
    $sqlAllApt = 'SELECT apartment_id, COUNT(*) AS c FROM reservations WHERE archived_flag = 0 AND start_date BETWEEN :from AND :to';
    $paramsAllApt = [':from' => $from, ':to' => $to];
    applyScopeCondition($scopeType, $scopeValue, $apartments, $sqlAllApt, $paramsAllApt);
    $sqlAllApt .= ' GROUP BY apartment_id';
    $stmtAllApt = $pdo->prepare($sqlAllApt);
    $stmtAllApt->execute($paramsAllApt);
    foreach (($stmtAllApt->fetchAll() ?: []) as $row) {
        $aptId = (string) ($row['apartment_id'] ?? '');
        $buildingName = (string) ($aptBuildingById[$aptId] ?? 'Unknown building');
        $buildingCountMap[$buildingName] = ($buildingCountMap[$buildingName] ?? 0) + (int) $row['c'];
    }

    arsort($buildingCountMap);
    foreach (array_slice($buildingCountMap, 0, 8, true) as $name => $count) {
        $topBuildings[] = ['name' => (string) $name, 'count' => (int) $count];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<?php renderSiteHeader('Reports'); ?>
<main class="container page-with-header">
    <section class="panel">
        <h2>Reservation reports</h2>
        <form class="feed-row reservation-form-grid" method="get">
            <label>From <input type="date" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>To <input type="date" name="to" value="<?= htmlspecialchars($to, ENT_QUOTES, 'UTF-8') ?>"></label>
            <select name="scope_type" id="scope_type">
                <option value="overall" <?= $scopeType === 'overall' ? 'selected' : '' ?>>Overall</option>
                <option value="building" <?= $scopeType === 'building' ? 'selected' : '' ?>>Building</option>
                <option value="apartment" <?= $scopeType === 'apartment' ? 'selected' : '' ?>>Apartment</option>
            </select>
            <select name="scope_value" id="scope_value">
                <option value="">All</option>
                <?php foreach ($buildings as $building): ?>
                    <option value="<?= htmlspecialchars((string) $building['id'], ENT_QUOTES, 'UTF-8') ?>" data-scope="building" <?= $scopeValue === (string) $building['id'] ? 'selected' : '' ?>>Building: <?= htmlspecialchars((string) $building['name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
                <?php foreach ($apartments as $apt): ?>
                    <option value="<?= htmlspecialchars((string) $apt['id'], ENT_QUOTES, 'UTF-8') ?>" data-scope="apartment" <?= $scopeValue === (string) $apt['id'] ? 'selected' : '' ?>>Apartment: <?= htmlspecialchars((string) ($apt['building_name'] . ' / ' . $apt['name']), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn accent" type="submit">Generate report</button>
        </form>
        <p class="tiny">Granularity: <?= htmlspecialchars($granularity, ENT_QUOTES, 'UTF-8') ?></p>

        <div class="report-card">
            <h3>Reservations trend</h3>
            <div id="report-chart" class="report-chart"></div>
        </div>

        <div class="report-rank-grid">
            <div class="report-card">
                <h3>Top apartments (selected period)</h3>
                <div id="top-apartment-chart" class="report-rows"></div>
            </div>
            <div class="report-card">
                <h3>Top buildings (selected period)</h3>
                <div id="top-building-chart" class="report-rows"></div>
            </div>
        </div>
    </section>
</main>
<script>
const trendData = <?= json_encode($series, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '[]' ?>;
const topApartmentData = <?= json_encode($topApartments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '[]' ?>;
const topBuildingData = <?= json_encode($topBuildings, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '[]' ?>;

const scopeType = document.getElementById('scope_type');
const scopeValue = document.getElementById('scope_value');
if (scopeType && scopeValue) {
  const refreshScopeOptions = () => {
    const wanted = scopeType.value;
    const all = scopeValue.querySelectorAll('option[data-scope]');
    all.forEach((opt) => {
      const show = wanted === 'overall' || opt.dataset.scope === wanted;
      opt.hidden = !show;
      if (!show && opt.selected) scopeValue.value = '';
    });
  };
  scopeType.addEventListener('change', refreshScopeOptions);
  refreshScopeOptions();
}

function renderVerticalBars(targetId, data) {
  const el = document.getElementById(targetId);
  if (!el) return;
  if (!Array.isArray(data) || data.length === 0) {
    el.innerHTML = '<p class="tiny">No data for selected range/scope.</p>';
    return;
  }
  const max = Math.max(...data.map((d) => Number(d.value || 0)), 1);
  el.innerHTML = `
    <div class="vchart-grid">
      ${data.map((d, i) => {
        const h = Math.max(8, Math.round((Number(d.value || 0) / max) * 100));
        const cls = ['c1', 'c2', 'c3', 'c4'][i % 4];
        return `<div class="vbar-wrap"><div class="vbar ${cls}" style="height:${h}%"></div><div class="vbar-value">${d.value}</div><div class="vbar-label">${d.label}</div></div>`;
      }).join('')}
    </div>`;
}

function renderTopRows(targetId, data) {
  const el = document.getElementById(targetId);
  if (!el) return;
  if (!Array.isArray(data) || data.length === 0) {
    el.innerHTML = '<p class="tiny">No rankings for selected range/scope.</p>';
    return;
  }
  const max = Math.max(...data.map((d) => Number(d.count || 0)), 1);
  el.innerHTML = data.map((d, idx) => {
    const pct = Math.max(6, Math.round((Number(d.count || 0) / max) * 100));
    return `
      <div class="report-row-item">
        <div class="report-row-head"><strong>#${idx + 1} ${d.name}</strong><span>${d.count} reservations</span></div>
        <div class="report-row-bar"><div style="width:${pct}%"></div></div>
      </div>`;
  }).join('');
}

renderVerticalBars('report-chart', trendData);
renderTopRows('top-apartment-chart', topApartmentData);
renderTopRows('top-building-chart', topBuildingData);
</script>
</body>
</html>
