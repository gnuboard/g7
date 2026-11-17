<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Concerns;

use App\Helpers\ResponseHelper;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 공개 상품 판정 (숨김·삭제 상품이면 404)
 *
 * 상품 상세·관련 상품만이 아니라 그 상품 아래의 공개 API(후기·문의·받을 수 있는 쿠폰)도 같은
 * 판정을 거쳐야 한다 — 한 곳만 빠지면 숨긴 상품의 정보가 그 경로로 새어 나간다.
 */
trait EnsuresPublicProduct
{
    /**
     * 상품이 공개 상태가 아니면 404 응답으로 요청을 끝냅니다.
     *
     * @param  Product|null  $product  라우트 바인딩된 상품
     * @return void 반환값 없음
     */
    protected function ensurePublicProduct(?Product $product): void
    {
        if (self::isPublicProduct($product)) {
            return;
        }

        abort(ResponseHelper::notFound('messages.products.not_found', [], 'sirsoft-ecommerce'));
    }

    /**
     * 상품이 사용자 화면에 공개된 상태인지 판정합니다. (전시 상태이고 삭제되지 않음)
     *
     * @param  Product|null  $product  상품
     * @return bool 공개 상태면 true
     */
    protected static function isPublicProduct(?Product $product): bool
    {
        if ($product === null || $product->trashed()) {
            return false;
        }

        $status = $product->display_status;

        return ($status instanceof ProductDisplayStatus ? $status->value : (string) $status) === ProductDisplayStatus::VISIBLE->value;
    }
}
