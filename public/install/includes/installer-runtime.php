<?php

/**
 * 인스톨러 런타임 설정 헬퍼
 *
 * 설치 진행 중 동적 설정(DB 자격증명, APP_KEY) 을 .env 가 아닌
 * storage/installer/runtime.php 에 PHP 배열로 기록한다.
 *
 * Laravel 부팅 시 InstallerRuntimeServiceProvider 가 이 파일을 읽어
 * config('database.*') / config('app.key') 에 주입하므로,
 * 설치 진행 중 .env 를 건드리지 않고도 마이그레이션/시더가 동작한다.
 *
 * 설치 완료 UI 노출 후 finalize-env.php 가 호출되면 이 파일의 내용을
 * .env 에 머지하고 파일을 삭제한다.
 *
 * @see https://github.com/gnuboard/g7/issues/23
 */
if (! defined('BASE_PATH')) {
    throw new RuntimeException('installer-runtime.php requires BASE_PATH constant.');
}

// .env 값 직렬화 정책 (개행 주입 차단) — functions.php 와 같은 관문을 공유한다.
require_once __DIR__.'/env-value.php';
// 이 파일은 비밀(DB 자격증명·APP_KEY)을 0600 채널에 쓴다. 그 쓰기가 쓰는 두 헬퍼의
// 출처를 명시한다 — 이 파일만 단독으로 로드하는 경로(단위 테스트·일부 API)가 있고,
// 의존을 적지 않으면 그 경로에서 `Call to undefined function` 으로 죽는다.
//   · `installerUniqueSuffix()` (functions.php) — 임시 파일 이름. 차단 가능한 함수에
//     기대지 않는 난수원이다. 그 이름의 **바이트 수와 판정 패턴**(`INSTALLER_TMP_SUFFIX_*`)
//     도 같은 파일이 소유하므로 아래 상수 정의보다 먼저 싣는다.
//   · `installerHasFunction()` (execution-mode.php) — `chmod` 가용 판정 SSoT.
// 둘 다 최상위 부수효과 없이 함수·상수만 선언하므로 순서 의존이 생기지 않는다
// (installer-state.php 가 같은 이유로 같은 두 파일을 require 한다).
require_once __DIR__.'/functions.php';
require_once __DIR__.'/execution-mode.php';

if (! defined('INSTALLER_RUNTIME_TMP_INFIX')) {
    // tmp 이름의 **생성 규칙**을 상수가 소유한다 — 스윕이 같은 규칙으로만 지우도록
    // 형제(`installer-state.php` 의 `INSTALLER_STATE_TMP_INFIX`)와 같은 규율을 쓴다.
    // 값은 이름 생성 SSoT(`functions.php` 의 `INSTALLER_TMP_INFIX`)를 그대로 가리킨다.
    define('INSTALLER_RUNTIME_TMP_INFIX', INSTALLER_TMP_INFIX);
}

if (! defined('INSTALLER_RUNTIME_TMP_SUFFIX_PATTERN')) {
    // 판정 패턴은 이름 생성 SSoT 곁의 상수를 **그대로** 가리킨다. 종전에는 여기 리터럴
    // `{8}` 이 있었는데 생성 이름은 16진 16자라 영원히 매치되지 않았다 — 스윕이 돌아도
    // 모집단이 0 이었고 오류도 경고도 없었다.
    define('INSTALLER_RUNTIME_TMP_SUFFIX_PATTERN', INSTALLER_TMP_SUFFIX_PATTERN);
}

if (! defined('INSTALLER_RUNTIME_PATH')) {
    define('INSTALLER_RUNTIME_PATH', BASE_PATH.'/storage/installer/runtime.php');
}

if (! defined('INSTALLER_ENV_BACKUP_INFIX')) {
    /**
     * finalize 가 남기는 `.env` 원본 보존 사본 이름의 중간 조각 (`.env.bak.<Ymd_His>[_N]`).
     *
     * 정리 주체(`StorageLeftoverPruneService::ENV_BACKUP_PREFIX`)가 같은 이름을 봐야 한다 —
     * 종전에는 양쪽 다 리터럴이라 어느 쪽이 바뀌어도 아무것도 red 가 되지 않았다.
     * 인스톨러는 Laravel 부팅 전 코드라 코어 상수를 참조할 수 없어 값이 두 곳에 있고,
     * 그 일치는 `StorageLeftoverPruneServiceTest` 가 잠근다.
     */
    define('INSTALLER_ENV_BACKUP_INFIX', '.bak.');
}

if (! defined('INSTALLER_ENV_BACKUP_STAMP_FORMAT')) {
    /** `.env` 보존 사본의 시각 조각 형식 (`date()` 형식 문자열). */
    define('INSTALLER_ENV_BACKUP_STAMP_FORMAT', 'Ymd_His');
}

if (! function_exists('installerWroteFully')) {
    /**
     * `file_put_contents()` 가 내용을 **끝까지** 썼는지 판정한다.
     *
     * 그 함수는 실패했을 때만 `false` 를 돌려주는 것이 아니다 — 디스크가 가득 찼거나
     * quota 를 넘으면 **쓴 바이트 수**를 돌려주고, 그 값은 truthy 다. 그래서 `=== false`
     * 만 보는 호출부는 **잘린 파일을 성공으로 통과시킨다.** 설치·되돌리기가 다루는 파일은
     * `.env`(APP_KEY·DB 자격증명) · `runtime.php`(비밀 채널) · 설정 JSON 처럼 잘리면
     * 되돌릴 수 없는 것들이라, 그 통과가 곧 복구 불가 상태다.
     *
     * 판정을 이 한 곳이 소유한다 — 지점마다 사본을 두면 나중에 추가되는 쓰기 지점이
     * 다시 사각이 된다. 형제 축(`exposure-check.php` sentinel · `installer-state.php`
     * 상태 파일 · 코어 `AbstractRootMarkerFileUpdater` · `CoreUpdateService`)이 같은
     * 규율을 쓴다.
     *
     * @param  int|false  $written  `file_put_contents()` 반환값
     * @param  string  $content  쓰려던 내용
     * @return bool 끝까지 썼으면 true
     */
    function installerWroteFully($written, string $content): bool
    {
        return $written !== false && $written === strlen($content);
    }
}

if (! function_exists('readInstallerRuntime')) {
    /**
     * runtime.php 를 읽어 배열로 반환한다.
     *
     * @return array<string, mixed>|null 파일이 없거나 형식이 잘못된 경우 null
     */
    function readInstallerRuntime(): ?array
    {
        if (! is_file(INSTALLER_RUNTIME_PATH)) {
            return null;
        }

        // runtime.php 는 PHP 파일이라 OPcache 가 캐시한다. 직전 요청이 갱신한 파일을
        // opcache.revalidate_freq(기본 2초) 창 안에서 다시 읽으면 stale 버전이 include 된다 —
        // 요청 간격이 0초인 단계별 실행에서 갱신 직후의 토큰·비밀번호 purge 가 보이지 않는다.
        installerInvalidateRuntimeOpcache();

        $data = @include INSTALLER_RUNTIME_PATH;

        return is_array($data) ? $data : null;
    }
}

if (! function_exists('installerSweepStaleRuntimeTmpFiles')) {
    /**
     * `runtime.php.tmp.<hex>` 잔여물을 걷는다.
     *
     * 생성 규칙과 **같은 형태만** 지운다 — 형제 두 지점과 같은 규율이다. 그리고 mtime 을
     * 읽지 못하면 **보존한다**: `(int) @filemtime()` 은 실패 시 `0` 이라 `time() - 0` 이
     * 언제나 하한을 넘어 「아주 오래됨」으로 읽히고, 그러면 **진행 중인 다른 요청의 tmp 를
     * 지운다**(fail-open). 형제 `installer-state.php` 가 앞서 같은 자리를 고쳤다.
     *
     * 요청당 한 번만 훑는다.
     *
     * @return void
     */
    function installerSweepStaleRuntimeTmpFiles(): void
    {
        static $swept = false;
        if ($swept) {
            return;
        }
        $swept = true;

        $prefix = INSTALLER_RUNTIME_PATH.INSTALLER_RUNTIME_TMP_INFIX;

        // `glob` 은 하드닝 프로필의 차단 대상이다 — 막히면 정리를 건너뛴다(잔여물이 남는
        // 것보다 치명이 더 나쁘다).
        foreach (installerHasFunction('glob') ? (glob($prefix.'*') ?: []) : [] as $stale) {
            $stale = (string) $stale;

            if (preg_match(INSTALLER_RUNTIME_TMP_SUFFIX_PATTERN, substr($stale, strlen($prefix))) !== 1) {
                continue;
            }

            $mtime = @filemtime($stale);

            if ($mtime !== false && $mtime < time() - 300) {
                @unlink($stale);
            }
        }
    }
}

if (! function_exists('writeInstallerRuntime')) {
    /**
     * runtime.php 를 atomic 하게 작성한다.
     *
     * 디렉토리 부재 시 생성. 파일 권한은 0600 으로 설정.
     *
     * @param  array<string, mixed>  $data  저장할 배열
     * @return bool 성공 여부
     */
    function writeInstallerRuntime(array $data): bool
    {
        $dir = dirname(INSTALLER_RUNTIME_PATH);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return false;
        }

        $php = "<?php\n\nreturn ".var_export($data, true).";\n";

        // 임시 파일 이름에 pid+uniqid 를 붙이고 rename **전에** 0600 을 건다.
        //
        // 이 파일은 DB·관리자 비밀번호와 stepwise 토큰을 담는다(R3 — 비밀은 0600 채널로만).
        // 고정 이름 `.tmp` 를 기본 umask 로 만들면 그 비밀이 예측 가능한 경로에 0644 로 잠깐
        // 존재해 같은 호스트의 다른 계정이 폴링해 읽을 수 있고, 동시 두 요청이 같은 tmp 를
        // 쓰면 한쪽의 rename 이 다른 쪽 내용을 확정해 클라이언트가 받은 토큰과 파일 속
        // 토큰이 어긋난다. 상태 파일 쓰기(installer-state.php)가 이미 같은 규칙을 쓴다.
        //
        // `getmypid()` 는 쓰지 않는다 — 하드닝 프로필의 차단 대상이라 막힌 호스팅에서
        // PHP 8 은 함수 테이블에서 지우고, 그 호출은 Error(치명)가 되어 이 비밀 채널
        // 자체가 무너진다. 고유성만 필요한 자리다.
        // 남겨진 tmp 를 치운다. 쓰기 성공과 rename 사이에서 프로세스가 죽으면(FPM 타임아웃
        // · OOM-kill · 재시작) tmp 가 남는데 **정리 주체가 없었다** — 형제 둘
        // (`installer-state.php` · `RootHtaccessUpdater`)은 스윕을 갖는데 이것만 빠져 있었다.
        // 이 파일은 DB 자격증명 + APP_KEY + 토큰을 담으므로 형제(상태 파일)보다 등급이
        // 높고, 확장자가 `.php` 가 아니라 배포 레이아웃 ②에서 웹서버가 평문으로 내보낼 수
        // 있다. 요청당 한 번만 훑는다.
        installerSweepStaleRuntimeTmpFiles();

        $tmp = INSTALLER_RUNTIME_PATH.INSTALLER_RUNTIME_TMP_INFIX.installerUniqueSuffix(INSTALLER_TMP_SUFFIX_BYTES);

        // 부분 쓰기는 `false` 가 아니라 쓴 바이트 수다. 이 파일은 `@include` 로 읽는 PHP
        // 소스라 잘리면 `return array(` 가 닫히지 않아 그 요청이 파스 오류로 죽는다 —
        // 게다가 DB 자격증명 + APP_KEY 를 담은 **비밀 채널**이라 잘린 채 rename 으로
        // 확정되면 설치를 진행할 수도 되돌릴 수도 없다. 형제(`installer-state.php`)와
        // 같은 규율로 대조하고, 실패한 tmp 는 남기지 않는다.
        if (! installerWroteFully(@file_put_contents($tmp, $php, LOCK_EX), $php)) {
            @unlink($tmp);

            return false;
        }

        // `chmod` 도 하드닝 프로필이 막을 수 있고, 막히면 PHP 8 은 함수 테이블에서 지우므로
        // 이 호출이 `Error`(치명)가 되어 **비밀 채널 쓰기 자체가 무너진다** — 설치가 한 걸음도
        // 나아가지 못한다. 형제 지점(exposure-check.php 의 sentinel 0600)은 이미 가드가 있었다.
        // 권한을 못 거는 것은 감수한다(비밀 파일은 어차피 곧 지워지고, 대안이 없다).
        if (installerHasFunction('chmod')) {
            @chmod($tmp, 0600);
        }

        // atomic rename — 짧은 핸들 경합은 재시도로 넘긴다 (D4).
        //
        // 이 지점은 형제(`installer-state.php` 의 상태 저장)와 **같은 모양**이고, 위 주석들이
        // 이미 세 번 「형제와 같은 규율」이라고 적고 있다. 그 형제만 재시도를 얻으면 그 짝이
        // 깨진다 — Windows 는 대상 파일을 다른 핸들이 `FILE_SHARE_DELETE` 없이 열고 있으면
        // `rename()` 이 실패하는데(POSIX 는 성공), runtime.php 는 **모든 요청이 `@include` 로
        // 읽는** 파일이라 형제보다 열려 있는 시간이 길다.
        //
        // 게다가 여기서의 실패는 형제보다 무겁다. 상태 저장은 유실돼도 설치가 계속 굴러갔지만
        // (그래서 조용했다), 이 파일은 DB 자격증명·APP_KEY·stepwise 토큰을 담아 쓰기 실패가
        // 곧 그 단계의 실패다. 없앨 수 있는 실패만 걷어낸다 — 없앨 수 없는 실패(권한 소실·
        // 디스크 풀)는 종전과 똑같이 false 로 남는다.
        //
        // 형제 파일을 로드하지 않는 진입 경로에서는 종전 동작 그대로다.
        $replaced = function_exists('installerReplaceFileWithRetry')
            ? installerReplaceFileWithRetry($tmp, INSTALLER_RUNTIME_PATH)
            : @rename($tmp, INSTALLER_RUNTIME_PATH);

        if (! $replaced) {
            @unlink($tmp);

            return false;
        }

        if (installerHasFunction('chmod')) {
            @chmod(INSTALLER_RUNTIME_PATH, 0600);
        }
        installerInvalidateRuntimeOpcache();

        return true;
    }
}

if (! function_exists('installerInvalidateRuntimeOpcache')) {
    /**
     * runtime.php 의 OPcache 항목을 버린다 (갱신 직후 같은 프로세스 풀의 다음 요청이 새 내용을 읽도록).
     *
     * opcache.restrict_api 등으로 호출이 막힌 환경에서는 조용히 건너뛴다 — 그 환경은
     * revalidate 창만큼 늦게 반영될 뿐 파괴적이지 않다.
     */
    function installerInvalidateRuntimeOpcache(): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(INSTALLER_RUNTIME_PATH, true);
        }
    }
}

if (! function_exists('deleteInstallerRuntime')) {
    /**
     * runtime.php 를 삭제한다.
     *
     * @return bool 삭제 성공 또는 이미 부재 시 true
     */
    function deleteInstallerRuntime(): bool
    {
        if (! is_file(INSTALLER_RUNTIME_PATH)) {
            return true;
        }

        $deleted = @unlink(INSTALLER_RUNTIME_PATH);

        // 쓰기 경로와 같은 규약 — 지운 뒤 revalidate 창 안의 다음 요청이 stale 항목을 include 해
        // 이미 삭제된 비밀을 읽지 않도록.
        installerInvalidateRuntimeOpcache();

        return $deleted;
    }
}

if (! function_exists('generateAppKeyInline')) {
    /**
     * APP_KEY 문자열을 pure PHP 로 생성한다.
     *
     * Laravel 의 Encrypter::generateKey('AES-256-CBC') 와 동일한 32 byte 키를
     * 'base64:' prefix 와 함께 반환한다. artisan key:generate 가 .env 를 직접
     * 수정하므로 인스톨러는 본 함수를 사용해 .env 를 건드리지 않고 키를 확보.
     *
     * @return string Laravel 표준 형식 'base64:...'
     */
    function generateAppKeyInline(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }
}

if (! function_exists('backupExistingEnvFile')) {
    /**
     * 기존 `.env` 를 `.env.bak.{timestamp}` 로 1회 보존한다.
     *
     * finalize 는 `.env.example` 을 기반으로 `.env` 를 **재생성**해 전면 대체한다.
     * 그래서 운영자가 이미 넣어 둔 값(메일·큐·레디스·서드파티 키)은 템플릿에 없는 한
     * 통째로 사라지는데, 되돌릴 사본이 아무 데도 남지 않는다. 쓰기 직전에 원본을 한 벌
     * 남겨 두면 재설치·복구 시 그 값을 되찾을 수 있다.
     *
     * 백업 자체가 실패해도 설치를 막지 않는다 — 이 함수는 안전망일 뿐이고, 실패는
     * 호출자가 로그로 남길 수 있도록 null 로 알린다.
     *
     * @param  string|null  $envPath  대상 `.env` 경로 (기본: BASE_PATH/.env)
     * @return string|null 생성된 백업 파일 경로. 원본 부재·백업 실패 시 null
     */
    function backupExistingEnvFile(?string $envPath = null): ?string
    {
        $envPath ??= BASE_PATH.'/.env';

        if (! is_file($envPath)) {
            return null;
        }

        $content = @file_get_contents($envPath);
        if ($content === false) {
            return null;
        }

        // 같은 초에 두 번 호출돼도 서로 덮어쓰지 않도록 접미사를 붙여 빈 이름을 찾는다.
        $base = $envPath.INSTALLER_ENV_BACKUP_INFIX.date(INSTALLER_ENV_BACKUP_STAMP_FORMAT);
        $candidate = $base;
        for ($i = 1; $i <= 50 && file_exists($candidate); $i++) {
            $candidate = $base.'_'.$i;
        }

        if (file_exists($candidate)) {
            return null;
        }

        // 부분 쓰기를 성공으로 읽으면 **잘린 백업**이 유일한 회수 경로로 남는다 —
        // 이 사본의 존재 이유(원본 `.env` 를 제자리 쓰기로 덮기 전 보존)가 그 자리에서
        // 사라진다. 잘린 사본은 지우고 「백업 없음」으로 보고하는 편이 정직하다.
        if (! installerWroteFully(@file_put_contents($candidate, $content, LOCK_EX), $content)) {
            @unlink($candidate);

            return null;
        }

        // 원본과 같은 비밀을 담으므로 소유자만 읽도록 조인다 (chmod 차단 환경은 가드로 통과).
        if (installerHasFunction('chmod')) {
            @chmod($candidate, 0600);
        }

        return $candidate;
    }
}

if (! function_exists('mergeRuntimeIntoEnv')) {
    /**
     * runtime 배열을 .env 문자열에 머지하여 반환한다.
     *
     * .env.example 템플릿 기반 콘텐츠(generateEnvContent() 결과) 에 runtime 의
     * DB 자격증명 + APP_KEY 를 치환하고 INSTALLER_COMPLETED=true 라인을 추가한다.
     * 파일 IO 를 수행하지 않으므로 단위 테스트 가능.
     *
     * 안전망: generateEnvContent() 가 state.config 에서 DB 정보를 못 읽었을 때
     * (예: state.json 결손) 에도 runtime 의 DB 정보로 .env 이 완성되도록 동작.
     *
     * @param  string  $envContent  .env.example 기반 치환된 본문
     * @param  array<string, mixed>  $runtime  runtime.php 배열
     * @return string 최종 .env 콘텐츠
     */
    function mergeRuntimeIntoEnv(string $envContent, array $runtime): string
    {
        // DB 자격증명 치환 — state.config 결손 안전망
        $write = $runtime['db']['write'] ?? null;
        if (is_array($write)) {
            $envContent = replaceEnvLine($envContent, 'DB_WRITE_HOST', escapeEnvLineValue((string) ($write['host'] ?? '')));
            $envContent = replaceEnvLine($envContent, 'DB_WRITE_PORT', escapeEnvLineValue((string) ($write['port'] ?? '')));
            $envContent = replaceEnvLine($envContent, 'DB_WRITE_DATABASE', escapeEnvLineValue((string) ($write['database'] ?? '')));
            $envContent = replaceEnvLine($envContent, 'DB_WRITE_USERNAME', escapeEnvLineValue((string) ($write['username'] ?? '')));
            $envContent = replaceEnvLine($envContent, 'DB_WRITE_PASSWORD', escapeEnvValue((string) ($write['password'] ?? '')));
        }

        // Read DB — runtime 에 read 키가 있을 때(use_read_db=true)만 명시 기록한다.
        // 미지정 시 DB_READ_* 를 빈 값으로 남겨 config/database.php 의 write fallback(Elvis)
        // 에 위임 → .env 에 write 값이 중복 기록되지 않고, write 변경 시 read stale 위험 제거.
        // (이슈 #63)
        $read = $runtime['db']['read'] ?? null;
        if (is_array($read)) {
            $envContent = replaceEnvLine($envContent, 'DB_READ_HOST', escapeEnvLineValue((string) ($read['host'] ?? '')));
            $envContent = replaceEnvLine($envContent, 'DB_READ_PORT', escapeEnvLineValue((string) ($read['port'] ?? '')));
            $envContent = replaceEnvLine($envContent, 'DB_READ_DATABASE', escapeEnvLineValue((string) ($read['database'] ?? '')));
            $envContent = replaceEnvLine($envContent, 'DB_READ_USERNAME', escapeEnvLineValue((string) ($read['username'] ?? '')));
            $envContent = replaceEnvLine($envContent, 'DB_READ_PASSWORD', escapeEnvValue((string) ($read['password'] ?? '')));
        } else {
            $envContent = replaceEnvLine($envContent, 'DB_READ_HOST', '');
            $envContent = replaceEnvLine($envContent, 'DB_READ_PORT', '');
            $envContent = replaceEnvLine($envContent, 'DB_READ_DATABASE', '');
            $envContent = replaceEnvLine($envContent, 'DB_READ_USERNAME', '');
            $envContent = replaceEnvLine($envContent, 'DB_READ_PASSWORD', '');
        }

        if (isset($runtime['db']['prefix'])) {
            $envContent = replaceEnvLine($envContent, 'DB_PREFIX', escapeEnvLineValue((string) $runtime['db']['prefix']));
        }

        // APP_KEY 치환
        $appKey = $runtime['app']['key'] ?? null;
        if (is_string($appKey) && str_starts_with($appKey, 'base64:')) {
            $envContent = replaceEnvLine($envContent, 'APP_KEY', $appKey);
        }

        // INSTALLER_COMPLETED 플래그 추가 (CachesModuleStatus 등이 사용)
        if (! preg_match('/^INSTALLER_COMPLETED=/m', $envContent)) {
            $envContent = rtrim($envContent)."\n\n# Installation Status\nINSTALLER_COMPLETED=true\n";
        }

        return $envContent;
    }
}

if (! function_exists('escapeEnvValue')) {
    /**
     * .env 값 이스케이프 (functions.php 의 동일 함수 polyfill).
     *
     * 인스톨러 본 흐름은 functions.php 가 먼저 로드되므로 if 분기로 진입하지
     * 않으나, 단위 테스트에서 functions.php 없이 installer-runtime.php 만
     * 로드하는 경우를 위한 안전망.
     *
     * @param  string  $value  이스케이프할 값
     * @return string 큰따옴표로 감싸고 내부 큰따옴표/백슬래시를 이스케이프한 값
     */
    function escapeEnvValue(string $value): string
    {
        // 직렬화 정책은 serializeEnvValue 단일 관문이 소유한다 (KVE-2026-2042).
        return serializeEnvValue($value);
    }
}

if (! function_exists('escapeEnvLineValue')) {
    /**
     * .env 라인 값 안전화 (functions.php 의 동일 함수 polyfill).
     *
     * 따옴표가 필요 없는 값은 원문 그대로, 그 외에는 escapeEnvValue() 로 넘긴다.
     *
     * @param  string  $value  원본 값
     * @return string .env 라인에 그대로 이어 붙일 수 있는 값
     */
    function escapeEnvLineValue(string $value): string
    {
        // 빈 값은 빈 라인(`KEY=`)으로 — 따옴표를 씌우면 기존 `.env` 표기가 바뀐다.
        if ($value === '') {
            return '';
        }

        if (preg_match('#^[A-Za-z0-9_.\-/:@]+$#', $value) === 1) {
            return $value;
        }

        return escapeEnvValue($value);
    }
}

if (! function_exists('replaceEnvLine')) {
    /**
     * .env 문자열에서 특정 키의 라인을 치환한다.
     *
     * 라인이 없으면 끝에 추가. 값에 공백/특수문자가 있는 경우 호출자가
     * escapeEnvValue() 등으로 escape 후 전달.
     *
     * @param  string  $envContent  .env 본문
     * @param  string  $key  환경 변수 키
     * @param  string  $value  대입할 값 (escape 처리된 상태)
     * @return string 치환된 .env 본문
     */
    function replaceEnvLine(string $envContent, string $key, string $value): string
    {
        $line = $key.'='.$value;
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        // 치환문자열이 아니라 **콜백**으로 넘긴다. preg_replace 는 두 번째 인자를 치환 규칙으로
        // 해석하므로 값 안의 `$1`·`${1}`·`\1`·`\\` 가 역참조/escape 로 먹혀 값이 조용히 변형된다:
        //   - `a$1b\c` 로 설정한 DB 비밀번호가 `.env` 에 `ab\c` 로 기록되어 설치는 "성공" 으로
        //     끝나고 첫 부팅에서만 인증 실패로 드러난다
        //   - escapeEnvValue() 가 만든 `\\` 가 `\` 로 붕괴해 escape 자체가 무력화된다
        // 콜백 반환값은 치환 규칙 해석을 거치지 않으므로 두 경로가 함께 닫힌다.
        $replaced = preg_replace_callback($pattern, static fn (): string => $line, $envContent, 1, $count);

        if ($count === 0) {
            return rtrim($envContent)."\n".$line."\n";
        }

        return $replaced;
    }
}

if (! function_exists('buildInstallerRuntimeFromState')) {
    /**
     * 설치 상태(state.json 의 config) 에서 runtime.php 용 배열을 생성한다.
     *
     * runtime.php 가 이미 존재하고 APP_KEY 가 있으면 재사용 (재시도 시 키 보존).
     * 없으면 generateAppKeyInline() 으로 새 키 생성.
     *
     * @param  array<string, mixed>  $stateConfig  state.json 의 config 섹션
     * @return array<string, mixed> runtime.php 형식의 배열
     */
    function buildInstallerRuntimeFromState(array $stateConfig): array
    {
        $existing = readInstallerRuntime();
        $appKey = $existing['app']['key'] ?? null;

        if (! $appKey || ! str_starts_with($appKey, 'base64:')) {
            $appKey = generateAppKeyInline();
        }

        // state.config 에는 더 이상 비밀번호가 기록되지 않으므로, 세션이 유실된
        // 재개/재시도 경로에서는 기존 runtime.php 의 값을 보존해야 한다. app.key 보존과
        // 동일한 패턴.
        $writePassword = (string) ($stateConfig['db_write_password'] ?? ($stateConfig['db_password'] ?? ''));
        if ($writePassword === '') {
            $writePassword = (string) ($existing['db']['write']['password'] ?? '');
        }

        $runtime = [
            'db' => [
                'write' => [
                    'host' => $stateConfig['db_write_host'] ?? ($stateConfig['db_host'] ?? '127.0.0.1'),
                    'port' => $stateConfig['db_write_port'] ?? ($stateConfig['db_port'] ?? '3306'),
                    'database' => $stateConfig['db_write_database'] ?? ($stateConfig['db_database'] ?? ''),
                    'username' => $stateConfig['db_write_username'] ?? ($stateConfig['db_username'] ?? ''),
                    'password' => $writePassword,
                ],
                'prefix' => $stateConfig['db_prefix'] ?? '',
            ],
            'app' => [
                'key' => $appKey,
            ],
            'created_at' => date('c'),
        ];

        // admin 비밀번호는 db_seed 가 소비할 때까지 runtime 에 보존한다. 호출자
        // (install-process.php) 가 세션 값으로 덮어쓰는 경우를 제외하면, 재시도/재개 시
        // 기존 runtime 의 값이 유실되지 않아야 한다 (유실 시 db_seed 재실행 불가).
        if (isset($existing['admin']) && is_array($existing['admin'])) {
            $runtime['admin'] = $existing['admin'];
        }

        // GitHub 접속 토큰도 state.config 에 기록되지 않으므로(비밀 5종) 여기서 이송·보존한다.
        // 소비는 env_create / create_settings_json 태스크 — 세션이 유실된 재개 경로에서도
        // 기존 runtime 의 값이 남아 있어야 프라이빗 저장소 설정이 사라지지 않는다.
        $githubToken = (string) ($stateConfig['core_update_github_token'] ?? '');
        if ($githubToken === '') {
            $githubToken = (string) ($existing['core_update']['github_token'] ?? '');
        }
        if ($githubToken !== '') {
            $runtime['core_update'] = ['github_token' => $githubToken];
        }

        // 인스톨러 내부 섹션(단계별 실행 토큰 등)도 보존한다 — env_update 태스크가 runtime 을
        // 다시 만들 때 토큰이 사라지면 그 다음 태스크 요청이 전부 거부된다. 이 섹션은
        // mergeRuntimeIntoEnv 의 화이트리스트 밖이라 .env 로는 새어나가지 않는다.
        if (isset($existing['installer']) && is_array($existing['installer'])) {
            $runtime['installer'] = $existing['installer'];
        }

        // 설치 계획 서명 키 — 재시도/재개 사이에 바뀌면 진행 중 워커가 계획 검증에 실패한다.
        if (is_string($existing['plan_key'] ?? null) && $existing['plan_key'] !== '') {
            $runtime['plan_key'] = $existing['plan_key'];
        }

        // Read 커넥션은 use_read_db 플래그가 켜진 경우에만 포함한다 (판정 SSoT).
        // 플래그가 꺼져 있으면 db_read_host 에 잔존 값이 있어도 무시 → runtime 에 read 키
        // 미생성 → 하류(mergeRuntimeIntoEnv / InstallerRuntimeServiceProvider)가 write 로
        // 자동 동기화. (이슈 #63: use_read_db=false 인데 db_read_host 를 참조하던 회귀 차단)
        if (! empty($stateConfig['use_read_db'])
            && ! empty($stateConfig['db_read_host'])
            && $stateConfig['db_read_host'] !== ($stateConfig['db_write_host'] ?? null)) {
            // read 비밀번호 폴백 순서: state.config → 기존 runtime 의 read 비밀번호 →
            // write 비밀번호. 기존 runtime 을 건너뛰고 write 값으로 대체하면 read 전용
            // 계정의 비밀번호가 write 값으로 오염된다 (부수 수정).
            $readPassword = (string) ($stateConfig['db_read_password'] ?? '');
            if ($readPassword === '') {
                $readPassword = (string) ($existing['db']['read']['password'] ?? '');
            }
            if ($readPassword === '') {
                $readPassword = (string) $runtime['db']['write']['password'];
            }

            $runtime['db']['read'] = [
                'host' => $stateConfig['db_read_host'],
                'port' => $stateConfig['db_read_port'] ?? $runtime['db']['write']['port'],
                'database' => $stateConfig['db_read_database'] ?? $runtime['db']['write']['database'],
                'username' => $stateConfig['db_read_username'] ?? $runtime['db']['write']['username'],
                'password' => $readPassword,
            ];
        }

        return $runtime;
    }
}

if (! function_exists('hydrateDbSecretsFromRuntime')) {
    /**
     * state.config 에서 제거된 DB 비밀번호를 runtime.php 값으로 채운다.
     *
     * state.json 은 더 이상 DB 비밀번호를 보관하지 않으므로, state.config 로 DB 에
     * 접속하던 소비처(db_cleanup / 롤백 seed truncate) 는 이 헬퍼로 자격증명을 복원해야
     * 한다. 두 소비처 모두 env_update 태스크 이후에 실행되므로 runtime.php 는 항상 존재.
     *
     * runtime 부재 또는 config 에 이미 비밀번호가 있으면 원본을 그대로 반환.
     *
     * @param  array<string, mixed>  $config  state.config (비밀번호 결손 가능)
     * @return array<string, mixed> DB 비밀번호가 복원된 config
     */
    function hydrateDbSecretsFromRuntime(array $config): array
    {
        $runtime = readInstallerRuntime();

        if ($runtime === null) {
            return $config;
        }

        if (empty($config['db_write_password'])) {
            $writePassword = $runtime['db']['write']['password'] ?? '';
            if ($writePassword !== '') {
                $config['db_write_password'] = $writePassword;
            }
        }

        if (empty($config['db_read_password'])) {
            $readPassword = $runtime['db']['read']['password'] ?? '';
            if ($readPassword !== '') {
                $config['db_read_password'] = $readPassword;
            }
        }

        return $config;
    }
}

if (! function_exists('installerConfigCachePath')) {
    /**
     * Laravel 설정 캐시 파일 경로 (bootstrap/cache/config.php).
     *
     * @return string 설정 캐시 파일 절대 경로
     */
    function installerConfigCachePath(): string
    {
        return BASE_PATH.'/bootstrap/cache/config.php';
    }
}

if (! function_exists('installerClearConfigCache')) {
    /**
     * 설정 캐시 파일을 지운다 (php artisan config:clear 와 같은 효과).
     *
     * 캐시가 없으면 다음 요청은 .env·config/*.php 로 부팅한다.
     *
     * @return bool 캐시 파일이 없는 상태가 됐으면 true
     */
    function installerClearConfigCache(): bool
    {
        $path = installerConfigCachePath();
        clearstatcache(true, $path);

        if (! is_file($path)) {
            return true;
        }

        @unlink($path);
        clearstatcache(true, $path);

        return ! is_file($path);
    }
}

if (! function_exists('installerRebuildConfigCache')) {
    /**
     * 설정 캐시를 새 artisan 프로세스로 다시 만든다 (.env 최종 병합 직후용).
     *
     * 설치 워커가 만든 설정 캐시는 병합 전 .env 와 runtime.php 주입 값으로 만들어진다. 병합과 runtime.php
     * 삭제가 끝난 뒤 새 프로세스로 다시 만들어야 캐시가 병합된 .env 값을 담는다. 다시 만들지 못하면
     * 캐시를 지운다 — 병합 전 값으로 만든 캐시를 남기지 않는다(다음 요청은 병합된 .env 로 부팅).
     *
     * @param  string  $phpBinary  artisan 을 띄울 PHP 실행 경로 (installer_php_binary_from_state() 결과)
     * @return array{rebuilt: bool, exit_code: int|null, output: string} 다시 만들었는지, 종료 코드, 명령 출력
     */
    function installerRebuildConfigCache(string $phpBinary): array
    {
        // 가용성 판정은 저장소 SSoT(installerHasFunction)를 쓴다 — 무CLI 호스팅은 exec 를 막는다. 이 파일은 머리에서
        // execution-mode.php 를 불러오므로 그 판정기는 언제나 있다(직접 function_exists 로 갈라 판정하지 않는다).
        $canExec = installerHasFunction('exec') && installerHasFunction('escapeshellarg');
        if (! $canExec || ! is_file(BASE_PATH.'/artisan')) {
            installerClearConfigCache();

            // audit:allow web-artisan-spawn-requires-runner 아래 문자열은 실행하지 못한 사유 표시다(스폰이 아니다).
            return ['rebuilt' => false, 'exit_code' => null, 'output' => 'exec() unavailable or artisan missing'];
        }

        // 자식 출력은 파이프가 아닌 파일로 받는다 (설치 워커의 artisan 호출과 같은 이유 — 파이프 교착 회피).
        // sys_get_temp_dir·uniqid 도 하드닝 프로필이 막을 수 있다 — 막히면 PHP 8 은 함수 테이블에서
        // 지우므로 Error 다. 임시 디렉터리는 가용할 때만 폴백으로 쓰고, 이름은 저장소 공용 난수원으로 만든다.
        $outputDir = BASE_PATH.'/storage/logs';
        if ((! is_dir($outputDir) || ! is_writable($outputDir))
            && installerHasFunction('sys_get_temp_dir')) {
            $outputDir = sys_get_temp_dir();
        }
        $uniqueSuffix = function_exists('installerUniqueSuffix') ? installerUniqueSuffix(8) : bin2hex(random_bytes(8));
        $outputFile = $outputDir.DIRECTORY_SEPARATOR.'installer-finalize-config-cache-'.$uniqueSuffix.'.log';

        // audit:allow web-artisan-spawn-requires-runner 설치 마무리(finalize-env)는 응답을 먼저 끝낸 뒤 도는 요청이라 설치기 러너(artisan-executor.php)를 싣지 않는다. 이 자리의 일은 병합된 .env 로 설정 캐시를 새 프로세스에서 다시 굽는 것 하나이고(in-process 로 구우면 이 요청이 부팅한 병합 전 값이 섞인다), exec 가 막힌 무CLI 호스팅은 위 판정에서 캐시를 지우는 쪽으로 빠진다(다음 요청이 병합된 .env 로 부팅).
        $command = escapeshellarg($phpBinary).' -d memory_limit=512M artisan config:cache > '
            .escapeshellarg($outputFile).' 2>&1';

        $previousDir = getcwd();
        @chdir(BASE_PATH);
        $exitCode = 1;
        $ignored = [];
        exec($command, $ignored, $exitCode);
        if ($previousDir !== false) {
            @chdir($previousDir);
        }

        $output = is_file($outputFile) ? trim((string) @file_get_contents($outputFile)) : '';
        @unlink($outputFile);

        clearstatcache(true, installerConfigCachePath());
        $rebuilt = $exitCode === 0 && is_file(installerConfigCachePath());

        if (! $rebuilt) {
            installerClearConfigCache();
        }

        return ['rebuilt' => $rebuilt, 'exit_code' => $exitCode, 'output' => $output];
    }
}
