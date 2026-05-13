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

if (! defined('INSTALLER_RUNTIME_PATH')) {
    define('INSTALLER_RUNTIME_PATH', BASE_PATH . '/storage/installer/runtime.php');
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

        $php = "<?php\n\nreturn " . var_export($data, true) . ";\n";

        $tmp = INSTALLER_RUNTIME_PATH . '.tmp';
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
        return 'base64:' . base64_encode(random_bytes(32));
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
            $envContent = replaceEnvLine($envContent, 'DB_WRITE_HOST', (string) ($write['host'] ?? ''));
            $envContent = replaceEnvLine($envContent, 'DB_WRITE_PORT', (string) ($write['port'] ?? ''));
            $envContent = replaceEnvLine($envContent, 'DB_WRITE_DATABASE', (string) ($write['database'] ?? ''));
            $envContent = replaceEnvLine($envContent, 'DB_WRITE_USERNAME', (string) ($write['username'] ?? ''));
            $envContent = replaceEnvLine($envContent, 'DB_WRITE_PASSWORD', escapeEnvValue((string) ($write['password'] ?? '')));
        }

        $read = $runtime['db']['read'] ?? $write; // read 미지정 시 write 와 동기화
        if (is_array($read)) {
            $envContent = replaceEnvLine($envContent, 'DB_READ_HOST', (string) ($read['host'] ?? ''));
            $envContent = replaceEnvLine($envContent, 'DB_READ_PORT', (string) ($read['port'] ?? ''));
            $envContent = replaceEnvLine($envContent, 'DB_READ_DATABASE', (string) ($read['database'] ?? ''));
            $envContent = replaceEnvLine($envContent, 'DB_READ_USERNAME', (string) ($read['username'] ?? ''));
            $envContent = replaceEnvLine($envContent, 'DB_READ_PASSWORD', escapeEnvValue((string) ($read['password'] ?? '')));
        }

        if (isset($runtime['db']['prefix'])) {
            $envContent = replaceEnvLine($envContent, 'DB_PREFIX', (string) $runtime['db']['prefix']);
        }

        // APP_KEY 치환
        $appKey = $runtime['app']['key'] ?? null;
        if (is_string($appKey) && str_starts_with($appKey, 'base64:')) {
            $envContent = replaceEnvLine($envContent, 'APP_KEY', $appKey);
        }

        // INSTALLER_COMPLETED 플래그 추가 (CachesModuleStatus 등이 사용)
        if (! preg_match('/^INSTALLER_COMPLETED=/m', $envContent)) {
            $envContent = rtrim($envContent) . "\n\n# Installation Status\nINSTALLER_COMPLETED=true\n";
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
        // CR/LF 제거 — .env 라인 주입 차단 (functions.php 의 정의와 동일 정책)
        if ($value !== '') {
            $value = str_replace(["\r", "\n"], '', $value);
        }

        if ($value === '') {
            return '""';
        }

        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return '"' . $escaped . '"';
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
        $line = $key . '=' . $value;
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        $replaced = preg_replace($pattern, $line, $envContent, 1, $count);

        if ($count === 0) {
            return rtrim($envContent) . "\n" . $line . "\n";
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

        $runtime = [
            'db' => [
                'write' => [
                    'host' => $stateConfig['db_write_host'] ?? ($stateConfig['db_host'] ?? '127.0.0.1'),
                    'port' => $stateConfig['db_write_port'] ?? ($stateConfig['db_port'] ?? '3306'),
                    'database' => $stateConfig['db_write_database'] ?? ($stateConfig['db_database'] ?? ''),
                    'username' => $stateConfig['db_write_username'] ?? ($stateConfig['db_username'] ?? ''),
                    'password' => $stateConfig['db_write_password'] ?? ($stateConfig['db_password'] ?? ''),
                ],
                'prefix' => $stateConfig['db_prefix'] ?? '',
            ],
            'app' => [
                'key' => $appKey,
            ],
            'created_at' => date('c'),
        ];

        // Read 커넥션이 별도로 지정된 경우만 포함 (그렇지 않으면 Laravel 이 write 사용)
        if (! empty($stateConfig['db_read_host']) && $stateConfig['db_read_host'] !== ($stateConfig['db_write_host'] ?? null)) {
            $runtime['db']['read'] = [
                'host' => $stateConfig['db_read_host'],
                'port' => $stateConfig['db_read_port'] ?? $runtime['db']['write']['port'],
                'database' => $stateConfig['db_read_database'] ?? $runtime['db']['write']['database'],
                'username' => $stateConfig['db_read_username'] ?? $runtime['db']['write']['username'],
                'password' => $stateConfig['db_read_password'] ?? $runtime['db']['write']['password'],
            ];
        }

        return $runtime;
    }
}
