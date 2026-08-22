<?php

namespace Tests\Feature\DevTools;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Support\DevTools\DebugGate;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * DevTools 엔드포인트의 디버그 모드 게이트 회귀 테스트.
 *
 * 배경:
 *   게이트는 원래 `config('app.debug') || \App\Models\Setting::getValue('debug.mode', false)`
 *   였는데 `App\Models\Setting` 은 저장소에 존재하지 않는 유물이었다. `||` 단축평가 덕에
 *   `APP_DEBUG=true` 인 동안에는 두 번째 항이 평가되지 않아 드러나지 않았을 뿐이고,
 *   `APP_DEBUG=false` + settings JSON 의 `debug` 카테고리 미동기화가 겹치면
 *   **403 이어야 할 응답이 `Class "App\Models\Setting" not found` 500 이 됐다.**
 *
 * 검증 축:
 *   1. app.debug=false + debug.mode=true  → 200 (두 번째 항이 실제로 평가되어야 한다)
 *   2. app.debug=false + debug.mode=false → 403 (500 이 아니어야 한다)
 *   3. app.debug=true                     → 200 (환경설정 조회 없이 통과)
 *
 * 게이트 적용 범위 (이슈 #128):
 *   게이트는 원래 POST 3종의 **핸들러 안**에만 있었다. GET 4종과 `DELETE clear` 는 게이트가
 *   없었고, 그중 `clear` 는 `File::cleanDirectory(storage/debug-dump)` 를 수행하는 파괴적
 *   엔드포인트다 — production·APP_DEBUG=false 에서도 미인증 요청이 200 으로 통과했다.
 *   GET 4종은 `routes/web.php` 의 User SPA catch-all 이 등록 순서상 앞서 가려 주고 있었을
 *   뿐이라(우연한 shadow) 라우트가 노출되는 순간 그대로 열린다.
 *
 *   이제 게이트는 `bootstrap/app.php` 의 devtools 래퍼가 그룹 미들웨어(`debug.gate`)로
 *   단일 적용하므로, 아래 테스트들은 GET·POST·DELETE 전 메서드가 같은 판정을 공유함을
 *   행위로 확인한다. 부착 자체(등록 계약)는 DevtoolsRouteGateContractTest 가 본다.
 */
class DevtoolsDebugGateTest extends TestCase
{
    /**
     * `storage/debug-dump` 백업 디렉토리 절대경로 (미백업 시 null).
     *
     * `DELETE clear` 는 실 `storage_path('debug-dump')` 를 비운다 — 경로가 핸들러에
     * 고정되어 있어 테스트가 다른 곳을 가리킬 수 없다. 개발자가 브라우저에서 받아 둔
     * 덤프를 테스트가 날려 버리지 않도록 클래스 단위로 스냅샷·복원한다.
     * 선례: DevtoolsRouteCacheTest 의 PROBE_FILE 스냅샷.
     */
    private static ?string $dumpBackupDir = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $dumpDir = dirname(__DIR__, 3).'/storage/debug-dump';

        if (! is_dir($dumpDir)) {
            return;
        }

        $backupDir = dirname(__DIR__, 3).'/storage/framework/testing/debug-dump-'.uniqid();
        self::copyDirectory($dumpDir, $backupDir);
        self::$dumpBackupDir = $backupDir;
    }

    public static function tearDownAfterClass(): void
    {
        // PHPUnit 은 테스트 실패·예외와 무관하게 이 훅을 호출한다.
        if (self::$dumpBackupDir !== null) {
            $dumpDir = dirname(__DIR__, 3).'/storage/debug-dump';

            self::removeDirectory($dumpDir);
            self::copyDirectory(self::$dumpBackupDir, $dumpDir);
            self::removeDirectory(self::$dumpBackupDir);

            self::$dumpBackupDir = null;
        }

        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * `debug.mode` 환경설정만 켜져 있어도 게이트를 통과해야 한다.
     *
     * 수정 전: HTTP 500 `Class "App\Models\Setting" not found`
     */
    public function test_dump_state_passes_gate_via_settings_debug_mode(): void
    {
        config(['app.debug' => false]);
        $this->mockConfigRepositoryDebugMode(true);

        $response = $this->postJson('/_boost/g7-debug/dump-state', ['test' => true]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);
    }

    /**
     * 양쪽 모두 꺼져 있으면 403 이어야 한다 (500 이 아니라).
     */
    public function test_dump_state_returns_403_when_debug_disabled(): void
    {
        config(['app.debug' => false]);
        $this->mockConfigRepositoryDebugMode(false);

        $response = $this->postJson('/_boost/g7-debug/dump-state', ['test' => true]);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error']);

        // 번역 키가 해석되지 않으면 Laravel 은 키 문자열을 그대로 돌려준다 — 오류 없이
        // 사용자에게 `devtools.debug_disabled` 가 노출되므로 해석 여부를 단언한다.
        $this->assertNotSame(
            'devtools.debug_disabled',
            $response->json('message'),
            '번역 키가 해석되지 않았습니다 (lang/{ko,en}/devtools.php 누락).'
        );
    }

    /**
     * `browser-logs` 도 동일 게이트를 사용한다.
     */
    public function test_browser_logs_returns_403_when_debug_disabled(): void
    {
        config(['app.debug' => false]);
        $this->mockConfigRepositoryDebugMode(false);

        $response = $this->postJson('/_boost/browser-logs', ['logs' => []]);

        $response->assertStatus(403);
    }

    /**
     * `.env` 의 `APP_DEBUG=true` 만으로도 통과한다 (환경설정 조회 불필요).
     */
    public function test_gate_passes_on_app_debug_without_settings_lookup(): void
    {
        config(['app.debug' => true]);

        $repository = Mockery::mock(ConfigRepositoryInterface::class);
        $repository->shouldNotReceive('get');
        $this->app->instance(ConfigRepositoryInterface::class, $repository);

        $this->assertTrue(DebugGate::isEnabled());
    }

    /**
     * `DELETE clear` 는 디버그 OFF 에서 403 이어야 하고, 덤프 파일을 지우지 않아야 한다.
     *
     * 수정 전: 미인증 200 + `storage/debug-dump` 전체 삭제 (게이트 전무).
     * 상태 코드만 단언하면 "403 을 돌려주면서 이미 지운" 회귀를 놓치므로 파일 불변까지 본다.
     */
    public function test_clear_returns_403_and_keeps_dump_files_when_debug_disabled(): void
    {
        config(['app.debug' => false]);
        $this->mockConfigRepositoryDebugMode(false);

        $sentinel = $this->seedDumpSentinel();
        $before = count(File::allFiles(storage_path('debug-dump')));

        $response = $this->deleteJson('/_boost/g7-debug/clear');

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error']);

        $this->assertNotSame(
            'devtools.debug_disabled',
            $response->json('message'),
            '번역 키가 해석되지 않았습니다 (lang/{ko,en}/devtools.php 누락).'
        );
        $this->assertNotEmpty($response->json('message'));

        $this->assertFileExists(
            $sentinel,
            'DELETE clear 가 403 을 돌려주기 전에 이미 덤프를 삭제했습니다 (게이트가 핸들러 안에 있으면 발생).'
        );
        $this->assertSame(
            $before,
            count(File::allFiles(storage_path('debug-dump'))),
            'DELETE clear 가 403 임에도 덤프 파일 수가 변했습니다.'
        );
    }

    /**
     * `DELETE clear` 는 디버그 ON 에서는 종전대로 동작해야 한다 (게이트 이관이 기능을 죽이지 않는다).
     *
     * 실 `storage/debug-dump` 를 비우지만 클래스 teardown 이 백업본으로 복원한다.
     */
    public function test_clear_succeeds_when_debug_enabled(): void
    {
        config(['app.debug' => true]);

        $sentinel = $this->seedDumpSentinel();

        $response = $this->deleteJson('/_boost/g7-debug/clear');

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);
        $this->assertFileDoesNotExist($sentinel, 'debug ON 인데 clear 가 덤프를 지우지 않았습니다.');
    }

    /**
     * MCP 조회용 GET 4종도 디버그 OFF 에서 403 JSON 이어야 한다.
     *
     * 수정 전: 게이트 부재 + `routes/web.php` User catch-all shadow 로 **HTML 404**.
     * "404 니까 안전하다" 는 우연이었다 — 제외 패턴이 `_boost` 를 빠뜨린 결과일 뿐,
     * catch-all 이 바뀌면 그대로 열린다. 그래서 403 JSON 을 단언해 게이트 도달을 증명한다.
     */
    public function test_get_endpoints_return_403_json_when_debug_disabled(): void
    {
        config(['app.debug' => false]);
        $this->mockConfigRepositoryDebugMode(false);

        foreach (['state', 'actions', 'cache', 'change-detection'] as $endpoint) {
            $response = $this->getJson('/_boost/g7-debug/'.$endpoint);

            $response->assertStatus(403);
            $response->assertJson(['status' => 'error']);
            $this->assertNotSame(
                'devtools.debug_disabled',
                $response->json('message'),
                "번역 키가 해석되지 않았습니다 ({$endpoint})."
            );
        }
    }

    /**
     * GET 4종은 디버그 ON 에서 정상 응답한다 (게이트만 걸고 기능은 유지 — PO 결정).
     */
    public function test_get_endpoints_respond_when_debug_enabled(): void
    {
        config(['app.debug' => true]);

        foreach (['state', 'actions', 'cache', 'change-detection'] as $endpoint) {
            $response = $this->getJson('/_boost/g7-debug/'.$endpoint);

            $response->assertStatus(200);
            $this->assertContains(
                $response->json('status'),
                ['success', 'no_data'],
                "{$endpoint} 응답의 status 가 예상 밖입니다."
            );
        }
    }

    /**
     * `log` 엔드포인트도 동일 게이트를 사용한다 (POST 3종 중 나머지 하나).
     */
    public function test_log_returns_403_when_debug_disabled(): void
    {
        config(['app.debug' => false]);
        $this->mockConfigRepositoryDebugMode(false);

        $response = $this->postJson('/_boost/g7-debug/log', ['type' => 'debug', 'data' => []]);

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error']);
    }

    /**
     * 덤프 디렉토리에 이번 테스트용 표식 파일을 만든다.
     *
     * @return string 생성된 표식 파일 절대경로
     */
    private function seedDumpSentinel(): string
    {
        $dir = storage_path('debug-dump');

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $path = $dir.'/gate-sentinel.json';
        File::put($path, json_encode(['sentinel' => true]));

        return $path;
    }

    /**
     * `ConfigRepositoryInterface` 를 `debug.mode` 고정값으로 대체한다.
     *
     * @param  bool  $enabled  debug.mode 값
     * @return void
     */
    private function mockConfigRepositoryDebugMode(bool $enabled): void
    {
        $repository = Mockery::mock(ConfigRepositoryInterface::class);
        $repository->shouldReceive('get')
            ->with('debug.mode', false)
            ->andReturn($enabled);
        $repository->shouldReceive('get')->andReturnUsing(
            static fn (string $key, mixed $default = null): mixed => $default
        );

        $this->app->instance(ConfigRepositoryInterface::class, $repository);
    }

    /**
     * 디렉토리를 재귀 복사한다 (Laravel 부트스트랩에 의존하지 않는 순수 구현).
     *
     * @param  string  $from  원본 디렉토리
     * @param  string  $to  대상 디렉토리
     * @return void
     */
    private static function copyDirectory(string $from, string $to): void
    {
        if (! is_dir($from)) {
            return;
        }

        if (! is_dir($to) && ! @mkdir($to, 0755, true) && ! is_dir($to)) {
            return;
        }

        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $src = $from.'/'.$entry;
            $dst = $to.'/'.$entry;

            is_dir($src) ? self::copyDirectory($src, $dst) : @copy($src, $dst);
        }
    }

    /**
     * 디렉토리를 재귀 삭제한다.
     *
     * @param  string  $dir  삭제할 디렉토리 절대경로
     * @return void
     */
    private static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
