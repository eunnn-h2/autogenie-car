<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
requireAdminCategory('traffic');
require_once __DIR__ . '/../config/database.php';

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function formatDuration(int $seconds): string {
    $seconds = max(0, $seconds);
    if ($seconds < 60) return $seconds.'초';
    $m = intdiv($seconds, 60); $s = $seconds % 60;
    if ($m < 60) return $m.'분 '.($s ? $s.'초' : '');
    $h = intdiv($m, 60); $m = $m % 60;
    return $h.'시간 '.($m ? $m.'분' : '');
}
function scalarValue(PDO $pdo, string $sql, array $params = []): int {
    try { $s=$pdo->prepare($sql); $s->execute($params); return (int)$s->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}
function fetchAllSafe(PDO $pdo, string $sql, array $params = []): array {
    try { $s=$pdo->prepare($sql); $s->execute($params); return $s->fetchAll(); }
    catch (Throwable $e) { return []; }
}

$period = (string)($_GET['period'] ?? '30');
$allowedPeriods = ['7','30','90','all'];
if (!in_array($period, $allowedPeriods, true)) $period = '30';
$sourceFilter = trim((string)($_GET['source'] ?? ''));
$mediumFilter = trim((string)($_GET['medium'] ?? ''));
$campaignFilter = trim((string)($_GET['campaign'] ?? ''));
$completedFilter = trim((string)($_GET['completed'] ?? ''));
if (!in_array($completedFilter, ['', '1', '0'], true)) $completedFilter = '';

$dateSql = '';
if ($period !== 'all') {
    $days = max(1, (int)$period);
    $dateSql = ' AND started_at >= DATE_SUB(NOW(), INTERVAL '.$days.' DAY)';
}

$where = ' WHERE 1=1'.$dateSql;
$params = [];
if ($sourceFilter !== '') {
    $where .= " AND COALESCE(NULLIF(utm_source,''),'직접/기타') = ?";
    $params[] = $sourceFilter;
}
if ($mediumFilter !== '') {
    $where .= " AND COALESCE(NULLIF(utm_medium,''),'미지정') = ?";
    $params[] = $mediumFilter;
}
if ($campaignFilter !== '') {
    $where .= " AND COALESCE(NULLIF(utm_campaign,''),'미지정') = ?";
    $params[] = $campaignFilter;
}
if ($completedFilter !== '') {
    $where .= ' AND is_completed = ?';
    $params[] = (int)$completedFilter;
}

$sessionCount = scalarValue($pdo, "SELECT COUNT(*) FROM estimate_abandonments{$where}", $params);
$completedCount = scalarValue($pdo, "SELECT COUNT(*) FROM estimate_abandonments{$where} AND is_completed=1", $params);
$conversion = $sessionCount > 0 ? round($completedCount / $sessionCount * 100, 1) : 0;
$avgStaySeconds = scalarValue($pdo, "SELECT ROUND(AVG(active_seconds)) FROM estimate_abandonments{$where}", $params);

$todayWhere = " WHERE DATE(started_at)=CURDATE()";
$todayParams = [];
if ($sourceFilter !== '') { $todayWhere .= " AND COALESCE(NULLIF(utm_source,''),'직접/기타')=?"; $todayParams[]=$sourceFilter; }
if ($mediumFilter !== '') { $todayWhere .= " AND COALESCE(NULLIF(utm_medium,''),'미지정')=?"; $todayParams[]=$mediumFilter; }
if ($campaignFilter !== '') { $todayWhere .= " AND COALESCE(NULLIF(utm_campaign,''),'미지정')=?"; $todayParams[]=$campaignFilter; }
if ($completedFilter !== '') { $todayWhere .= " AND is_completed=?"; $todayParams[]=(int)$completedFilter; }
$todayCount = scalarValue($pdo, "SELECT COUNT(*) FROM estimate_abandonments{$todayWhere}", $todayParams);

// Filter option lists should follow the selected period, but not hide values because of the other filters.
$optionWhere = ' WHERE 1=1'.$dateSql;
$sourceOptions = fetchAllSafe($pdo, "SELECT DISTINCT COALESCE(NULLIF(utm_source,''),'직접/기타') label FROM estimate_abandonments{$optionWhere} ORDER BY label");
$mediumOptions = fetchAllSafe($pdo, "SELECT DISTINCT COALESCE(NULLIF(utm_medium,''),'미지정') label FROM estimate_abandonments{$optionWhere} ORDER BY label");
$campaignOptions = fetchAllSafe($pdo, "SELECT DISTINCT COALESCE(NULLIF(utm_campaign,''),'미지정') label FROM estimate_abandonments{$optionWhere} ORDER BY label");

$sources = [];
$mediums = [];
$campaigns = [];
$recent = [];
$loadError = '';
try {
    $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(utm_source,''),'직접/기타') label,
        COUNT(*) cnt,
        SUM(is_completed) completed,
        ROUND(AVG(active_seconds)) avg_stay
        FROM estimate_abandonments{$where}
        GROUP BY label ORDER BY cnt DESC, label ASC");
    $stmt->execute($params);
    $sources = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(utm_medium,''),'미지정') label,
        COUNT(*) cnt,
        SUM(is_completed) completed,
        ROUND(AVG(active_seconds)) avg_stay
        FROM estimate_abandonments{$where}
        GROUP BY label ORDER BY cnt DESC, label ASC LIMIT 20");
    $stmt->execute($params);
    $mediums = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(utm_campaign,''),'미지정') label,
        COUNT(*) cnt,
        SUM(is_completed) completed,
        ROUND(AVG(active_seconds)) avg_stay
        FROM estimate_abandonments{$where}
        GROUP BY label ORDER BY cnt DESC, label ASC LIMIT 20");
    $stmt->execute($params);
    $campaigns = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, session_key, member_id, stage, vehicle_name, utm_source, utm_medium, utm_campaign,
        referrer, landing_page, is_completed, active_seconds, started_at, last_activity_at
        FROM estimate_abandonments{$where}
        ORDER BY started_at DESC LIMIT 100");
    $stmt->execute($params);
    $recent = $stmt->fetchAll();
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}
$stageLabels=['LANDING'=>'유입','VEHICLE_LIST'=>'차량목록','VEHICLE_DETAIL'=>'차량상세','TRIM_SELECTED'=>'트림선택','CONDITIONS_SELECTED'=>'조건선택','ESTIMATE_FORM'=>'신청폼','CUSTOMER_INPUT'=>'정보입력','COMPLETED'=>'신청완료'];

$queryBase = [
    'period'=>$period,
    'source'=>$sourceFilter,
    'medium'=>$mediumFilter,
    'campaign'=>$campaignFilter,
    'completed'=>$completedFilter,
];
?>
<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>유입 분석 - 오토지니 관리자</title>
<link rel="stylesheet" href="./sidebar.css">
<link rel="stylesheet" href="./traffic-page.css"><link rel="stylesheet" href="./admin-ui.css"><link rel="stylesheet" href="./date-range-picker.css"><script src="./date-range-picker.js" defer></script>
<link rel="stylesheet" href="./traffic-table.css"></head>
<body><div class="admin-shell">
<?php $currentAdminPage='traffic'; require __DIR__ . '/sidebar.php'; ?>
<main class="main">
    <section class="card ag-page-card">
        <div class="top"><div><h1>유입 분석</h1><p>유입경로별 방문 수, 평균 체류시간과 견적 전환을 필터로 비교합니다.</p></div><div class="top-stats"><span class="stat">조회 유입 <b><?=number_format($sessionCount)?></b></span><span class="stat">전환율 <b><?=h($conversion)?>%</b></span></div></div>
    <section class="filter-box">
        <form class="filter" method="get">
            <div><label>기간</label><select name="period"><option value="7" <?=$period==='7'?'selected':''?>>최근 7일</option><option value="30" <?=$period==='30'?'selected':''?>>최근 30일</option><option value="90" <?=$period==='90'?'selected':''?>>최근 90일</option><option value="all" <?=$period==='all'?'selected':''?>>전체 기간</option></select></div>
            <div><label>유입경로</label><select name="source"><option value="">전체 유입경로</option><?php foreach($sourceOptions as $r): $s=(string)$r['label']; ?><option value="<?=h($s)?>" <?=$sourceFilter===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select></div>
            <div><label>매체</label><select name="medium"><option value="">전체 매체</option><?php foreach($mediumOptions as $r): $s=(string)$r['label']; ?><option value="<?=h($s)?>" <?=$mediumFilter===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select></div>
            <div><label>캠페인</label><select name="campaign"><option value="">전체 캠페인</option><?php foreach($campaignOptions as $r): $s=(string)$r['label']; ?><option value="<?=h($s)?>" <?=$campaignFilter===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select></div>
            <div><label>견적 완료</label><select name="completed"><option value="">전체</option><option value="1" <?=$completedFilter==='1'?'selected':''?>>완료</option><option value="0" <?=$completedFilter==='0'?'selected':''?>>미완료</option></select></div>
            <div><button type="submit">조회</button></div>
            <div><a class="reset" href="./traffic.php">초기화</a></div>
        </form>
        <div class="filter-summary">현재 조회: <strong><?=number_format($sessionCount)?>건</strong> · 평균 체류시간 <strong><?=h(formatDuration($avgStaySeconds))?></strong> · 전환율 <strong><?=h($conversion)?>%</strong></div>
    </section>
    </section>

    <?php if ($loadError !== ''): ?><div class="error">유입 분석 데이터를 불러오지 못했습니다: <?=h($loadError)?></div><?php endif; ?>
    <div class="cards"><div class="metric"><span>오늘 유입</span><strong><?=number_format($todayCount)?></strong></div><div class="metric"><span>조회 유입</span><strong><?=number_format($sessionCount)?></strong></div><div class="metric"><span>평균 체류시간</span><strong><?=h(formatDuration($avgStaySeconds))?></strong></div><div class="metric"><span>견적 완료</span><strong><?=number_format($completedCount)?></strong></div><div class="metric"><span>견적 전환율</span><strong><?=h($conversion)?>%</strong></div></div>

    <div class="grid">
        <section class="panel"><div class="panel-head"><h2>유입경로별 평균 체류시간</h2></div>
            <div class="table-wrap"><table class="summary-table"><thead><tr><th>유입경로</th><th>유입 수</th><th>평균 체류시간</th><th>견적 완료</th><th>전환율</th></tr></thead><tbody>
            <?php foreach($sources as $r): $cnt=(int)$r['cnt']; $done=(int)$r['completed']; $rate=$cnt>0?round($done/$cnt*100,1):0; ?><tr><td class="name"><?=h($r['label'])?></td><td><?=number_format($cnt)?></td><td><b><?=h(formatDuration((int)$r['avg_stay']))?></b></td><td><?=number_format($done)?></td><td class="rate"><?=h($rate)?>%</td></tr><?php endforeach;?>
            <?php if(!$sources):?><tr><td class="empty" colspan="5">선택한 조건의 유입 데이터가 없습니다.</td></tr><?php endif;?></tbody></table></div>
        </section>

        <section class="panel"><div class="panel-head"><h2>매체별 평균 체류시간</h2></div>
            <div class="table-wrap"><table class="summary-table"><thead><tr><th>매체</th><th>유입 수</th><th>평균 체류시간</th><th>견적 완료</th><th>전환율</th></tr></thead><tbody>
            <?php foreach($mediums as $r): $cnt=(int)$r['cnt']; $done=(int)$r['completed']; $rate=$cnt>0?round($done/$cnt*100,1):0; ?><tr><td class="name"><?=h($r['label'])?></td><td><?=number_format($cnt)?></td><td><b><?=h(formatDuration((int)$r['avg_stay']))?></b></td><td><?=number_format($done)?></td><td class="rate"><?=h($rate)?>%</td></tr><?php endforeach;?>
            <?php if(!$mediums):?><tr><td class="empty" colspan="5">선택한 조건의 매체 데이터가 없습니다.</td></tr><?php endif;?></tbody></table></div>
        </section>
    </div>

    <section class="panel"><div class="panel-head"><h2>캠페인별 평균 체류시간</h2></div>
        <div class="table-wrap"><table class="summary-table"><thead><tr><th>캠페인</th><th>유입 수</th><th>평균 체류시간</th><th>견적 완료</th><th>전환율</th></tr></thead><tbody>
        <?php foreach($campaigns as $r): $cnt=(int)$r['cnt']; $done=(int)$r['completed']; $rate=$cnt>0?round($done/$cnt*100,1):0; ?><tr><td class="name"><?=h($r['label'])?></td><td><?=number_format($cnt)?></td><td><b><?=h(formatDuration((int)$r['avg_stay']))?></b></td><td><?=number_format($done)?></td><td class="rate"><?=h($rate)?>%</td></tr><?php endforeach;?>
        <?php if(!$campaigns):?><tr><td class="empty" colspan="5">선택한 조건의 캠페인 데이터가 없습니다.</td></tr><?php endif;?></tbody></table></div>
    </section>

    <section class="panel"><h2>광고용 UTM 링크 만들기</h2><div class="generator">
        <div class="wide"><label>랜딩 페이지 주소</label><input id="utmBase" type="text"></div>
        <div><label>유입경로 · utm_source</label><input id="utmSource" type="text" placeholder="예: naver, daangn, instagram"></div>
        <div><label>매체 · utm_medium</label><input id="utmMedium" type="text" placeholder="예: cpc, paid, social"></div>
        <div><label>캠페인 · utm_campaign</label><input id="utmCampaign" type="text" placeholder="예: august_importcar"></div>
        <div class="wide"><label>생성된 링크</label><div class="generator-output"><input id="utmResult" type="text" readonly><button class="copy-btn" id="copyUtm" type="button">링크 복사</button></div></div>
    </div></section>

    <section class="panel"><div class="panel-head"><h2>유입 내역</h2><span class="muted">현재 필터 기준 최근 100건 · 표를 좌우로 스크롤하여 확인</span></div><div class="table-wrap traffic-history-scroll"><table class="table traffic-history-table"><thead><tr><th>유입일시</th><th>체류시간</th><th>유입경로</th><th>매체</th><th>캠페인</th><th>회원 ID</th><th>최종 단계</th><th>차량</th><th>완료</th><th>랜딩 페이지</th><th>이전 페이지</th></tr></thead><tbody>
    <?php foreach($recent as $r):?><tr><td><?=h($r['started_at'])?></td><td><b><?=h(formatDuration((int)$r['active_seconds']))?></b></td><td><b><?=h($r['utm_source'] ?: '직접/기타')?></b></td><td><?=h($r['utm_medium'] ?: '미지정')?></td><td><?=h($r['utm_campaign'] ?: '미지정')?></td><td><?=h($r['member_id'] ?: '-')?></td><td><span class="pill"><?=h($stageLabels[$r['stage']]??$r['stage'])?></span></td><td><?=h($r['vehicle_name'] ?: '-')?></td><td><?=((int)$r['is_completed']===1)?'<span class="pill done">완료</span>':'-'?></td><td class="url"><div class="traffic-url-cell"><?php if(!empty($r['landing_page'])): ?><button class="traffic-url-copy" type="button" data-copy-url="<?=h($r['landing_page'])?>" data-original-label="<?=h($r['landing_page'])?>" title="전체 주소 복사: <?=h($r['landing_page'])?>" aria-label="랜딩 페이지 전체 주소 복사"><?=h($r['landing_page'])?></button><?php else: ?>-<?php endif; ?></div></td><td class="url"><div class="traffic-url-cell"><?php if(!empty($r['referrer'])): ?><button class="traffic-url-copy" type="button" data-copy-url="<?=h($r['referrer'])?>" data-original-label="<?=h($r['referrer'])?>" title="전체 주소 복사: <?=h($r['referrer'])?>" aria-label="이전 페이지 전체 주소 복사"><?=h($r['referrer'])?></button><?php else: ?>-<?php endif; ?></div></td></tr><?php endforeach;?>
    <?php if(!$recent):?><tr><td class="empty" colspan="11">선택한 필터 조건의 유입 내역이 없습니다.</td></tr><?php endif;?></tbody></table></div></section>
</main></div>
<script>
(function(){
  const base=document.getElementById('utmBase'), source=document.getElementById('utmSource'), medium=document.getElementById('utmMedium'), campaign=document.getElementById('utmCampaign'), result=document.getElementById('utmResult'), copy=document.getElementById('copyUtm');
  base.value=new URL('../db-test.html', location.href).href;
  function build(){
    try{
      const u=new URL(base.value.trim() || new URL('../db-test.html', location.href).href);
      [['utm_source',source.value],['utm_medium',medium.value],['utm_campaign',campaign.value]].forEach(([k,v])=>{v=v.trim(); if(v)u.searchParams.set(k,v); else u.searchParams.delete(k);});
      result.value=u.href;
    }catch(e){result.value='올바른 주소를 입력해주세요.';}
  }
  [base,source,medium,campaign].forEach(el=>el.addEventListener('input',build)); build();
  copy.addEventListener('click',async()=>{if(!result.value)return; try{await navigator.clipboard.writeText(result.value); const old=copy.textContent;copy.textContent='복사됨';setTimeout(()=>copy.textContent=old,1200);}catch(e){result.select();document.execCommand('copy');}});
})();
(function(){
  async function copyText(value){
    if(navigator.clipboard && window.isSecureContext){
      await navigator.clipboard.writeText(value);
      return;
    }
    const input=document.createElement('textarea');
    input.value=value;
    input.setAttribute('readonly','');
    input.style.position='fixed';
    input.style.opacity='0';
    document.body.appendChild(input);
    input.select();
    try{
      if(!document.execCommand('copy')) throw new Error('copy failed');
    } finally {
      input.remove();
    }
  }
  document.querySelectorAll('.traffic-url-copy').forEach(function(button){
    button.addEventListener('click',async function(){
      const url=button.dataset.copyUrl;
      if(!url) return;
      try{
        await copyText(url);
        button.textContent='복사됨';
      }catch(error){
        button.textContent='복사 실패';
      }
      clearTimeout(button._copyFeedbackTimer);
      button._copyFeedbackTimer=setTimeout(function(){button.textContent=button.dataset.originalLabel || url;},1500);
    });
  });
})();
</script></body></html>
