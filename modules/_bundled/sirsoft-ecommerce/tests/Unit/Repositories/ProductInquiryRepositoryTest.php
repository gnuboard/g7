<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductInquiryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ProductInquiryRepository 단위 테스트
 *
 * SoftDeletes 도입(#107 후속)에 따른 신규 메서드와 answered_at 보존 계약을 검증합니다.
 * - markAsAnswered: 최초 답변 시각 보존 (재마킹이 시각을 덮어쓰면 안 됨)
 * - restoreByInquirable: 소프트 삭제 피벗 복원 (게시판 질문 글 복원 대칭)
 * - forceDeleteByProductId: 상품 삭제 경로 — trashed 포함 영구 삭제
 * - findByProductIdWithTrashed: trashed 포함 조회 (기본 조회는 trashed 제외)
 */
class ProductInquiryRepositoryTest extends ModuleTestCase
{
    protected ProductInquiryRepositoryInterface $repository;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(ProductInquiryRepositoryInterface::class);
        $this->product = Product::factory()->create();
    }

    /**
     * markAsAnswered 는 기존 answered_at 을 보존한다.
     *
     * 답변 수정/복원 재마킹 경로에서 최초 답변 시각이 덮어써지면 안 된다
     * (선례: ProductReviewService). 값이 없을 때만 now() 로 채운다.
     */
    public function test_mark_as_answered_preserves_existing_answered_at(): void
    {
        // Given: 최초 답변 시각이 기록된 피벗 (답변완료 해제 없이 재마킹되는 상황)
        $firstAnsweredAt = Carbon::parse('2026-01-01 12:34:56');
        $inquiry = ProductInquiry::factory()->create([
            'product_id' => $this->product->id,
            'is_answered' => false,
            'answered_at' => $firstAnsweredAt,
        ]);

        // When: 재마킹
        $updated = $this->repository->markAsAnswered($inquiry);

        // Then: is_answered 는 true, answered_at 은 최초 시각 그대로
        $this->assertTrue($updated->is_answered);
        $this->assertSame(
            $firstAnsweredAt->format('Y-m-d H:i:s'),
            $updated->answered_at->format('Y-m-d H:i:s'),
            '재마킹이 최초 답변 시각을 덮어쓰면 안 됩니다.'
        );

        // And: answered_at 이 없는 피벗은 now() 로 채워진다 (기존 동작 유지)
        $fresh = ProductInquiry::factory()->create([
            'product_id' => $this->product->id,
            'is_answered' => false,
            'answered_at' => null,
        ]);
        $freshUpdated = $this->repository->markAsAnswered($fresh);
        $this->assertTrue($freshUpdated->is_answered);
        $this->assertNotNull($freshUpdated->answered_at);
    }

    /**
     * restoreByInquirable 은 소프트 삭제된 피벗만 복원한다 (onlyTrashed → restore).
     */
    public function test_restore_by_inquirable_restores_soft_deleted_pivot(): void
    {
        // Given: 소프트 삭제된 피벗
        $trashed = ProductInquiry::factory()->create([
            'product_id' => $this->product->id,
            'inquirable_type' => 'Modules\\Sirsoft\\Board\\Models\\Post',
            'inquirable_id' => 501,
        ]);
        $trashed->delete();
        $this->assertSoftDeleted('ecommerce_product_inquiries', ['id' => $trashed->id]);

        // And: 살아있는 피벗 (onlyTrashed 대상 아님)
        $alive = ProductInquiry::factory()->create([
            'product_id' => $this->product->id,
            'inquirable_type' => 'Modules\\Sirsoft\\Board\\Models\\Post',
            'inquirable_id' => 502,
        ]);

        // When: 복원
        $restored = $this->repository->restoreByInquirable(
            'Modules\\Sirsoft\\Board\\Models\\Post',
            501
        );

        // Then: 1건 복원 + 기본 스코프에서 다시 조회 가능
        $this->assertSame(1, $restored);
        $this->assertNull(ProductInquiry::withTrashed()->find($trashed->id)->deleted_at);
        $this->assertNotNull(ProductInquiry::find($trashed->id));

        // And: 살아있는 피벗을 대상으로 하면 0건 (onlyTrashed 스코프)
        $this->assertSame(
            0,
            $this->repository->restoreByInquirable('Modules\\Sirsoft\\Board\\Models\\Post', 502)
        );
        $this->assertNotNull(ProductInquiry::find($alive->id));
    }

    /**
     * forceDeleteByProductId 는 trashed 피벗까지 포함해 영구 삭제한다 (상품 삭제 경로).
     */
    public function test_force_delete_by_product_id_removes_trashed_too(): void
    {
        // Given: 살아있는 피벗 1건 + 소프트 삭제된 피벗 1건
        $alive = ProductInquiry::factory()->create(['product_id' => $this->product->id]);
        $trashed = ProductInquiry::factory()->create(['product_id' => $this->product->id]);
        $trashed->delete();

        // And: 다른 상품의 피벗 (삭제 대상 아님)
        $otherProduct = Product::factory()->create();
        $other = ProductInquiry::factory()->create(['product_id' => $otherProduct->id]);

        // When: 상품 기준 영구 삭제
        $deleted = $this->repository->forceDeleteByProductId($this->product->id);

        // Then: trashed 포함 2건 영구 삭제 — 행 자체가 사라진다
        $this->assertSame(2, $deleted);
        $this->assertDatabaseMissing('ecommerce_product_inquiries', ['id' => $alive->id]);
        $this->assertDatabaseMissing('ecommerce_product_inquiries', ['id' => $trashed->id]);

        // And: 다른 상품의 피벗은 보존
        $this->assertDatabaseHas('ecommerce_product_inquiries', ['id' => $other->id]);
    }

    /**
     * findByProductIdWithTrashed 는 trashed 를 포함하고, 기본 findByProductId 는 제외한다.
     */
    public function test_find_by_product_id_with_trashed_includes_trashed(): void
    {
        // Given: 살아있는 피벗 2건 + 소프트 삭제된 피벗 1건
        $alive1 = ProductInquiry::factory()->create(['product_id' => $this->product->id]);
        $alive2 = ProductInquiry::factory()->create(['product_id' => $this->product->id]);
        $trashed = ProductInquiry::factory()->create(['product_id' => $this->product->id]);
        $trashed->delete();

        // When / Then: withTrashed 조회는 3건 전부
        $withTrashed = $this->repository->findByProductIdWithTrashed($this->product->id);
        $this->assertCount(3, $withTrashed);
        $this->assertEqualsCanonicalizing(
            [$alive1->id, $alive2->id, $trashed->id],
            $withTrashed->pluck('id')->all()
        );

        // And: 기본 조회는 trashed 제외 2건
        $default = $this->repository->findByProductId($this->product->id);
        $this->assertCount(2, $default);
        $this->assertEqualsCanonicalizing(
            [$alive1->id, $alive2->id],
            $default->pluck('id')->all()
        );
    }
}
