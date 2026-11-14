<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Review;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Exceptions\ReviewNotWritableException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문옵션당 활성 후기 1건 DB 강제 회귀 테스트
 *
 * 작성 자격 검사(canWrite)는 "이 주문옵션에 후기가 없다"를 조회로만 확인한다. 같은 옵션의 작성이 동시에
 * 겹치면 둘 다 검사를 통과해 후기가 두 건 생겼다. 단일 프로세스 테스트에서는 진짜 동시 요청을 만들 수
 * 없으므로, 검사를 마친 뒤 저장 직전에 발행되는 `before_create` 훅 안에서 같은 옵션의 작성을 한 번 더
 * 일으켜 같은 경합 창을 재현한다(수정 전: 후기 2건, 예외 없음).
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/member-review-write-and-reply.yaml
 */
class ProductReviewUniqueGuardTest extends ModuleTestCase
{
    private const BEFORE_CREATE_HOOK = 'sirsoft-ecommerce.product-review.before_create';

    private User $user;

    private OrderOption $option;

    private ProductReviewService $service;

    /**
     * 본인 주문의 구매확정 옵션과 후기 서비스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
        $product = Product::factory()->create();
        $order = Order::factory()->create(['user_id' => $this->user->id]);

        $this->option = OrderOption::factory()->forOrder($order)->create([
            'product_id' => $product->id,
            'option_status' => OrderStatusEnum::CONFIRMED,
            'confirmed_at' => now(),
            'quantity' => 1,
        ]);

        $this->service = app(ProductReviewService::class);
    }

    /**
     * 작성 자격 검사를 통과한 뒤 같은 주문옵션의 후기가 먼저 저장되면 뒤의 작성은 already_written 으로
     * 거절되고 활성 후기는 1건만 남는지 확인합니다 (수정 전: 2건 저장).
     *
     * @scenario operation=create_review
     *
     * @effects review_duplicate_active_write_rejected_by_unique_guard
     *
     * @return void 반환값 없음
     */
    public function test_reentrant_write_for_same_order_option_keeps_single_active_review(): void
    {
        $reentered = false;
        $innerReviewId = null;

        // 바깥 작성이 canWrite 를 통과한 뒤, 저장 직전에 같은 옵션으로 한 번 더 작성한다 (경합 창 재현)
        $reenter = function () use (&$reentered, &$innerReviewId): void {
            if ($reentered) {
                return;
            }
            $reentered = true;

            $innerReviewId = $this->service->createReview($this->user->id, $this->payload('먼저 저장되는 후기입니다'))->id;
        };

        HookManager::addAction(self::BEFORE_CREATE_HOOK, $reenter);

        $caught = null;

        try {
            $this->service->createReview($this->user->id, $this->payload('뒤에 저장을 시도하는 후기입니다'));
        } catch (ReviewNotWritableException $e) {
            $caught = $e;
        } finally {
            HookManager::removeAction(self::BEFORE_CREATE_HOOK, $reenter);
        }

        $this->assertTrue($reentered, '재진입 훅이 실행되지 않으면 경합 창을 재현하지 못한 것이다.');
        $this->assertNotNull($caught, '같은 주문옵션의 두 번째 저장은 거절돼야 한다(수정 전: 예외 없이 2건 저장).');
        $this->assertSame('already_written', $caught->getReason());

        $active = ProductReview::where('order_option_id', $this->option->id)->pluck('id')->all();
        $this->assertSame([$innerReviewId], $active, '활성 후기는 먼저 저장된 1건만 남아야 한다.');
    }

    /**
     * 지운(소프트 삭제) 후기 뒤에는 같은 주문옵션으로 다시 쓸 수 있는지 확인합니다.
     *
     * @scenario operation=create_review
     *
     * @effects review_rewrite_allowed_after_soft_delete
     *
     * @return void 반환값 없음
     */
    public function test_rewrite_is_allowed_after_previous_review_is_soft_deleted(): void
    {
        $first = $this->service->createReview($this->user->id, $this->payload('처음 쓴 후기입니다 지울 예정'));
        $first->delete();

        $second = $this->service->createReview($this->user->id, $this->payload('지운 뒤 다시 쓴 후기입니다'));

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, ProductReview::withTrashed()->where('order_option_id', $this->option->id)->count());
        $this->assertSame(
            [$second->id],
            ProductReview::where('order_option_id', $this->option->id)->pluck('id')->all(),
            '활성 후기는 다시 쓴 1건이어야 한다.'
        );
    }

    /**
     * DB 가 활성 후기 중복을 직접 막고(서비스를 거치지 않은 저장 포함), 주문옵션이 없는 이관 후기는
     * 여러 건이어도 막지 않는지 확인합니다.
     *
     * @scenario operation=create_review
     *
     * @effects review_active_order_option_unique_enforced_in_schema
     *
     * @return void 반환값 없음
     */
    public function test_schema_rejects_second_active_review_but_allows_null_order_option(): void
    {
        $this->assertTrue(
            Schema::hasColumn('ecommerce_product_reviews', 'active_order_option_key'),
            '활성 후기 파생 키 생성 컬럼이 없다.'
        );

        $unique = collect(Schema::getIndexes('ecommerce_product_reviews'))
            ->first(fn (array $index) => $index['columns'] === ['active_order_option_key']);
        $this->assertNotNull($unique, '활성 후기 파생 키 인덱스가 없다.');
        $this->assertTrue($unique['unique'], '활성 후기 파생 키 인덱스는 유니크여야 한다.');

        // 주문옵션이 없는 이관 후기는 파생 키가 NULL 이라 여러 건이어도 된다
        ProductReview::factory()->count(2)->create([
            'product_id' => $this->option->product_id,
            'order_option_id' => null,
            'user_id' => null,
            'author_name' => '이관작성자',
        ]);

        ProductReview::factory()->create([
            'product_id' => $this->option->product_id,
            'order_option_id' => $this->option->id,
            'user_id' => $this->user->id,
            'status' => ReviewStatus::VISIBLE->value,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        ProductReview::factory()->create([
            'product_id' => $this->option->product_id,
            'order_option_id' => $this->option->id,
            'user_id' => $this->user->id,
            'status' => ReviewStatus::HIDDEN->value,
        ]);
    }

    /**
     * 작성 요청 본문을 만듭니다.
     *
     * @param  string  $content  후기 본문
     * @return array<string, mixed> createReview 입력
     */
    private function payload(string $content): array
    {
        return [
            'order_option_id' => $this->option->id,
            'rating' => 5,
            'content' => $content,
            'content_mode' => 'text',
        ];
    }
}
