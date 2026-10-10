<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\Concerns;

use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderCancelOption;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\OrderTaxInvoice;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;

/**
 * 샘플 주문과 그 하위 문서를 지우는 공용 정리 절차
 *
 * 주문을 지우는 시더가 둘이다(OrderSeeder 재시드, ProductSeeder 의 상품 재생성). 각자 지우면
 * 한쪽만 하위 문서를 빠뜨린다 — 클레임 첨부는 다형 관계라 FK 가 없어 주문을 지워도 행과 파일이
 * 남는다. 그래서 정리 순서를 여기 한 곳에 둔다. 삭제는 DB CASCADE 에 기대지 않고 자식부터 명시적으로 한다.
 */
trait PurgesSampleOrders
{
    /**
     * 주문 전체와 하위 문서(클레임·환불·취소·결제·배송·주소·옵션)를 지우고 지운 주문 수를 돌려줍니다.
     *
     * 쿠폰 발급 이력은 지우지 않고 사용 상태만 되돌린다 — 발급 이력은 쿠폰 시더 몫이다.
     *
     * @return array{orders: int, coupons_restored: int} 지운 주문 수와 사용 해제한 쿠폰 수
     */
    protected function purgeSampleOrderGraph(): array
    {
        $orderCount = Order::withTrashed()->count();

        $couponsRestored = CouponIssue::where('status', CouponIssueRecordStatus::USED)
            ->update([
                'status' => CouponIssueRecordStatus::AVAILABLE,
                'used_at' => null,
                'order_id' => null,
                'discount_amount' => null,
            ]);

        if ($orderCount === 0) {
            return ['orders' => 0, 'coupons_restored' => $couponsRestored];
        }

        $this->deleteClaimImages();

        ClaimPayment::query()->delete();
        OrderExchangeOption::query()->delete();
        OrderExchange::query()->delete();
        OrderReturnOption::query()->delete();
        OrderReturn::query()->delete();
        OrderRefundOption::query()->delete();
        OrderRefund::query()->delete();
        OrderCancelOption::query()->delete();
        OrderCancel::query()->delete();
        OrderCashReceipt::query()->delete();
        OrderTaxInvoice::query()->delete();
        OrderShipping::query()->delete();
        OrderPayment::query()->delete();
        OrderAddress::query()->delete();
        OrderOption::query()->delete();
        Order::withTrashed()->forceDelete();

        return ['orders' => $orderCount, 'coupons_restored' => $couponsRestored];
    }

    /**
     * 클레임 첨부 행과 저장소 파일을 함께 지웁니다 (다형 관계라 주문 삭제로는 정리되지 않음).
     *
     * @return void 반환값 없음
     */
    private function deleteClaimImages(): void
    {
        // 파일은 첨부 서비스의 저장소 경로로 지운다(행이 기록한 디스크를 따름). 삭제 훅을 타는 delete() 를 쓰지
        // 않는 이유는 샘플 정리마다 첨부 삭제 활동 로그가 쌓이지 않게 하기 위해서다.
        $attachments = app(ClaimAttachmentService::class);
        ClaimImage::query()->orderBy('id')->chunkById(200, fn ($images) => $attachments->deleteStoredFiles($images));

        ClaimImage::query()->delete();
    }
}
