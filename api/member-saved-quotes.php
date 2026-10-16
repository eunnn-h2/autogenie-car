<?php
declare(strict_types=1);
require_once __DIR__.'/member-auth-common.php';
header('Cache-Control: no-store');
$memberId=(int)($_SESSION['member_id']??0);
if ($memberId<1) member_response(['ok'=>false,'message'=>'로그인이 필요합니다.'],401);
if (!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)) member_response(['ok'=>false,'message'=>'잘못된 요청입니다.'],405);
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $data=member_json_input();$action=$data['action']??'save';
        if ($action==='delete') {
            $q=$pdo->prepare('DELETE FROM member_saved_quotes WHERE id=? AND member_id=?');$q->execute([(int)($data['id']??0),$memberId]);
            if (!$q->rowCount()) member_response(['ok'=>false,'message'=>'저장한 견적을 찾을 수 없습니다.'],404);
        } elseif ($action==='save') {
            $values=[];
            foreach (['vehicle_id'=>[1,2147483647],'trim_id'=>[1,2147483647],'color_id'=>[0,2147483647],'contract_months'=>[1,120],'annual_mileage'=>[0,1000000]] as $field=>$range) {
                $value=filter_var($data[$field]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>$range[0],'max_range'=>$range[1]]]);
                if ($value===false) member_response(['ok'=>false,'message'=>'저장할 견적 조건을 확인해 주세요.'],422);
                $values[$field]=$value;
            }
            $product=$data['product_type']??'';$rate=$data['prepayment_rate']??null;
            if (!in_array($product,['RENT','LEASE'],true)||!is_numeric($rate)||(float)$rate<0||(float)$rate>100) member_response(['ok'=>false,'message'=>'이용상품과 선납률을 확인해 주세요.'],422);
            $values['product_type']=$product;$values['prepayment_rate']=round((float)$rate,2);
            $q=$pdo->prepare('SELECT v.name vehicle_name,b.name brand_name,t.name trim_name FROM car_vehicles v JOIN car_brands b ON b.id=v.brand_id JOIN car_trims t ON t.vehicle_id=v.id AND t.is_active=1 WHERE v.id=? AND v.is_active=1 AND t.id=?');$q->execute([$values['vehicle_id'],$values['trim_id']]);$labels=$q->fetch(PDO::FETCH_ASSOC);
            if (!$labels) member_response(['ok'=>false,'message'=>'현재 저장할 수 없는 차량 또는 트림입니다.'],422);
            $labels['color_name']='미선택';
            if ($values['color_id']>0) {
                $q=$pdo->prepare('SELECT name FROM car_colors WHERE id=? AND vehicle_id=? AND is_active=1');$q->execute([$values['color_id'],$values['vehicle_id']]);$name=$q->fetchColumn();
                if (!$name) member_response(['ok'=>false,'message'=>'선택한 색상을 확인해 주세요.'],422);
                $labels['color_name']=$name;
            }
            ksort($values);$key=hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
            $pdo->beginTransaction();
            $q=$pdo->prepare('SELECT id,status FROM member_accounts WHERE id=? FOR UPDATE');$q->execute([$memberId]);$owner=$q->fetch(PDO::FETCH_ASSOC);
            if (!$owner||$owner['status']!=='ACTIVE') {$pdo->rollBack();member_response(['ok'=>false,'message'=>'로그인이 필요합니다.'],401);}
            $q=$pdo->prepare('SELECT COUNT(*) FROM member_saved_quotes WHERE member_id=? AND quote_key<>?');$q->execute([$memberId,$key]);
            if ((int)$q->fetchColumn()>=30) {$pdo->rollBack();member_response(['ok'=>false,'message'=>'견적은 최대 30개까지 저장할 수 있습니다. 기존 항목을 삭제해 주세요.'],422);}
            $pdo->prepare('INSERT INTO member_saved_quotes (member_id,quote_key,selection_json,labels_json) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE labels_json=VALUES(labels_json),updated_at=NOW()')->execute([$memberId,$key,json_encode($values,JSON_THROW_ON_ERROR),json_encode($labels,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $pdo->commit();
        } else member_response(['ok'=>false,'message'=>'잘못된 요청입니다.'],400);
    }
    $q=$pdo->prepare('SELECT id,selection_json,labels_json,updated_at FROM member_saved_quotes WHERE member_id=? ORDER BY updated_at DESC,id DESC LIMIT 30');$q->execute([$memberId]);$items=[];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $items[]=['id'=>(int)$row['id'],'selection'=>json_decode($row['selection_json'],true),'labels'=>json_decode($row['labels_json'],true),'updated_at'=>$row['updated_at']];
    member_response(['ok'=>true,'items'=>$items]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[AutoGenie saved quote] '.$e->getMessage());
    member_response(['ok'=>false,'message'=>'저장한 견적을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.'],503);
}
