<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 1.1.1 문의 정합화 데이터 마이그레이션(04/05/06) 회귀 테스트
 *
 * - 04 SoftDeleteOrphanInquiryReplyPosts: 부모(질문)가 부재/삭제된 고아 답변 글 소프트 삭제
 * - 05 SoftDeleteInquiryPivotsForTrashedQuestionPosts: 질문 Post 가 부재/삭제인데 살아있는 피벗 소프트 삭제
 * - 06 UnmarkAnsweredInquiriesWithoutLiveReplies: 살아있는 답변이 없는 「답변완료」 피벗 정정
 *
 * 문의 게시판 판별(04)은 설정 파일 축이 아닌 「피벗 join 실데이터」 축으로 검증한다 —
 * 테스트 DB 의 boards 테이블에는 설정 slug 에 대응하는 행이 없으므로 설정 축은 자연 무해하다.
 */
class InquiryOrphanReplyBackfillTest extends ModuleTestCase
{
    private const POST_MODEL = 'Modules\\Sirsoft\\Board\\Models\\Post';

    private const BOARD_ID = 7;

    private object $orphanReplyStep;

    private object $trashedQuestionPivotStep;

    private object $unmarkAnsweredStep;

    private UpgradeContext $context;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $migrationsDir = dirname(__DIR__, 3).'/upgrades/data/1.1.1/migrations';

        require_once $migrationsDir.'/04_SoftDeleteOrphanInquiryReplyPosts.php';
        require_once $migrationsDir.'/05_SoftDeleteInquiryPivotsForTrashedQuestionPosts.php';
        require_once $migrationsDir.'/06_UnmarkAnsweredInquiriesWithoutLiveReplies.php';

        $namespace = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_1_1\\Migrations\\';
        $orphanClass = $namespace.'SoftDeleteOrphanInquiryReplyPosts';
        $pivotClass = $namespace.'SoftDeleteInquiryPivotsForTrashedQuestionPosts';
        $unmarkClass = $namespace.'UnmarkAnsweredInquiriesWithoutLiveReplies';

        $this->orphanReplyStep = new $orphanClass;
        $this->trashedQuestionPivotStep = new $pivotClass;
        $this->unmarkAnsweredStep = new $unmarkClass;

        $this->context = new UpgradeContext(
            fromVersion: '1.1.0',
            toVersion: '1.1.1',
            currentStep: '1.1.1',
            logChannel: 'extension-upgrade',
        );

        $this->product = Product::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 게시글 행을 생성합니다 (board_posts 직접 시드 — V-1 안전 스텝과 동일하게 raw DB).
     *
     * @param  array  $overrides  컬럼 오버라이드
     * @return int 생성된 게시글 ID
     */
    private function makeBoardPost(array $overrides = []): int
    {
        return (int) DB::table('board_posts')->insertGetId(array_merge([
            'board_id' => self::BOARD_ID,
            'title' => '상품 문의 글',
            'content' => '상품 문의 내용입니다.',
            'ip_address' => '127.0.0.1',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * 문의 피벗 행을 생성합니다 (inquirable_type 은 게시판 Post FQCN 고정).
     *
     * @param  int  $postId  질문 Post ID
     * @param  array  $overrides  컬럼 오버라이드
     * @return ProductInquiry 생성된 피벗
     */
    private function makePivot(int $postId, array $overrides = []): ProductInquiry
    {
        return ProductInquiry::factory()->create(array_merge([
            'product_id' => $this->product->id,
            'inquirable_type' => self::POST_MODEL,
            'inquirable_id' => $postId,
        ], $overrides));
    }

    /**
     * 04 — 부모가 삭제/부재인 답변 글만 소프트 삭제하고, 부모가 살아있는 답변은 건드리지 않는다.
     */
    public function test_04_soft_deletes_only_orphan_reply_posts(): void
    {
        // Given: 살아있는 질문 + 그 답변 (오탐 검증 대상)
        $aliveQuestion = $this->makeBoardPost();
        $aliveReply = $this->makeBoardPost(['parent_id' => $aliveQuestion, 'depth' => 1]);

        // And: 소프트 삭제된 질문 + 잔존 답변 (고아 ①)
        $trashedQuestion = $this->makeBoardPost([
            'status' => 'deleted',
            'deleted_at' => now()->subDay(),
        ]);
        $orphanByTrashedParent = $this->makeBoardPost(['parent_id' => $trashedQuestion, 'depth' => 1]);

        // And: 부모가 하드 삭제로 부재한 답변 (고아 ②)
        $orphanByMissingParent = $this->makeBoardPost(['parent_id' => 999999, 'depth' => 1]);

        // And: 문의 게시판 판별용 피벗 (피벗 join posts.board_id 축)
        $this->makePivot($aliveQuestion);

        // When: 04 실행
        $this->orphanReplyStep->run($this->context);

        // Then: 고아 답변 2건만 status=deleted + deleted_at 마킹
        foreach ([$orphanByTrashedParent, $orphanByMissingParent] as $orphanId) {
            $row = DB::table('board_posts')->where('id', $orphanId)->first();
            $this->assertSame('deleted', $row->status, "고아 답변({$orphanId})은 deleted 상태여야 합니다.");
            $this->assertNotNull($row->deleted_at, "고아 답변({$orphanId})은 소프트 삭제되어야 합니다.");
        }

        // And: 부모가 살아있는 답변과 질문 글은 오탐 없이 그대로
        foreach ([$aliveQuestion, $aliveReply] as $aliveId) {
            $row = DB::table('board_posts')->where('id', $aliveId)->first();
            $this->assertSame('published', $row->status, "살아있는 글({$aliveId})은 건드리면 안 됩니다.");
            $this->assertNull($row->deleted_at);
        }
    }

    /**
     * 04 — 재실행 멱등: 이미 마킹된 고아의 deleted_at 이 갱신되지 않는다.
     */
    public function test_04_is_idempotent_on_rerun(): void
    {
        // Given: 고아 답변 1건 (부모 부재) + 판별용 피벗
        $question = $this->makeBoardPost();
        $orphan = $this->makeBoardPost(['parent_id' => 999999, 'depth' => 1]);
        $this->makePivot($question);

        // When: 1차 실행 (T1 시각)
        $firstRunAt = Carbon::parse('2026-08-17 10:00:00');
        Carbon::setTestNow($firstRunAt);
        $this->orphanReplyStep->run($this->context);

        $afterFirst = DB::table('board_posts')->where('id', $orphan)->value('deleted_at');
        $this->assertNotNull($afterFirst);

        // When: 시간이 흐른 뒤 2차 실행 (T2 시각)
        Carbon::setTestNow($firstRunAt->copy()->addHour());
        $this->orphanReplyStep->run($this->context);

        // Then: deleted_at IS NULL 필터로 재실행이 0건 — 1차 마킹 시각 그대로
        $this->assertSame(
            $afterFirst,
            DB::table('board_posts')->where('id', $orphan)->value('deleted_at'),
            '재실행이 기존 소프트 삭제 시각을 덮어쓰면 안 됩니다 (멱등).'
        );
    }

    /**
     * 05 — 질문 Post 가 삭제/부재인 살아있는 피벗만 소프트 삭제한다.
     */
    public function test_05_soft_deletes_pivots_whose_question_post_is_gone(): void
    {
        // Given: 살아있는 질문 + 그 피벗 (생존 대상)
        $aliveQuestion = $this->makeBoardPost();
        $alivePivot = $this->makePivot($aliveQuestion);

        // And: 소프트 삭제된 질문 + 살아있는 피벗 (정리 대상 ①)
        $trashedQuestion = $this->makeBoardPost([
            'status' => 'deleted',
            'deleted_at' => now()->subDay(),
        ]);
        $pivotForTrashed = $this->makePivot($trashedQuestion);

        // And: 하드 삭제로 부재한 질문을 가리키는 피벗 (정리 대상 ②)
        $pivotForMissing = $this->makePivot(888888);

        // When: 05 실행
        $this->trashedQuestionPivotStep->run($this->context);

        // Then: 질문이 사라진 피벗 2건만 소프트 삭제
        $this->assertSoftDeleted('ecommerce_product_inquiries', ['id' => $pivotForTrashed->id]);
        $this->assertSoftDeleted('ecommerce_product_inquiries', ['id' => $pivotForMissing->id]);

        // And: 질문이 살아있는 피벗은 그대로
        $this->assertNull(
            ProductInquiry::withTrashed()->find($alivePivot->id)->deleted_at,
            '질문 Post 가 살아있는 피벗은 건드리면 안 됩니다.'
        );
    }

    /**
     * 06 — 살아있는 답변이 없는 「답변완료」 피벗만 미답변으로 정정한다.
     */
    public function test_06_unmarks_answered_inquiries_without_live_replies(): void
    {
        // Given: 답변이 소프트 삭제된 질문 + 답변완료 피벗 (정정 대상)
        $questionWithoutReply = $this->makeBoardPost();
        $this->makeBoardPost([
            'parent_id' => $questionWithoutReply,
            'depth' => 1,
            'status' => 'deleted',
            'deleted_at' => now()->subDay(),
        ]);
        $staleAnswered = $this->makePivot($questionWithoutReply, [
            'is_answered' => true,
            'answered_at' => now()->subDays(2),
        ]);

        // And: 살아있는 답변이 있는 질문 + 답변완료 피벗 (유지 대상)
        $questionWithReply = $this->makeBoardPost();
        $this->makeBoardPost(['parent_id' => $questionWithReply, 'depth' => 1]);
        $validAnswered = $this->makePivot($questionWithReply, [
            'is_answered' => true,
            'answered_at' => now()->subDays(2),
        ]);

        // When: 06 실행
        $this->unmarkAnsweredStep->run($this->context);

        // Then: 답변 없는 답변완료만 해제 (is_answered=0 + answered_at=null)
        $this->assertDatabaseHas('ecommerce_product_inquiries', [
            'id' => $staleAnswered->id,
            'is_answered' => false,
            'answered_at' => null,
        ]);

        // And: 살아있는 답변이 있는 피벗은 답변완료 유지
        $kept = ProductInquiry::find($validAnswered->id);
        $this->assertTrue($kept->is_answered, '살아있는 답변이 있으면 답변완료를 유지해야 합니다.');
        $this->assertNotNull($kept->answered_at);
    }
}
