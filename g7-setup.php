<?php

declare(strict_types=1);

/**
 * G7 압축 풀기 부트스트랩 (ZIP Bootstrap) — FTP 전용 계정용 단일 진입 파일
 *
 * 쉘도 PHP CLI 도 쓸 수 없고 FTP 만 주어지는 호스팅에서는 배포 ZIP 을 서버에서 풀 수단이
 * 없다. FTP 클라이언트로 수만 개 파일을 그대로 올리면 수 시간이 걸리고 중간에 끊긴다.
 * 이 파일 하나와 배포 ZIP 만 올린 뒤 브라우저로 이 파일을 열면 같은 폴더에 ZIP 을 푼다.
 *
 * ── 자기 완결성 (이 파일이 다른 파일을 require 하지 않는 이유) ──────────────────
 * 이 파일이 도는 시점에는 **아직 아무것도 풀리지 않았다.** `public/install/includes/**`
 * 도 `vendor/` 도 없다. 그래서 이 파일은 인스톨러의 헬퍼를 쓸 수 없고, 필요한 판정을
 * 자기 안에 갖는다. 사본이 갈리는 위험은 계약 테스트가 막는다 —
 * `tests/Unit/Installer/ZipBootstrapContractTest.php` 가 이 파일의 완료 판정과
 * `public/install/api/_guard.php` 의 판정이 같은 규칙인지 대조한다.
 *
 * ── 설계 결정 ──────────────────────────────────────────────────────────────
 *  · 해제 전략은 `ZipArchive` **단독**이다. `PharData` 는 경로 100바이트 제한으로 파일을
 *    조용히 누락시킨 선례가 있어 쓰지 않는다(코어 아카이브 추출에서 이미 제거된 전략).
 *    `tar`·`unzip` 같은 외부 명령도 쓰지 않는다 — 이 파일의 존재 이유가 그 명령을 쓸 수
 *    없는 환경이다.
 *  · 대용량 ZIP 은 한 요청 안에서 끝나지 않는다. 처리한 엔트리 **개수**를 상태 파일에
 *    남기고 브라우저가 이어서 호출한다. 진행 표시도 용량이 아니라 개수 기준이다 —
 *    용량 기준은 큰 파일 하나에서 멈춘 것처럼 보인다.
 *  · 루트 `.htaccess` 와 루트 `index.php` 는 **맨 마지막에** 쓴다. 그 둘이 먼저 놓이면
 *    모든 요청이 `public/` 으로 위임되어 이 파일 자신이 브라우저에서 사라지고, 그 순간
 *    재개가 불가능해진다.
 *  · 완료되면 ZIP·상태 파일·번호 파일·이 파일 자신을 지운다. 남으면 임의 파일 쓰기
 *    입구가 된다. 지우지 못하면 그 사실을 화면에 띄운다 (조용히 남기지 않는다).
 *  · 설치가 끝난 사이트에서는 아무 일도 하지 않는다 (410).
 *
 * ── 확인 번호 ──────────────────────────────────────────────────────────────
 * 최초 실행 시 6자리 숫자를 만들어 `g7-setup-token.txt` 에 쓴다. 그 파일을 FTP 로 열어야
 * 번호를 알 수 있으므로 **파일 접근 권한이 곧 인증**이다. 6자리는 원격 대입 표면이므로
 * 연속 오입력에 지수 지연 + 잠금 + 번호 재생성을 건다.
 *
 * 번호 파일은 **최초 인증 성공 즉시 삭제**한다. 아직 `.htaccess` 가 놓이기 전이라 그
 * 파일은 브라우저에서도 열릴 수 있기 때문이다(정적 파일 차단 수단이 서버마다 다르다).
 * 인증 뒤에는 세션 키가 그 자리를 대신하고, 세션이 끊기면 번호를 **새로** 만들어 다시
 * 묻는다. 최선 노력으로 임시 `.htaccess` 도 함께 두지만 그것은 Apache 에서만 유효하다.
 *
 * @see INSTALL.md "방법 8: FTP 만 있는 호스팅 — 브라우저로 압축 풀기"
 */

// ── 상수 ────────────────────────────────────────────────────────────────────

/** 이 파일이 놓인 디렉토리 = 압축을 풀 대상 디렉토리 */
const G7_SETUP_TOKEN_FILE = 'g7-setup-token.txt';
const G7_SETUP_STATE_FILE = 'g7-setup-state.json';
const G7_SETUP_GUARD_FILE = '.htaccess';

/**
 * 배포본이 담고 있는 이 파일의 정식 이름.
 *
 * 운영자가 이 파일을 다른 이름으로 올렸을 수 있으므로 `basename(__FILE__)` 과 별도로 둔다 —
 * 정식 이름을 함께 보지 않으면 배포본 안의 사본이 풀린 뒤 그대로 남는다.
 */
const G7_SETUP_SELF_NAME = 'g7-setup.php';

/** 확인 번호 자릿수 (W4 — FTP 로 열어 눈으로 옮겨 적는 값이라 짧아야 한다) */
const G7_SETUP_TOKEN_DIGITS = 6;

/** 연속 오입력 허용 횟수. 넘으면 잠금 + 번호 재생성 */
const G7_SETUP_MAX_ATTEMPTS = 5;

/** 오입력 지연 상한(초) — 지수 백오프의 천장 */
const G7_SETUP_BACKOFF_CAP = 30;

/** 허용 횟수 초과 시 잠금 시간(초) */
const G7_SETUP_LOCK_SECONDS = 300;

/** 확인 번호 재발급 최소 간격(초) — 무인증 재발급 경로의 제동 */
const G7_SETUP_REISSUE_MIN_INTERVAL = 10;

/** 한 요청에서 처리할 엔트리 수 상한 */
const G7_SETUP_MAX_ENTRIES_PER_REQUEST = 400;

/** 한 요청의 시간 예산(초). 요청 시간 상한이 30초인 서버를 전제로 여유를 둔다 */
const G7_SETUP_TIME_BUDGET = 10.0;

/** 스트림 복사 청크 크기 */
const G7_SETUP_STREAM_CHUNK = 262144;

/**
 * 맨 마지막에 쓰는 루트 파일 — 이 파일 자신을 브라우저에서 사라지게 만드는 것들.
 *
 * 루트 `.htaccess` 는 모든 요청을 `public/` 으로 위임하고, 루트 `index.php` 는 그 위임의
 * 도착점이다. 둘 중 하나라도 먼저 놓이면 `/g7-setup.php` 가 `public/` 아래의 `g7-setup.php` 로
 * 해석되어 404 가 되고 재개 호출이 통째로 끊긴다.
 */
const G7_SETUP_DEFERRED_ENTRIES = ['.htaccess', 'index.php', 'web.config'];

/** ZIP 이 G7 배포본인지 판정하는 표식 (푼 뒤 기준 경로) */
const G7_SETUP_PAYLOAD_MARKERS = ['artisan', 'composer.json', 'public/index.php'];

// ── 기반 헬퍼 ───────────────────────────────────────────────────────────────

/**
 * 함수가 실제로 호출 가능한가 (하드닝 프로필 반영).
 *
 * `function_exists()` 만 보면 `disable_functions` 로 막힌 함수를 놓친다. PHP 8 은 차단
 * 함수를 함수 테이블에서 제거하므로 그 호출은 `Error`(치명)이고 `@` 는 가드가 아니다.
 * 코어의 `App\Support\ExecutionEnvironment::hasFunction()` 과 같은 규칙이며, 이 파일은
 * 부팅 전에 돌아 그 클래스를 쓸 수 없어 같은 판정을 안에 둔다.
 *
 * @param  string  $name  함수 이름
 * @return bool 호출 가능하면 true
 */
function g7setup_has_function(string $name): bool
{
    if (! function_exists($name)) {
        return false;
    }

    // `ini_get` 자신이 막혀 있을 수 있다 — 그러면 목록을 읽을 수 없으므로
    // `function_exists` 의 판정을 그대로 쓴다(그 이상 알 방법이 없다).
    if (! function_exists('ini_get')) {
        return true;
    }

    $disabled = (string) @ini_get('disable_functions');
    if ($disabled === '') {
        return true;
    }

    foreach (explode(',', $disabled) as $entry) {
        if (strcasecmp(trim($entry), $name) === 0) {
            return false;
        }
    }

    return true;
}

/**
 * 이 파일이 놓인 디렉토리 (압축을 풀 대상).
 *
 * @return string 후행 슬래시 없는 절대 경로
 */
function g7setup_base_dir(): string
{
    return rtrim(str_replace('\\', '/', __DIR__), '/');
}

/**
 * 사이트 마운트 접두사 — 브라우저가 이 파일을 연 주소에서 도출한다.
 *
 * 하위 폴더·별칭 배치에서 완료 후 안내 링크가 사이트 밖으로 나가지 않게 한다.
 *
 * @return string `/` 또는 `/g7` 처럼 후행 슬래시 없는 경로
 */
function g7setup_base_url(): string
{
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = str_replace('\\', '/', dirname($script));

    return ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
}

/**
 * 설치가 이미 완료된 사이트인가.
 *
 * 판정 규칙은 `public/install/api/_guard.php::installer_is_completed()` 와 같다 —
 * ① `storage/app/g7_installed` 락 파일 ② `.env` 의 `INSTALLER_COMPLETED=true|1|yes`.
 * `parse_ini_file` 이 깨져도 fail-open 하지 않고 줄 앵커 정규식으로 2차 판정한다.
 *
 * @return bool 완료된 사이트면 true
 */
function g7setup_installation_completed(): bool
{
    $base = g7setup_base_dir();

    if (is_file($base.'/storage/app/g7_installed')) {
        return true;
    }

    $envFile = $base.'/.env';
    if (! is_file($envFile)) {
        return false;
    }

    $env = @parse_ini_file($envFile, false, INI_SCANNER_RAW);
    if (is_array($env)) {
        $flag = strtolower(trim((string) ($env['INSTALLER_COMPLETED'] ?? '')));
        $flag = trim($flag, "\"'");

        return in_array($flag, ['true', '1', 'yes'], true);
    }

    $raw = (string) @file_get_contents($envFile);

    return preg_match('/^\s*INSTALLER_COMPLETED\s*=\s*["\']?(?:true|1|yes)\b["\']?\s*$/mi', $raw) === 1;
}

/**
 * 디렉토리의 파일 목록 — `glob` 이 막힌 호스팅을 위해 `scandir` 폴백을 둔다.
 *
 * @param  string  $dir  대상 디렉토리
 * @return array<int, string> 파일 이름(경로 아님) 목록
 */
function g7setup_list_files(string $dir): array
{
    $names = [];

    if (g7setup_has_function('scandir')) {
        $entries = @scandir($dir);
        if (is_array($entries)) {
            $names = $entries;
        }
    }

    if ($names === [] && g7setup_has_function('glob')) {
        $found = @glob($dir.'/*');
        if (is_array($found)) {
            $names = array_map('basename', $found);
        }
    }

    $files = [];
    foreach ($names as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (is_file($dir.'/'.$name)) {
            $files[] = $name;
        }
    }

    sort($files, SORT_STRING);

    return $files;
}

/**
 * 대상 디렉토리의 ZIP 후보 목록.
 *
 * @return array<int, array{name: string, size: int}> 이름·크기
 */
function g7setup_zip_candidates(): array
{
    $dir = g7setup_base_dir();
    $out = [];

    foreach (g7setup_list_files($dir) as $name) {
        if (! preg_match('/\.zip$/i', $name)) {
            continue;
        }
        $out[] = ['name' => $name, 'size' => (int) @filesize($dir.'/'.$name)];
    }

    // G7 배포본으로 보이는 이름을 앞에 둔다 (기본 선택값).
    usort($out, static function (array $a, array $b): int {
        $aG7 = preg_match('/^g7[-_]/i', $a['name']) === 1 ? 0 : 1;
        $bG7 = preg_match('/^g7[-_]/i', $b['name']) === 1 ? 0 : 1;
        if ($aG7 !== $bG7) {
            return $aG7 <=> $bG7;
        }

        return $b['size'] <=> $a['size'];
    });

    return $out;
}

// ── 상태 파일 ───────────────────────────────────────────────────────────────

/**
 * 상태 파일 경로.
 */
function g7setup_state_path(): string
{
    return g7setup_base_dir().'/'.G7_SETUP_STATE_FILE;
}

/**
 * 상태를 읽는다. 없거나 깨졌으면 초기 상태를 돌려준다.
 *
 * @return array<string, mixed>
 */
function g7setup_state_read(): array
{
    $defaults = [
        'version' => 1,
        'token_hash' => '',
        'token_issued_at' => 0,
        'session_hash' => '',
        'attempts' => 0,
        'locked_until' => 0,
        'zip' => '',
        'zip_size' => 0,
        'zip_mtime' => 0,
        'strip_prefix' => '',
        'total' => 0,
        'index' => 0,
        'updated_at' => 0,
    ];

    $path = g7setup_state_path();
    if (! is_file($path) || ! is_readable($path)) {
        return $defaults;
    }

    $decoded = json_decode((string) @file_get_contents($path), true);
    if (! is_array($decoded)) {
        return $defaults;
    }

    return array_merge($defaults, $decoded);
}

/**
 * 상태를 원자적으로 쓴다 (임시 파일 + rename).
 *
 * 부분 쓰기 상태 파일은 다음 요청에서 진행 인덱스를 0 으로 되돌려 처음부터 다시 풀게
 * 만든다 — 그래서 반드시 rename 으로 교체한다.
 *
 * @param  array<string, mixed>  $state  저장할 상태
 * @return bool 저장 성공 여부
 */
function g7setup_state_write(array $state): bool
{
    $state['updated_at'] = time();
    $path = g7setup_state_path();
    $tmp = $path.'.tmp';

    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    if (g7setup_has_function('chmod')) {
        @chmod($tmp, 0600);
    }

    if (! @rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }

    return true;
}

// ── 확인 번호 ───────────────────────────────────────────────────────────────

/**
 * 번호 파일 경로.
 */
function g7setup_token_path(): string
{
    return g7setup_base_dir().'/'.G7_SETUP_TOKEN_FILE;
}

/**
 * 6자리 확인 번호를 새로 만들어 파일에 쓰고, 상태에 해시를 남긴다.
 *
 * 번호 자체는 상태 파일에 두지 않는다 — 상태 파일이 읽히면 번호도 함께 새기 때문이다.
 *
 * 진행 중인 세션(`session_hash`)은 **건드리지 않는다.** 번호 재발급은 무인증 경로에서도
 * 일어날 수 있어(오입력 상한 도달), 여기서 세션을 끊으면 원격 대입 시도만으로 운영자의
 * 해제 작업이 중단된다. 새 번호로 인증에 성공하면 그때 세션이 교체된다.
 *
 * @param  array<string, mixed>  $state  상태 (참조로 갱신)
 * @return bool 번호 파일 쓰기 성공 여부
 */
function g7setup_token_issue(array &$state): bool
{
    $max = (10 ** G7_SETUP_TOKEN_DIGITS) - 1;
    $number = str_pad((string) random_int(0, $max), G7_SETUP_TOKEN_DIGITS, '0', STR_PAD_LEFT);

    $body = $number."\n";

    if (@file_put_contents(g7setup_token_path(), $body, LOCK_EX) === false) {
        return false;
    }

    if (g7setup_has_function('chmod')) {
        @chmod(g7setup_token_path(), 0600);
    }

    $state['token_hash'] = hash('sha256', $number);
    $state['token_issued_at'] = time();
    $state['attempts'] = 0;
    $state['locked_until'] = 0;

    return true;
}

/**
 * 번호 파일이 브라우저에서 열리지 않도록 최선 노력으로 임시 `.htaccess` 를 둔다.
 *
 * 압축을 풀기 전에는 배포본의 루트 `.htaccess` 가 아직 없어 번호 파일이 정적으로 서빙될
 * 수 있다. Apache 에서만 유효한 방어이고(다른 서버는 설정 파일이 필요하다), 이미
 * `.htaccess` 가 있으면 손대지 않는다 — 운영자 파일을 덮어쓰지 않는다.
 *
 * 압축 해제 마지막 단계에서 배포본의 루트 `.htaccess` 가 이 파일을 대체한다.
 *
 * @return bool 보호 규칙이 놓였거나 이미 다른 `.htaccess` 가 있으면 true
 */
function g7setup_guard_file_ensure(): bool
{
    $path = g7setup_base_dir().'/'.G7_SETUP_GUARD_FILE;

    if (file_exists($path)) {
        return true;
    }

    $rules = <<<'HTACCESS'
# G7 압축 풀기 임시 보호 규칙 — 압축 해제가 끝나면 배포본의 루트 .htaccess 가 대체한다.
<FilesMatch "^g7-setup-(token\.txt|state\.json)$">
    <IfModule mod_access_compat.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>
HTACCESS;

    return @file_put_contents($path, $rules."\n", LOCK_EX) !== false;
}

// ── ZIP 계획 ────────────────────────────────────────────────────────────────

/**
 * ZIP 엔트리 이름을 안전한 상대 경로로 정규화한다.
 *
 * 거부 대상: 절대 경로 · 드라이브 문자 · `..` 세그먼트 · 빈 이름. 거부는 예외가 아니라
 * `null` 로 돌려주고 호출부가 그 엔트리를 **거부 사유와 함께 실패**시킨다 — 조용히
 * 건너뛰면 파일이 빠진 채 "완료" 가 된다.
 *
 * @param  string  $name  ZIP 안의 엔트리 이름
 * @return string|null 정규화된 상대 경로. 안전하지 않으면 null
 */
function g7setup_normalize_entry(string $name): ?string
{
    $path = str_replace('\\', '/', $name);

    if ($path === '') {
        return null;
    }

    // 절대 경로 · Windows 드라이브 문자 · UNC
    if ($path[0] === '/' || preg_match('~^[A-Za-z]:~', $path) === 1) {
        return null;
    }

    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            return null;
        }
        $segments[] = $segment;
    }

    if ($segments === []) {
        return null;
    }

    return implode('/', $segments);
}

/**
 * 모든 엔트리가 공유하는 최상위 디렉토리를 구한다.
 *
 * GitHub 릴리즈 ZIP 은 `g7-7.1.0/` 같은 폴더 하나로 감싸여 있다. 그것을 그대로 풀면
 * 문서 루트 아래에 폴더가 하나 더 생겨 사이트가 서지 않는다. 모든 엔트리가 같은 최상위
 * 폴더를 공유할 때만 그 폴더를 벗긴다.
 *
 * @param  ZipArchive  $zip  열린 아카이브
 * @return string 벗길 접두사 (`g7-7.1.0/`) 또는 빈 문자열
 */
function g7setup_detect_strip_prefix(ZipArchive $zip): string
{
    $prefix = null;

    for ($i = 0, $n = $zip->numFiles; $i < $n; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $normalized = g7setup_normalize_entry($name);
        if ($normalized === null) {
            continue;
        }

        $slash = strpos($normalized, '/');
        if ($slash === false) {
            // 최상위에 파일이 하나라도 있으면 감싼 폴더가 아니다.
            return '';
        }

        $top = substr($normalized, 0, $slash);
        if ($prefix === null) {
            $prefix = $top;
        } elseif ($prefix !== $top) {
            return '';
        }
    }

    return $prefix === null ? '' : $prefix.'/';
}

/**
 * 접두사를 벗긴 대상 상대 경로. 벗길 수 없으면 null.
 *
 * @param  string  $normalized  정규화된 엔트리 경로
 * @param  string  $prefix  벗길 접두사 (빈 문자열이면 그대로)
 * @return string|null 대상 상대 경로 (감싼 폴더 자신이면 빈 문자열)
 */
function g7setup_strip_prefix(string $normalized, string $prefix): ?string
{
    if ($prefix === '') {
        return $normalized;
    }

    if ($normalized.'/' === $prefix) {
        return '';
    }

    if (strncmp($normalized, $prefix, strlen($prefix)) !== 0) {
        return null;
    }

    return substr($normalized, strlen($prefix));
}

/**
 * 이 부트스트랩이 관리하는 파일인가 (해제 대상에서 뺀다).
 *
 * 배포본에는 이 파일 자신이 들어 있다. 풀어 두면 완료 시 지워야 할 사본이 하나 더 생기고,
 * 실행 중인 파일을 덮어쓰는 일도 피해야 한다.
 *
 * @param  string  $target  대상 상대 경로
 * @return bool 건너뛸 대상이면 true
 */
function g7setup_is_self_managed(string $target): bool
{
    return in_array($target, [
        basename(__FILE__),
        G7_SETUP_SELF_NAME,
        G7_SETUP_TOKEN_FILE,
        G7_SETUP_STATE_FILE,
        G7_SETUP_STATE_FILE.'.tmp',
    ], true);
}

/**
 * 해제 순서 계획을 만든다.
 *
 * 반환 배열의 각 항목은 `[zip 인덱스, 대상 상대 경로]` 다. 라우팅을 가로채는 루트 파일
 * (`.htaccess`·`index.php`·`web.config`)은 **맨 끝**으로 미룬다.
 *
 * @param  ZipArchive  $zip  열린 아카이브
 * @param  string  $prefix  벗길 접두사
 * @return array{plan: array<int, array{0: int, 1: string}>, unsafe: string|null}
 */
function g7setup_build_plan(ZipArchive $zip, string $prefix): array
{
    $main = [];
    $deferred = [];

    for ($i = 0, $n = $zip->numFiles; $i < $n; $i++) {
        $raw = (string) $zip->getNameIndex($i);
        $normalized = g7setup_normalize_entry($raw);

        if ($normalized === null) {
            return ['plan' => [], 'unsafe' => $raw];
        }

        $target = g7setup_strip_prefix($normalized, $prefix);
        if ($target === null || $target === '') {
            continue;
        }

        if (g7setup_is_self_managed($target)) {
            continue;
        }

        if (in_array($target, G7_SETUP_DEFERRED_ENTRIES, true)) {
            $deferred[] = [$i, $target];

            continue;
        }

        $main[] = [$i, $target];
    }

    return ['plan' => array_merge($main, $deferred), 'unsafe' => null];
}

/**
 * 계획이 G7 배포본을 가리키는가.
 *
 * @param  array<int, array{0: int, 1: string}>  $plan  해제 계획
 * @return bool 배포본 표식을 모두 담고 있으면 true
 */
function g7setup_plan_is_g7_payload(array $plan): bool
{
    $names = [];
    foreach ($plan as $entry) {
        $names[$entry[1]] = true;
    }

    foreach (G7_SETUP_PAYLOAD_MARKERS as $marker) {
        if (! isset($names[$marker])) {
            return false;
        }
    }

    return true;
}

// ── 해제 ────────────────────────────────────────────────────────────────────

/**
 * 엔트리 하나를 대상 경로에 쓴다.
 *
 * 디렉토리 엔트리(`/` 로 끝나는 이름)는 디렉토리를 만들고, 파일은 스트림으로 복사한다.
 * 전량 적재(`getFromIndex`)를 쓰지 않는 이유는 큰 파일 하나가 메모리 상한을 넘기기
 * 때문이다.
 *
 * @param  ZipArchive  $zip  열린 아카이브
 * @param  int  $index  엔트리 인덱스
 * @param  string  $target  대상 상대 경로
 * @param  string  $baseDir  대상 디렉토리 절대 경로
 * @return string|null 실패 사유(경로). 성공하면 null
 */
function g7setup_write_entry(ZipArchive $zip, int $index, string $target, string $baseDir): ?string
{
    $full = $baseDir.'/'.$target;
    $isDirectory = substr((string) $zip->getNameIndex($index), -1) === '/';

    // 2차 봉쇄 — 정규화를 통과한 뒤에도 실제 경로가 대상 밖을 가리키지 않는지 다시 본다.
    $parent = $isDirectory ? $full : dirname($full);
    if (! is_dir($parent) && ! @mkdir($parent, 0755, true) && ! is_dir($parent)) {
        return $target;
    }

    $realParent = realpath($parent);
    $realBase = realpath($baseDir);
    if ($realParent === false || $realBase === false) {
        return $target;
    }
    $realParent = str_replace('\\', '/', $realParent);
    $realBase = str_replace('\\', '/', $realBase);
    if ($realParent !== $realBase && strncmp($realParent, $realBase.'/', strlen($realBase) + 1) !== 0) {
        return $target;
    }

    if ($isDirectory) {
        return null;
    }

    // 기존 심볼릭 링크 위에 쓰면 링크가 가리키는 **대상 밖 파일**이 덮인다. 위 realpath
    // 봉쇄는 부모 디렉토리만 보므로 링크 자신은 여기서 걷어낸다.
    if (is_link($full)) {
        @unlink($full);
    }

    $source = $zip->getStream((string) $zip->getNameIndex($index));
    if ($source === false) {
        return $target;
    }

    $handle = @fopen($full, 'wb');
    if ($handle === false) {
        @fclose($source);

        return $target;
    }

    $failed = false;
    while (! feof($source)) {
        $chunk = fread($source, G7_SETUP_STREAM_CHUNK);
        if ($chunk === false) {
            $failed = true;
            break;
        }
        if ($chunk !== '' && fwrite($handle, $chunk) === false) {
            $failed = true;
            break;
        }
    }

    @fclose($source);
    @fclose($handle);

    return $failed ? $target : null;
}

/**
 * 완료 후 뒷정리 — 상태·번호·ZIP·이 파일 자신을 지운다.
 *
 * 지우지 못한 것은 목록으로 돌려준다. 남았는데 조용히 넘기면 임의 파일 쓰기 입구가
 * 그대로 남는다.
 *
 * @param  string  $zipName  지울 ZIP 파일 이름
 * @return array<int, string> 지우지 못한 파일 이름 목록
 */
function g7setup_cleanup(string $zipName): array
{
    $baseDir = g7setup_base_dir();
    $leftover = [];

    $targets = [G7_SETUP_STATE_FILE, G7_SETUP_TOKEN_FILE];
    if ($zipName !== '') {
        $targets[] = $zipName;
    }
    // 자기 자신은 마지막이다 — 먼저 지우면 남은 정리가 실행되지 않는다. 운영자가 이 파일을
    // 다른 이름으로 올렸을 수 있으므로 정식 이름도 함께 본다.
    $targets[] = G7_SETUP_SELF_NAME;
    $targets[] = basename(__FILE__);

    foreach ($targets as $name) {
        $path = $baseDir.'/'.$name;
        if (! file_exists($path)) {
            continue;
        }
        if (! @unlink($path) && file_exists($path)) {
            $leftover[] = $name;
        }
    }

    return $leftover;
}

// ── 다국어 ──────────────────────────────────────────────────────────────────

/**
 * 부트스트랩 전용 사전.
 *
 * 인스톨러 사전(`public/install/lang/*.php`)은 아직 풀리지 않아 쓸 수 없다. 지원 언어는
 * 인스톨러의 `SUPPORTED_LANGUAGES` 와 같은 둘(ko·en)이다.
 *
 * @return array<string, array<string, string>>
 */
function g7setup_dictionary(): array
{
    return [
        'ko' => [
            'brand' => '그누보드7',
            'title' => '그누보드7 압축 풀기',
            'intro' => '올려두신 압축 파일을 풀어 설치를 준비합니다.',
            'label_target' => '대상 파일',
            'label_location' => '풀 위치',
            'location_value' => '현재 폴더',
            'label_token' => '확인 번호',
            'token_hint' => '서버의 :file 파일에 적힌 번호를 넣어주세요.',
            'token_reissued_hint' => '확인 번호를 새로 만들었습니다. :file 파일을 다시 열어 새 번호를 넣어주세요.',
            'start' => '풀기 시작',
            'resume' => '이어서 풀기',
            'progress' => ':done / :total 개',
            'self_delete_notice' => '완료되면 이 화면과 압축 파일은 자동으로 삭제됩니다.',
            'no_zip' => '이 폴더에서 압축 파일(.zip)을 찾지 못했습니다. 배포 ZIP 을 이 파일과 같은 폴더에 올려주세요.',
            'already_installed' => '이미 설치가 완료된 사이트입니다. 압축 풀기를 진행할 수 없습니다.',
            'already_installed_action' => '이 파일과 압축 파일을 FTP 로 삭제해주세요.',
            'invalid_token' => '확인 번호가 맞지 않습니다. :remaining 회 남았습니다.',
            'locked' => '입력 시도가 많아 :seconds 초 동안 잠겼습니다.',
            'token_reissued' => '입력 시도가 많아 확인 번호를 새로 만들었습니다. 서버의 :file 파일을 다시 열어주세요.',
            'token_write_failed' => '확인 번호 파일을 만들지 못했습니다. 이 폴더에 쓰기 권한이 있는지 확인해주세요.',
            'not_a_g7_payload' => '선택한 압축 파일이 그누보드7 배포본으로 보이지 않습니다.',
            'zip_missing_extension' => 'PHP 의 zip 확장이 설치되어 있지 않아 압축을 풀 수 없습니다. 호스팅 업체에 zip 확장 활성화를 요청해주세요.',
            'zip_open_failed' => '압축 파일을 열지 못했습니다. 업로드가 완전히 끝났는지 확인해주세요.',
            'unsafe_entry' => '압축 파일 안에 안전하지 않은 경로가 있어 중단했습니다: :entry',
            'write_failed' => '파일을 쓰지 못했습니다: :entry',
            'incomplete_payload' => '압축을 풀었지만 필수 파일이 보이지 않습니다. 압축 파일을 다시 올려주세요.',
            'done_title' => '압축 풀기가 끝났습니다.',
            'done_next' => '설치 마법사로 이동',
            'leftover_warning' => '아래 파일을 지우지 못했습니다. 보안을 위해 FTP 로 직접 삭제해주세요: :files',
            'toggle_theme' => '밝은/어두운 모드 전환',
            'select_language' => '언어',
            'network_error' => '서버와 통신하지 못했습니다. 잠시 후 다시 시도해주세요.',
        ],
        'en' => [
            'brand' => 'Gnuboard7',
            'title' => 'Gnuboard7 Archive Extraction',
            'intro' => 'Extracts the archive you uploaded so installation can begin.',
            'label_target' => 'Archive',
            'label_location' => 'Destination',
            'location_value' => 'Current folder',
            'label_token' => 'Confirmation number',
            'token_hint' => 'Enter the number written in :file on the server.',
            'token_reissued_hint' => 'A new confirmation number was issued. Open :file again and enter the new number.',
            'start' => 'Start extracting',
            'resume' => 'Resume extracting',
            'progress' => ':done / :total files',
            'self_delete_notice' => 'This page and the archive are deleted automatically when extraction finishes.',
            'no_zip' => 'No archive (.zip) was found in this folder. Upload the release ZIP next to this file.',
            'already_installed' => 'This site is already installed. Extraction cannot proceed.',
            'already_installed_action' => 'Please delete this file and the archive over FTP.',
            'invalid_token' => 'The confirmation number is incorrect. :remaining attempt(s) left.',
            'locked' => 'Too many attempts. Locked for :seconds seconds.',
            'token_reissued' => 'Too many attempts, so a new confirmation number was issued. Open :file on the server again.',
            'token_write_failed' => 'Could not create the confirmation number file. Check that this folder is writable.',
            'not_a_g7_payload' => 'The selected archive does not look like a Gnuboard7 release.',
            'zip_missing_extension' => 'The PHP zip extension is not available, so the archive cannot be extracted. Ask your host to enable it.',
            'zip_open_failed' => 'Could not open the archive. Make sure the upload completed.',
            'unsafe_entry' => 'Stopped because the archive contains an unsafe path: :entry',
            'write_failed' => 'Could not write a file: :entry',
            'incomplete_payload' => 'Extraction finished but required files are missing. Please upload the archive again.',
            'done_title' => 'Extraction finished.',
            'done_next' => 'Go to the installer',
            'leftover_warning' => 'These files could not be deleted. Please remove them over FTP for security: :files',
            'toggle_theme' => 'Toggle light/dark mode',
            'select_language' => 'Language',
            'network_error' => 'Could not reach the server. Please try again shortly.',
        ],
    ];
}

/**
 * 현재 언어 코드.
 */
function g7setup_locale(): string
{
    $requested = strtolower((string) ($_REQUEST['lang'] ?? ''));
    if (isset(g7setup_dictionary()[$requested])) {
        return $requested;
    }

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));

    return str_starts_with($accept, 'ko') ? 'ko' : 'en';
}

/**
 * 문구를 돌려준다.
 *
 * @param  string  $key  사전 키
 * @param  array<string, string|int>  $params  `:name` 치환 값
 * @return string 번역문 (없으면 키 그대로)
 */
function g7setup_lang(string $key, array $params = []): string
{
    $dict = g7setup_dictionary();
    $locale = g7setup_locale();
    $text = $dict[$locale][$key] ?? $dict['en'][$key] ?? $key;

    foreach ($params as $name => $value) {
        $text = str_replace(':'.$name, (string) $value, $text);
    }

    return $text;
}

// ── 응답 ────────────────────────────────────────────────────────────────────

/**
 * JSON 응답을 내보내고 종료한다.
 *
 * @param  array<string, mixed>  $payload  응답 본문
 * @param  int  $status  HTTP 상태 코드
 */
function g7setup_json(array $payload, int $status = 200): void
{
    if (! headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 인증 검증 — 세션 키 우선, 없으면 확인 번호.
 *
 * 판정 순서가 곧 설계다. **세션 검증이 잠금보다 먼저**다 — 잠금은 번호 추측에 대한
 * 제동이지 이미 인증된 브라우저에 대한 제동이 아니다. 순서를 뒤집으면 원격 대입 시도만으로
 * 운영자의 해제가 잠금 시간만큼 멈춘다(원격에서 성립하는 서비스 거부).
 *
 * 성공 시 세션 키를 새로 발급하고 번호 파일을 지운다 — 아직 배포본의 루트 `.htaccess` 가
 * 없어 그 파일이 정적으로 서빙될 수 있는 창을 첫 인증에서 닫는다. 실패 시 지수 지연을
 * 걸고, 허용 횟수를 넘으면 잠금 + 번호 재발급.
 *
 * @param  array<string, mixed>  $state  상태 (참조로 갱신)
 * @return array{ok: bool, session: string, error: array<string, mixed>|null}
 */
function g7setup_authenticate(array &$state): array
{
    $now = time();

    $session = (string) ($_POST['session'] ?? '');
    if ($session !== '' && (string) $state['session_hash'] !== ''
        && hash_equals((string) $state['session_hash'], hash('sha256', $session))) {
        return ['ok' => true, 'session' => $session, 'error' => null];
    }

    if ((int) $state['locked_until'] > $now) {
        return ['ok' => false, 'session' => '', 'error' => [
            'code' => 'locked',
            'message' => g7setup_lang('locked', ['seconds' => (int) $state['locked_until'] - $now]),
            'retry_after' => (int) $state['locked_until'] - $now,
        ]];
    }

    $token = (string) preg_replace('/[^0-9]/', '', (string) ($_POST['token'] ?? ''));

    if ($token !== '' && (string) $state['token_hash'] !== ''
        && hash_equals((string) $state['token_hash'], hash('sha256', $token))) {
        $newSession = bin2hex(random_bytes(24));
        $state['session_hash'] = hash('sha256', $newSession);
        $state['attempts'] = 0;
        $state['locked_until'] = 0;
        // 번호 파일은 첫 인증 성공과 함께 사라진다 — 정적 서빙으로 새어 나갈 창을 닫는다.
        @unlink(g7setup_token_path());

        return ['ok' => true, 'session' => $newSession, 'error' => null];
    }

    $state['attempts'] = (int) $state['attempts'] + 1;

    if ($state['attempts'] >= G7_SETUP_MAX_ATTEMPTS) {
        $reissued = g7setup_token_issue($state);
        $state['locked_until'] = $now + G7_SETUP_LOCK_SECONDS;
        $state['attempts'] = 0;

        return ['ok' => false, 'session' => '', 'error' => [
            'code' => 'token_reissued',
            'message' => $reissued
                ? g7setup_lang('token_reissued', ['file' => G7_SETUP_TOKEN_FILE])
                : g7setup_lang('token_write_failed'),
            'retry_after' => G7_SETUP_LOCK_SECONDS,
        ]];
    }

    // 지수 지연 — 대기는 서버 워커를 붙잡지 않고 잠금 시각으로 표현한다.
    $delay = min(2 ** ((int) $state['attempts'] - 1), G7_SETUP_BACKOFF_CAP);
    $state['locked_until'] = $now + $delay;

    return ['ok' => false, 'session' => '', 'error' => [
        'code' => 'invalid_token',
        'message' => g7setup_lang('invalid_token', [
            'remaining' => G7_SETUP_MAX_ATTEMPTS - (int) $state['attempts'],
        ]),
        'retry_after' => $delay,
    ]];
}

/**
 * 확인 번호를 다시 만든다 (`action=reissue`).
 *
 * 번호 파일은 첫 인증 성공과 함께 사라진다. 그 뒤 브라우저를 닫아 세션 키를 잃으면
 * 운영자에게는 다시 인증할 수단이 없다 — 그 막다른 길을 여는 유일한 통로다.
 *
 * 무인증 경로이므로 두 가지로 제동을 건다. ① 번호 파일이 이미 있으면 아무 일도 하지
 * 않는다(멱등) ② 최소 재발급 간격을 둔다. 진행 중인 세션은 끊지 않는다.
 */
function g7setup_handle_reissue(): void
{
    $state = g7setup_state_read();

    if (is_file(g7setup_token_path())) {
        g7setup_json(['ok' => true, 'reissued' => false, 'message' => g7setup_lang('token_hint', ['file' => G7_SETUP_TOKEN_FILE])]);
    }

    $elapsed = time() - (int) $state['token_issued_at'];
    if ($elapsed < G7_SETUP_REISSUE_MIN_INTERVAL) {
        g7setup_json([
            'ok' => false,
            'code' => 'locked',
            'message' => g7setup_lang('locked', ['seconds' => G7_SETUP_REISSUE_MIN_INTERVAL - $elapsed]),
            'retry_after' => G7_SETUP_REISSUE_MIN_INTERVAL - $elapsed,
        ], 429);
    }

    if (! g7setup_token_issue($state)) {
        g7setup_json(['ok' => false, 'code' => 'token_write_failed', 'message' => g7setup_lang('token_write_failed')], 500);
    }

    g7setup_state_write($state);

    g7setup_json([
        'ok' => true,
        'reissued' => true,
        'message' => g7setup_lang('token_reissued_hint', ['file' => G7_SETUP_TOKEN_FILE]),
    ]);
}

/**
 * 한 요청 분량의 해제를 수행한다 (`action=extract`).
 */
function g7setup_handle_extract(): void
{
    if (! class_exists('ZipArchive')) {
        g7setup_json(['ok' => false, 'code' => 'zip_missing_extension', 'message' => g7setup_lang('zip_missing_extension')], 500);
    }

    $state = g7setup_state_read();
    $auth = g7setup_authenticate($state);
    g7setup_state_write($state);

    if (! $auth['ok']) {
        $error = $auth['error'] ?? ['code' => 'invalid_token', 'message' => g7setup_lang('invalid_token', ['remaining' => 0])];
        g7setup_json(['ok' => false] + $error, $error['code'] === 'locked' ? 429 : 401);
    }

    $baseDir = g7setup_base_dir();

    // 대상 ZIP 결정 — 요청 값은 이 폴더의 실제 후보 목록으로만 받는다(경로 주입 차단).
    $candidates = array_column(g7setup_zip_candidates(), 'name');
    $requested = basename((string) ($_POST['zip'] ?? ''));
    $zipName = in_array($requested, $candidates, true) ? $requested : (string) $state['zip'];

    if ($zipName === '' || ! in_array($zipName, $candidates, true)) {
        $zipName = $candidates[0] ?? '';
    }

    if ($zipName === '') {
        g7setup_json(['ok' => false, 'code' => 'no_zip', 'message' => g7setup_lang('no_zip')], 400);
    }

    $zipPath = $baseDir.'/'.$zipName;
    $zipSize = (int) @filesize($zipPath);
    $zipMtime = (int) @filemtime($zipPath);

    // 대상이 바뀌었거나 파일이 교체되었으면 진행 인덱스를 처음으로 되돌린다.
    if ((string) $state['zip'] !== $zipName
        || (int) $state['zip_size'] !== $zipSize
        || (int) $state['zip_mtime'] !== $zipMtime) {
        $state['zip'] = $zipName;
        $state['zip_size'] = $zipSize;
        $state['zip_mtime'] = $zipMtime;
        $state['index'] = 0;
        $state['strip_prefix'] = '';
    }

    $zip = new ZipArchive;
    if ($zip->open($zipPath) !== true) {
        g7setup_json(['ok' => false, 'code' => 'zip_open_failed', 'message' => g7setup_lang('zip_open_failed')], 400);
    }

    $prefix = g7setup_detect_strip_prefix($zip);
    $built = g7setup_build_plan($zip, $prefix);

    if ($built['unsafe'] !== null) {
        $zip->close();
        g7setup_json([
            'ok' => false,
            'code' => 'unsafe_entry',
            'message' => g7setup_lang('unsafe_entry', ['entry' => $built['unsafe']]),
        ], 400);
    }

    $plan = $built['plan'];

    if (! g7setup_plan_is_g7_payload($plan)) {
        $zip->close();
        g7setup_json(['ok' => false, 'code' => 'not_a_g7_payload', 'message' => g7setup_lang('not_a_g7_payload')], 400);
    }

    $state['strip_prefix'] = $prefix;
    $state['total'] = count($plan);

    if (g7setup_has_function('set_time_limit')) {
        @set_time_limit(0);
    }

    $startedAt = microtime(true);
    $index = max(0, min((int) $state['index'], $state['total']));
    $processed = 0;

    while ($index < $state['total']) {
        [$zipIndex, $target] = $plan[$index];

        $failure = g7setup_write_entry($zip, $zipIndex, $target, $baseDir);
        if ($failure !== null) {
            $state['index'] = $index;
            g7setup_state_write($state);
            $zip->close();
            g7setup_json([
                'ok' => false,
                'code' => 'write_failed',
                'message' => g7setup_lang('write_failed', ['entry' => $failure]),
                'done' => $index,
                'total' => $state['total'],
            ], 500);
        }

        $index++;
        $processed++;

        if ($processed >= G7_SETUP_MAX_ENTRIES_PER_REQUEST
            || (microtime(true) - $startedAt) >= G7_SETUP_TIME_BUDGET) {
            break;
        }
    }

    $zip->close();

    $state['index'] = $index;
    $finished = $index >= $state['total'];

    if (! $finished) {
        g7setup_state_write($state);
        g7setup_json([
            'ok' => true,
            'done' => $index,
            'total' => $state['total'],
            'finished' => false,
            'session' => $auth['session'],
            'zip' => $zipName,
        ]);
    }

    // 완료 판정 — 표식 파일이 실제로 놓였는지 확인한 뒤에만 뒷정리를 한다.
    foreach (G7_SETUP_PAYLOAD_MARKERS as $marker) {
        if (! file_exists($baseDir.'/'.$marker)) {
            $state['index'] = 0;
            g7setup_state_write($state);
            g7setup_json([
                'ok' => false,
                'code' => 'incomplete_payload',
                'message' => g7setup_lang('incomplete_payload'),
                'done' => $index,
                'total' => $state['total'],
            ], 500);
        }
    }

    $leftover = g7setup_cleanup($zipName);

    g7setup_json([
        'ok' => true,
        'done' => $index,
        'total' => $state['total'],
        'finished' => true,
        'leftover' => $leftover,
        'leftover_message' => $leftover === []
            ? ''
            : g7setup_lang('leftover_warning', ['files' => implode(', ', $leftover)]),
        'next_url' => g7setup_base_url().'/install/',
        'done_title' => g7setup_lang('done_title'),
        'done_next' => g7setup_lang('done_next'),
    ]);
}

// ── 진입 ────────────────────────────────────────────────────────────────────

/**
 * 이 파일이 테스트에서 require 될 때는 요청 처리를 하지 않는다.
 *
 * 테스트는 순수 함수(경로 정규화·접두사 판정·완료 판정)를 그대로 부르기 위해 이 파일을
 * 읽어 들인다. 상수 하나로 그 경계를 명시한다.
 */
if (defined('G7_SETUP_LIBRARY_ONLY') && G7_SETUP_LIBRARY_ONLY === true) {
    return;
}

$isCompleted = g7setup_installation_completed();
$action = (string) ($_POST['action'] ?? '');

if ($isCompleted) {
    if ($action !== '') {
        g7setup_json([
            'ok' => false,
            'code' => 'already_installed',
            'message' => g7setup_lang('already_installed'),
        ], 410);
    }
} elseif ($action === 'extract') {
    g7setup_handle_extract();
} elseif ($action === 'reissue') {
    g7setup_handle_reissue();
}

// ── 화면 (W4) ───────────────────────────────────────────────────────────────

$locale = g7setup_locale();
$state = g7setup_state_read();
$candidates = $isCompleted ? [] : g7setup_zip_candidates();
$tokenReissued = false;

/**
 * 진행 중인 세션이 있는가 — 번호 파일이 사라진 이유가 「첫 인증 성공」인지
 * 「아직 만든 적 없음」인지를 가르는 신호다. 이 구분이 없으면 해제 도중의 새로고침이
 * 번호를 새로 만들면서 그 브라우저의 세션을 무효로 만든다.
 */
$hasSession = (string) $state['session_hash'] !== '';

/** 세션을 잃은 상태라 운영자가 번호를 다시 받아야 하는가 (JS 가 최종 판정한다) */
$mayNeedReissue = false;

if (! $isCompleted && $candidates !== []) {
    g7setup_guard_file_ensure();

    if (! is_file(g7setup_token_path())) {
        if ($hasSession) {
            // 첫 인증이 번호 파일을 지운 상태. 브라우저가 세션 키를 갖고 있으면 그대로
            // 이어서 풀면 되고, 잃었으면 JS 가 재발급을 요청한다.
            $mayNeedReissue = true;
        } else {
            $tokenWritten = g7setup_token_issue($state);
            g7setup_state_write($state);
            if (! $tokenWritten) {
                $tokenError = g7setup_lang('token_write_failed');
            }
        }
    }
}

$resumeDone = (int) $state['index'];
$resumeTotal = (int) $state['total'];
$hasProgress = $resumeTotal > 0 && $resumeDone > 0 && $resumeDone < $resumeTotal;
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

if (! headers_sent()) {
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if ($isCompleted) {
        http_response_code(410);
    }
}

/**
 * HTML 이스케이프 단축.
 *
 * @param  string  $value  원문
 * @return string 이스케이프된 문자열
 */
function g7setup_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 파일 크기를 사람이 읽는 표기로.
 *
 * @param  int  $bytes  바이트 수
 * @return string 표기 문자열
 */
function g7setup_format_size(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 1).'GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1).'MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1).'KB';
    }

    return $bytes.'B';
}
?>
<!DOCTYPE html>
<html lang="<?= g7setup_e($locale) ?>">
<head>
    <meta charset="UTF-8">
    <script>
    (function(){
        try {
            var theme = localStorage.getItem('g7_color_scheme')
                || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', theme);
        } catch (e) {
            document.documentElement.setAttribute('data-theme', 'light');
        }
    })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= g7setup_e(g7setup_lang('title')) ?></title>
    <style>
    /* 인스톨러(public/install/assets/css/installer.css) 와 같은 토큰 이름·값을 쓴다.
       그 파일은 아직 풀리지 않았으므로 필요한 최소분만 여기에 둔다 (D16 다크 포함). */
    :root {
      --primary-color: #3b82f6; --primary-hover: #2563eb;
      --text-color: #1f2937; --text-secondary: #4b5563; --text-muted: #6b7280;
      --bg-color: #ffffff; --bg-light: #f9fafb; --bg-gray: #f3f4f6;
      --border-color: #e5e7eb;
      --warning-color: #f59e0b; --warning-surface: #fffbeb; --warning-text: #92400e;
      --error-color: #ef4444; --error-surface: #fef2f2; --error-text: #991b1b;
      --success-color: #10b981; --success-surface: #f0fdf4; --success-text: #065f46;
      --on-primary: #ffffff;
      --radius: 0.5rem;
      --font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans KR', 'Malgun Gothic', sans-serif;
      --font-mono: 'Consolas', 'Monaco', 'Courier New', monospace;
    }
    [data-theme="dark"] {
      --primary-color: #60a5fa; --primary-hover: #3b82f6;
      --text-color: #e2e8f0; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
      --bg-color: #1e293b; --bg-light: #0f172a; --bg-gray: #334155;
      --border-color: #475569;
      --warning-color: #fbbf24; --warning-surface: rgba(245, 158, 11, 0.12); --warning-text: #fcd34d;
      --error-color: #f87171; --error-surface: rgba(239, 68, 68, 0.12); --error-text: #fca5a5;
      --success-color: #34d399; --success-surface: rgba(52, 211, 153, 0.12); --success-text: #86efac;
      --on-primary: #0f172a;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0; padding: 0; font-family: var(--font-family);
      background: var(--bg-light); color: var(--text-color); line-height: 1.5;
    }
    .setup-header {
      display: flex; justify-content: space-between; align-items: center;
      padding: 0.75rem 1.25rem; background: var(--bg-color);
      border-bottom: 1px solid var(--border-color);
    }
    .setup-brand { font-weight: 700; color: var(--primary-color); }
    .setup-header-right { display: flex; align-items: center; gap: 0.5rem; }
    .setup-theme-toggle, .setup-lang {
      background: transparent; border: 1px solid var(--border-color); color: var(--text-color);
      border-radius: var(--radius); padding: 0.35rem 0.6rem; cursor: pointer; font-size: 0.875rem;
    }
    .setup-wrapper { display: flex; justify-content: center; padding: 2rem 1rem; }
    .setup-card {
      width: 100%; max-width: 640px; background: var(--bg-color);
      border: 1px solid var(--border-color); border-radius: var(--radius);
      padding: 1.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }
    .setup-title { margin: 0 0 0.5rem; font-size: 1.375rem; }
    .setup-intro { margin: 0 0 1.5rem; color: var(--text-secondary); }
    .setup-row { display: flex; gap: 1rem; margin-bottom: 0.75rem; align-items: baseline; }
    .setup-row-label { flex: 0 0 7.5rem; color: var(--text-muted); font-size: 0.875rem; }
    .setup-row-value { flex: 1 1 auto; min-width: 0; word-break: break-all; }
    .setup-field { margin: 1.25rem 0 0.5rem; }
    .setup-field label { display: block; margin-bottom: 0.35rem; font-size: 0.875rem; color: var(--text-muted); }
    .setup-input, .setup-select {
      width: 100%; padding: 0.6rem 0.75rem; border: 1px solid var(--border-color);
      border-radius: var(--radius); background: var(--bg-color); color: var(--text-color);
      font-size: 1rem; font-family: inherit;
    }
    .setup-input { font-family: var(--font-mono); letter-spacing: 0.25em; }
    .setup-hint { margin: 0.4rem 0 0; font-size: 0.8125rem; color: var(--text-muted); }
    .setup-hint code { font-family: var(--font-mono); }
    .setup-progress-wrap { margin: 1.5rem 0 0.5rem; display: none; }
    .setup-progress-wrap.is-visible { display: block; }
    .setup-progress-track {
      height: 0.6rem; border-radius: 999px; background: var(--bg-gray); overflow: hidden;
    }
    .setup-progress-bar { height: 100%; width: 0; background: var(--primary-color); transition: width 200ms ease-out; }
    .setup-progress-text { margin: 0.4rem 0 0; font-size: 0.875rem; color: var(--text-secondary); }
    .setup-notice {
      margin: 1.5rem 0 0; padding: 0.75rem 0.9rem; border-radius: var(--radius);
      background: var(--warning-surface); color: var(--warning-text); font-size: 0.875rem;
    }
    .setup-alert { margin: 1rem 0 0; padding: 0.75rem 0.9rem; border-radius: var(--radius); font-size: 0.875rem; display: none; }
    .setup-alert.is-visible { display: block; }
    .setup-alert.is-error { background: var(--error-surface); color: var(--error-text); }
    .setup-alert.is-success { background: var(--success-surface); color: var(--success-text); }
    .setup-actions { display: flex; justify-content: flex-end; margin-top: 1.5rem; }
    .setup-button {
      background: var(--primary-color); color: var(--on-primary); border: 0; border-radius: var(--radius);
      padding: 0.65rem 1.4rem; font-size: 1rem; cursor: pointer; font-family: inherit;
    }
    .setup-button:hover { background: var(--primary-hover); }
    .setup-button[disabled] { opacity: 0.6; cursor: not-allowed; }
    .setup-done-link { display: inline-block; margin-top: 0.75rem; color: var(--primary-color); }
    @media (max-width: 640px) {
      .setup-row { display: block; }
      .setup-row-label { margin-bottom: 0.15rem; }
      .setup-card { padding: 1.25rem; }
    }
    </style>
</head>
<body>
<div class="setup-header">
    <span class="setup-brand"><?= g7setup_e(g7setup_lang('brand')) ?></span>
    <div class="setup-header-right">
        <select class="setup-lang" id="setup-lang" aria-label="<?= g7setup_e(g7setup_lang('select_language')) ?>">
            <option value="ko" <?= $locale === 'ko' ? 'selected' : '' ?>>한국어</option>
            <option value="en" <?= $locale === 'en' ? 'selected' : '' ?>>English</option>
        </select>
        <button type="button" class="setup-theme-toggle" id="setup-theme-toggle"
                aria-label="<?= g7setup_e(g7setup_lang('toggle_theme')) ?>"
                title="<?= g7setup_e(g7setup_lang('toggle_theme')) ?>">🌙</button>
    </div>
</div>

<div class="setup-wrapper">
    <div class="setup-card">
        <h1 class="setup-title"><?= g7setup_e(g7setup_lang('title')) ?></h1>

        <?php if ($isCompleted) { ?>
            <div class="setup-alert is-error is-visible">
                <?= g7setup_e(g7setup_lang('already_installed')) ?>
                <br><?= g7setup_e(g7setup_lang('already_installed_action')) ?>
            </div>
        <?php } elseif ($candidates === []) { ?>
            <p class="setup-intro"><?= g7setup_e(g7setup_lang('intro')) ?></p>
            <div class="setup-alert is-error is-visible"><?= g7setup_e(g7setup_lang('no_zip')) ?></div>
        <?php } else { ?>
            <p class="setup-intro"><?= g7setup_e(g7setup_lang('intro')) ?></p>

            <div class="setup-row">
                <div class="setup-row-label"><?= g7setup_e(g7setup_lang('label_target')) ?></div>
                <div class="setup-row-value">
                    <?php if (count($candidates) === 1) { ?>
                        <?= g7setup_e($candidates[0]['name']) ?>
                        <span class="setup-hint">(<?= g7setup_e(g7setup_format_size($candidates[0]['size'])) ?>)</span>
                        <input type="hidden" id="setup-zip" value="<?= g7setup_e($candidates[0]['name']) ?>">
                    <?php } else { ?>
                        <select class="setup-select" id="setup-zip">
                            <?php foreach ($candidates as $candidate) { ?>
                                <option value="<?= g7setup_e($candidate['name']) ?>"
                                    <?= $candidate['name'] === (string) $state['zip'] ? 'selected' : '' ?>>
                                    <?= g7setup_e($candidate['name']) ?> (<?= g7setup_e(g7setup_format_size($candidate['size'])) ?>)
                                </option>
                            <?php } ?>
                        </select>
                    <?php } ?>
                </div>
            </div>

            <div class="setup-row">
                <div class="setup-row-label"><?= g7setup_e(g7setup_lang('label_location')) ?></div>
                <div class="setup-row-value"><?= g7setup_e(g7setup_lang('location_value')) ?></div>
            </div>

            <div class="setup-field" id="setup-token-field">
                <label for="setup-token"><?= g7setup_e(g7setup_lang('label_token')) ?></label>
                <input type="text" class="setup-input" id="setup-token" inputmode="numeric"
                       autocomplete="off" maxlength="<?= (int) G7_SETUP_TOKEN_DIGITS ?>"
                       pattern="[0-9]*">
                <p class="setup-hint" id="setup-token-hint">
                    <?= g7setup_e(g7setup_lang($tokenReissued ? 'token_reissued_hint' : 'token_hint', ['file' => G7_SETUP_TOKEN_FILE])) ?>
                </p>
                <?php if (isset($tokenError)) { ?>
                    <p class="setup-hint" style="color: var(--error-text);"><?= g7setup_e($tokenError) ?></p>
                <?php } ?>
            </div>

            <div class="setup-progress-wrap<?= $hasProgress ? ' is-visible' : '' ?>" id="setup-progress">
                <div class="setup-progress-track">
                    <div class="setup-progress-bar" id="setup-progress-bar"
                         style="width: <?= $resumeTotal > 0 ? (int) round($resumeDone / $resumeTotal * 100) : 0 ?>%"></div>
                </div>
                <p class="setup-progress-text" id="setup-progress-text">
                    <?= $hasProgress ? g7setup_e(g7setup_lang('progress', ['done' => number_format($resumeDone), 'total' => number_format($resumeTotal)])) : '' ?>
                </p>
            </div>

            <div class="setup-alert" id="setup-alert"></div>

            <p class="setup-notice">⚠️ <?= g7setup_e(g7setup_lang('self_delete_notice')) ?></p>

            <div class="setup-actions">
                <button type="button" class="setup-button" id="setup-start">
                    <?= g7setup_e(g7setup_lang($hasProgress ? 'resume' : 'start')) ?>
                </button>
            </div>
        <?php } ?>
    </div>
</div>

<script>
(function () {
    var LANG = <?= json_encode([
        'progress' => g7setup_lang('progress'),
        'network_error' => g7setup_lang('network_error'),
        'done_title' => g7setup_lang('done_title'),
        'done_next' => g7setup_lang('done_next'),
    ], $jsonFlags) ?>;
    var LOCALE = <?= json_encode($locale, $jsonFlags) ?>;
    var MAY_NEED_REISSUE = <?= json_encode($mayNeedReissue, $jsonFlags) ?>;

    var toggle = document.getElementById('setup-theme-toggle');
    if (toggle) {
        var syncIcon = function () {
            toggle.textContent = document.documentElement.getAttribute('data-theme') === 'dark' ? '☀️' : '🌙';
        };
        syncIcon();
        toggle.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('g7_color_scheme', next); } catch (e) {}
            syncIcon();
        });
    }

    var langSelect = document.getElementById('setup-lang');
    if (langSelect) {
        langSelect.addEventListener('change', function () {
            var url = new URL(window.location.href);
            url.searchParams.set('lang', langSelect.value);
            window.location.href = url.toString();
        });
    }

    var startBtn = document.getElementById('setup-start');
    if (!startBtn) { return; }

    var tokenInput = document.getElementById('setup-token');
    var zipInput = document.getElementById('setup-zip');
    var alertBox = document.getElementById('setup-alert');
    var progressWrap = document.getElementById('setup-progress');
    var progressBar = document.getElementById('setup-progress-bar');
    var progressText = document.getElementById('setup-progress-text');
    var sessionKey = '';

    try { sessionKey = window.sessionStorage.getItem('g7_setup_session') || ''; } catch (e) { sessionKey = ''; }

    var tokenField = document.getElementById('setup-token-field');
    var tokenHint = document.getElementById('setup-token-hint');

    // 세션 키를 갖고 있으면 번호를 다시 묻지 않는다 — 해제 도중의 새로고침이 그 경우다.
    if (sessionKey && tokenField) {
        tokenField.style.display = 'none';
    } else if (MAY_NEED_REISSUE) {
        // 번호 파일은 첫 인증에서 지워졌는데 이 브라우저에는 세션이 없다(브라우저 종료 후
        // 재진입). 번호를 다시 만들어 달라고 요청하고, 새 번호를 읽으라고 안내한다.
        var reissueBody = new URLSearchParams();
        reissueBody.set('action', 'reissue');
        reissueBody.set('lang', LOCALE);
        fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: reissueBody.toString(),
            cache: 'no-store',
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () { return null; });
        }).then(function (data) {
            if (data && data.message && tokenHint) {
                tokenHint.textContent = data.message;
            }
        }).catch(function () {});
    }

    function showAlert(message, kind) {
        alertBox.textContent = message;
        alertBox.className = 'setup-alert is-visible is-' + kind;
    }

    function clearAlert() {
        alertBox.className = 'setup-alert';
        alertBox.textContent = '';
    }

    function renderProgress(done, total) {
        progressWrap.classList.add('is-visible');
        var percent = total > 0 ? Math.round((done / total) * 100) : 0;
        progressBar.style.width = percent + '%';
        progressText.textContent = LANG.progress
            .replace(':done', done.toLocaleString(LOCALE))
            .replace(':total', total.toLocaleString(LOCALE));
    }

    function finish(data) {
        renderProgress(data.done, data.total);
        var html = data.done_title || LANG.done_title;
        showAlert(html, 'success');
        if (data.leftover_message) {
            var warn = document.createElement('p');
            warn.textContent = data.leftover_message;
            alertBox.appendChild(warn);
        }
        var link = document.createElement('a');
        link.className = 'setup-done-link';
        link.href = data.next_url;
        link.textContent = data.done_next || LANG.done_next;
        alertBox.appendChild(link);
        startBtn.disabled = true;
        tokenInput.disabled = true;
    }

    function step() {
        var body = new URLSearchParams();
        body.set('action', 'extract');
        body.set('lang', LOCALE);
        if (sessionKey) { body.set('session', sessionKey); }
        if (tokenInput.value) { body.set('token', tokenInput.value); }
        if (zipInput) { body.set('zip', zipInput.value); }

        fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            cache: 'no-store',
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () { return null; });
        }).then(function (data) {
            if (!data) {
                showAlert(LANG.network_error, 'error');
                startBtn.disabled = false;
                return;
            }
            if (!data.ok) {
                showAlert(data.message || LANG.network_error, 'error');
                startBtn.disabled = false;
                // 번호가 재발급되면 이전 세션은 무효다 — 다시 입력받는다.
                if (data.code === 'token_reissued' || data.code === 'invalid_token') {
                    sessionKey = '';
                    try { window.sessionStorage.removeItem('g7_setup_session'); } catch (e) {}
                    tokenInput.value = '';
                }
                return;
            }

            if (data.session) {
                sessionKey = data.session;
                try { window.sessionStorage.setItem('g7_setup_session', sessionKey); } catch (e) {}
            }

            renderProgress(data.done, data.total);

            if (data.finished) {
                finish(data);
                return;
            }

            step();
        }).catch(function () {
            showAlert(LANG.network_error, 'error');
            startBtn.disabled = false;
        });
    }

    startBtn.addEventListener('click', function () {
        clearAlert();
        startBtn.disabled = true;
        step();
    });
})();
</script>
</body>
</html>
