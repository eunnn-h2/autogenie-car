<?php
declare(strict_types=1);
$alimtalkLocal = is_file(__DIR__.'/alimtalk.local.php') ? require __DIR__.'/alimtalk.local.php' : [];
if (!is_array($alimtalkLocal)) $alimtalkLocal=[];
$sms = require __DIR__.'/sms.php';
return array_replace([
    'enabled'=>false,
    'service_id'=>(string)(getenv('NAVER_ALIMTALK_SERVICE_ID') ?: ''),
    'access_key'=>$sms['access_key'], 'secret_key'=>$sms['secret_key'],
    'channel_id'=>(string)(getenv('NAVER_ALIMTALK_CHANNEL_ID') ?: ''),
    'site_url'=>(string)(getenv('AG_PUBLIC_SITE_URL') ?: ''),
    'receipt_code'=>'', 'assigned_code'=>'',
    'receipt_content'=>"[오토지니] 견적 신청이 접수되었습니다.\n\n견적번호: #{견적번호}\n차량: #{차량}\n세부모델: #{트림}\n이용조건: #{이용조건}\n신청 당시 월 이용료: #{월이용료}\n\n담당자 배정 후 상담을 안내해 드리겠습니다.",
    'assigned_content'=>"[오토지니] 견적 상담 담당자가 배정되었습니다.\n\n견적번호: #{견적번호}\n차량: #{차량}\n담당자: #{담당자}\n연락처: #{담당자연락처}\n\n아래 버튼에서 담당자를 확인하고 카카오톡 상담을 시작해 주세요.",
    'receipt_button'=>'신청 내역 확인', 'assigned_button'=>'담당자 상담하기',
], $alimtalkLocal);
