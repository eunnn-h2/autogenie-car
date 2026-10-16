<?php
declare(strict_types=1);

/** Customer sessions have their own cookie and store, independent of administrator logout and PHP file cleanup. */
final class AgMemberSessionStore implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface {
    private array $locks=[];
    public function __construct(private PDO $pdo) {}
    private function key(string $id): string { return hash('sha256',$id); }
    private function lock(string $id): void {
        $key=$this->key($id);
        if(isset($this->locks[$key]))return;
        $name='agm:'.substr($key,0,60);
        $q=$this->pdo->prepare('SELECT GET_LOCK(?,15)');$q->execute([$name]);
        if((int)$q->fetchColumn()!==1)throw new RuntimeException('회원 로그인 상태 확인이 지연되고 있습니다. 잠시 후 다시 시도해 주세요.');
        $this->locks[$key]=$name;
    }
    public function open(string $path,string $name): bool { return true; }
    public function close(): bool {
        foreach($this->locks as $name){$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);}
        $this->locks=[];return true;
    }
    public function read(string $id): string|false {
        $this->lock($id);
        $q=$this->pdo->prepare('SELECT payload FROM ag_member_sessions WHERE session_key=? AND expires_at>NOW()');$q->execute([$this->key($id)]);
        return (string)($q->fetchColumn()?:'');
    }
    public function write(string $id,string $data): bool {
        $this->lock($id);
        $q=$this->pdo->prepare('INSERT INTO ag_member_sessions (session_key,payload,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 7 DAY)) ON DUPLICATE KEY UPDATE payload=VALUES(payload),expires_at=VALUES(expires_at),updated_at=NOW()');
        return $q->execute([$this->key($id),$data]);
    }
    public function destroy(string $id): bool {
        $this->lock($id);
        return $this->pdo->prepare('DELETE FROM ag_member_sessions WHERE session_key=?')->execute([$this->key($id)]);
    }
    public function gc(int $max_lifetime): int|false {
        return $this->pdo->exec('DELETE FROM ag_member_sessions WHERE expires_at<=NOW() LIMIT 1000');
    }
    public function validateId(string $id): bool {
        $q=$this->pdo->prepare('SELECT 1 FROM ag_member_sessions WHERE session_key=? AND expires_at>NOW()');$q->execute([$this->key($id)]);
        return (bool)$q->fetchColumn();
    }
    public function updateTimestamp(string $id,string $data): bool { return $this->write($id,$data); }
}

function agMemberSessionStart(PDO $pdo): void {
    if(session_status()===PHP_SESSION_ACTIVE)return;
    $root=str_replace('\\','/',(string)realpath(__DIR__.'/..'));
    $script=str_replace('\\','/',(string)realpath($_SERVER['SCRIPT_FILENAME']??''));
    $scriptName=(string)($_SERVER['SCRIPT_NAME']??'');
    $relative=str_starts_with($script,$root.'/')?substr($script,strlen($root)):'';
    $base=$relative!=='' && str_ends_with($scriptName,$relative)?substr($scriptName,0,-strlen($relative)):'';
    $path=rtrim($base,'/').'/';
    session_name('AG_MEMBER_'.substr(hash('sha256',$path),0,12));
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.use_trans_sid','0');
    ini_set('session.gc_maxlifetime','604800');
    ini_set('session.gc_probability','1');ini_set('session.gc_divisor','1000');
    session_set_cookie_params(['lifetime'=>604800,'path'=>$path,'httponly'=>true,'samesite'=>'Lax',
        'secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')]);
    session_set_save_handler(new AgMemberSessionStore($pdo),true);
    if(!session_start())throw new RuntimeException('회원 로그인 상태를 확인하지 못했습니다.');
    // Renew the browser cookie while the user is active, matching the seven-day server idle expiry.
    if((int)($_SESSION['member_cookie_renewed_at']??0)<time()-600){
        $params=session_get_cookie_params();
        setcookie(session_name(),session_id(),['expires'=>time()+604800,'path'=>$params['path'],'domain'=>$params['domain'],
            'secure'=>$params['secure'],'httponly'=>true,'samesite'=>'Lax']);
        $_SESSION['member_cookie_renewed_at']=time();
    }
}
