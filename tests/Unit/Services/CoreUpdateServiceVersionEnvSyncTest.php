<?php

namespace Tests\Unit\Services;

use App\Services\CoreUpdateService;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `CoreUpdateService::updateVersionInEnv()` 가 **이 프로세스**에도 새 버전을 반영하는지 검증한다.
 *
 * 회귀 시나리오 (G8 실측 2026-09-02):
 *   1. 웹 코어 업데이트 마무리 단계가 `.env` 의 `APP_VERSION` 을 새 버전으로 고친다
 *   2. **같은 요청 안에서** `ConfigCacheHelper::rebuild()` 가 `config:cache` 를 굽는다
 *   3. Dotenv 의 immutable 로더는 이미 프로세스에 실린 키를 덮지 않으므로 새 캐시에
 *      **옛 버전**이 박제된다
 *   4. 업데이트가 성공했는데도 사이트가 옛 버전으로 동작한다 (관리자 푸터 · `config('app.version')`
 *      · 화면에 내려가는 `appConfig.version` 전부) — 다음 수명주기 이벤트가 캐시를 다시 구울
 *      때까지 이어진다
 *
 * 실측: 7.0.10 → 7.0.11 완주 후 `bootstrap/cache/config.php` 가 `'version' => '7.0.10'` 이었고
 * 그 파일을 지우자 즉시 7.0.11 로 바뀌었다. 7.0.11 → 7.0.12 에서도 같은 형태로 재현했다.
 *
 * 디스크만 검사하는 테스트는 이 결함을 통과시킨다 — `.env` 는 언제나 옳았다.
 * 그래서 이 테스트는 **프로세스가 읽는 값**(`env()` · `config('app.version')`)을 잰다.
 *
 * 저장소의 실제 `.env` 는 건드리지 않는다 — 임시 디렉토리로 base path 를 옮겨 그 안의
 * `.env` 만 고친다 (개발 `.env` 파괴 사고 방지).
 */
class CoreUpdateServiceVersionEnvSyncTest extends TestCase
{
    private string $sandbox = '';

    private string $originalBasePath = '';

    private ?string $originalProcessVersion = null;

    private mixed $originalConfigVersion = null;

    /**
     * 기준 경로와 프로세스, config 의 APP_VERSION 원래 값을 기억하고 .env 를 둘 임시 샌드박스를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBasePath = base_path();
        $this->originalProcessVersion = $_ENV['APP_VERSION'] ?? null;
        $this->originalConfigVersion = config('app.version');

        $this->sandbox = storage_path('framework/testing/core-update-env-'.uniqid());
        File::ensureDirectoryExists($this->sandbox);
    }

    /**
     * 기준 경로, 슈퍼전역 APP_VERSION, config 버전을 원래대로 되돌리고 샌드박스를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBasePath);

        if ($this->sandbox !== '' && File::isDirectory($this->sandbox)) {
            File::deleteDirectory($this->sandbox);
        }

        // 슈퍼전역은 프로세스 전역이라 되돌리지 않으면 형제 테스트로 샌다.
        if ($this->originalProcessVersion === null) {
            unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
        } else {
            $_ENV['APP_VERSION'] = $this->originalProcessVersion;
            $_SERVER['APP_VERSION'] = $this->originalProcessVersion;
        }

        config(['app.version' => $this->originalConfigVersion]);

        parent::tearDown();
    }

    /**
     * `.env` 를 고친 뒤 **같은 프로세스**의 `env()` · `config('app.version')` 이 새 값을 본다.
     *
     * @scenario memory_version_source=env_app_version
     *
     * @effects env_version_update_reaches_the_running_process
     *
     * @return void 반환값 없음
     */
    public function test_version_update_reaches_the_running_process_not_only_the_env_file(): void
    {
        File::put($this->sandbox.'/.env', "APP_NAME=G7\nAPP_VERSION=0.0.1-before\n");
        $this->app->setBasePath($this->sandbox);

        // 부팅 시점의 값이 프로세스에 실려 있는 상태를 만든다 — 결함의 전제다.
        $_ENV['APP_VERSION'] = '0.0.1-before';
        $_SERVER['APP_VERSION'] = '0.0.1-before';
        config(['app.version' => '0.0.1-before']);

        app(CoreUpdateService::class)->updateVersionInEnv('9.9.9-after');

        $this->assertStringContainsString(
            'APP_VERSION=9.9.9-after',
            File::get($this->sandbox.'/.env'),
            '.env 파일이 새 버전으로 갱신되지 않았다'
        );

        $this->assertSame(
            '9.9.9-after',
            Env::get('APP_VERSION'),
            '프로세스 환경 저장소가 옛 버전을 들고 있다 — 같은 요청에서 구운 config 캐시에 옛 값이 박제된다'
        );

        $this->assertSame(
            '9.9.9-after',
            config('app.version'),
            '런타임 config 미러가 옛 버전을 들고 있다 — 같은 요청의 나머지 구간이 옛 버전으로 동작한다'
        );
    }

    /**
     * `.env` 가 없으면 프로세스 값도 건드리지 않는다 (조기 반환 계약 보존).
     *
     * 이 갈래가 프로세스 값을 먼저 고치면, 파일이 없어 되돌릴 수 없는 상태에서 메모리만
     * 새 버전이 되어 디스크와 메모리가 영구히 어긋난다.
     *
     * @scenario memory_version_source=config_only_env_absent
     *
     * @effects missing_env_file_leaves_process_version_untouched
     *
     * @return void 반환값 없음
     */
    public function test_missing_env_file_leaves_process_version_untouched(): void
    {
        // 샌드박스에 `.env` 를 만들지 않는다.
        $this->app->setBasePath($this->sandbox);

        $_ENV['APP_VERSION'] = '1.2.3-kept';
        $_SERVER['APP_VERSION'] = '1.2.3-kept';
        config(['app.version' => '1.2.3-kept']);

        app(CoreUpdateService::class)->updateVersionInEnv('9.9.9-after');

        $this->assertSame('1.2.3-kept', Env::get('APP_VERSION'));
        $this->assertSame('1.2.3-kept', config('app.version'));
        $this->assertFileDoesNotExist($this->sandbox.'/.env');
    }
}
