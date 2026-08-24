<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';
$status=trim((string)($_GET['status']??''));$type=strtoupper(trim((string)($_GET['type']??'')));$q=trim((string)($_GET['q']??''));$source=trim((string)($_GET['source']??''));
$rows=[];
function addRows(PDO $pdo,string $table,string $kind,string $type,string $status,string $q,string $source,array &$rows):void{
 if($type!==''&&$type!==$kind)return;$where=[];$params=[];
 if($status!==''){$where[]='status=?';$params[]=$status;}
 if($source!==''){$where[]="COALESCE(NULLIF(utm_source,''), '직접/기타')=?";$params[]=$source;}
 if($q!==''){$like='%'.$q.'%'; if($kind==='DIRECT'){$where[]='(estimate_no LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ? OR vehicle_name LIKE ?)';}else{$where[]='(estimate_no LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ? OR car_type LIKE ?)';}array_push($params,$like,$like,$like,$like);}
 $sql='SELECT * FROM '.$table.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY created_at DESC';
 try{$s=$pdo->prepare($sql);$s->execute($params);foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){$r['_kind']=$kind;$rows[]=$r;}}catch(Throwable $e){}
}
addRows($pdo,'estimates','DIRECT',$type,$status,$q,$source,$rows);addRows($pdo,'quick_estimates','QUICK',$type,$status,$q,$source,$rows);
usort($rows,fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at']));
$filename='autogenie_estimates_'.date('Ymd_His').'.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Cache-Control: no-store');echo "\xEF\xBB\xBF";
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><html><head><meta charset="UTF-8"></head><body><table border="1"><thead><tr><th>구분</th><th>견적번호</th><th>상태</th><th>고객명</th><th>연락처</th><th>브랜드</th><th>차량/관심차종</th><th>트림</th><th>색상</th><th>이용방식</th><th>계약개월</th><th>선납금</th><th>주행거리</th><th>월납입금</th><th>희망예산</th><th>utm_source</th><th>utm_medium</th><th>utm_campaign</th><th>유입페이지</th><th>이전페이지</th><th>신청일</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['_kind']==='DIRECT'?'직접견적':'간편견적')?></td><td><?=e($r['estimate_no']??'')?></td><td><?=e($r['status']??'')?></td><td><?=e($r['customer_name']??'')?></td><td style="mso-number-format:'\@'\"><?=e($r['customer_phone']??'')?></td><td><?=e($r['brand_name']??'')?></td><td><?=e($r['_kind']==='DIRECT'?($r['vehicle_name']??''):($r['car_type']??'상담 후 결정'))?></td><td><?=e($r['trim_name']??'')?></td><td><?=e($r['color_name']??'')?></td><td><?=e($r['product_type']??'')?></td><td><?=e($r['contract_months']??'')?></td><td><?=e($r['prepayment_rate']??'')?></td><td><?=e($r['annual_mileage']??'')?></td><td><?=e($r['monthly_payment']??'')?></td><td><?=e($r['monthly_budget']??'')?></td><td><?=e($r['utm_source']??'')?></td><td><?=e($r['utm_medium']??'')?></td><td><?=e($r['utm_campaign']??'')?></td><td><?=e($r['landing_page']??'')?></td><td><?=e($r['referrer']??'')?></td><td><?=e($r['created_at']??'')?></td></tr><?php endforeach;?></tbody></table></body></html>