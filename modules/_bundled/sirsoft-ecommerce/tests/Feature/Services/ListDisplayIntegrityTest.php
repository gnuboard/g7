<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Services;

use Modules\Sirsoft\Ecommerce\DTO\MileageAdminDeductDto;
use Modules\Sirsoft\Ecommerce\DTO\MileageAdminEarnDto;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Http\Resources\CouponIssueResource;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductWishlistRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 목록 표시 정합성 회귀 테스트 (#492 6차)
 *
 * 브라우저 실측에서 "행은 나오는데 칸이 비거나 상태가 틀린" 세 지점을 고정한다.
 * - 찜 목록에 소프트 삭제된 상품 행이 남아 카드 렌더가 실패했다
 * - 유효기간이 지난 쿠폰이 "사용가능" 라벨로 표시됐다
 * - 마일리지 사용·차감 거래의 내용(description)이 비어 있었다
 */
class ListDisplayIntegrityTest extends ModuleTestCase
{
    /**
     * 소프트 삭제된 상품의 찜 행이 목록에서 빠지는지 검증합니다.
     *
     * @return void
     */
    public function test_wishlist_excludes_soft_deleted_products(): void
    {
        $user = $this->createUser();
        $products = Product::factory()->count(3)->create();

        foreach ($products as $product) {
            ProductWishlist::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
            ]);
        }

        $repository = app(ProductWishlistRepositoryInterface::class);

        $this->assertSame(3, $repository->getByUser($user->id, 20)->total());

        $products->first()->delete();

        $paginator = $repository->getByUser($user->id, 20);

        $this->assertSame(2, $paginator->total(), '소프트 삭제된 상품의 찜 행이 목록에 남아 있습니다.');
        foreach ($paginator->items() as $item) {
            $this->assertNotNull($item->product, '상품이 없는 찜 행이 목록에 포함됐습니다.');
        }
    }

    /**
     * 유효기간이 지난 쿠폰이 만료 라벨로 표시되는지 검증합니다.
     *
     * @return void
     */
    public function test_expired_coupon_issue_is_labelled_as_expired(): void
    {
        $user = $this->createUser();

        $issue = new CouponIssue([
            'user_id' => $user->id,
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'expired_at' => now()->subDay(),
        ]);

        $payload = (new CouponIssueResource($issue))->toArray(request());

        $this->assertTrue($payload['is_expired']);
        $this->assertSame('available', $payload['status'], '저장된 원시 상태는 그대로 노출되어야 합니다.');
        $this->assertSame(
            CouponIssueRecordStatus::EXPIRED->label(),
            $payload['status_label'],
            '유효기간이 지난 쿠폰이 사용가능으로 표시됩니다.'
        );
        $this->assertSame(CouponIssueRecordStatus::EXPIRED->badgeColor(), $payload['status_badge_color']);
    }

    /**
     * 유효기간이 남은 쿠폰은 원래 라벨을 유지하는지 검증합니다.
     *
     * @return void
     */
    public function test_active_coupon_issue_keeps_available_label(): void
    {
        $user = $this->createUser();

        $issue = new CouponIssue([
            'user_id' => $user->id,
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'expired_at' => now()->addDay(),
        ]);

        $payload = (new CouponIssueResource($issue))->toArray(request());

        $this->assertFalse($payload['is_expired']);
        $this->assertSame(CouponIssueRecordStatus::AVAILABLE->label(), $payload['status_label']);
    }

    /**
     * 관리자 차감 거래에 내용(description)이 기록되는지 검증합니다.
     *
     * @return void
     */
    public function test_admin_deduct_records_description(): void
    {
        $user = $this->createUser();
        $service = app(UserMileageService::class);

        $service->adminEarn($user->id, new MileageAdminEarnDto(
            amount: 5000,
            currency: 'KRW',
            grantedBy: $user->id,
        ));

        $deduct = $service->adminDeduct($user->id, new MileageAdminDeductDto(
            amount: 1200,
            currency: 'KRW',
            grantedBy: $user->id,
        ));

        $this->assertNotEmpty(
            $deduct->description,
            '차감 거래의 내용이 비어 있어 회원 마일리지 내역의 「내용」 칸이 공백이 됩니다.'
        );
        $this->assertSame(
            __('sirsoft-ecommerce::activity_log.description.mileage_admin_deduct', ['amount' => 1200]),
            $deduct->description
        );
    }
}
