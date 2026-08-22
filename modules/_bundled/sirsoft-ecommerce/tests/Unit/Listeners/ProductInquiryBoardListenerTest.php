<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Extension\HookManager;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Listeners\ProductInquiryBoardListener;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ProductInquiryBoardListener 단위 테스트
 *
 * 게시판 Post 삭제/복원/게시판 삭제 이벤트에 대한 문의 피벗 정합 동작을 검증합니다.
 * - after_delete: 질문 글 → 피벗 소프트 삭제, 답변 글 → 잔여 답변 기준 답변완료 재계산
 * - after_restore: 질문 글 → 피벗 복원, 답변 글 → 답변완료 재마킹 (answered_at 보존)
 * - board.posts.before_force_delete: 삭제될 글 ID 벌크 수신 → 피벗 일괄 소프트 삭제 (멱등)
 *
 * Post 객체는 리스너가 get_class($post) 로 inquirable_type 을 매칭하므로,
 * stdClass 스텁을 쓰는 테스트는 피벗의 inquirable_type 도 'stdClass' 로 맞춘다.
 * (벌크 훅은 FQCN 리터럴 'Modules\Sirsoft\Board\Models\Post' 고정 — 스텁 무관)
 */
class ProductInquiryBoardListenerTest extends ModuleTestCase
{
    private const POST_MODEL = 'Modules\\Sirsoft\\Board\\Models\\Post';

    protected ProductInquiryBoardListener $listener;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->listener = app(ProductInquiryBoardListener::class);
        $this->product = Product::factory()->create();

        // 다른 모듈이 등록한 count_replies 필터 잔존 차단 (cross-module contamination)
        HookManager::clearFilter('sirsoft-ecommerce.inquiry.count_replies');
    }

    /**
     * stdClass 기반 Post 스텁을 생성합니다.
     *
     * @param  int  $id  Post ID
     * @param  int|null  $parentId  부모 Post ID (답변 글이면 지정)
     * @return object Post 스텁
     */
    private function makePostStub(int $id, ?int $parentId = null): object
    {
        return (object) [
            'id' => $id,
            'parent_id' => $parentId,
        ];
    }

    /**
     * getSubscribedHooks 는 3개 액션 훅을 sync=true 로 선언한다.
     *
     * sync 미선언 시 큐 워커 미가동 환경에서 피벗 정리가 조용히 누락된다(N3).
     */
    public function test_subscribed_hooks_declare_three_sync_action_hooks(): void
    {
        $hooks = ProductInquiryBoardListener::getSubscribedHooks();

        // 액션 훅 3종 — method + sync=true
        $this->assertSame('handlePostDeleted', $hooks['sirsoft-board.post.after_delete']['method']);
        $this->assertTrue($hooks['sirsoft-board.post.after_delete']['sync']);

        $this->assertSame('handlePostRestored', $hooks['sirsoft-board.post.after_restore']['method']);
        $this->assertTrue($hooks['sirsoft-board.post.after_restore']['sync']);

        $this->assertSame(
            'handleBoardPostsForceDeleting',
            $hooks['sirsoft-board.board.posts.before_force_delete']['method']
        );
        $this->assertTrue($hooks['sirsoft-board.board.posts.before_force_delete']['sync']);

        // 필터 훅 2종 — type: 'filter' 명시 (미지정 시 반환값 무시 회귀)
        $this->assertSame('filter', $hooks['sirsoft-ecommerce.inquiry.store_validation_rules']['type']);
        $this->assertSame('filter', $hooks['sirsoft-ecommerce.inquiry.update_validation_rules']['type']);
    }

    /**
     * 질문 글 삭제 → 연결된 피벗이 소프트 삭제된다 (복원 대칭 확보).
     */
    public function test_handle_post_deleted_soft_deletes_pivot_for_question_post(): void
    {
        // Given: 질문 Post(101)를 가리키는 피벗
        $pivot = ProductInquiry::factory()->create([
            'product_id' => $this->product->id,
            'inquirable_type' => 'stdClass',
            'inquirable_id' => 101,
        ]);

        // When: 질문 글(parent_id 없음) 삭제 이벤트
        $this->listener->handlePostDeleted($this->makePostStub(101), 'test-inquiry-board');

        // Then: 피벗 소프트 삭제 — 행은 잔존, deleted_at 세팅
        $this->assertSoftDeleted('ecommerce_product_inquiries', ['id' => $pivot->id]);
        $this->assertNotNull(ProductInquiry::withTrashed()->find($pivot->id)->deleted_at);
    }

    /**
     * 답변 글 삭제 + 잔여 답변 0 → 부모 피벗의 답변완료가 해제된다 (피벗은 삭제하지 않음).
     */
    public function test_handle_post_deleted_unmarks_answered_when_no_replies_remain(): void
    {
        // Given: 질문 Post(201)를 가리키는 답변완료 피벗
        $pivot = ProductInquiry::factory()->answered()->create([
            'product_id' => $this->product->id,
            'inquirable_type' => 'stdClass',
            'inquirable_id' => 201,
        ]);

        // And: 잔여 답변 0건 모킹
        HookManager::addFilter('sirsoft-ecommerce.inquiry.count_replies', fn () => 0, priority: 1);

        // When: 답변 글(301, parent_id=201) 삭제 이벤트
        $this->listener->handlePostDeleted($this->makePostStub(301, 201), 'test-inquiry-board');

        // Then: 답변완료 해제 + answered_at 초기화
        $this->assertDatabaseHas('ecommerce_product_inquiries', [
            'id' => $pivot->id,
            'is_answered' => false,
            'answered_at' => null,
        ]);

        // And: 피벗 자체는 삭제되지 않는다 (질문 분기와 구분 — 답변 분기는 return 으로 종료)
        $this->assertNull(ProductInquiry::withTrashed()->find($pivot->id)->deleted_at);
    }

    /**
     * 답변 글 삭제 + 잔여 답변 1 → 답변완료 표기가 유지된다 (#106 다중 답변 잔재 대응).
     */
    public function test_handle_post_deleted_keeps_answered_when_replies_remain(): void
    {
        // Given: 질문 Post(202)를 가리키는 답변완료 피벗
        $pivot = ProductInquiry::factory()->answered()->create([
            'product_id' => $this->product->id,
            'inquirable_type' => 'stdClass',
            'inquirable_id' => 202,
        ]);

        // And: 잔여 답변 1건 모킹
        HookManager::addFilter('sirsoft-ecommerce.inquiry.count_replies', fn () => 1, priority: 1);

        // When: 답변 글(302, parent_id=202) 삭제 이벤트
        $this->listener->handlePostDeleted($this->makePostStub(302, 202), 'test-inquiry-board');

        // Then: 답변완료 유지
        $this->assertDatabaseHas('ecommerce_product_inquiries', [
            'id' => $pivot->id,
            'is_answered' => true,
        ]);
        $this->assertNotNull(ProductInquiry::find($pivot->id)->answered_at);
    }

    /**
     * 질문 글 복원 → 소프트 삭제됐던 피벗이 복원된다.
     */
    public function test_handle_post_restored_restores_pivot_for_question_post(): void
    {
        // Given: 질문 Post(401)를 가리키는 소프트 삭제된 피벗
        $pivot = ProductInquiry::factory()->create([
            'product_id' => $this->product->id,
            'inquirable_type' => 'stdClass',
            'inquirable_id' => 401,
        ]);
        $pivot->delete();
        $this->assertSoftDeleted('ecommerce_product_inquiries', ['id' => $pivot->id]);

        // When: 질문 글 복원 이벤트
        $this->listener->handlePostRestored($this->makePostStub(401), 'test-inquiry-board');

        // Then: 피벗 복원 — 기본 스코프에서 다시 조회 가능
        $this->assertNotNull(ProductInquiry::find($pivot->id));
        $this->assertNull(ProductInquiry::withTrashed()->find($pivot->id)->deleted_at);
    }

    /**
     * 답변 글 복원 → 부모 피벗이 답변완료로 재마킹되고 answered_at 은 기존값이 보존된다.
     */
    public function test_handle_post_restored_remarks_answered_preserving_answered_at(): void
    {
        // Given: 최초 답변 시각이 남아 있는 미답변 피벗 (질문 Post 402)
        $firstAnsweredAt = Carbon::parse('2026-02-02 09:00:00');
        $pivot = ProductInquiry::factory()->create([
            'product_id' => $this->product->id,
            'inquirable_type' => 'stdClass',
            'inquirable_id' => 402,
            'is_answered' => false,
            'answered_at' => $firstAnsweredAt,
        ]);

        // When: 답변 글(502, parent_id=402) 복원 이벤트
        $this->listener->handlePostRestored($this->makePostStub(502, 402), 'test-inquiry-board');

        // Then: 답변완료 재마킹 + 최초 답변 시각 보존 (markAsAnswered 의 보존 계약)
        $fresh = ProductInquiry::find($pivot->id);
        $this->assertTrue($fresh->is_answered);
        $this->assertSame(
            $firstAnsweredAt->format('Y-m-d H:i:s'),
            $fresh->answered_at->format('Y-m-d H:i:s'),
            '재마킹이 최초 답변 시각을 덮어쓰면 안 됩니다.'
        );
    }

    /**
     * 게시판 삭제 벌크 훅 → 삭제될 글 ID 의 피벗을 일괄 소프트 삭제하며, 재호출은 멱등이다.
     *
     * chunk 당 1회 다회 발화되는 훅이므로 이미 삭제된 피벗은 0건 처리여야 한다.
     */
    public function test_handle_board_posts_force_deleting_bulk_soft_deletes_and_is_idempotent(): void
    {
        // Given: 게시판 Post FQCN 을 가리키는 피벗 2건 + 대상 밖 피벗 1건
        $pivot1 = ProductInquiry::factory()->create([
            'product_id' => $this->product->id,
            'inquirable_type' => self::POST_MODEL,
            'inquirable_id' => 601,
        ]);
        $pivot2 = ProductInquiry::factory()->create([
            'product_id' => $this->product->id,
            'inquirable_type' => self::POST_MODEL,
            'inquirable_id' => 602,
        ]);
        $untouched = ProductInquiry::factory()->create([
            'product_id' => $this->product->id,
            'inquirable_type' => self::POST_MODEL,
            'inquirable_id' => 699,
        ]);

        $board = (object) ['id' => 7];

        // When: 벌크 삭제 통지
        $this->listener->handleBoardPostsForceDeleting($board, [601, 602]);

        // Then: 대상 피벗만 소프트 삭제
        $this->assertSoftDeleted('ecommerce_product_inquiries', ['id' => $pivot1->id]);
        $this->assertSoftDeleted('ecommerce_product_inquiries', ['id' => $pivot2->id]);
        $this->assertNull(ProductInquiry::withTrashed()->find($untouched->id)->deleted_at);

        // When: 동일 payload 재호출 (chunk 다회 발화 시뮬레이션) — 예외 없이 0건 처리
        $firstDeletedAt = ProductInquiry::withTrashed()->find($pivot1->id)->deleted_at;
        $this->listener->handleBoardPostsForceDeleting($board, [601, 602]);

        // Then: 멱등 — deleted_at 이 갱신되지 않고 그대로 유지된다
        $this->assertSame(
            $firstDeletedAt->format('Y-m-d H:i:s'),
            ProductInquiry::withTrashed()->find($pivot1->id)->deleted_at->format('Y-m-d H:i:s')
        );
        $this->assertSoftDeleted('ecommerce_product_inquiries', ['id' => $pivot2->id]);

        // And: 빈 배열 호출은 no-op
        $this->listener->handleBoardPostsForceDeleting($board, []);
        $this->assertNull(ProductInquiry::withTrashed()->find($untouched->id)->deleted_at);
    }
}
