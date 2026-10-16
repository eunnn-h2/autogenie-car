<?php
declare(strict_types=1);
require_once __DIR__ . '/member-sms-common.php';
member_phone_api_guard();
$id = (int)($_SESSION['member_id'] ?? 0);
if ($id < 1) member_response(['ok'=>false,'message'=>'로그인이 필요합니다.'],401);
$q=$pdo->prepare('SELECT * FROM member_accounts WHERE id=? AND status=\'ACTIVE\'');$q->execute([$id]);$member=$q->fetch(PDO::FETCH_ASSOC);
if (!$member) member_response(['ok'=>false,'message'=>'회원 정보를 확인할 수 없습니다.'],401);
$data=member_json_input();$action=$data['action']??'save';
$phone=normalize_member_phone(is_string($data['phone']??null)?$data['phone']:'');
$changed=$phone!=='' && $phone!==(string)$member['phone'];
$owner=hash('sha256',$id.':'.$member['password_hash'].':'.$member['phone']);
try {
    if (in_array($action,['request_code','confirm_code'],true)) {
        if (!$changed) member_response(['ok'=>false,'message'=>'변경할 휴대폰 번호를 입력해 주세요.'],422);
        $q=$pdo->prepare('SELECT id FROM member_accounts WHERE phone=? AND id<>? LIMIT 1');$q->execute([$phone,$id]);
        if ($q->fetchColumn()) member_response(['ok'=>false,'message'=>'이미 다른 계정에서 사용하는 번호입니다.'],409);
    }
    if ($action==='request_code') {
        if (time()-(int)($_SESSION['profile_phone_requested_at']??0)<60) member_response(['ok'=>false,'message'=>'인증번호는 1분 후 다시 요청해 주세요.'],429);
        $settings=require __DIR__.'/../config/sms.php';
        $local=in_array(strtolower((string)($_SERVER['HTTP_HOST']??'')),['localhost','localhost:80','127.0.0.1','127.0.0.1:80'],true)&&in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true);
        if (!($local&&$settings['local_debug'])&&($settings['service_id']===''||$settings['access_key']===''||$settings['secret_key']===''||$settings['sender']==='')) member_response(['ok'=>false,'message'=>'문자 인증 서비스가 아직 설정되지 않았습니다. 관리자에게 문의해 주세요.'],503);
        member_sms_consume_rate($pdo,$phone);
        $code=(string)random_int(100000,999999);$debug=member_sms_send($phone,$code,'연락처 변경');
        $_SESSION['profile_phone_challenge']=['owner'=>$owner,'phone'=>$phone,'hash'=>password_hash($code,PASSWORD_DEFAULT),'expires'=>time()+600,'attempts'=>0,'verified'=>false];
        $_SESSION['profile_phone_requested_at']=time();
        $result=['ok'=>true,'message'=>'변경할 번호로 인증번호 발송을 요청했습니다. (10분 유효)'];
        if ($debug) $result['debug_code']=$code;
        member_response($result);
    }
    $challenge=$_SESSION['profile_phone_challenge']??[];
    $valid=$changed&&($challenge['phone']??'')===$phone&&hash_equals((string)($challenge['owner']??''),$owner)&&(int)($challenge['expires']??0)>time();
    if ($action==='confirm_code') {
        if (!$valid) member_response(['ok'=>false,'message'=>'인증번호가 없거나 만료되었습니다. 다시 발급해 주세요.'],410);
        if ((int)$challenge['attempts']>=5) member_response(['ok'=>false,'message'=>'인증 시도 횟수를 초과했습니다. 다시 발급해 주세요.'],429);
        $code=is_string($data['code']??null)?$data['code']:'';
        if (!preg_match('/^\d{6}$/D',$code)||!password_verify($code,(string)$challenge['hash'])) {
            $_SESSION['profile_phone_challenge']['attempts']++;
            member_response(['ok'=>false,'message'=>'인증번호가 일치하지 않습니다.'],422);
        }
        $_SESSION['profile_phone_challenge']['verified']=true;
        member_response(['ok'=>true,'message'=>'휴대폰 인증이 완료되었습니다. 정보 저장을 눌러 주세요.']);
    }
    if ($action!=='save') member_response(['ok'=>false,'message'=>'잘못된 요청입니다.'],400);
    $name=trim(is_string($data['name']??null)?$data['name']:'');
    if (!preg_match('/^[가-힣ㄱ-ㅎㅏ-ㅣA-Za-z]+(?: [가-힣ㄱ-ㅎㅏ-ㅣA-Za-z]+)*$/u',$name)||mb_strlen(str_replace(' ','',$name),'UTF-8')<2||mb_strlen($name,'UTF-8')>30) member_response(['ok'=>false,'message'=>'이름은 한글 또는 영문 2~30자로 입력해 주세요.'],422);
    // A social account may leave its as-yet unregistered phone empty while changing its name.
    if ($phone==='' && !(($data['phone']??'')==='' && str_starts_with((string)$member['phone'],'KAKAO-'))) member_response(['ok'=>false,'message'=>'올바른 휴대폰 번호를 입력해 주세요.'],422);
    if ($changed && !($valid&&($challenge['verified']??false))) member_response(['ok'=>false,'message'=>'변경할 휴대폰 번호의 인증을 완료해 주세요.'],422);
    $pdo->beginTransaction();
    $q=$pdo->prepare('SELECT phone,password_hash,status FROM member_accounts WHERE id=? FOR UPDATE');$q->execute([$id]);$latest=$q->fetch(PDO::FETCH_ASSOC);
    if (!$latest||$latest['status']!=='ACTIVE'||!hash_equals($owner,hash('sha256',$id.':'.$latest['password_hash'].':'.$latest['phone']))) throw new DomainException('회원 정보가 변경되었습니다. 다시 로그인한 뒤 시도해 주세요.');
    $pdo->prepare('UPDATE member_accounts SET name=?,phone=? WHERE id=?')->execute([$name,$changed?$phone:$member['phone'],$id]);
    $pdo->commit();
    unset($_SESSION['profile_phone_challenge']);
    $member['name']=$name;if ($changed) $member['phone']=$phone;
    member_response(['ok'=>true,'member'=>member_public($member),'message'=>'회원 정보를 저장했습니다.']);
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    member_response(['ok'=>false,'message'=>$e->getMessage()],429);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException && ($e->errorInfo[1]??0)===1062) member_response(['ok'=>false,'message'=>'이미 다른 계정에서 사용하는 번호입니다.'],409);
    error_log('[AutoGenie profile] '.$e->getMessage());
    member_response(['ok'=>false,'message'=>'회원 정보를 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.'],503);
}
