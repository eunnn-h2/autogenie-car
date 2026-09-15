<?php
declare(strict_types=1);
const OPTION_FETCHER_VERSION = '2026-09-15-r3';

/**
 * 오토지니 - 제조사 공식 사이트 옵션 이미지 수집기
 *
 * 브라우저 실행:
 *   http://localhost/autogenie-car/tools/fetch_official_option_images.php
 *
 * 동작:
 * - data/vehicle-options-official.json의 차량/옵션을 읽음
 * - 각 제조사 공식 페이지에서 모델 페이지/특징 페이지를 찾음
 * - 옵션명과 이미지 alt/주변 설명이 일치하는 이미지에 한해서 다운로드
 * - images/options/{브랜드}/{차량명}/ 에 저장
 * - JSON의 option.image_path를 로컬 경로로 갱신
 *
 * 주의:
 * - 차량 외관 사진을 옵션 이미지 fallback으로 사용하지 않음
 * - 공식 페이지에서 의미 있는 일치 이미지를 찾지 못한 옵션은 빈 값으로 유지
 */

header('Content-Type: text/html; charset=utf-8');
@set_time_limit(0);
@ini_set('memory_limit', '512M');

// AJAX 응답에는 PHP 경고/공지 HTML이 섞이면 JSON 파싱이 깨집니다.
// AJAX 호출일 때는 화면 출력을 막고 모든 경고를 예외로 변환해 JSON으로 반환합니다.
$isAjaxRequest = isset($_GET['ajax']);
if ($isAjaxRequest) {
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
    ob_start();
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
}

$root = dirname(__DIR__);
$jsonPath = $root . '/data/vehicle-options-official.json';
$imageRoot = $root . '/images/options';

if (!is_file($jsonPath)) {
    http_response_code(500);
    exit('vehicle-options-official.json을 찾을 수 없습니다: ' . htmlspecialchars($jsonPath, ENT_QUOTES, 'UTF-8'));
}

function out_json(array $data, int $status = 200): never {
    // 혹시 이전에 발생한 경고/공지 출력이 버퍼에 있어도 JSON 앞에 붙지 않게 제거합니다.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = '{"success":false,"error":"JSON 응답 생성에 실패했습니다."}';
    }
    echo $json;
    exit;
}

function str_norm(string $value): string {
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = mb_strtolower($value, 'UTF-8');
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
}

function safe_segment(string $value): string {
    $value = trim($value);

    // Windows 파일명에서 사용할 수 없는 문자는 단순 치환합니다.
    // 파일명 정리 과정에서는 정규식을 쓰지 않아 modifier 오류를 원천 차단합니다.
    $value = strtr($value, [
        "\\" => "_",
        "/" => "_",
        ":" => "_",
        "*" => "_",
        "?" => "_",
        "\"" => "_",
        "<" => "_",
        ">" => "_",
        "|" => "_",
    ]);

    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value, " .\t\n\r\0\x0B");
}

function absolute_url(string $base, string $url): string {
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, 'javascript:')) return '';
    if (preg_match('~^https?://~i', $url)) return $url;
    if (str_starts_with($url, '//')) {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $url;
    }
    $bp = parse_url($base);
    if (!$bp || empty($bp['host'])) return '';
    $scheme = $bp['scheme'] ?? 'https';
    $host = $bp['host'];
    $port = isset($bp['port']) ? ':' . $bp['port'] : '';
    if (str_starts_with($url, '/')) return "$scheme://$host$port$url";
    $path = $bp['path'] ?? '/';
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    if ($dir === '.') $dir = '';
    $combined = "$scheme://$host$port$dir/$url";
    $parts = parse_url($combined);
    $segments = [];
    foreach (explode('/', $parts['path'] ?? '/') as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') array_pop($segments); else $segments[] = $seg;
    }
    return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? $host) . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/' . implode('/', $segments) . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

function http_fetch(string $url, bool $binary = false): array {
    $headers = [
        'Accept-Language: ko-KR,ko;q=0.9,en;q=0.7',
        'Accept: ' . ($binary ? 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8' : 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'),
        'Cache-Control: no-cache',
    ];
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150 Safari/537.36';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => $binary ? 30 : 25,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER => true,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok'=>false,'status'=>0,'body'=>'','content_type'=>'','url'=>$url,'error'=>$err];
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $body = substr($raw, $headerSize);
        curl_close($ch);
        return ['ok'=>$status >= 200 && $status < 400,'status'=>$status,'body'=>$body,'content_type'=>$contentType,'url'=>$finalUrl ?: $url,'error'=>''];
    }

    $ctx = stream_context_create(['http'=>[
        'method'=>'GET','header'=>"User-Agent: $ua\r\n" . implode("\r\n", $headers) . "\r\n",'timeout'=>25,'ignore_errors'=>true
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return ['ok'=>$body !== false,'status'=>$body !== false ? 200 : 0,'body'=>$body ?: '','content_type'=>'','url'=>$url,'error'=>$body === false ? 'HTTP fetch failed' : ''];
}

function brand_hub(string $brand, string $vehicle): string {
    if ($brand === '현대' && preg_match('/^(g70|g80|g90|gv60|gv70|gv80)/iu', $vehicle)) {
        return 'https://www.genesis.com/kr/ko/models/';
    }
    return match ($brand) {
        '현대' => 'https://www.hyundai.com/kr/ko/e/all-vehicles',
        '기아' => 'https://www.kia.com/kr/vehicles',
        'KGM' => 'https://www.kg-mobility.com/kr/',
        '르노' => 'https://www.renault.co.kr/',
        '쉐보레' => 'https://www.chevrolet.co.kr/',
        'BMW' => 'https://www.bmw.co.kr/ko/all-models.html',
        '벤츠' => 'https://www.mercedes-benz.co.kr/passengercars/models.html',
        '아우디' => 'https://www.audi.co.kr/ko/models/',
        '볼보' => 'https://www.volvocars.com/kr/cars/',
        '랜드로버' => 'https://www.landroverkorea.co.kr/',
        '폭스바겐' => 'https://www.volkswagen.co.kr/ko/models.html',
        '테슬라' => 'https://www.tesla.com/ko_kr/',
        default => '',
    };
}

function option_aliases(string $name): array {
    $aliases = [$name];
    $rules = [
        '헤드업' => ['헤드업 디스플레이','HUD','Head-Up Display','Head Up Display'],
        '서라운드' => ['서라운드 뷰','360° 카메라','360 카메라','Surround View','360 camera','SVM'],
        '어라운드' => ['어라운드 뷰','360° 카메라','360 카메라','Around View','Surround View'],
        '360° 카메라' => ['360° 카메라','360 camera','Surround View'],
        '후방 모니터' => ['후방 모니터','후방 카메라','Rear View Monitor','Rear Camera'],
        '전방 충돌' => ['전방 충돌방지 보조','Forward Collision-Avoidance Assist','FCA'],
        '후측방' => ['후측방 충돌방지','Blind-Spot','BCA','BVM'],
        '고속도로 주행' => ['고속도로 주행 보조','Highway Driving Assist','HDA'],
        '차로 유지' => ['차로 유지 보조','Lane Following Assist','LFA'],
        '스마트 크루즈' => ['스마트 크루즈 컨트롤','Smart Cruise Control','Adaptive Cruise Control','ACC'],
        '어댑티브 크루즈' => ['Adaptive Cruise','어댑티브 크루즈','ACC'],
        '파노라믹 와이드' => ['파노라믹 와이드 디스플레이','Panoramic Wide Display'],
        '파노라마 글라스' => ['파노라마 글라스 루프','Panoramic Roof','Panoramic Glass Roof'],
        '글래스 루프' => ['Glass Roof','글래스 루프','Panoramic Roof'],
        '디지털 키' => ['디지털 키','Digital Key','KEYLESS-GO'],
        'KEYLESS-GO' => ['KEYLESS-GO','Digital Key','디지털 키'],
        '커브드 디스플레이' => ['BMW Curved Display','Curved Display','커브드 디스플레이'],
        '오퍼레이팅 시스템' => ['Operating System','BMW Operating System','오퍼레이팅 시스템'],
        '드라이빙 어시스턴트' => ['Driving Assistant','드라이빙 어시스턴트'],
        '파킹 어시스턴트' => ['Parking Assistant','파킹 어시스턴트','Park Assist'],
        'MBUX' => ['MBUX','인포테인먼트'],
        '앰비언트' => ['Ambient Light','Ambient Lighting','앰비언트 라이트'],
        '주차 패키지' => ['Parking Package','주차 패키지','Park Assist'],
        'virtual cockpit' => ['Audi virtual cockpit','Virtual Cockpit'],
        'MMI' => ['MMI','MMI touch','MMI display'],
        'drive select' => ['Audi drive select','drive select'],
        'Pilot Assist' => ['Pilot Assist'],
        'BLIS' => ['BLIS','Blind Spot Information'],
        'Pivi Pro' => ['Pivi Pro'],
        'Terrain Response' => ['Terrain Response'],
        'ClearSight' => ['ClearSight'],
        'Meridian' => ['Meridian'],
        '오토파일럿' => ['Autopilot','오토파일럿'],
        '중앙 터치스크린' => ['Touchscreen','터치스크린','Central Display'],
        '회생제동' => ['Regenerative Braking','회생제동','i-PEDAL'],
        'V2L' => ['V2L','Vehicle to Load'],
        '열선' => ['열선 시트','통풍 시트','Heated Seat','Ventilated Seat'],
        '통풍' => ['통풍 시트','Ventilated Seat'],
        '파워 테일게이트' => ['Power Tailgate','Smart Power Tailgate','파워 테일게이트'],
        '무선 소프트웨어' => ['OTA','Over-the-Air','무선 소프트웨어 업데이트'],
        '인포테인먼트' => ['Infotainment','인포테인먼트','Navigation','내비게이션'],
        '내비게이션' => ['Navigation','내비게이션','Infotainment'],
    ];
    foreach ($rules as $needle => $vals) {
        if (mb_stripos($name, $needle, 0, 'UTF-8') !== false) array_push($aliases, ...$vals);
    }
    return array_values(array_unique(array_filter($aliases)));
}

function parse_html_assets(string $html, string $pageUrl): array {
    if ($html === '') return ['images'=>[], 'links'=>[]];
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    $xp = new DOMXPath($dom);
    $images = [];
    foreach ($xp->query('//img') ?: [] as $img) {
        if (!$img instanceof DOMElement) continue;
        $src = '';
        foreach (['src','data-src','data-lazy-src','data-original','data-image-src','data-desktop-src','data-mobile-src','data-pc-src','data-img-src'] as $attr) {
            $v = trim($img->getAttribute($attr));
            if ($v !== '' && !str_starts_with($v, 'data:')) { $src = $v; break; }
        }
        if ($src === '') {
            $srcset = trim($img->getAttribute('srcset'));
            if ($srcset === '') $srcset = trim($img->getAttribute('data-srcset'));
            if ($srcset !== '') {
                $first = trim(explode(',', $srcset)[0] ?? '');
                $src = preg_split('/\s+/', $first)[0] ?? '';
            }
        }
        $src = absolute_url($pageUrl, $src);
        if ($src === '') continue;
        $alt = trim($img->getAttribute('alt') . ' ' . $img->getAttribute('title') . ' ' . $img->getAttribute('aria-label'));
        $ctx = $alt;
        $node = $img->parentNode;
        for ($i=0; $i<3 && $node; $i++, $node=$node->parentNode) {
            $txt = trim(preg_replace('/\s+/u', ' ', (string)$node->textContent));
            if ($txt !== '') $ctx .= ' ' . mb_substr($txt, 0, 500, 'UTF-8');
        }
        $images[] = ['url'=>$src,'context'=>$ctx,'alt'=>$alt,'page'=>$pageUrl];
    }
    foreach ($xp->query('//source[@srcset]') ?: [] as $source) {
        if (!$source instanceof DOMElement) continue;
        $srcset = trim($source->getAttribute('srcset'));
        $first = trim(explode(',', $srcset)[0] ?? '');
        $src = preg_split('/\s+/', $first)[0] ?? '';
        $src = absolute_url($pageUrl, $src);
        if ($src === '') continue;
        $parent = $source->parentNode;
        $ctx = $parent ? trim(preg_replace('/\s+/u', ' ', (string)$parent->textContent)) : '';
        $images[] = ['url'=>$src,'context'=>$ctx,'alt'=>'','page'=>$pageUrl];
    }
    // Open Graph / image_src 등 대표 자산도 후보로 수집합니다.
    foreach ($xp->query('//meta[@content] | //link[@href]') ?: [] as $node) {
        if (!$node instanceof DOMElement) continue;
        $prop = mb_strtolower(trim($node->getAttribute('property') . ' ' . $node->getAttribute('name') . ' ' . $node->getAttribute('rel')), 'UTF-8');
        if (!str_contains($prop, 'image') && !str_contains($prop, 'thumbnail')) continue;
        $raw = trim($node->getAttribute('content') ?: $node->getAttribute('href'));
        $u = absolute_url($pageUrl, $raw);
        if ($u !== '') $images[] = ['url'=>$u,'context'=>$prop,'alt'=>'','page'=>$pageUrl];
    }

    // 공식 사이트 상당수가 CSS background-image 또는 JSON/스크립트 안에 이미지 URL을 둡니다.
    // URL 주변의 HTML 텍스트를 context로 같이 저장해 옵션명 매칭에 사용합니다.
    $decodedHtml = html_entity_decode(str_replace('\\/', '/', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $patterns = [
        '~url\\([\\"\\\']?([^\\)\\"\\\']+\\.(?:jpe?g|png|webp|avif))(?:\\?[^\\)\\"\\\']*)?[\\"\\\']?\\)~iu',
        '~https?://[^\\s\\"\\\'<>]+\\.(?:jpe?g|png|webp|avif)(?:\\?[^\\s\\"\\\'<>]*)?~iu',
        '~[\\"\\\']([^\\"\\\']+\\.(?:jpe?g|png|webp|avif)(?:\\?[^\\"\\\']*)?)[\\"\\\']~iu',
    ];
    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $decodedHtml, $mm, PREG_OFFSET_CAPTURE)) continue;
        $group = isset($mm[1]) && $mm[1] ? $mm[1] : $mm[0];
        foreach ($group as $m) {
            $rawUrl = trim((string)$m[0]);
            $offset = (int)$m[1];
            $u = absolute_url($pageUrl, $rawUrl);
            if ($u === '') continue;
            $start = max(0, $offset - 500);
            $ctxRaw = substr($decodedHtml, $start, 1100);
            $ctx = trim(preg_replace('~\\s+~u', ' ', strip_tags($ctxRaw)) ?? '');
            $images[] = ['url'=>$u,'context'=>$ctx,'alt'=>'','page'=>$pageUrl];
        }
    }

    // 같은 URL 중복 제거
    $uniqImages = [];
    foreach ($images as $candidate) {
        if (!empty($candidate['url'])) $uniqImages[$candidate['url']] = $candidate;
    }
    $images = array_values($uniqImages);

    $links = [];
    foreach ($xp->query('//a[@href]') ?: [] as $a) {
        if (!$a instanceof DOMElement) continue;
        $href = absolute_url($pageUrl, $a->getAttribute('href'));
        if ($href === '' || !preg_match('~^https?://~i', $href)) continue;
        $text = trim(preg_replace('/\s+/u', ' ', (string)$a->textContent));
        $links[] = ['url'=>$href,'text'=>$text];
    }
    return ['images'=>$images,'links'=>$links];
}

function same_official_host(string $a, string $b): bool {
    $ha = preg_replace('/^www\./', '', strtolower((string)parse_url($a, PHP_URL_HOST)));
    $hb = preg_replace('/^www\./', '', strtolower((string)parse_url($b, PHP_URL_HOST)));
    if ($ha === '' || $hb === '') return false;
    return $ha === $hb || str_ends_with($ha, '.' . $hb) || str_ends_with($hb, '.' . $ha);
}

function discover_model_pages(string $hub, string $vehicle, string $explicitSource): array {
    $urls = [];
    if ($explicitSource !== '') $urls[] = $explicitSource;
    if ($hub !== '') $urls[] = $hub;

    $fetch = http_fetch($hub ?: $explicitSource);
    if ($fetch['ok']) {
        $assets = parse_html_assets($fetch['body'], $fetch['url']);
        $vn = str_norm($vehicle);
        $scored = [];
        foreach ($assets['links'] as $link) {
            if (!same_official_host($fetch['url'], $link['url'])) continue;
            $hay = str_norm($link['text'] . ' ' . $link['url']);
            if ($vn !== '' && str_contains($hay, $vn)) $scored[$link['url']] = 100;
            else {
                $tokens = preg_split('/\s+/u', trim($vehicle)) ?: [];
                $score = 0;
                foreach ($tokens as $t) {
                    $tn = str_norm($t);
                    if (mb_strlen($tn, 'UTF-8') >= 2 && str_contains($hay, $tn)) $score += 15;
                }
                if ($score > 0) $scored[$link['url']] = max($scored[$link['url']] ?? 0, $score);
            }
        }
        arsort($scored);
        foreach (array_slice(array_keys($scored), 0, 4) as $u) $urls[] = $u;
    }
    return array_values(array_unique(array_filter($urls)));
}

function feature_pages(array $basePages): array {
    $pages = $basePages;
    foreach (array_slice($basePages, 0, 3) as $page) {
        $f = http_fetch($page);
        if (!$f['ok']) continue;
        $assets = parse_html_assets($f['body'], $f['url']);
        $keywords = ['feature','features','technology','safety','convenience','interior','design','highlight','highlights','특징','기술','안전','편의','내장','사양'];
        $candidates = [];
        foreach ($assets['links'] as $link) {
            if (!same_official_host($f['url'], $link['url'])) continue;
            $hay = mb_strtolower($link['text'] . ' ' . $link['url'], 'UTF-8');
            foreach ($keywords as $kw) {
                if (mb_stripos($hay, $kw, 0, 'UTF-8') !== false) {
                    $candidates[$link['url']] = 1;
                    break;
                }
            }
        }
        foreach (array_slice(array_keys($candidates), 0, 4) as $u) $pages[] = $u;
    }
    return array_values(array_unique($pages));
}

function score_image_for_option(array $img, string $optionName): int {
    $context = str_norm((string)$img['context']);
    $urlNorm = str_norm((string)$img['url']);
    $score = 0;
    foreach (option_aliases($optionName) as $idx => $alias) {
        $n = str_norm($alias);
        if ($n === '') continue;
        if (str_contains($context, $n)) $score += $idx === 0 ? 100 : 35;
        elseif (str_contains($urlNorm, $n)) $score += $idx === 0 ? 55 : 20;
    }
    $tokens = preg_split('/[^\p{L}\p{N}]+/u', $optionName) ?: [];
    foreach ($tokens as $token) {
        $n = str_norm($token);
        if (mb_strlen($n, 'UTF-8') < 2) continue;
        if (str_contains($context, $n)) $score += 8;
        if (str_contains($urlNorm, $n)) $score += 3;
    }
    // 옵션 의미가 안 맞는 일반 차량/외장 갤러리 이미지는 제외 쪽으로 가중치 차감
    $bad = ['exterior','front','rear','side','외장','전면','후면','측면','360vr'];
    $optionN = str_norm($optionName);
    foreach ($bad as $b) {
        if ((str_contains($context, str_norm($b)) || str_contains($urlNorm, str_norm($b))) && !str_contains($optionN, str_norm($b))) $score -= 12;
    }
    return $score;
}

function extension_for_image(string $url, string $contentType): string {
    $ext = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','webp','gif','avif'], true)) return $ext === 'jpeg' ? 'jpg' : $ext;
    $ct = strtolower($contentType);
    return match (true) {
        str_contains($ct, 'png') => 'png',
        str_contains($ct, 'webp') => 'webp',
        str_contains($ct, 'avif') => 'avif',
        str_contains($ct, 'gif') => 'gif',
        default => 'jpg',
    };
}

function save_official_image(string $url, string $brand, string $vehicle, string $optionName, int $order, string $root, string $imageRoot): array {
    $r = http_fetch($url, true);
    if (!$r['ok'] || strlen($r['body']) < 1000) return ['ok'=>false,'error'=>$r['error'] ?: ('HTTP ' . $r['status'])];
    $ct = strtolower((string)$r['content_type']);
    if ($ct !== '' && !str_contains($ct, 'image')) return ['ok'=>false,'error'=>'이미지 MIME 타입이 아닙니다.'];
    $ext = extension_for_image($r['url'], $ct);
    $brandDir = safe_segment($brand);
    $vehicleDir = safe_segment($vehicle);
    $dir = $imageRoot . '/' . $brandDir . '/' . $vehicleDir;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return ['ok'=>false,'error'=>'이미지 폴더 생성 실패'];
    $base = sprintf('%02d_%s.%s', $order, safe_segment($optionName), $ext);
    $abs = $dir . '/' . $base;
    if (@file_put_contents($abs, $r['body']) === false) return ['ok'=>false,'error'=>'이미지 저장 실패'];
    $rel = 'images/options/' . $brandDir . '/' . $vehicleDir . '/' . $base;
    return ['ok'=>true,'path'=>$rel,'source_url'=>$r['url']];
}

function process_vehicle(array &$vehicleRec, string $key, string $root, string $imageRoot): array {
    $brand = (string)($vehicleRec['brand_name'] ?? explode('::', $key, 2)[0] ?? '');
    $vehicle = (string)($vehicleRec['vehicle_name'] ?? explode('::', $key, 2)[1] ?? '');
    $explicit = trim((string)($vehicleRec['source_url'] ?? ''));
    $hub = brand_hub($brand, $vehicle);
    $basePages = discover_model_pages($hub, $vehicle, $explicit);
    $pages = feature_pages($basePages);

    $allImages = [];
    $usedPages = [];
    foreach (array_slice($pages, 0, 7) as $page) {
        $f = http_fetch($page);
        if (!$f['ok']) continue;
        $usedPages[] = $f['url'];
        $assets = parse_html_assets($f['body'], $f['url']);
        foreach ($assets['images'] as $img) $allImages[$img['url']] = $img;
    }
    $allImages = array_values($allImages);

    $matched = 0; $failed = 0; $details = [];
    foreach (($vehicleRec['options'] ?? []) as $i => &$option) {
        $optionName = (string)($option['option_name'] ?? '');
        if ($optionName === '') continue;
        // 실제 로컬 파일이 존재하는 옵션 이미지만 유지합니다.
        // JSON에 경로만 있고 파일이 없는 경우에는 다시 공식 사이트에서 수집합니다.
        $existing = trim((string)($option['image_path'] ?? ''));
        if ($existing !== '' && str_starts_with($existing, 'images/options/')) {
            $existingAbs = $root . '/' . ltrim(str_replace('\\', '/', $existing), '/');
            if (is_file($existingAbs) && filesize($existingAbs) > 500) {
                $details[] = ['option'=>$optionName,'status'=>'keep','path'=>$existing];
                $matched++;
                continue;
            }
            $option['image_path'] = '';
            unset($option['image_source_url'], $option['image_source_page'], $option['image_match_score']);
        }
        $best = null; $bestScore = -999;
        foreach ($allImages as $img) {
            $s = score_image_for_option($img, $optionName);
            if ($s > $bestScore) { $bestScore = $s; $best = $img; }
        }
        // 엄격하게 매칭. 낮은 점수는 차량 외관사진 대체 방지를 위해 사용하지 않음.
        if (!$best || $bestScore < 30) {
            $option['image_path'] = '';
            $details[] = ['option'=>$optionName,'status'=>'not_found','score'=>$bestScore];
            $failed++;
            continue;
        }
        $saved = save_official_image((string)$best['url'], $brand, $vehicle, $optionName, $i + 1, $root, $imageRoot);
        if ($saved['ok']) {
            $option['image_path'] = $saved['path'];
            $option['image_source_url'] = $saved['source_url'];
            $option['image_source_page'] = $best['page'];
            $option['image_match_score'] = $bestScore;
            $matched++;
            $details[] = ['option'=>$optionName,'status'=>'saved','path'=>$saved['path'],'score'=>$bestScore,'source'=>$saved['source_url']];
        } else {
            $option['image_path'] = '';
            $failed++;
            $details[] = ['option'=>$optionName,'status'=>'download_failed','score'=>$bestScore,'error'=>$saved['error'] ?? ''];
        }
    }
    unset($option);

    if ($usedPages) {
        $vehicleRec['image_source_pages'] = array_values(array_unique($usedPages));
        // 실제로 찾은 모델/기능 페이지를 우선 source_url에 기록
        $vehicleRec['source_url'] = $usedPages[0];
    }
    $vehicleRec['image_checked_at'] = date('Y-m-d');
    return ['key'=>$key,'brand'=>$brand,'vehicle'=>$vehicle,'matched'=>$matched,'failed'=>$failed,'pages'=>$usedPages,'details'=>$details];
}

$raw = file_get_contents($jsonPath);
$catalog = json_decode((string)$raw, true);
if (!is_array($catalog) || !isset($catalog['vehicles']) || !is_array($catalog['vehicles'])) {
    http_response_code(500);
    exit('옵션 JSON 형식이 올바르지 않습니다.');
}

if (isset($_GET['ajax'])) {
    try {
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $limit = max(1, min(3, (int)($_GET['limit'] ?? 2)));
        $keys = array_keys($catalog['vehicles']);
        $slice = array_slice($keys, $offset, $limit);
        $results = [];

        foreach ($slice as $key) {
            try {
                $results[] = process_vehicle($catalog['vehicles'][$key], $key, $root, $imageRoot);
            } catch (Throwable $vehicleError) {
                $rec = $catalog['vehicles'][$key] ?? [];
                $results[] = [
                    'key' => $key,
                    'brand' => (string)($rec['brand'] ?? ''),
                    'vehicle' => (string)($rec['vehicle'] ?? $rec['name'] ?? $key),
                    'matched' => 0,
                    'failed' => count($rec['options'] ?? []),
                    'pages' => [],
                    'details' => [],
                    'error' => $vehicleError->getMessage(),
                ];
            }
        }

        $catalog['meta']['option_images'] = '제조사 공식 페이지에서 옵션 의미와 일치한 이미지만 로컬 저장. 미확인 항목은 빈 이미지 유지.';
        $catalog['meta']['image_fetch_updated_at'] = date('c');
        $encodedCatalog = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encodedCatalog === false) {
            throw new RuntimeException('옵션 JSON 저장용 데이터 변환에 실패했습니다.');
        }
        if (@file_put_contents($jsonPath, $encodedCatalog, LOCK_EX) === false) {
            throw new RuntimeException('vehicle-options-official.json 저장에 실패했습니다. 파일 쓰기 권한을 확인해주세요.');
        }

        restore_error_handler();
        out_json([
            'success' => true,
            'offset' => $offset,
            'processed' => count($slice),
            'next_offset' => $offset + count($slice),
            'total' => count($keys),
            'done' => ($offset + count($slice)) >= count($keys),
            'results' => $results,
        ]);
    } catch (Throwable $e) {
        restore_error_handler();
        out_json([
            'success' => false,
            'error' => $e->getMessage(),
            'offset' => (int)($_GET['offset'] ?? 0),
        ], 500);
    }
}

$total = count($catalog['vehicles']);
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>공식 옵션 이미지 수집</title>
<style>
body{font-family:Arial,'Noto Sans KR',sans-serif;background:#f4f6f8;color:#18212b;margin:0;padding:30px}
.wrap{max-width:960px;margin:auto;background:#fff;border:1px solid #dfe5ea;border-radius:14px;padding:24px}
h1{font-size:22px;margin:0 0 8px}.desc{color:#607080;font-size:14px;line-height:1.7}.notice{background:#f7f8ff;border:1px solid #dcd8ff;padding:12px 14px;border-radius:10px;margin:18px 0;font-size:13px;line-height:1.7}
button{border:0;background:#3924b9;color:#fff;font-weight:700;padding:11px 18px;border-radius:8px;cursor:pointer}button:disabled{opacity:.5;cursor:default}
.progress{height:12px;background:#edf0f3;border-radius:20px;overflow:hidden;margin:18px 0}.bar{height:100%;width:0;background:#3924b9;transition:.2s}.stat{font-size:14px;font-weight:700;margin-bottom:10px}.log{height:420px;overflow:auto;background:#111923;color:#d8e4ee;padding:14px;border-radius:10px;font:12px/1.6 Consolas,monospace;white-space:pre-wrap}.ok{color:#68e096}.warn{color:#ffc96b}.error{color:#ff8c8c}
</style>
</head>
<body><div class="wrap">
<h1>제조사 공식 옵션 이미지 수집</h1>
<p style="margin:4px 0 14px;color:#7b8794;font-size:12px;">수집기 버전 <?= htmlspecialchars(OPTION_FETCHER_VERSION, ENT_QUOTES, 'UTF-8') ?></p>
<div class="desc">등록 차량 <?= number_format($total) ?>대의 옵션 이미지를 각 제조사 공식 사이트에서 찾아 프로젝트 내부에 저장합니다.</div>
<div class="notice"><b>수집 기준</b><br>옵션명과 공식 페이지 이미지의 alt/설명이 일치하는 경우만 저장합니다. 일치도가 낮은 차량 외관·갤러리 사진은 대체 이미지로 사용하지 않습니다.<br>저장 위치: <code>images/options/브랜드/차량명/</code></div>
<button id="start">수집 시작</button>
<div class="progress"><div class="bar" id="bar"></div></div>
<div class="stat" id="stat">대기 중 · 0 / <?= (int)$total ?></div>
<div class="log" id="log"></div>
</div>
<script>
const total=<?= (int)$total ?>;let offset=0;let running=false;
const btn=document.getElementById('start'),log=document.getElementById('log'),bar=document.getElementById('bar'),stat=document.getElementById('stat');
function line(text,cls=''){const d=document.createElement('div');if(cls)d.className=cls;d.textContent=text;log.appendChild(d);log.scrollTop=log.scrollHeight}
async function batch(){
 if(!running)return;
 try{
  const r=await fetch(`?ajax=1&offset=${offset}&limit=2`,{cache:'no-store'});
  const raw=await r.text();
  let data;
  try{data=JSON.parse(raw)}catch(parseError){
    const preview=raw.replace(/\s+/g,' ').slice(0,240);
    throw new Error(`서버 응답이 JSON이 아닙니다. ${preview||'(빈 응답)'}`);
  }
  if(!r.ok||!data.success)throw new Error(data.error||`수집 실패 (HTTP ${r.status})`);
  for(const v of data.results){
    line(`\n[${v.brand} / ${v.vehicle}]  성공 ${v.matched} · 미확인 ${v.failed}`,v.failed?'warn':'ok');
    if(v.error)line(`  ! 처리 오류: ${v.error}`,'error');
    for(const x of v.details){if(x.status==='saved')line(`  ✓ ${x.option} → ${x.path}`,'ok');else if(x.status==='keep')line(`  = ${x.option} → 기존 이미지 유지`);else line(`  - ${x.option} → 공식 옵션 이미지 미확인`,'warn')}
  }
  offset=data.next_offset;bar.style.width=Math.min(100,offset/total*100)+'%';stat.textContent=`수집 중 · ${Math.min(offset,total)} / ${total}`;
  if(data.done){running=false;btn.disabled=false;btn.textContent='다시 확인';stat.textContent=`완료 · ${total} / ${total}`;line('\n전체 차량 확인이 완료되었습니다.','ok');return}
  setTimeout(batch,250);
 }catch(e){running=false;btn.disabled=false;btn.textContent='이어서 수집';line('오류: '+e.message,'error')}
}
btn.addEventListener('click',()=>{if(running)return;running=true;btn.disabled=true;btn.textContent='수집 중...';line('공식 사이트 확인을 시작합니다.');batch()});
</script>
</body></html>
