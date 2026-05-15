<?php

namespace App\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

/**
 * 인스톨러 런타임 설정 주입 ServiceProvider
 *
 * 설치 진행 중 동적 설정(DB 자격증명, APP_KEY) 이 .env 가 아닌
 * storage/installer/runtime.php 에 기록된다. 본 Provider 는 부팅 시
 * 그 파일을 읽어 config('database.*') / config('app.key') 에 주입하여
 * 마이그레이션/시더가 .env 무수정 상태로 동작하도록 한다.
 *
 * 파일 부재 시 즉시 return 하므로 일반 운영 환경에서 오버헤드 없음.
 *
 * `bootstrap/providers.php` 의 첫 번째 항목으로 등록되어
 * SettingsServiceProvider / EncryptionServiceProvider 보다 먼저 실행되어야 한다.
 *
 * @see https://github.com/gnuboard/g7/issues/23
 */
class InstallerRuntimeServiceProvider extends ServiceProvider
{
    /**
     * 인스톨러 런타임 파일 경로 (storage/installer/runtime.php).
     */
    public const RUNTIME_PATH_RELATIVE = 'storage/installer/runtime.php';

    public function register(): void
    {
        // testing 환경은 .env.testing 의 DB/APP_KEY 가 SSoT 이며,
        // 인스톨러 runtime 파일이 존재하더라도 그 값으로 덮어쓰면
        // 프로덕션 DB(g7_2 등) 가 테스트 대상이 되어 RefreshDatabase 가
        // 프로덕션 데이터를 파괴할 수 있다. 테스트 환경에서는 무조건 skip.
        if ($this->app->environment('testing')) {
            return;
        }

        $runtimePath = base_path(self::RUNTIME_PATH_RELATIVE);

        if (! is_file($runtimePath)) {
            // beta.7 한시 보정 — 이미 finalize 완료된 환경의 stale process ENV 회귀 차단.
            //
            // 배경 (이슈 #371): beta.4~6 손상 환경에서 beta.7 코어 업그레이드의 spawn 자식 1
            // (core:execute-upgrade-steps) 이 .env 머지 + runtime.php 삭제를 마쳐도, 부모
            // 프로세스의 process ENV (getenv / $_ENV / $_SERVER) 는 부팅 시점의 stale 값
            // (.env.example fallback: DB_WRITE_USERNAME=root) 그대로 유지된다. 그 직후 부모가
            // 띄우는 spawn 자식 2 (core:execute-bundled-updates) 에 stale ENV 가 그대로
            // 상속되어 Laravel Dotenv::createImmutable() 이 디스크의 정합 .env 값을 보지 못해
            // env('DB_WRITE_USERNAME') 이 stale 'root' 반환 → CoreServiceProvider::boot() 의
            // isDatabaseConnectionValid() 가드가 트리거되어 확장 로딩 skip + ERROR 출력 →
            // 번들 일괄 업데이트 전체 실패.
            //
            // 본 보정은 자식 2 가 부팅할 때 InstallerRuntimeServiceProvider::register() (가장 먼저
            // 실행되는 ServiceProvider) 안에서 다음 4개 조건을 모두 만족하면 디스크 .env 를
            // 직접 파싱하여 process ENV 를 갱신한다:
            //   (1) runtime.php 부재 (이미 finalize 완료된 상태 — 본 분기에 도달)
            //   (2) .env 존재
            //   (3) .env 의 INSTALLER_COMPLETED 가 truthy (finalize 성공 신호)
            //   (4) .env 의 DB_WRITE_USERNAME 이 정합 (root 아님 + 빈 값 아님)
            //       AND process ENV 의 DB_WRITE_USERNAME 이 stale (root 또는 빈 값)
            //
            // 정상 환경에서는 (4) 의 process ENV stale 시그니처가 false 이므로 보정 미발동.
            $this->recoverStaleProcessEnvFromDiskEnv();

            // 본 PR 부수효과 정리 — beta.7 업그레이드 흐름의 모든 root 권한 자식 spawn
            // 부팅마다 자동으로 storage/framework/cache/data/ 하위 root 소유 디렉토리를
            // 즉시 chown. 자식 1 (FinalizeOrphanedInstallerRuntime) 종료 후에 자식 2~N
            // (번들 update / 후속 자식들) 이 root 권한으로 캐시 쓰기를 트리거하더라도
            // 그 자식 자체의 ServiceProvider 부팅 시점에 본 보정이 발화하여 정리.
            //
            // 가드:
            //   (1) runtime.php 부재 (이미 finalize 완료 — 본 분기에 도달)
            //   (2) 디스크 config/app.php 의 version === '7.0.0-beta.7' (recover 와 동일 가드)
            //   (3) posix_geteuid() === 0 (root 권한 자식만)
            //
            // 정상 환경(beta.3 / beta.8+) 또는 PHP-FPM 워커(www-data 권한) 는 (2) 또는 (3)
            // 가드로 자동 비활성화.
            $this->normalizeRootOwnedCacheDirs();

            return;
        }

        $runtime = @include $runtimePath;

        if (! is_array($runtime)) {
            return;
        }

        $this->applyDatabaseConfig($runtime);
        $this->applyAppKey($runtime);
    }

    /**
     * beta.7 한시 보정 — 디스크 .env 가 정합이고 process ENV 가 stale 일 때만 갱신.
     *
     * 트리거 조건이 매우 보수적이라 정상 환경 부팅 비용은 .env 의 parseEnvFile 1회 + 키 4~5개
     * 비교. 손상 환경에서만 putenv/$_ENV/$_SERVER 갱신이 일어남.
     */
    private function recoverStaleProcessEnvFromDiskEnv(): void
    {
        // beta.7 한시 가드 — 본 보정은 beta.4~6 finalize 결함 환경의 회귀 차단 전용.
        // 디스크 config/app.php 의 'version' 값을 라인 매칭으로 추출 (메모리/env 무관)
        // 하여 정확히 beta.7 로 업그레이드 중인 경우에만 발화. 메모리 config('app.version')
        // 은 부모 stale ENV 의 APP_VERSION 을 따라가므로 신뢰할 수 없음.
        $coreVersion = $this->readCoreVersionFromDisk();
        if ($coreVersion !== '7.0.0-beta.7') {
            return;
        }

        $envPath = base_path('.env');

        if (! is_file($envPath)) {
            return;
        }

        // parse_ini_file 은 .env 가 PHP INI 와 100% 호환되지 않는 케이스 (예약어 값,
        // 일부 특수문자) 에서 실패 가능. 라인 단위 직접 파싱으로 호환성 함정 회피.
        $parsed = $this->parseEnvFile($envPath);

        if (! is_array($parsed) || $parsed === []) {
            return;
        }

        // 조건 (3): INSTALLER_COMPLETED 가 truthy 여야 함 (finalize 완료 신호)
        $completedFlagRaw = (string) ($parsed['INSTALLER_COMPLETED'] ?? '');
        $completedFlag = strtolower(trim($completedFlagRaw, " \t\"'"));
        if (! in_array($completedFlag, ['true', '1', 'yes'], true)) {
            return;
        }

        // 조건 (4-a): .env 의 DB_WRITE_USERNAME 이 정합 (root 아님 + 비어있지 않음)
        $diskUsernameRaw = (string) ($parsed['DB_WRITE_USERNAME'] ?? '');
        $diskUsername = $this->unwrapEnvValue($diskUsernameRaw);
        if ($diskUsername === '' || $diskUsername === 'root') {
            return;
        }

        // 조건 (4-b): process ENV 의 DB_WRITE_USERNAME 이 stale (root 또는 빈 값).
        //
        // bootstrap/app.php 의 Env::disablePutenv() 로 인해 getenv 가 빈 값일 수 있으므로,
        // getenv / $_ENV / $_SERVER 셋 중 어느 하나라도 정합 (root 아님 + 빈 값 아님) 이면
        // 정상 환경으로 판정하여 보정 skip. 셋 다 stale (root 또는 빈) 일 때만 진입.
        $envUsername = (string) getenv('DB_WRITE_USERNAME');
        $envUsernameSE = (string) ($_ENV['DB_WRITE_USERNAME'] ?? '');
        $serverUsernameSE = (string) ($_SERVER['DB_WRITE_USERNAME'] ?? '');
        foreach ([$envUsername, $envUsernameSE, $serverUsernameSE] as $candidate) {
            if ($candidate !== '' && $candidate !== 'root') {
                return;
            }
        }

        // 4개 조건 모두 만족 — process ENV 갱신
        $refreshableKeys = [
            'DB_WRITE_HOST', 'DB_WRITE_PORT', 'DB_WRITE_DATABASE', 'DB_WRITE_USERNAME', 'DB_WRITE_PASSWORD',
            'DB_READ_HOST', 'DB_READ_PORT', 'DB_READ_DATABASE', 'DB_READ_USERNAME', 'DB_READ_PASSWORD',
            'DB_PREFIX', 'APP_KEY',
        ];

        foreach ($refreshableKeys as $key) {
            if (! array_key_exists($key, $parsed)) {
                continue;
            }
            $value = $this->unwrapEnvValue((string) $parsed[$key]);
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        // Laravel Config 도 동기 갱신 — register() 시점에 LoadConfiguration 이 이미 실행되어
        // process ENV 만 갱신해도 config('database.connections.mysql.*') 가 stale 값
        // (root) 으로 캐시된 상태이기 때문에 직접 덮어써야 한다.
        $this->applyConfigFromParsedEnv($parsed);
    }

    /**
     * recover 분기 — 파싱된 .env 의 DB_* / APP_KEY / DB_PREFIX 를 Laravel Config 에 적용.
     *
     * 기존 `applyDatabaseConfig` 와 동등한 키 경로 / 구조 (`database.connections.mysql.{read,write}.*`).
     *
     * @param  array<string, string>  $parsed
     */
    private function applyConfigFromParsedEnv(array $parsed): void
    {
        $writeHost = $parsed['DB_WRITE_HOST'] ?? null;
        if ($writeHost !== null && $writeHost !== '') {
            Config::set('database.connections.mysql.write.host', [$writeHost]);
            Config::set('database.connections.mysql.write.port', $parsed['DB_WRITE_PORT'] ?? '3306');
            Config::set('database.connections.mysql.write.database', $parsed['DB_WRITE_DATABASE'] ?? '');
            Config::set('database.connections.mysql.write.username', $parsed['DB_WRITE_USERNAME'] ?? '');
            Config::set('database.connections.mysql.write.password', $parsed['DB_WRITE_PASSWORD'] ?? '');
        }

        $readHost = $parsed['DB_READ_HOST'] ?? null;
        if ($readHost !== null && $readHost !== '') {
            Config::set('database.connections.mysql.read.host', [$readHost]);
            Config::set('database.connections.mysql.read.port', $parsed['DB_READ_PORT'] ?? '3306');
            Config::set('database.connections.mysql.read.database', $parsed['DB_READ_DATABASE'] ?? '');
            Config::set('database.connections.mysql.read.username', $parsed['DB_READ_USERNAME'] ?? '');
            Config::set('database.connections.mysql.read.password', $parsed['DB_READ_PASSWORD'] ?? '');
        } elseif ($writeHost !== null && $writeHost !== '') {
            // read 가 별도 지정되지 않은 경우 write 값으로 동기화 (단일 DB 시나리오)
            Config::set('database.connections.mysql.read.host', [$writeHost]);
            Config::set('database.connections.mysql.read.port', $parsed['DB_WRITE_PORT'] ?? '3306');
            Config::set('database.connections.mysql.read.database', $parsed['DB_WRITE_DATABASE'] ?? '');
            Config::set('database.connections.mysql.read.username', $parsed['DB_WRITE_USERNAME'] ?? '');
            Config::set('database.connections.mysql.read.password', $parsed['DB_WRITE_PASSWORD'] ?? '');
        }

        if (isset($parsed['DB_PREFIX'])) {
            Config::set('database.connections.mysql.prefix', $parsed['DB_PREFIX']);
        }

        $appKey = $parsed['APP_KEY'] ?? null;
        if (is_string($appKey) && $appKey !== '') {
            Config::set('app.key', $appKey);
        }

        // 기존 connection 인스턴스의 stale credential 캐시 폐기 — 다음 DB::connection() 호출 시
        // 새 Config 로 재인스턴스화되도록 강제. config.write.username 이 stale root 로
        // 남아 있던 회귀의 부속 원인 차단 (DB Manager 의 connection 캐시).
        try {
            \Illuminate\Support\Facades\DB::purge('mysql');
        } catch (\Throwable $e) {
            // non-fatal — Config 만 갱신된 상태로도 다음 connection 시 정합 값 사용
        }
    }

    /**
     * beta.7 업그레이드 자식 spawn 부팅마다 자동 발동 — storage/framework/cache/data/
     * 하위 root 소유 디렉토리/파일을 정상 owner/group 으로 chown.
     *
     * 본 PR 의 recover 가 발화하면서 부수적으로 만들 수 있는 root 권한 캐시 디렉토리를
     * 자식 자체의 부팅 시점에 자동 청소한다. 안전망 역할 — recover 가 정상 발화하여
     * loadModules 가 정상 권한 캐시를 생성하면 본 메서드는 found=0 으로 no-op.
     *
     * 가드:
     *   - 디스크 config/app.php version === '7.0.0-beta.7' (recover 와 동일)
     *   - posix_geteuid() === 0 (root 권한 자식만 — 운영자 의도 권한 보존)
     *   - cache/data 자신이 정합 owner/group (chown 기준값 추정)
     */
    private function normalizeRootOwnedCacheDirs(): void
    {
        // beta.7 한정 — recover 와 동일 가드
        $coreVersion = $this->readCoreVersionFromDisk();
        if ($coreVersion !== '7.0.0-beta.7') {
            return;
        }

        if (! function_exists('posix_geteuid') || ! function_exists('chown') || ! function_exists('chgrp')) {
            return;
        }

        // root 권한 프로세스만 (PHP-FPM 워커는 www-data → euid != 0 → skip)
        if (posix_geteuid() !== 0) {
            return;
        }

        $cacheRoot = base_path('storage/framework/cache/data');
        if (! is_dir($cacheRoot)) {
            return;
        }

        // 정상 owner/group 추정 (cache/data 자신이 SSoT)
        $targetUid = @fileowner($cacheRoot);
        $targetGid = @filegroup($cacheRoot);
        if (! is_int($targetUid) || ! is_int($targetGid) || $targetUid === 0) {
            // cache/data 자신이 root 소유면 추정 실패 — bootstrap/cache fallback
            $bootstrapCache = base_path('bootstrap/cache');
            if (is_dir($bootstrapCache)) {
                $targetUid = @fileowner($bootstrapCache);
                $targetGid = @filegroup($bootstrapCache);
            }
            if (! is_int($targetUid) || ! is_int($targetGid) || $targetUid === 0) {
                return;
            }
        }

        // 1depth 만 빠르게 점검 — root 소유 디렉토리 발견 시 재귀 chown
        $handle = @opendir($cacheRoot);
        if ($handle === false) {
            return;
        }
        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $cacheRoot.DIRECTORY_SEPARATOR.$name;
            $uid = @fileowner($path);
            $gid = @filegroup($path);
            if ($uid !== 0 && $gid !== 0) {
                continue;
            }
            // root 소유 항목만 보정 — 사용자가 의도해서 만든 다른 소유자는 보존
            $this->chownRecursive($path, $targetUid, $targetGid);
        }
        closedir($handle);
    }

    /**
     * 디렉토리/파일을 재귀적으로 chown + chgrp.
     *
     * @return bool 모든 항목 성공 시 true
     */
    private function chownRecursive(string $path, int $uid, int $gid): bool
    {
        $ok = @chown($path, $uid) && @chgrp($path, $gid);
        if (! is_dir($path) || is_link($path)) {
            return $ok;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $entry) {
            /** @var \SplFileInfo $entry */
            $childPath = $entry->getPathname();
            $ok = (@chown($childPath, $uid) && @chgrp($childPath, $gid)) && $ok;
        }

        return $ok;
    }

    /**
     * 디스크 `config/app.php` 의 `'version' => ...` 라인을 정규식 매칭으로 추출.
     *
     * 메모리 config('app.version') 은 부모 stale ENV 의 APP_VERSION 을 따르므로
     * 본 fix 의 자기 활성화 가드로 사용 불가. 디스크 SSoT (config/app.php) 직접 읽기로
     * 코어 업그레이드 시점의 실제 적용 버전 확보.
     *
     * @return string|null 추출 실패 시 null
     */
    private function readCoreVersionFromDisk(): ?string
    {
        $configPath = base_path('config/app.php');
        if (! is_file($configPath)) {
            return null;
        }
        $content = @file_get_contents($configPath);
        if ($content === false) {
            return null;
        }
        // 'version' => env('APP_VERSION', '7.0.0-beta.7'),
        if (preg_match("/'version'\s*=>\s*env\(\s*'APP_VERSION'\s*,\s*'([^']+)'\s*\)/", $content, $m)) {
            return $m[1];
        }
        // fallback: 'version' => '7.0.0-beta.7',
        if (preg_match("/'version'\s*=>\s*'([^']+)'/", $content, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * .env 파일을 라인 단위로 직접 파싱하여 key=>value 배열 반환.
     *
     * parse_ini_file 의 PHP INI 호환성 함정을 회피하기 위해 자체 파싱:
     *   - 빈 줄 / `#` 또는 `;` 로 시작하는 주석 줄 무시
     *   - 첫 `=` 기준으로 key/value 분할 (값에 `=` 포함 가능: APP_KEY=base64:...= 등)
     *   - 외곽 인용부호 (single/double) 제거 — unwrapEnvValue 사용
     *   - 키 이름은 [A-Za-z_][A-Za-z0-9_]* 만 인정
     *
     * @return array<string, string>
     */
    private function parseEnvFile(string $envPath): array
    {
        $content = @file_get_contents($envPath);
        if ($content === false) {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false) {
            return [];
        }

        $result = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
                continue;
            }
            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false || $eqPos === 0) {
                continue;
            }
            $key = rtrim(substr($trimmed, 0, $eqPos));
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            $value = substr($trimmed, $eqPos + 1);
            // 줄 끝의 행간 공백 제거 (인용된 값은 인용부호 안의 내용 보존)
            $value = rtrim($value, " \t");
            $value = $this->unwrapEnvValue($value);
            $result[$key] = $value;
        }

        return $result;
    }

    private function unwrapEnvValue(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, $length - 2);
            }
        }

        return $value;
    }

    /**
     * runtime 배열의 DB 자격증명을 config('database.connections.mysql.*') 에 주입.
     *
     * @param  array<string, mixed>  $runtime
     */
    protected function applyDatabaseConfig(array $runtime): void
    {
        $write = $runtime['db']['write'] ?? null;

        if (! is_array($write)) {
            return;
        }

        $prefix = $runtime['db']['prefix'] ?? '';

        // mysql 커넥션의 read/write 구조에 맞춰 주입
        // config/database.php:48-65 가 이 키 구조를 사용
        if (! empty($write['host'])) {
            Config::set('database.connections.mysql.write.host', [$write['host']]);
            Config::set('database.connections.mysql.write.port', $write['port'] ?? '3306');
            Config::set('database.connections.mysql.write.database', $write['database'] ?? '');
            Config::set('database.connections.mysql.write.username', $write['username'] ?? '');
            Config::set('database.connections.mysql.write.password', $write['password'] ?? '');
        }

        $read = $runtime['db']['read'] ?? null;
        if (is_array($read) && ! empty($read['host'])) {
            Config::set('database.connections.mysql.read.host', [$read['host']]);
            Config::set('database.connections.mysql.read.port', $read['port'] ?? '3306');
            Config::set('database.connections.mysql.read.database', $read['database'] ?? '');
            Config::set('database.connections.mysql.read.username', $read['username'] ?? '');
            Config::set('database.connections.mysql.read.password', $read['password'] ?? '');
        } else {
            // read 가 별도 지정되지 않은 경우 write 값으로 동기화 (단일 DB 시나리오)
            Config::set('database.connections.mysql.read.host', [$write['host']]);
            Config::set('database.connections.mysql.read.port', $write['port'] ?? '3306');
            Config::set('database.connections.mysql.read.database', $write['database'] ?? '');
            Config::set('database.connections.mysql.read.username', $write['username'] ?? '');
            Config::set('database.connections.mysql.read.password', $write['password'] ?? '');
        }

        Config::set('database.connections.mysql.prefix', $prefix);
    }

    /**
     * runtime 배열의 APP_KEY 를 config('app.key') 에 주입.
     *
     * Encrypter 가 lazy resolve 이므로 register 단계 변경으로 충분.
     *
     * @param  array<string, mixed>  $runtime
     */
    protected function applyAppKey(array $runtime): void
    {
        $key = $runtime['app']['key'] ?? null;

        if (! is_string($key) || $key === '') {
            return;
        }

        Config::set('app.key', $key);
    }
}
