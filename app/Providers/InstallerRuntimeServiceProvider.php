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
     * 트리거 조건이 매우 보수적이라 정상 환경 부팅 비용은 .env 의 parse_ini_file 1회 + 키 4~5개
     * 비교. 손상 환경에서만 putenv/$_ENV/$_SERVER 갱신이 일어남.
     */
    private function recoverStaleProcessEnvFromDiskEnv(): void
    {
        $envPath = base_path('.env');

        if (! is_file($envPath)) {
            return;
        }

        $parsed = @parse_ini_file($envPath, false, INI_SCANNER_RAW);

        if (! is_array($parsed)) {
            return;
        }

        // 조건 (3): INSTALLER_COMPLETED 가 truthy 여야 함 (finalize 완료 신호)
        $completedFlag = strtolower(trim((string) ($parsed['INSTALLER_COMPLETED'] ?? ''), " \t\"'"));
        if (! in_array($completedFlag, ['true', '1', 'yes'], true)) {
            return;
        }

        // 조건 (4-a): .env 의 DB_WRITE_USERNAME 이 정합 (root 아님 + 비어있지 않음)
        $diskUsername = $this->unwrapEnvValue((string) ($parsed['DB_WRITE_USERNAME'] ?? ''));
        if ($diskUsername === '' || $diskUsername === 'root') {
            return;
        }

        // 조건 (4-b): process ENV 의 DB_WRITE_USERNAME 이 stale (root 또는 빈 값)
        $envUsername = (string) getenv('DB_WRITE_USERNAME');
        if ($envUsername !== '' && $envUsername !== 'root') {
            return;
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
