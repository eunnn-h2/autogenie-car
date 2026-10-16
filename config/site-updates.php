<?php
declare(strict_types=1);

/** Additive, versioned schema upgrade. Existing records are never re-dated. */
function agSiteUpdates(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;
    try {
        $existing=$pdo->prepare('SELECT version_key FROM ag_schema_versions WHERE version_key=?');$existing->execute(['20261015_member_session_v1']);
        if($existing->fetchColumn()){$ready=true;return;}
    } catch(PDOException $e) { if(($e->errorInfo[1]??0)!==1146)throw $e; }
    $pdo->exec("CREATE TABLE IF NOT EXISTS ag_schema_versions (version_key VARCHAR(64) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q = $pdo->prepare('SELECT version_key FROM ag_schema_versions WHERE version_key = ?');
    $q->execute(['20261015_member_session_v1']);
    if ($q->fetchColumn()) { $ready = true; return; }
    foreach (['estimate_direct','estimate_quick'] as $table) {
        $columns = array_column($pdo->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        foreach (['contracted_at'=>'DATETIME NULL', 'next_contact_at'=>'DATETIME NULL'] as $column=>$type) {
            if (!in_array($column,$columns,true)) {
                try { $pdo->exec("ALTER TABLE $table ADD COLUMN $column $type"); }
                catch (PDOException $e) { if (($e->errorInfo[1] ?? 0) !== 1060) throw $e; }
            }
        }
    }
    $columns = array_column($pdo->query('SHOW COLUMNS FROM car_prices')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    foreach (['checked_at'=>'DATE NULL','valid_until'=>'DATE NULL'] as $column=>$type) {
        if (!in_array($column,$columns,true)) {
            try { $pdo->exec("ALTER TABLE car_prices ADD COLUMN $column $type"); }
            catch (PDOException $e) { if (($e->errorInfo[1] ?? 0) !== 1060) throw $e; }
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_estimate_history (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, estimate_source VARCHAR(8) NOT NULL,
        estimate_id BIGINT UNSIGNED NOT NULL, author_id INT NULL, author_name VARCHAR(100) NOT NULL,
        changes_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_estimate_history (estimate_source, estimate_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS member_vehicle_favorites (
        member_id BIGINT UNSIGNED NOT NULL, vehicle_id INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (member_id,vehicle_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS member_saved_quotes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, member_id BIGINT UNSIGNED NOT NULL,
        quote_key CHAR(64) NOT NULL, selection_json TEXT NOT NULL, labels_json TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_saved_member_selection (member_id,quote_key), KEY idx_saved_member (member_id,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ag_login_limits (
        limit_key CHAR(64) PRIMARY KEY, attempts INT NOT NULL DEFAULT 0,
        window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_login_limits_window (window_started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $columns=array_column($pdo->query('SHOW COLUMNS FROM admin_accounts')->fetchAll(PDO::FETCH_ASSOC),'Field');
    foreach(['business_phone'=>'VARCHAR(20) NULL','kakao_chat_url'=>'VARCHAR(255) NULL'] as $column=>$type){
        if(!in_array($column,$columns,true)){try{$pdo->exec("ALTER TABLE admin_accounts ADD COLUMN $column $type");}catch(PDOException $e){if(($e->errorInfo[1]??0)!==1060)throw $e;}}
    }
    foreach(['estimate_direct','estimate_quick'] as $table){
        $columns=array_column($pdo->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_ASSOC),'Field');
        if(!in_array('consultation_revision',$columns,true)){try{$pdo->exec("ALTER TABLE $table ADD COLUMN consultation_revision INT UNSIGNED NOT NULL DEFAULT 0");}catch(PDOException $e){if(($e->errorInfo[1]??0)!==1060)throw $e;}}
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS ag_estimate_messages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, estimate_source VARCHAR(8) NOT NULL,
        estimate_id BIGINT UNSIGNED NOT NULL,event_kind VARCHAR(16) NOT NULL,event_key VARCHAR(64) NOT NULL,
        assignee_id INT NULL,status VARCHAR(24) NOT NULL DEFAULT 'QUEUED', attempts INT NOT NULL DEFAULT 0,
        request_id VARCHAR(160) NULL,message_id VARCHAR(160) NULL,detail VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_estimate_message (estimate_source,estimate_id,event_key), KEY idx_message_status (status,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Kakao's internal placeholder is 30 characters, longer than older 20-character phone columns.
    $phoneColumn=$pdo->query("SHOW COLUMNS FROM member_accounts LIKE 'phone'")->fetch(PDO::FETCH_ASSOC);
    if($phoneColumn && preg_match('/^varchar\((\d+)\)$/i',(string)$phoneColumn['Type'],$phoneSize) && (int)$phoneSize[1]<40){
        $pdo->exec('ALTER TABLE member_accounts MODIFY COLUMN phone VARCHAR(40) NOT NULL');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS ag_member_sessions (
        session_key CHAR(64) NOT NULL PRIMARY KEY,payload MEDIUMBLOB NOT NULL,
        expires_at DATETIME NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_member_session_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->prepare('INSERT IGNORE INTO ag_schema_versions (version_key) VALUES (?)')->execute(['20261015_member_session_v1']);
    $ready = true;
}

function agAcquisitionValues(array $data): array {
    $out=[];
    foreach (['utm_source'=>100,'utm_medium'=>100,'utm_campaign'=>150,'utm_content'=>150,'utm_term'=>150,'referrer'=>500,'landing_page'=>500] as $key=>$length) {
        $out[$key] = mb_substr(trim(is_string($data[$key] ?? null) ? $data[$key] : ''),0,$length,'UTF-8') ?: null;
    }
    return $out;
}

function agLoginAllowed(PDO $pdo, string $scope, string $login): bool {
    $keys = [hash('sha256',$scope.':ip:'.($_SERVER['REMOTE_ADDR'] ?? 'unknown'))=>50,
             hash('sha256',$scope.':account:'.mb_strtolower($login,'UTF-8'))=>10];
    $pdo->beginTransaction();
    try {
        foreach ($keys as $key=>$maximum) {
            $pdo->prepare('INSERT IGNORE INTO ag_login_limits (limit_key) VALUES (?)')->execute([$key]);
            $q=$pdo->prepare('SELECT attempts, TIMESTAMPDIFF(SECOND,window_started_at,NOW()) age FROM ag_login_limits WHERE limit_key=? FOR UPDATE');$q->execute([$key]);$row=$q->fetch();
            $count=(int)$row['age']>=900 ? 0 : (int)$row['attempts'];
            if ($count >= $maximum) { $pdo->rollBack(); return false; }
            if ((int)$row['age']>=900) $pdo->prepare('UPDATE ag_login_limits SET attempts=1, window_started_at=NOW() WHERE limit_key=?')->execute([$key]);
            else $pdo->prepare('UPDATE ag_login_limits SET attempts=attempts+1 WHERE limit_key=?')->execute([$key]);
        }
        $pdo->commit(); return true;
    } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}
function agLoginSucceeded(PDO $pdo,string $scope,string $login): void {
    // Preserve the IP limit; only reset successful account attempts.
    $pdo->prepare('DELETE FROM ag_login_limits WHERE limit_key=?')->execute([hash('sha256',$scope.':account:'.mb_strtolower($login,'UTF-8'))]);
}

function agEstimateHistory(PDO $pdo, string $table, int $id, array $before, array $after, ?int $actorId=null, ?string $actorName=null): void {
    $fields=['status','assigned_admin_id','customer_name','customer_phone','vehicle_name','trim_name','color_name','product_type','contract_months','prepayment_rate','annual_mileage','monthly_payment','contracted_at','next_contact_at','contact_tag'];
    $changes=[];
    foreach($fields as $field) if(array_key_exists($field,$after) && (string)($before[$field] ?? '') !== (string)($after[$field] ?? '')) $changes[$field]=['before'=>$before[$field] ?? null,'after'=>$after[$field]];
    if(!$changes)return;
    $pdo->prepare('INSERT INTO admin_estimate_history (estimate_source,estimate_id,author_id,author_name,changes_json) VALUES (?,?,?,?,?)')->execute([
        $table==='estimate_quick'?'QUICK':'DIRECT',$id,$actorId ?? (int)($_SESSION['admin_id'] ?? 0),
        $actorName ?? (string)($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? '관리자'),
        json_encode($changes,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
function agDateTimeValue($value,string $label): ?string {
    if(!is_string($value))throw new InvalidArgumentException($label.'을 확인해 주세요.');
    if(trim($value)==='')return null;
    $value=str_replace('T',' ',trim($value));
    $format=strlen($value)===16?'Y-m-d H:i':'Y-m-d H:i:s';
    $date=DateTimeImmutable::createFromFormat('!'.$format,$value);
    if(!$date || $date->format($format)!==$value)throw new InvalidArgumentException($label.'을 올바르게 입력해 주세요.');
    return $date->format('Y-m-d H:i:s');
}

function agDeleteEstimate(PDO $pdo,string $table,int $id):void{
    if(!in_array($table,['estimate_direct','estimate_quick'],true)||$id<1)throw new InvalidArgumentException('잘못된 견적 정보입니다.');
    $source=$table==='estimate_quick'?'QUICK':'DIRECT';
    foreach(['admin_estimate_history','admin_estimate_notes','admin_estimate_contact_tags'] as $meta){
        $q=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$meta]);
        if($q->fetchColumn())$pdo->prepare("DELETE FROM $meta WHERE estimate_source=? AND estimate_id=?")->execute([$source,$id]);
    }
    $pdo->prepare('DELETE FROM ag_estimate_messages WHERE estimate_source=? AND estimate_id=?')->execute([$source,$id]);
    $pdo->prepare("DELETE FROM $table WHERE id=?")->execute([$id]);
}
