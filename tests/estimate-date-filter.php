<?php
declare(strict_types=1);
require __DIR__ . '/../admin/estimate-date-filter.php';
function verify(bool $valid): void { if (!$valid) throw new RuntimeException('Date filter check failed'); }
verify(estimateDateRange([]) === ['from'=>'', 'to'=>'']);
foreach ([['from'=>'2024-02-29'], ['to'=>'2026-09-15'], ['from'=>'2026-09-15','to'=>'2026-09-15']] as $query) {
    $range = estimateDateRange($query);
    foreach (['created_at','e.created_at','q.created_at'] as $column) {
        $where = ['status = ?']; $params = ['NEW'];
        applyEstimateDateRange($where, $params, $range, $column);
        verify($params[0] === 'NEW');
        if ($range['from']) verify(in_array($range['from'].' 00:00:00', $params, true));
        if ($range['to']) {
            verify(end($where) === $column.' < DATE_ADD(?, INTERVAL 1 DAY)');
            verify(end($params) === $range['to']);
        }
    }
}
foreach ([['from'=>'2025-02-29'], ['to'=>'2026-04-31'], ['from'=>[]], ['from'=>'2026-09-16','to'=>'2026-09-15'], ['from'=>"2026-09-15\0"], ['from'=>'0000-01-01']] as $query) {
    try { estimateDateRange($query); throw new RuntimeException('Invalid date accepted'); }
    catch (InvalidArgumentException $e) {}
}
$where = []; $params = [];
applyEstimateDateRange($where, $params, estimateDateRange([]));
verify($where === [] && $params === []);
echo "Date filter checks passed.\n";
