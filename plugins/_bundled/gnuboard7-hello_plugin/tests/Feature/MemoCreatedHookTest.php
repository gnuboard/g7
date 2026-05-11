<?php

namespace Plugins\Gnuboard7\HelloPlugin\Tests\Feature;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Plugins\Gnuboard7\HelloPlugin\Services\HelloLogService;
use Plugins\Gnuboard7\HelloPlugin\Tests\PluginTestCase;

/**
 * Hello 모듈 메모 생성 훅 E2E 테스트
 *
 * Hello 모듈이 발행하는 `gnuboard7-hello_module.memo.created` 훅을
 * HookManager::doAction() 으로 시뮬레이션 발행했을 때,
 * Hello 플러그인 리스너가 실제 파일에 기록하는지 검증합니다.
 *
 * 이 테스트는 Hook/Event 도메인 규약을 따릅니다:
 *  - mock 금지
 *  - 실제 HookManager 체인 사용
 *  - 관찰 가능한 상태(파일 존재 및 내용) 로 검증
 */
class MemoCreatedHookTest extends PluginTestCase
{
    /**
     * 테스트마다 로그 파일을 초기화합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $logFile = storage_path(HelloLogService::LOG_FILE);
        if (file_exists($logFile)) {
            @unlink($logFile);
        }
    }

    /**
     * 테스트 종료 시 생성된 로그 파일을 제거합니다.
     */
    protected function tearDown(): void
    {
        $logFile = storage_path(HelloLogService::LOG_FILE);
        if (file_exists($logFile)) {
            @unlink($logFile);
        }

        parent::tearDown();
    }

    /**
     * log_enabled=true (기본값) 인 상태에서 훅 발행 시 파일에 기록되어야 합니다.
     */
    public function test_memo_created_hook_writes_log_when_enabled(): void
    {
        // PluginSettingsService 를 스텁으로 대체해 log_enabled=true 반환
        $settings = new class extends PluginSettingsService
        {
            public function __construct() {}

            public function get(string $identifier, ?string $key = null, mixed $default = null): mixed
            {
                if ($key === 'log_enabled') {
                    return true;
                }

                return $default;
            }
        };
        $this->app->instance(PluginSettingsService::class, $settings);

        // 가짜 Memo 데이터 객체 (stdClass) — 리스너는 id/uuid/title 만 참조합니다
        $fakeMemo = (object) [
            'id' => 42,
            'uuid' => 'test-uuid-0001',
            'title' => 'Sample Memo',
        ];

        // 훅 발행 시뮬레이션
        HookManager::doAction('gnuboard7-hello_module.memo.created', $fakeMemo);

        $logFile = storage_path(HelloLogService::LOG_FILE);
        $this->assertFileExists($logFile, '훅 발행 시 로그 파일이 생성되어야 합니다.');

        $contents = file_get_contents($logFile);
        $this->assertStringContainsString('Memo created hook received', $contents);
        $this->assertStringContainsString('test-uuid-0001', $contents);
        $this->assertStringContainsString('Sample Memo', $contents);
    }

    /**
     * log_enabled=false 인 상태에서는 훅이 발행되어도 파일에 기록되지 않아야 합니다.
     */
    public function test_memo_created_hook_skips_log_when_disabled(): void
    {
        $settings = new class extends PluginSettingsService
        {
            public function __construct() {}

            public function get(string $identifier, ?string $key = null, mixed $default = null): mixed
            {
                if ($key === 'log_enabled') {
                    return false;
                }

                return $default;
            }
        };
        $this->app->instance(PluginSettingsService::class, $settings);

        $fakeMemo = (object) [
            'id' => 7,
            'uuid' => 'test-uuid-disabled',
            'title' => 'Disabled Memo',
        ];

        HookManager::doAction('gnuboard7-hello_module.memo.created', $fakeMemo);

        $logFile = storage_path(HelloLogService::LOG_FILE);
        $this->assertFileDoesNotExist(
            $logFile,
            'log_enabled=false 일 때는 파일이 생성되지 않아야 합니다.'
        );
    }

    /**
     * 로그 기록 직후 플러그인 자체 훅 `gnuboard7-hello_plugin.log.written` 이 발행되어야 합니다.
     */
    public function test_listener_dispatches_own_log_written_action_hook(): void
    {
        $settings = new class extends PluginSettingsService
        {
            public function __construct() {}

            public function get(string $identifier, ?string $key = null, mixed $default = null): mixed
            {
                return $key === 'log_enabled' ? true : $default;
            }
        };
        $this->app->instance(PluginSettingsService::class, $settings);

        $received = [];
        HookManager::addAction(
            'gnuboard7-hello_plugin.log.written',
            function (...$args) use (&$received) {
                $received[] = $args;
            },
            10,
        );

        $fakeMemo = (object) [
            'id' => 1,
            'uuid' => 'uuid-own-hook',
            'title' => 'Own Hook Memo',
        ];

        HookManager::doAction('gnuboard7-hello_module.memo.created', $fakeMemo);

        $this->assertNotEmpty($received, '플러그인 자체 훅이 발행되어야 합니다.');
        $this->assertSame('[HelloPlugin] Memo created hook received', $received[0][0] ?? null);
        $this->assertIsArray($received[0][1] ?? null);
        $this->assertSame('uuid-own-hook', $received[0][1]['memo_uuid'] ?? null);
    }

    /**
     * Filter 훅 리스너가 type:'filter' 로 등록되어 반환값이 체인에 반영되어야 합니다.
     */
    public function test_filter_listener_prepends_hello_prefix_to_title(): void
    {
        $result = HookManager::applyFilters(
            'gnuboard7-hello_module.memo.title.filter',
            'original title',
        );

        $this->assertSame('[Hello] original title', $result);
    }
}
