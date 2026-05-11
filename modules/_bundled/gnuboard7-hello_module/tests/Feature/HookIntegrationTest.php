<?php

namespace Modules\Gnuboard7\HelloModule\Tests\Feature;

require_once __DIR__.'/../FeatureTestCase.php';

use App\Extension\HookManager;
use Modules\Gnuboard7\HelloModule\Models\Memo;
use Modules\Gnuboard7\HelloModule\Services\MemoService;
use Modules\Gnuboard7\HelloModule\Tests\FeatureTestCase;

/**
 * 훅 통합 테스트
 *
 * MemoService::createMemo() 호출 시 `gnuboard7-hello_module.memo.created` 훅이
 * 실제로 발행되고 리스너가 반응하는지 검증합니다. (mock 금지 - 실제 훅 체인)
 */
class HookIntegrationTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        Memo::query()->delete();

        parent::tearDown();
    }

    /**
     * MemoService::createMemo 호출 시 memo.created 훅이 발행되는지 확인합니다.
     */
    public function test_creating_memo_triggers_memo_created_hook(): void
    {
        $captured = [];

        // 테스트 전용 리스너 등록 (HookManager 에 직접 주입)
        $hookManager = app(HookManager::class);
        $hookManager->addAction(
            'gnuboard7-hello_module.memo.created',
            function (...$args) use (&$captured) {
                $captured[] = $args[0] ?? null;
            },
            5
        );

        /** @var MemoService $service */
        $service = app(MemoService::class);
        $memo = $service->createMemo([
            'title' => '훅 발행 테스트',
            'content' => '본문',
        ]);

        // 훅이 1회 발행되고, 전달된 payload 가 생성된 Memo 와 동일한지 검증
        $this->assertCount(1, $captured, '메모 생성 시 memo.created 훅이 정확히 1회 발행되어야 합니다.');
        $this->assertInstanceOf(Memo::class, $captured[0]);
        $this->assertEquals($memo->id, $captured[0]->id);
        $this->assertEquals('훅 발행 테스트', $captured[0]->title);
    }

    /**
     * 기본 등록된 LogMemoCreatedListener 가 예외 없이 호출되는지 확인합니다.
     *
     * (로그 내용은 검증하지 않고, 훅 체인 실행 시 예외가 발생하지 않는지만 확인)
     */
    public function test_default_listener_runs_without_exception(): void
    {
        /** @var MemoService $service */
        $service = app(MemoService::class);

        $memo = $service->createMemo([
            'title' => '기본 리스너 테스트',
            'content' => '본문',
        ]);

        $this->assertNotNull($memo->id);
    }
}
