<?php
declare(strict_types=1);
require __DIR__ . '/../admin/vehicle-create-batch.php';
$checks = 0;
function checkBatch(bool $condition): void { global $checks; if (!$condition) throw new RuntimeException('Batch test failed'); $checks++; }
$post = [
    'colors' => [2 => ['name' => 'White', 'hex_code' => '#FFFFFF', 'sort_order' => '5', 'is_active' => '0'], 8 => ['name' => 'Black']],
    'trims' => [3 => ['name' => 'Base', 'price' => '10000'], 9 => ['name' => 'Premium', 'price' => '20000']],
    'prices' => [
        0 => ['trim_key' => '9', 'product_type' => 'RENT', 'contract_months' => '48', 'prepayment_rate' => '0', 'annual_mileage' => '20000', 'monthly_payment' => '500000'],
        4 => ['trim_key' => '3', 'product_type' => 'LEASE', 'contract_months' => '36', 'prepayment_rate' => '10', 'annual_mileage' => '10000', 'monthly_payment' => '300000'],
    ],
];
$groups = validateVehicleCreateRows($post);
checkBatch(count($groups['colors']) === 2 && count($groups['trims']) === 2 && count($groups['prices']) === 2);
checkBatch(validateVehicleCreateRows([]) === ['colors' => [], 'trims' => [], 'prices' => []]);
foreach (['missing_trim', 'duplicate_price', 'negative_price', 'empty_name', 'bad_hex', 'bad_product', 'truncated'] as $case) {
    $bad = $post;
    switch ($case) {
        case 'missing_trim': unset($bad['trims'][9]); break;
        case 'duplicate_price': $bad['prices'][5] = $bad['prices'][0]; break;
        case 'negative_price': $bad['prices'][0]['monthly_payment'] = -1; break;
        case 'empty_name': $bad['colors'][2]['name'] = ''; break;
        case 'bad_hex': $bad['colors'][2]['hex_code'] = 'white'; break;
        case 'bad_product': $bad['prices'][0]['product_type'] = 'INVALID'; break;
        case 'truncated': $bad['batch_create'] = '1'; break;
    }
    $rejected = false;
    try { validateVehicleCreateRows($bad); } catch (RuntimeException $e) { $rejected = true; }
    checkBatch($rejected);
}
class BatchDb extends PDO {
    public array $writes = [];
    public int $lastId = 100;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new BatchStmt($this, $query); }
    public function lastInsertId(?string $name = null): string|false { return (string)$this->lastId; }
}
class BatchStmt extends PDOStatement {
    public function __construct(private BatchDb $db, private string $sql) {}
    public function execute(?array $params = null): bool { $this->db->writes[] = [$this->sql, $params]; $this->db->lastId++; return true; }
}
$db = new BatchDb();
insertVehicleCreateRows($db, 50, $groups, static fn($key) => 'images/' . $key . '.png');
checkBatch(count($db->writes) === 6);
checkBatch($db->writes[0][1][4] === 'images/2.png');
checkBatch($db->writes[0][1][5] === 5 && $db->writes[0][1][6] === 0);
checkBatch($db->writes[4][1][1] === 104 && $db->writes[5][1][1] === 103);
foreach ($db->writes as [$sql, $params]) checkBatch($params[0] === 50);
$selectedDb = new BatchDb();
insertVehicleCreateRows($selectedDb, 50, $groups, static fn($key) => 'images/' . $key . '.png', '8');
checkBatch($selectedDb->writes[2][1] === ['images/8.png', 50]);
$rejected = false;
try { insertVehicleCreateRows(new BatchDb(), 50, $groups, static fn($key) => null, '8'); } catch (RuntimeException $e) { $rejected = true; }
checkBatch($rejected);
echo "Passed $checks checks.\n";
