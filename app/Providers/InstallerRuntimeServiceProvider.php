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
