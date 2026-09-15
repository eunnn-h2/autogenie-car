<?php
declare(strict_types=1);

function estimateDateRange(array $query): array {
    $dates = [];
    foreach (['from', 'to'] as $key) {
        $value = $query[$key] ?? '';
        if (!is_string($value)) throw new InvalidArgumentException('조회기간을 올바르게 입력해주세요.');
        $value = trim($value, " \t\r\n");
        if ($value !== '') {
            if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
                throw new InvalidArgumentException('조회기간을 올바른 날짜로 입력해주세요.');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value || substr($value, 0, 4) === '0000') {
                throw new InvalidArgumentException('조회기간을 올바른 날짜로 입력해주세요.');
            }
        }
        $dates[$key] = $value;
    }
    if ($dates['from'] !== '' && $dates['to'] !== '' && $dates['from'] > $dates['to']) {
        throw new InvalidArgumentException('시작일은 종료일보다 늦을 수 없습니다.');
    }
    return $dates;
}

function applyEstimateDateRange(array &$where, array &$params, array $dates, string $column = 'created_at'): void {
    if (!in_array($column, ['created_at', 'e.created_at', 'q.created_at'], true)) {
        throw new InvalidArgumentException('Invalid date column');
    }
    if ($dates['from'] !== '') {
        $where[] = $column . ' >= ?';
        $params[] = $dates['from'] . ' 00:00:00';
    }
    if ($dates['to'] !== '') {
        // Exclusive next-day bound includes the entire end date, including fractional seconds.
        $where[] = $column . ' < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $dates['to'];
    }
}
