<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/member-provider.php';

function verify(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

// Emulate table discovery while exercising the member lookup against real SQL.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'main');
$pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
$pdo->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT)');
$pdo->exec('CREATE TABLE member_accounts (id INTEGER PRIMARY KEY)');
$pdo->exec('INSERT INTO member_accounts VALUES (1), (2)');
$records = [['member_id' => 1], ['member_id' => 2], ['member_id' => 2], ['member_id' => null], ['member_id' => 99]];
$map = adminMemberProviders($pdo, $records);
verify($map === [1 => 'site', 2 => 'site'], 'Legacy schema without Kakao table failed');
$pdo->exec('CREATE TABLE member_kakao_accounts (member_id INTEGER)');
$pdo->exec('INSERT INTO member_kakao_accounts VALUES (2)');
$pdo->exec("INSERT INTO information_schema.TABLES VALUES ('main', 'member_kakao_accounts')");
$map = adminMemberProviders($pdo, $records);
verify($map === [1 => 'site', 2 => 'kakao'], 'Linked membership was incorrectly classified');
foreach ([[1, '사이트'], [2, '카카오'], [null, '비회원'], [99, '가입경로 미확인']] as [$id, $label]) {
    verify(str_contains(adminMemberProviderBadge(['member_id' => $id], $map), '>' . $label . '</span>'), 'Incorrect badge: ' . $label);
}
verify(adminMemberProviders($pdo, [['member_id' => null]]) === [], 'Guest should not require member lookup');
verify(str_contains(adminMemberProviderBadge(['member_id' => 1], []), '가입경로 미확인'), 'Missing lookup must not be labelled as site signup');
echo "Member provider: Kakao, site, guest, missing member and legacy schema checks passed.\n";
