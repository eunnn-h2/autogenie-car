<?php
declare(strict_types=1);

function agConsultationContact(array $input, string $prefix=''): array {
    $phone=trim((string)($input[$prefix.'business_phone']??''));
    $digits=preg_replace('/\D/','',$phone);
    if($phone!=='' && !preg_match('/^(?:010\d{8}|01[16789]\d{7,8})$/',$digits)) throw new InvalidArgumentException('영업용 휴대폰 번호를 확인해 주세요.');
    $url=trim((string)($input[$prefix.'kakao_chat_url']??''));
    if($url!=='' && !preg_match('~^https://open\.kakao\.com/(?:o|me)/[A-Za-z0-9_-]+/?$~D',$url)) throw new InvalidArgumentException('카카오 1:1 오픈프로필의 https://open.kakao.com/ 상담 링크를 입력해 주세요.');
    if($url!=='' && $digits==='') throw new InvalidArgumentException('카카오 상담 링크와 함께 영업용 휴대폰 번호를 입력해 주세요.');
    return [$digits,$url];
}
function agConsultationEstimate(PDO $pdo,string $source,int $id,int $memberId=0): array|false {
    if(!in_array($source,['DIRECT','QUICK'],true)||$id<1) return false;
    $table=$source==='QUICK'?'estimate_quick':'estimate_direct';
    $sql="SELECT e.*,a.id consultant_id,a.name consultant_name,a.business_phone consultation_phone,a.kakao_chat_url consultation_chat_url FROM $table e LEFT JOIN admin_accounts a ON a.id=e.assigned_admin_id AND a.is_active=1 AND a.role='SALES' WHERE e.id=?";
    $args=[$id];if($memberId>0){$sql.=' AND e.member_id=?';$args[]=$memberId;}
    $q=$pdo->prepare($sql);$q->execute($args);return $q->fetch(PDO::FETCH_ASSOC);
}
function agConsultationSummary(array $e,string $source): string {
    $product=['RENT'=>'장기렌트','LEASE'=>'리스'][$e['product_type']??'']??'상담 후 결정';
    if($source==='QUICK') return $product.' / '.($e['monthly_budget']?:'예산 상담');
    $mileage=(int)$e['annual_mileage']===0?'무제한':number_format((int)$e['annual_mileage']).'km';
    return $product.' / '.(int)$e['contract_months'].'개월 / 선납 '.(float)$e['prepayment_rate'].'% / 연 '.$mileage;
}
function agConsultationCopy(array $e,string $source): string {
    $title=$source==='QUICK'?($e['car_type']?:'차량 상담'):trim($e['brand_name'].' '.$e['vehicle_name']);
    $text='[오토지니 견적 상담]'."\n견적번호: ".$e['estimate_no']."\n차량: ".$title;
    if($source==='DIRECT')$text.="\n트림: ".$e['trim_name']."\n색상: ".$e['color_name'];
    $text.="\n이용조건: ".agConsultationSummary($e,$source);
    if($source==='DIRECT')$text.="\n신청 당시 월 이용료: ".number_format((int)$e['monthly_payment']).'원';
    return $text."\n위 신청 건으로 상담 부탁드립니다.";
}

function agAlimtalkQueue(PDO $pdo,string $table,int $id,string $event): void {
    if(!in_array($table,['estimate_direct','estimate_quick'],true)||!in_array($event,['receipt','assigned'],true))throw new InvalidArgumentException('잘못된 알림 정보입니다.');
    $source=$table==='estimate_quick'?'QUICK':'DIRECT';
    $e=agConsultationEstimate($pdo,$source,$id);
    if(!$e || (int)($e['member_id']??0)<1)return; // No guest application lookup is introduced.
    $revision=$event==='assigned'?(int)$e['consultation_revision']:0;
    $key=$event.'-'.$revision;
    $pdo->prepare("INSERT IGNORE INTO ag_estimate_messages (estimate_source,estimate_id,event_kind,event_key,assignee_id) VALUES (?,?,?,?,?)")
        ->execute([$source,$id,$event,$key,$event==='assigned'?($e['consultant_id']?:null):null]);
}
function agAlimtalkHttp(string $method,string $path,?array $body,array $config): array {
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL 확장 기능을 확인해 주세요.');
    $timestamp=(string)(int)round(microtime(true)*1000);
    $signature=base64_encode(hash_hmac('sha256',$method.' '.$path."\n".$timestamp."\n".$config['access_key'],$config['secret_key'],true));
    $ch=curl_init('https://sens.apigw.ntruss.com'.$path);
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-ncp-apigw-timestamp: '.$timestamp,'x-ncp-iam-access-key: '.$config['access_key'],'x-ncp-apigw-signature-v2: '.$signature]]);
    if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($raw===false)throw new RuntimeException('발송 서버 응답을 확인하지 못했습니다. SENS 발송 이력을 먼저 확인해 주세요.');
    $data=json_decode($raw,true);
    if(!is_array($data))throw new RuntimeException('발송 서버의 응답을 확인해 주세요.');
    return [$http,$data];
}
function agAlimtalkConfigReady(array $c,string $event): bool {
    return $c['enabled']===true && preg_match('/^[A-Za-z0-9:_-]+$/',(string)$c['service_id'])
        && $c['access_key']!=='' && $c['secret_key']!=='' && $c['channel_id']!=='' && $c[$event.'_code']!==''
        && preg_match('~^https://[^\s?#]+$~D',(string)$c['site_url']);
}
function agAlimtalkPayload(array $e,string $source,string $event,array $c): array {
    $url=rtrim($c['site_url'],'/').'/consultation.php?type='.$source.'&id='.(int)$e['id'];
    $vars=['#{견적번호}'=>$e['estimate_no'],'#{차량}'=>$source==='QUICK'?($e['car_type']?:'차량 상담'):trim($e['brand_name'].' '.$e['vehicle_name']),
        '#{트림}'=>$e['trim_name']??'상담 후 결정','#{이용조건}'=>agConsultationSummary($e,$source),
        '#{월이용료}'=>isset($e['monthly_payment'])?number_format((int)$e['monthly_payment']).'원':'상담 후 안내',
        '#{담당자}'=>$e['consultant_name']?:'담당 영업사원','#{담당자연락처}'=>$e['consultation_phone']?:'1600-3481'];
    $content=strtr($c[$event.'_content'],$vars);
    if(preg_match('/#\{[^}]+\}/u',$content)||mb_strlen($content,'UTF-8')>1000)throw new InvalidArgumentException('알림톡 템플릿 변수와 길이를 확인해 주세요.');
    return ['plusFriendId'=>$c['channel_id'],'templateCode'=>$c[$event.'_code'],'messages'=>[[
        'countryCode'=>'82','to'=>preg_replace('/\D/','',$e['customer_phone']),'content'=>$content,'useSmsFailover'=>false,
        'buttons'=>[['type'=>'WL','name'=>$c[$event.'_button'],'linkMobile'=>$url,'linkPc'=>$url]]]]];
}

/** Durable claim prevents double sends. An ambiguous network result is never retried automatically. */
function agAlimtalkProcess(PDO $pdo,int $messageId,?callable $transport=null,?array $config=null): void {
    if($pdo->inTransaction())throw new LogicException('알림 처리는 견적 저장 완료 후 실행해야 합니다.');
    $c=$config??require __DIR__.'/alimtalk.php';$transport??='agAlimtalkHttp';
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM ag_estimate_messages WHERE id=? FOR UPDATE');$q->execute([$messageId]);$row=$q->fetch();
        if(!$row || !in_array($row['status'],['QUEUED','NOT_CONFIGURED'],true)){$pdo->commit();return;}
        $e=agConsultationEstimate($pdo,$row['estimate_source'],(int)$row['estimate_id']);
        $valid=$e && (int)($e['member_id']??0)>0;
        if($valid){$q=$pdo->prepare("SELECT 1 FROM member_accounts WHERE id=? AND status='ACTIVE'");$q->execute([(int)$e['member_id']]);$valid=(bool)$q->fetchColumn();}
        if($valid && $row['event_kind']==='assigned')$valid=$e['consultant_id'] && (int)$e['consultant_id']===(int)$row['assignee_id'] && $row['event_key']==='assigned-'.(int)$e['consultation_revision'];
        if(!$valid){$pdo->prepare("UPDATE ag_estimate_messages SET status='CANCELED',detail='담당자 변경 또는 회원/견적 상태 변경',updated_at=NOW() WHERE id=?")->execute([$messageId]);$pdo->commit();return;}
        if(!agAlimtalkConfigReady($c,$row['event_kind'])){
            $pdo->prepare("UPDATE ag_estimate_messages SET status='NOT_CONFIGURED',detail='알림톡 설정 및 승인 템플릿 확인 필요',updated_at=NOW() WHERE id=?")->execute([$messageId]);$pdo->commit();return;
        }
        if($transport==='agAlimtalkHttp' && !function_exists('curl_init'))throw new InvalidArgumentException('PHP cURL 확장 기능을 확인해 주세요.');
        $payload=agAlimtalkPayload($e,$row['estimate_source'],$row['event_kind'],$c);
        if(!preg_match('/^01[016789]\d{7,8}$/',$payload['messages'][0]['to']))throw new InvalidArgumentException('고객 휴대폰 번호를 확인해 주세요.');
        if($row['event_kind']==='assigned' && (!$e['consultation_phone'] || !$e['consultation_chat_url']))throw new InvalidArgumentException('담당자의 영업용 번호와 1:1 카카오 상담 링크를 먼저 등록해 주세요.');
        $pdo->prepare("UPDATE ag_estimate_messages SET status='SENDING',attempts=attempts+1,detail='',updated_at=NOW() WHERE id=?")->execute([$messageId]);
        $pdo->commit();
    }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();$pdo->prepare("UPDATE ag_estimate_messages SET status='FAILED',detail=?,updated_at=NOW() WHERE id=? AND status IN ('QUEUED','NOT_CONFIGURED')")->execute([mb_substr($ex->getMessage(),0,250),$messageId]);return;}
    try{
        [$http,$data]=$transport('POST','/alimtalk/v2/services/'.$c['service_id'].'/messages',$payload,$c);
        $msg=$data['messages'][0]??[];
        $accepted=$http===202 && (string)($data['statusCode']??'')==='202' && ($msg['requestStatusCode']??'')==='A000' && !empty($msg['messageId']);
        $status=$accepted?'REQUESTED':($http>=400&&$http<500?'FAILED':'UNKNOWN');
        if(!$accepted && isset($msg['requestStatusCode']) && $msg['requestStatusCode']!=='A000')$status='FAILED';
        $pdo->prepare('UPDATE ag_estimate_messages SET status=?,request_id=?,message_id=?,detail=?,updated_at=NOW() WHERE id=?')->execute([
            $status,$data['requestId']??null,$msg['messageId']??null,$accepted?'발송 접수됨 · 수신 결과 확인 전':($status==='FAILED'?'발송 요청 거절 · SENS 설정/이력 확인':'응답 불명확 · SENS 이력 확인 후 처리'),$messageId]);
    }catch(Throwable $ex){$pdo->prepare("UPDATE ag_estimate_messages SET status='UNKNOWN',detail='응답 미확인 · 중복 방지를 위해 SENS 이력을 먼저 확인해 주세요.',updated_at=NOW() WHERE id=?")->execute([$messageId]);}
}
function agAlimtalkRefresh(PDO $pdo,int $id,?callable $transport=null,?array $config=null): void {
    $q=$pdo->prepare('SELECT * FROM ag_estimate_messages WHERE id=?');$q->execute([$id]);$r=$q->fetch();
    if(!$r || !in_array($r['status'],['REQUESTED','UNKNOWN'],true) || !$r['message_id'])return;
    $c=$config??require __DIR__.'/alimtalk.php';if(!agAlimtalkConfigReady($c,$r['event_kind']))return;$transport??='agAlimtalkHttp';
    [$http,$data]=$transport('GET','/alimtalk/v2/services/'.$c['service_id'].'/messages/'.rawurlencode($r['message_id']),null,$c);
    if($http!==200)return;
    $code=(string)($data['messageStatusCode']??'');
    if($code===''){
        $pdo->prepare("UPDATE ag_estimate_messages SET updated_at=NOW() WHERE id=? AND status='REQUESTED'")->execute([$id]);
        return;
    }
    $pdo->prepare("UPDATE ag_estimate_messages SET status=?,detail=?,updated_at=NOW() WHERE id=? AND status IN ('REQUESTED','UNKNOWN')")->execute([$code==='0000'?'DELIVERED':'FAILED',$code==='0000'?'카카오톡 수신 성공':'수신 실패 ('.$code.') · SENS 이력 확인',$id]);
}
function agAlimtalkDispatchEstimate(PDO $pdo,string $table,int $id): void {
    try{
        $q=$pdo->prepare("SELECT id FROM ag_estimate_messages WHERE estimate_source=? AND estimate_id=? AND status IN ('QUEUED','NOT_CONFIGURED') ORDER BY id LIMIT 2");$q->execute([$table==='estimate_quick'?'QUICK':'DIRECT',$id]);
        foreach($q->fetchAll() as $row)agAlimtalkProcess($pdo,(int)$row['id']);
    }catch(Throwable $ex){error_log('AutoGenie notification dispatch failed');}
}
