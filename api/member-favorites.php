<?php
declare(strict_types=1);
require_once __DIR__.'/member-auth-common.php';
header('Cache-Control: no-store');
$memberId=(int)($_SESSION['member_id']??0);
if($memberId<1)member_response(['ok'=>false,'message'=>'로그인이 필요합니다.'],401);
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(($_SERVER['HTTP_X_REQUESTED_WITH']??'')!=='XMLHttpRequest')member_response(['ok'=>false,'message'=>'허용되지 않은 요청입니다.'],403);
    $data=member_json_input();$vehicleId=filter_var($data['vehicle_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$vehicleId || !is_bool($data['active']??null))member_response(['ok'=>false,'message'=>'차량 정보를 확인해 주세요.'],422);
    $q=$pdo->prepare('SELECT id FROM car_vehicles WHERE id=? AND is_active=1');$q->execute([$vehicleId]);
    if(!$q->fetchColumn())member_response(['ok'=>false,'message'=>'차량을 찾을 수 없습니다.'],404);
    if($data['active'])$pdo->prepare('INSERT IGNORE INTO member_vehicle_favorites (member_id,vehicle_id) VALUES (?,?)')->execute([$memberId,$vehicleId]);
    else $pdo->prepare('DELETE FROM member_vehicle_favorites WHERE member_id=? AND vehicle_id=?')->execute([$memberId,$vehicleId]);
}elseif($_SERVER['REQUEST_METHOD']!=='GET')member_response(['ok'=>false,'message'=>'잘못된 요청입니다.'],405);
$q=$pdo->prepare('SELECT f.vehicle_id FROM member_vehicle_favorites f JOIN car_vehicles v ON v.id=f.vehicle_id AND v.is_active=1 WHERE member_id=? ORDER BY f.created_at DESC,vehicle_id DESC LIMIT 100');$q->execute([$memberId]);
member_response(['ok'=>true,'ids'=>array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN))]);
