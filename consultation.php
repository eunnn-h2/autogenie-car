<?php
declare(strict_types=1);
require_once __DIR__.'/api/member-auth-common.php';
require_once __DIR__.'/config/consultation.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');
$source=strtoupper((string)($_GET['type']??''));$id=(int)($_GET['id']??0);$memberId=(int)($_SESSION['member_id']??0);
$valid=in_array($source,['DIRECT','QUICK'],true)&&$id>0;
$e=$valid&&$memberId>0?agConsultationEstimate($pdo,$source,$id,$memberId):false;
if(!$valid || ($memberId>0&&!$e))http_response_code(404);
function ch($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$chat='';$phone='';if($e){try{[$phone,$chat]=agConsultationContact(['business_phone'=>$e['consultation_phone']??'','kakao_chat_url'=>$e['consultation_chat_url']??'']);}catch(InvalidArgumentException $ignored){}}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>담당자 상담 연결 | 오토지니</title><link rel="stylesheet" href="./fonts/fonts.css"><link rel="stylesheet" href="./consultation.css?v=<?=filemtime(__DIR__.'/consultation.css')?>"><script src="./consultation.js?v=<?=filemtime(__DIR__.'/consultation.js')?>" defer></script></head>
<body class="consultation-page"><main class="consultation-card"><a class="consultation-brand" href="./db-test.html">오토지니</a>
<?php if(!$valid): ?><h1>신청 정보를 확인해 주세요</h1><p>유효하지 않은 상담 연결입니다.</p>
<?php elseif($memberId<1): ?><h1>로그인 후 확인해 주세요</h1><p>견적을 신청한 계정으로 로그인하면 신청 내역과 담당자를 확인할 수 있습니다.</p><a class="consultation-primary" href="./db-test.html?consultation=<?=ch($source.'-'.$id)?>">로그인하고 신청 내역 확인</a>
<?php elseif(!$e): ?><h1>신청 내역을 확인할 수 없습니다</h1><p>신청한 계정으로 로그인했는지 확인해 주세요.</p><a class="consultation-primary" href="./db-test.html">사이트로 이동</a>
<?php else: ?>
<p class="consultation-eyebrow">견적 신청 내역</p><h1><?=ch($source==='QUICK'?($e['car_type']?:'차량 상담'):trim($e['brand_name'].' '.$e['vehicle_name']))?></h1>
<dl class="consultation-details"><div><dt>견적번호</dt><dd><?=ch($e['estimate_no'])?></dd></div><?php if($source==='DIRECT'): ?><div><dt>트림</dt><dd><?=ch($e['trim_name'])?></dd></div><div><dt>외장색상</dt><dd><?=ch($e['color_name'])?></dd></div><?php endif; ?><div><dt>이용조건</dt><dd><?=ch(agConsultationSummary($e,$source))?></dd></div><?php if($source==='DIRECT'): ?><div><dt>신청 당시 월 이용료</dt><dd><?=number_format((int)$e['monthly_payment'])?>원</dd></div><?php endif; ?></dl>
<section class="consultation-agent"><h2>상담 담당자</h2><?php if($e['consultant_id']): ?><strong><?=ch($e['consultant_name']?:'담당 영업사원')?></strong><?php if($phone): ?><a class="consultation-phone" href="tel:<?=ch($phone)?>"><?=ch($phone)?></a><?php endif; ?><?php else: ?><p>담당자를 배정하고 있습니다. 배정 후 이 화면에서 확인하실 수 있습니다.</p><?php endif; ?></section>
<p class="consultation-help">상담 내용을 복사한 뒤 카카오톡에 붙여 넣어 주세요. 견적번호를 보내주시면 신청 건을 빠르게 확인할 수 있습니다.</p>
<textarea id="consultationCopyText" class="consultation-copy-text" readonly aria-label="상담 전달 내용"><?=ch(agConsultationCopy($e,$source))?></textarea>
<div class="consultation-actions"><button type="button" data-copy-consultation>상담 내용 복사</button><?php if($chat): ?><a class="consultation-kakao" href="<?=ch($chat)?>" target="_blank" rel="noopener noreferrer">담당자와 카카오톡 상담</a><?php elseif($e['consultant_id']): ?><p class="consultation-help">카카오 상담 연결을 준비하고 있습니다. 담당자 번호로 연락해 주세요.</p><?php endif; ?><?php if($phone): ?><a class="consultation-primary" href="tel:<?=ch($phone)?>">담당자에게 전화하기</a><?php endif; ?></div><p id="consultationCopyStatus" role="status" aria-live="polite"></p>
<?php endif; ?><a class="consultation-back" href="./db-test.html">사이트로 돌아가기</a></main></body></html>
