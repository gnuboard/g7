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
    /**
     * 테스트가 만든 메모를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Memo::query()->delete();

        parent::tearDown();
    }

    /**
     * MemoService::createMemo 호출 시 memo.created 훅이 발행되는지 확인합니다.
     *
     * @scenario case=memo_created_action
     *
     * @effects memo_created_action_fires_once_with_memo
     *
     * @return void 반환값 없음
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
     * 메모 제목이 저장 전에 `gnuboard7-hello_module.memo.title.filter` 필터를 거친다.
     *
     * Hello 플러그인의 FilterMemoTitleListener 가 이 필터를 구독하는데 모듈에 발화처가 없어 한 번도
     * 실행되지 않았다(#138 후속 — 문서·구독에만 있는 훅은 발화를 만든다).
     *
     * @scenario case=memo_title_filter
     *
     * @effects memo_title_filter_applied_before_save
     *
     * @return void 반환값 없음
     */
    public function test_memo_title_passes_through_title_filter_before_save(): void
    {
        $hookManager = app(HookManager::class);
        $hookManager->addFilter('gnuboard7-hello_module.memo.title.filter', fn ($title) => '[필터] '.$title, 5);

        /** @var MemoService $service */
        $service = app(MemoService::class);
        $memo = $service->createMemo(['title' => '원래 제목', 'content' => '본문']);

        $this->assertSame('[필터] 원래 제목', $memo->fresh()->title);
    }

    /**
     * 기본 등록된 LogMemoCreatedListener 가 예외 없이 호출되는지 확인합니다.
     *
     * (로그 내용은 검증하지 않고, 훅 체인 실행 시 예외가 발생하지 않는지만 확인)
     *
     * @scenario case=memo_created_action
     *
     * @effects default_listener_runs_without_exception
     *
     * @return void 반환값 없음
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
