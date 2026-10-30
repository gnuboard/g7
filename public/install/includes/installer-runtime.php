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

if (! defined('INSTALLER_RUNTIME_PATH')) {
    define('INSTALLER_RUNTIME_PATH', BASE_PATH.'/storage/installer/runtime.php');
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

        $data = @include INSTALLER_RUNTIME_PATH;

        return is_array($data) ? $data : null;
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

        $tmp = INSTALLER_RUNTIME_PATH.'.tmp';
        if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
            return false;
        }

        // atomic rename
        if (! @rename($tmp, INSTALLER_RUNTIME_PATH)) {
            @unlink($tmp);

            return false;
        }

        @chmod(INSTALLER_RUNTIME_PATH, 0600);

        return true;
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

        return @unlink(INSTALLER_RUNTIME_PATH);
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
            $envContent = replaceEnvLine($envContent, 'DB_WRITE_HOST', serializeEnvValue((string) ($write['host'] ?? '')));
            $envContent = replaceEnvLine($envContent, 'DB_WRITE_PORT', serializeEnvValue((string) ($write['port'] ?? '')));
            $envContent = replaceEnvLine($envContent, 'DB_WRITE_DATABASE', serializeEnvValue((string) ($write['database'] ?? '')));
            $envContent = replaceEnvLine($envContent, 'DB_WRITE_USERNAME', serializeEnvValue((string) ($write['username'] ?? '')));
            $envContent = replaceEnvLine($envContent, 'DB_WRITE_PASSWORD', serializeEnvValue((string) ($write['password'] ?? '')));
        }

        // Read DB — runtime 에 read 키가 있을 때(use_read_db=true)만 명시 기록한다.
        // 미지정 시 DB_READ_* 를 빈 값으로 남겨 config/database.php 의 write fallback(Elvis)
        // 에 위임 → .env 에 write 값이 중복 기록되지 않고, write 변경 시 read stale 위험 제거.
        // (이슈 #63)
        $read = $runtime['db']['read'] ?? null;
        if (is_array($read)) {
            $envContent = replaceEnvLine($envContent, 'DB_READ_HOST', serializeEnvValue((string) ($read['host'] ?? '')));
            $envContent = replaceEnvLine($envContent, 'DB_READ_PORT', serializeEnvValue((string) ($read['port'] ?? '')));
            $envContent = replaceEnvLine($envContent, 'DB_READ_DATABASE', serializeEnvValue((string) ($read['database'] ?? '')));
            $envContent = replaceEnvLine($envContent, 'DB_READ_USERNAME', serializeEnvValue((string) ($read['username'] ?? '')));
            $envContent = replaceEnvLine($envContent, 'DB_READ_PASSWORD', serializeEnvValue((string) ($read['password'] ?? '')));
        } else {
            $envContent = replaceEnvLine($envContent, 'DB_READ_HOST', '');
            $envContent = replaceEnvLine($envContent, 'DB_READ_PORT', '');
            $envContent = replaceEnvLine($envContent, 'DB_READ_DATABASE', '');
            $envContent = replaceEnvLine($envContent, 'DB_READ_USERNAME', '');
            $envContent = replaceEnvLine($envContent, 'DB_READ_PASSWORD', '');
        }

        if (isset($runtime['db']['prefix'])) {
            $envContent = replaceEnvLine($envContent, 'DB_PREFIX', serializeEnvValue((string) $runtime['db']['prefix']));
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

        $replaced = preg_replace($pattern, $line, $envContent, 1, $count);

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

        // state.config 에는 더 이상 비밀번호가 기록되지 않으므로(이슈 #465), 세션이 유실된
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
            // 계정의 비밀번호가 write 값으로 오염된다 (이슈 #465 부수 수정).
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
     * state.config 에서 제거된 DB 비밀번호를 runtime.php 값으로 채운다 (이슈 #465).
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
        if (! function_exists('exec') || ! is_file(BASE_PATH.'/artisan')) {
            installerClearConfigCache();

            return ['rebuilt' => false, 'exit_code' => null, 'output' => 'exec() unavailable or artisan missing'];
        }

        // 자식 출력은 파이프가 아닌 파일로 받는다 (설치 워커의 artisan 호출과 같은 이유 — 파이프 교착 회피).
        $outputDir = BASE_PATH.'/storage/logs';
        if (! is_dir($outputDir) || ! is_writable($outputDir)) {
            $outputDir = sys_get_temp_dir();
        }
        $outputFile = $outputDir.DIRECTORY_SEPARATOR.'installer-finalize-config-cache-'.uniqid('', true).'.log';

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
