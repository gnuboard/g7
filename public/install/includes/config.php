<?php

/**
 * 그누보드7 웹 인스톨러 설정 파일
 *
 * 인스톨러의 기본 상수와 설정을 정의합니다.
 */

// 프로젝트 루트 경로 (public/install/includes에서 3단계 상위)
if (! defined('BASE_PATH')) {
    // `realpath` 도 호스팅이 막을 수 있는 함수다 (13차 환경#1). 이 줄은 인스톨러가 실행하는
    // 사실상 첫 코드라, 막힌 환경에서 무가드로 부르면 어떤 봉투도 서기 전에 치명 종료한다 —
    // 운영자에게는 백지만 남는다. 정규화는 못 해도 조립 경로로 설치는 진행된다.
    define('BASE_PATH', function_exists('realpath')
        ? (realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3))
        : dirname(__DIR__, 3));
}

// UTF-8 정규화 / JSON 출력 헬퍼 (의존성 0 — 가장 먼저 로드)
require_once __DIR__.'/utf8.php';

// 인스톨러 기본 URL
$installerPath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
if (! function_exists('installerDeriveBaseUrl')) {
    /**
     * 진입 스크립트 경로에서 인스톨러 기준 URL 을 파생합니다.
     *
     * API 엔드포인트(install/api/*.php)가 진입 스크립트인 요청에서는 dirname 이
     * `.../install/api` 가 된다. 이 꼬리를 먼저 벗겨야 아래 보정이 `/install/api/install` 을
     * 만들지 않는다 — 서버측 리다이렉트(예: task-runner 의 완료 redirect)와 웹 노출 프로브
     * 기준점(installer.js::getProjectRootUrl)이 이 값을 쓰므로 API 컨텍스트에서도 옳아야 한다.
     *
     * 꼬리 판정은 **대소문자를 구분하지 않는다**. 대소문자 무시 파일시스템(Windows·IIS·macOS)
     * 에서 운영자가 `/Install/` 로 접속하면 SCRIPT_NAME 이 그대로 들어오는데, 구분해서 재면
     * 「install 이 없다」로 읽혀 `/Install/install` 로 이중 조립되고 그 값을 쓰는 소비처가
     * 함께 깨진다.
     *
     * ## 요청 경로가 우선 근거다 (레이아웃 ②)
     *
     * 문서 루트 = 프로젝트 루트 배치에서는 루트 `.htaccess` 가 요청을 `public/` 아래로
     * **위임**한다. 그래서 브라우저는 `/install/` 에 있는데 `SCRIPT_NAME` 은
     * `/public/install/index.php` 다 — 그 값으로 파생하면 기준 URL 이 `/public/install` 이
     * 되어 실제 주소와 어긋난다. 그 어긋남은 그대로 전파된다: 화면이 프로젝트 루트를
     * 구하려고 `/public` 꼬리를 벗기는 추측을 하게 되고, 프로젝트 디렉토리 이름이 실제로
     * `public` 인 배치에서는 한 칸 위를 재게 된다.
     *
     * 요청 경로에는 그 위임이 반영되지 않으므로 그것을 먼저 본다. 위임이 없는 배치
     * (표준 배치·서브디렉토리)에서는 두 값이 같아 결과가 달라지지 않는다.
     *
     * ## 접두사를 벗기는 리버스 프록시 (`X-Forwarded-Prefix`)
     *
     * 프록시가 `/g7/install/` 을 `/install/` 로 벗겨 넘기면 요청 경로도 스크립트 경로도
     * 접두사를 모른다 — 기준 URL 이 사이트 밖(`/install`)이 되어 노출 프로브가 언제나
     * 「측정 불가」로 강등되고, 운영자는 그 사유를 알 수 없다 (13차 G3 W1-11). 프록시가
     * 벗긴 접두사를 알려 주는 관례 헤더가 있으면 그것을 앞에 붙인다. 값은 **경로 접두사로만**
     * 받는다: `/` 로 시작하고 `//`(프로토콜 상대)가 아니며, 세그먼트마다 unreserved 문자 ·
     * 유효한 퍼센트 인코딩 · 원시 UTF-8 만 허용하고 제어문자·공백·역슬래시·`.`/`..` 는
     * 버린다(`installerSanitizeForwardedPrefix`). 이 헤더는 신뢰 경계 밖에서 올 수 있어
     * 스킴·호스트를 실어 보내는 값은 버리고, 통과한 값은 정규화된 퍼센트 인코딩 형태다.
     * 요청 경로가 이미 그 접두사로 시작하면(벗기지 않는 프록시) 두 번 붙이지 않는다.
     *
     * @param  string  $scriptName  진입 스크립트 경로 ($_SERVER['SCRIPT_NAME'])
     * @param  string|null  $requestUri  요청 URI ($_SERVER['REQUEST_URI']) — 없으면 스크립트 경로만 본다
     * @param  string|null  $forwardedPrefix  프록시가 벗긴 접두사 ($_SERVER['HTTP_X_FORWARDED_PREFIX']) — 없으면 무시
     * @return string 인스톨러 기준 URL (후행 슬래시 없음)
     */
    function installerDeriveBaseUrl(string $scriptName, ?string $requestUri = null, ?string $forwardedPrefix = null): string
    {
        $prefix = installerSanitizeForwardedPrefix($forwardedPrefix);

        if ($requestUri !== null && $requestUri !== '') {
            $path = str_replace('\\', '/', (string) (parse_url($requestUri, PHP_URL_PATH) ?? ''));

            // 역슬래시 치환은 `parse_url` **뒤**라, 앞머리 역슬래시 두 개(`\\evil.com/…`)가
            // `//evil.com/…`(프로토콜 상대 = 다른 오리진)로 바뀐다 (17차 A-4). 그 값은
            // `<script src>`·`Location:`·`APP_URL` 에 실려 사이트 밖을 가리킨다. 접두사
            // 정제기가 프로토콜 상대를 거부하는 것과 **같은 판정**을 요청 경로 축에도 적용해,
            // 걸리면 이 분기를 버리고 `SCRIPT_NAME` 파생으로 떨어진다.
            //
            // 인코딩된 상위 디렉토리(`%2E%2E`)도 같은 자리에서 버린다 (17차 A-2). 접두사
            // 정제기는 세그먼트를 디코딩해 `..`/`.` 를 거부하는데(위 `dot_segment`), 요청 경로
            // 축에는 그 판정이 없어 `installerNormalizePathEncoding()` 이 `%2E%2E` 를 실제 `..`
            // 로 되살렸다 — `/g7/%2E%2E/%2E%2E/install` 이 `/g7/../../install` 이 되어 마운트
            // 밖을 가리켰다. 요청에 없던 dot-segment 를 기준 URL 에 만들지 않는다.
            if (! str_starts_with($path, '//')
                && preg_match('#^(.*/install)(/|$)#i', $path, $m) === 1
                && ! installerPathHasDotSegment($m[1])) {
                return installerPrependForwardedPrefix(rtrim($m[1], '/'), $prefix);
            }
        }

        $installerPath = str_replace('\\', '/', dirname(str_replace('\\', '/', $scriptName)));

        if ($installerPath === '.' || $installerPath === '/') {
            $installerPath = '';
        }

        if (strcasecmp(substr($installerPath, -4), '/api') === 0) {
            $installerPath = substr($installerPath, 0, -4);
        }

        if (strcasecmp(substr($installerPath, -8), '/install') !== 0) {
            $installerPath .= '/install';
        }

        return installerPrependForwardedPrefix($installerPath, $prefix);
    }
}

if (! function_exists('installerSanitizeForwardedPrefix')) {
    /**
     * `X-Forwarded-Prefix` 값을 경로 접두사로 정제합니다 — 조건에 맞지 않으면 빈 문자열.
     *
     * 이 값은 **값의 형태로만** 신뢰한다. 인스톨러에는 신뢰 프록시 목록을 넣을 통로가 없고,
     * 프록시 없이 직결된 배치에서는 이 헤더를 클라이언트가 그대로 보낼 수 있다 — 그리고
     * 그 값은 `Location:` 과 `<link href>`·`<script src>` 에 실린다. 그래서 차단 문자를
     * 열거하지 않고 **허용 문자만** 받는다. 열거 방식은 우회가 하나 드러날 때마다 한 글자씩
     * 뒤따라야 했다: 역슬래시는 WHATWG URL 파서가 `/\evil.com` 을 `//evil.com` 으로
     * 정규화해 다른 오리진이 되고, `"><script>` 는 속성 밖으로 탈출한다 (14차 G3 W2-1).
     * 세그먼트로는 `.`·`..` 를 거부한다 — 접두사는 오르내리는 경로가 아니다.
     *
     * 원시 문자로는 RFC 3986 pchar(unreserved `A-Za-z0-9._~-` · sub-delims `!$&'()*+,;=` ·
     * `:` `@`)·구분자 `/`·퍼센트 시퀀스·비ASCII 바이트만 받는다. 퍼센트 인코딩과 비ASCII 는
     * **세그먼트를 디코딩해 다시 판정**한다 — 디코딩 결과에 제어문자·`/`·`\`·`<>"` 가 있거나
     * UTF-8 이 아니면 버린다. 종전 화이트리스트는 이 둘을 통째로 버렸는데, 비ASCII 마운트
     * (`/한글/`)와 그 인코딩 형태는 **정상 배치**라 그 사이트에서는 기준 URL 이 마운트 밖이
     * 되어 인스톨러 자체가 404 였다 (15차 G3 W1-1). 통과한 값은 세그먼트별 `rawurlencode` 로
     * **정규화된 퍼센트 인코딩 한 형태**로 돌려준다 — 출력이 헤더·속성에 실리므로 원시 UTF-8
     * 을 그대로 내보내지 않는다.
     *
     * 같은 값의 두 표기(원시 `/g7,x` · 인코딩 `/g7%2Cx`)는 **같은 판정**을 받는다 (16차 G3 A6).
     * 종전에는 원시 허용이 unreserved 뿐이라 앞의 것만 버렸는데, 출력이 어차피 정규형이라
     * 표기 차이에 의미가 없다 — 어느 표기로 헤더를 보내느냐에 따라 인스톨러가 404 가 됐다.
     * 같은 이유로 디코딩 뒤 거부 집합에서 공백과 `'` 를 뺐다: 둘 다 정규형에서 `%20`·`%27`
     * 로 접혀 속성·헤더를 탈출하지 못하고, 이름에 공백이 든 디렉토리는 정상 마운트다. 원시
     * 공백은 여전히 버린다 — 헤더 값의 **형태**가 아니다. 중간 빈 세그먼트(`/a//b`)는 접는다
     * (A14) — `//` 로 **시작**하는 값은 그 전에 버려지므로 접기가 오리진을 바꾸지는 못한다.
     *
     * 버린 값은 사유·길이만 기록한다(`installerForwardedPrefixRejection`) — 값 자체는 신뢰
     * 경계 밖에서 온 것이라 로그에 싣지 않는다. 종전에는 버리는 순간 아무 흔적도 없어,
     * 프록시 뒤에서 인스톨러가 통째로 404 가 되는 이유를 운영자가 알 수 없었다 (15차 G3 W1-2).
     *
     * @param  string|null  $value  헤더 원문
     * @return string 정제된 접두사 (`/g7` 형태, 끝 슬래시 없음) 또는 빈 문자열
     */
    function installerSanitizeForwardedPrefix(?string $value): string
    {
        if ($value === null || $value === '') {
            installerForwardedPrefixRejection(null, true);

            return '';
        }

        $reject = static function (string $reason) use ($value): string {
            installerForwardedPrefixRejection(['reason' => $reason, 'length' => strlen($value)], true);

            if (function_exists('installerErrorLog')) {
                installerErrorLog('[installer] X-Forwarded-Prefix rejected (reason='.$reason.', length='.strlen($value).')');
            }

            return '';
        };

        // `/` 로 시작하되 `//` 는 아니어야 한다(프로토콜 상대 주소는 다른 오리진이다).
        if (str_starts_with($value, '//')) {
            return $reject('protocol_relative');
        }

        // 원시 허용 문자(pchar) 밖(제어문자·공백·역슬래시·큰따옴표·`<>` · 불완전한 `%` …)이
        // 하나라도 있으면 통째로 버린다.
        if (preg_match('#^/(?:[A-Za-z0-9._~!$&\'()*+,;=:@/-]|%[0-9A-Fa-f]{2}|[\x80-\xFF])*$#', $value) !== 1) {
            return $reject('disallowed_character');
        }

        $segments = [];

        foreach (explode('/', $value) as $segment) {
            // 빈 세그먼트(선두 `/` 의 왼쪽 · `//` · 끝 슬래시)는 접는다.
            if ($segment === '') {
                continue;
            }

            $decoded = rawurldecode($segment);

            if ($decoded === '.' || $decoded === '..') {
                return $reject('dot_segment');
            }

            // 디코딩 뒤에 제어문자·구분자·역슬래시·속성 탈출 문자가 나타나면 인코딩으로 감춘 것이다.
            if (preg_match('#[\x00-\x1F\x7F/\\\\<>"]#', $decoded) === 1) {
                return $reject('encoded_disallowed_character');
            }

            if (preg_match('//u', $decoded) !== 1) {
                return $reject('invalid_utf8');
            }

            $segments[] = rawurlencode($decoded);
        }

        installerForwardedPrefixRejection(null, true);

        return $segments === [] ? '' : '/'.implode('/', $segments);
    }
}

if (! function_exists('installerNormalizePathEncoding')) {
    /**
     * 경로를 세그먼트별 퍼센트 인코딩 정규형(`rawurlencode(rawurldecode())`)으로 만듭니다.
     *
     * 정제된 접두사가 이 형태이므로, 요청 경로와 대조하거나 이어 붙일 때 요청 경로도 같은
     * 형태로 만든다 — 원시 UTF-8·소문자 hex·`%7E` 같은 표기 차이가 대조를 깨지 않도록.
     *
     * @param  string  $path  `/` 로 시작하는 경로
     * @return string 같은 경로의 정규형
     */
    function installerNormalizePathEncoding(string $path): string
    {
        return implode('/', array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', $path)
        ));
    }
}

if (! function_exists('installerPathHasDotSegment')) {
    /**
     * 경로에 상위/현재 디렉토리 세그먼트(`.`·`..`)가 하나라도 있는지 봅니다 (17차 A-2).
     *
     * 판정은 **디코딩한 세그먼트**로 한다 — 접두사 정제기(`installerSanitizeForwardedPrefix`)의
     * `dot_segment` 거부와 같은 판정이다. `%2E%2E` 같은 인코딩 형태도 실제 `..` 로 보고 걸러야,
     * 정규화가 그것을 기준 URL 에 되살려 마운트 밖을 가리키게 만드는 것을 막는다.
     *
     * @param  string  $path  `/` 로 구분된 경로
     * @return bool 상위/현재 디렉토리 세그먼트가 있으면 true
     */
    function installerPathHasDotSegment(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '.' || $decoded === '..') {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('installerForwardedPrefixRejection')) {
    /**
     * 마지막 `installerSanitizeForwardedPrefix()` 호출이 값을 버렸는지 — 버렸으면 사유·길이.
     *
     * 값은 싣지 않는다. 노출 점검(`installerExposureProbePlan`)이 이 기록을 `unmeasured`
     * 사유(`forwarded_prefix_rejected`)로 화면에 올린다.
     *
     * @param  array{reason: string, length: int}|null  $set  갱신할 기록 (`$update` 가 true 일 때만)
     * @param  bool  $update  갱신 호출인지 (조회는 인수 없이)
     * @return array{reason: string, length: int}|null 마지막 거부 기록
     */
    function installerForwardedPrefixRejection(?array $set = null, bool $update = false): ?array
    {
        static $rejection = null;

        if ($update) {
            $rejection = $set;
        }

        return $rejection;
    }
}

if (! function_exists('installerPrependForwardedPrefix')) {
    /**
     * 파생한 기준 URL 앞에 프록시 접두사를 붙입니다 — 이미 그 접두사로 시작하면 그대로.
     *
     * 대조는 **같은 정규형**끼리 한다 (16차 G3 A1). 접두사는 정제기가 퍼센트 인코딩 정규형으로
     * 만들어 주는데 요청 경로는 브라우저가 친 표기 그대로다 — 비ASCII 마운트(`/한글/`)에서
     * 벗기지 않는 프록시가 접두사를 알려 주면 두 표기가 달라 대조가 언제나 불일치했고, 그
     * 결과 `/%ED%95%9C%EA%B8%80/한글/install` 처럼 접두사가 두 번 붙었다. 접두사가 있을 때의
     * 출력도 정규형이다 — 붙인 값이 `Location:`·속성·`APP_URL` 에 실린다. 접두사가 없으면
     * 종전대로 요청 경로의 표기를 보존한다(그 축은 이 결함과 무관하다).
     *
     * @param  string  $installerPath  파생한 기준 URL
     * @param  string  $prefix  정제된 접두사 (빈 문자열이면 무접두)
     * @return string 접두사가 반영된 기준 URL
     */
    function installerPrependForwardedPrefix(string $installerPath, string $prefix): string
    {
        // 정규화는 접두사 유무와 무관하게 **언제나** 돈다 (17차 A-1). 종전에는 접두사가 있을
        // 때만 정규형(`rawurlencode(rawurldecode())`)으로 만들고, 없으면 요청 경로의 원문
        // 표기를 그대로 돌려줬다 — 같은 마운트가 `/한글/install`(원시 UTF-8) 과
        // `/%ED%95%9C%EA%B8%80/install`(인코딩) 두 형태로 갈렸다. 이 값은 `<script src>`·
        // `Location:`·`APP_URL` 에 실리므로 축마다 다른 표기를 내보내면 그 자리가 갈린다.
        // 대소문자·unreserved 는 정규형에서도 원문대로 보존되어 기존 파생 결과는 바뀌지 않는다.
        if ($prefix === '') {
            return installerNormalizePathEncoding($installerPath);
        }

        $installerPath = installerNormalizePathEncoding($installerPath);

        if (strcasecmp($installerPath, $prefix) === 0 || stripos($installerPath, $prefix.'/') === 0) {
            return $installerPath;
        }

        return $prefix.$installerPath;
    }
}

// 인스톨러 기본 URL
$installerPath = installerDeriveBaseUrl(
    (string) $_SERVER['SCRIPT_NAME'],
    isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : null,
    isset($_SERVER['HTTP_X_FORWARDED_PREFIX']) ? (string) $_SERVER['HTTP_X_FORWARDED_PREFIX'] : null,
);

// 다음 define 들은 가드 필수 — 테스트 환경에서 같은 프로세스가 config.php 를 직접/간접
// 재진입(예: Laravel bootstrap + 테스트 require)할 수 있어 PHP 9 에서 fatal 이 되는
// 중복 정의 warning 을 차단.
if (! defined('INSTALLER_BASE_URL')) {
    define('INSTALLER_BASE_URL', $installerPath);
}

// 소프트웨어 최초 출시 연도 (저작권 표시용)
if (! defined('APP_RELEASE_YEAR')) {
    define('APP_RELEASE_YEAR', '2026');
}

// 최소 PHP 버전
if (! defined('MIN_PHP_VERSION')) {
    define('MIN_PHP_VERSION', '8.2.0');
}

// 필수 PHP 모듈
if (! defined('REQUIRED_EXTENSIONS')) {
    define('REQUIRED_EXTENSIONS', [
        'pdo',
        // 드라이버까지 본다 — `pdo` 만 검사하면 pdo_mysql 이 없는 환경이 요구사항을 통과한 뒤
        // DB 연결 테스트에서야 "could not find driver" 로 막힌다. G7 은 MySQL/MariaDB 만
        // 지원하므로 이 드라이버는 구동 자체의 전제다 (docs/requirements.md §1.4 「필수」).
        //
        // 근거 표기 정정 (17차): 종전 주석은 「13차 환경#21」을 인용했으나 그 번호는 계획서에도
        // 히스토리에도 없다. 존재하지 않는 근거는 다음 감사가 이 항목을 「기록 없는 변경」으로
        // 되돌리게 만든다 — 실제 근거는 위 규정 문서다.
        'pdo_mysql',
        'mbstring',
        'openssl',
        'tokenizer',
        'xml',
        'ctype',
        'json',
        'fileinfo',
        'curl',
        'dom',
        'filter',
        'hash',
        'pcre',
        'session',
    ]);
}

// 선택적 PHP 모듈 (설치를 권장하지만 필수는 아님)
if (! defined('OPTIONAL_EXTENSIONS')) {
    define('OPTIONAL_EXTENSIONS', [
        'zlib',     // gzip 압축 지원 (응답 압축에 사용)
        'gd',       // 이미지 처리
        'imagick',  // 고급 이미지 처리
        'redis',    // Redis 캐시 지원
        'intl',     // 국제화 지원
    ]);
}

// 최소 디스크 공간 (MB)
if (! defined('MIN_DISK_SPACE_MB')) {
    define('MIN_DISK_SPACE_MB', 500);
}

// 실사용 권장 여유 (docs/requirements.md §9 — 코어+기본 확장 실사용 약 700MB).
// 차단 기준(MIN_DISK_SPACE_MB)은 유지하고, 이 값 미만이면 경고만 덧붙인다
// (21차 #21-7, 제품 결정 — 기존 통과 환경을 막지 않는다).
if (! defined('RECOMMENDED_DISK_SPACE_MB')) {
    define('RECOMMENDED_DISK_SPACE_MB', 700);
}

// DB 테이블 접두사 최대 길이.
// MySQL identifier 한도(64자) 안에서 자동 생성 인덱스명이 안전하도록 제한한다.
// 가장 긴 자동 생성 인덱스명(접두사 제외)이 58자이므로 58 + 6 = 64 로 정확히 한도에 맞는다.
// 7자 이상 접두사는 일부 인덱스명이 65자가 되어 마이그레이션이 실패한다.
if (! defined('MAX_DB_PREFIX_LENGTH')) {
    define('MAX_DB_PREFIX_LENGTH', 6);
}

// 사이트명 길이 상한 — 이 값은 `.env` 의 APP_NAME 과 설정의 site_name 으로 그대로
// 흘러가 설치 직후 관리자·사용자 화면의 입력이 된다. 인스톨러 자신은 이 값을 화면에
// 그리지 않지만, 상한이 없으면 그 뒤의 모든 면이 상한 없는 문자열을 받게 된다.
if (! defined('MAX_APP_NAME_LENGTH')) {
    define('MAX_APP_NAME_LENGTH', 100);
}

// 디렉토리 권한 설정 (8진수)
// 업계 표준 755 (WordPress/Drupal/Joomla/Laravel 공통) — 실제 통과 기준은 is_writable() && is_readable()
if (! defined('REQUIRED_DIRECTORY_PERMISSIONS')) {
    define('REQUIRED_DIRECTORY_PERMISSIONS', 0755);
}

// 권한 표시용 문자열 (사용자에게 보여줄 형식)
if (! defined('REQUIRED_DIRECTORY_PERMISSIONS_DISPLAY')) {
    define('REQUIRED_DIRECTORY_PERMISSIONS_DISPLAY', '755');
}

// 권한 검증이 필요한 디렉토리 목록
// 값이 true인 경우 하위 디렉토리까지 재귀적으로 체크
if (! defined('REQUIRED_DIRECTORIES')) {
    define('REQUIRED_DIRECTORIES', [
        'storage' => true,
        'bootstrap/cache' => false,
        'vendor' => false,
        'modules' => false,
        'modules/_pending' => false,
        'plugins' => false,
        'plugins/_pending' => false,
        'templates' => false,
        'templates/_pending' => false,
        'public/build' => false,          // 초기 화면 정적 파일 게시 루트(public/build/ext)의 상위 — 설치 마지막 단계와 웹 렌더 자가 치유가 웹 계정으로 쓴다
        'lang-packs' => false,            // 언어팩 활성 디렉토리 (코어/모듈/플러그인/템플릿 lang-packs)
        'lang-packs/_pending' => false,   // 외부 ZIP/URL/GitHub 다운로드 staging
        'storage/app/core_pending' => false,  // 코어 업데이트 _pending (기본값, 커스텀 경로는 Step 3에서 설정)
    ]);
}

// 인스톨러 기본 설정값
if (! defined('DEFAULT_INSTALL_CONFIG')) {
    define('DEFAULT_INSTALL_CONFIG', [
        // Write DB 설정
        'db_write_host' => 'localhost',
        'db_write_port' => '3306',
        'db_write_database' => '',
        'db_write_username' => '',
        'db_write_password' => '',
        'db_prefix' => 'g7_',

        // Read DB 설정
        'use_read_db' => false,
        'db_read_host' => 'localhost',
        'db_read_port' => '3306',
        'db_read_database' => '',
        'db_read_username' => '',
        'db_read_password' => '',

        // 사이트 설정
        'app_name' => '그누보드7',
        'app_env' => 'production',

        // 관리자 계정 설정
        'admin_name' => '관리자',
        'admin_email' => '',
        'admin_password' => '',
        'admin_language' => 'ko',

        // 코어 업데이트 설정
        'core_update_pending_path' => '',  // 빈 값 = 기본값(storage/app/core_pending) 사용
        'core_update_github_url' => 'https://github.com/gnuboard/g7',
        'core_update_github_token' => '',  // GitHub Personal Access Token (프라이빗 저장소용)

        // PHP CLI / Composer 경로 설정
        'php_binary' => 'php',        // PHP CLI 바이너리 경로 (예: /usr/local/php82/bin/php)
        'composer_binary' => '',      // Composer 바이너리 경로 (빈 값 = 시스템 PATH 사용)

        // Vendor 설치 모드 (auto|composer|bundled)
        // - auto: composer 사용 가능 시 composer, 불가 시 vendor-bundle.zip 추출
        // - composer: 강제 composer 실행
        // - bundled: 강제 vendor-bundle.zip 추출 (공유 호스팅 환경)
        'vendor_mode' => 'auto',

        // 실행 모드 (auto|subprocess|inprocess) — Step 3 선택란의 기본값 (D33)
        // - auto: 환경을 감지해 정한다 (ExecutionEnvironment)
        // - subprocess: 항상 `php artisan` 을 별도 프로세스로 실행
        // - inprocess: 웹 요청 안에서 Laravel 을 부팅해 실행 (CLI 없는 호스팅)
        // 어휘 SSoT 는 코어 App\Execution\ExecutionMode 이고, 여기에는 기본값만 둔다.
        'execution_mode' => 'auto',
    ]);
}

// 진행 기록에서 「명령 원문」을 가려내는 표식 (§12.0 W3 — 진행 기록은 사람 말로 쓴다)
//
// 진행 기록(storage/logs/installation.log)은 SSE·폴링·단계별 실행 세 모드가 공유하는 단일
// 채널이라, 채널 자체를 나누면 세 모드의 재생 경로가 전부 갈라진다. 대신 줄에 표식을 붙여
// **화면이** 분류한다 — 로그 파일에는 그대로 남아 진단이 보존되고, 화면에서는 「자세히」
// 접기 영역으로 들어간다. 서버와 화면이 같은 리터럴을 써야 하므로 회귀 테스트가 두 소스를
// 대조한다(InstallerCommandOutputChannelTest).
if (! defined('INSTALLER_COMMAND_OUTPUT_PREFIX')) {
    define('INSTALLER_COMMAND_OUTPUT_PREFIX', '[cmd] ');
}

// 설치 단계별 파일 매핑
// Step 5 (installation)에서 완료/실패/중단 화면까지 모두 처리
if (! defined('STEP_FILE_MAP')) {
    define('STEP_FILE_MAP', [
        0 => 'welcome',
        1 => 'license',
        2 => 'requirements',
        3 => 'configuration',
        4 => 'extension-selection',
        5 => 'installation',
    ]);
}

// 인스톨러 기본 상태 정의
if (! defined('DEFAULT_INSTALLATION_STATE')) {
    define('DEFAULT_INSTALLATION_STATE', [
        'current_step' => 0,
        'step_status' => [
            '0' => 'pending',
            '1' => 'pending',
            '2' => 'pending',
            '3' => 'pending',
            '4' => 'pending',
            '5' => 'pending',
        ],
        'completed_tasks' => [],
        'current_task' => null,
        'current_task_name' => null,
        'installation_status' => 'not_started',
        'config' => [],
        'selected_extensions' => [
            'admin_templates' => [],
            'user_templates' => [],
            'modules' => [],
            'plugins' => [],
            'language_packs' => [],
        ],
        'extension_names' => [], // 확장 이름 매핑 (identifier → {ko: '...', en: '...'})
    ]);
}

// 지원 언어 목록 (언어 추가 시 이 한 곳만 수정)
if (! defined('SUPPORTED_LANGUAGES')) {
    define('SUPPORTED_LANGUAGES', [
        'ko' => '한국어 (Korean)',
        'en' => 'English',
    ]);
}

// 설치 완료 후 인스톨러 파일 삭제 여부
if (! defined('DELETE_INSTALLER_AFTER_COMPLETE')) {
    define('DELETE_INSTALLER_AFTER_COMPLETE', true);
}

// 코어와 공유하는 판정 SSoT 클래스 로드.
//
// 인스톨러는 Laravel 오토로드 없이 도는 순수 PHP 이므로 파일을 직접 require 한다.
// 두 클래스는 프레임워크 의존성이 0 이라 이 방식이 성립하며, 인스톨러와 코어가
// 서로 다른 판정을 내리는 것을 구조적으로 차단한다.
// class_exists 가드: Laravel 부트가 선행된 테스트 환경에서 중복 로드 방지.
//
// 오토로드를 **막지 않는다**(2번째 인자 생략). `false` 를 주면 오토로드 가능한
// 클래스를 못 본 채 require 로 내려가는데, BASE_PATH 를 임시 디렉토리로 바꿔 두는
// 인스톨러 단위 테스트에서는 그 경로에 app/Support 가 없어 fatal 이 된다.
// 순수 인스톨러 실행 시에는 등록된 오토로더가 없어 false 를 반환하므로 require 가 그대로 돈다.
// 경로는 execution-mode.php 의 로더와 같은 규칙으로 찾는다: 판정 클래스는 이 인스톨러와 같은
// 코드 트리에 있으므로 BASE_PATH(설치 대상 루트, 테스트에서는 temp)가 아니라 이 파일 위치를
// 기준으로 삼고, 트리 밖에 복제된 경우만 BASE_PATH 로 폴백한다. 두 지점이 서로 다른 규칙을
// 쓰면 같은 클래스를 서로 다른 파일에서 찾게 된다.
//
// 이 블록의 네 클래스는 **같은 규칙**을 쓴다 — 일부만 BASE_PATH 직접 조립으로 남기면
// 다음 사람이 어느 쪽을 따라야 할지 알 수 없고, 오토로더가 없고 BASE_PATH 가 코드 트리
// 밖을 가리키는 조합에서 그 일부만 fatal 이 된다.
$g7SharedClassRoot = dirname(__DIR__, 3);
if (! is_file($g7SharedClassRoot.'/app/Support/ExecutionEnvironment.php')) {
    $g7SharedClassRoot = BASE_PATH;
}
foreach ([
    'App\\Support\\PrivilegedDatabaseAccounts' => '/app/Support/PrivilegedDatabaseAccounts.php',
    'App\\Support\\OpcacheStatus' => '/app/Support/OpcacheStatus.php',
    // 실행 모드 판정 SSoT — PhpBinaryResolver 는 ExecutionEnvironment 의 probe 위임 대상이라 함께 로드
    'App\\Support\\PhpBinaryResolver' => '/app/Support/PhpBinaryResolver.php',
    'App\\Support\\ExecutionEnvironment' => '/app/Support/ExecutionEnvironment.php',
] as $g7SharedClass => $g7SharedClassFile) {
    if (! class_exists($g7SharedClass)) {
        require_once $g7SharedClassRoot.$g7SharedClassFile;
    }
}
unset($g7SharedClassRoot, $g7SharedClass, $g7SharedClassFile);
