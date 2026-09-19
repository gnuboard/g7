<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Enums\UserStatus;
use App\Http\Resources\BaseApiResource;
use App\Http\Resources\Traits\ExposesMemberContact;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;

/**
 * 관리자 찜 목록 행 리소스
 *
 * “누가 어떤 상품을 찜했는가” 가 행의 주어다. 회원 정보와 상품 상태를 함께 싣는다.
 * 이메일은 canExposeMemberEmail 게이트를 통과할 때만 싣는다
 * (docs/api/README.md 「회원 이메일 노출 범위」).
 */
class AdminWishlistResource extends BaseApiResource
{
    use ExposesMemberContact;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 관리자 목록 행
     */
    public function toArray(Request $request): array
    {
        $member = $this->relationLoaded('user') ? $this->user : null;

        return [
            'id' => $this->id,
            'member' => $member !== null ? $this->resolveMember($member, $request) : null,
            'product' => $this->resolveProduct($this->relationLoaded('product') ? $this->product : null),
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
        ];
    }

    /**
     * 회원 블록을 구성합니다 (탈퇴 회원은 이름 익명화, 이메일은 게이트 통과 시에만).
     *
     * 이 Resource 는 관리자 컨트롤러에서만 쓰이지만 게이트를 거치지 않으면, 나중에
     * 사용자 경로가 이 Resource 를 재사용하는 순간 회원 주소록이 그대로 새어나간다.
     *
     * @param  mixed  $member  회원 모델
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 회원 정보
     */
    private function resolveMember($member, Request $request): array
    {
        $isWithdrawn = UserStatus::tryFrom((string) $member->status) === UserStatus::Withdrawn;

        return [
            'uuid' => $member->uuid,
            'name' => $isWithdrawn ? __('user.withdrawn_user') : $member->name,
            'email' => $this->canExposeMemberEmail($request, (int) $member->id) ? $member->email : null,
            'is_withdrawn' => $isWithdrawn,
        ];
    }

    /**
     * 상품 블록을 구성합니다 (전시·판매 상태 라벨 포함).
     *
     * 숨김·판매중지 상품도 관리자 목록에는 남긴다 — "왜 사용자 화면에서 사라졌는가" 를
     * 설명하는 것이 이 화면의 일이라 상태를 감추면 안 된다.
     *
     * @param  mixed  $product  상품 모델
     * @return array<string, mixed>|null 상품 정보
     */
    private function resolveProduct($product): ?array
    {
        if ($product === null) {
            return null;
        }

        $display = $product->display_status instanceof ProductDisplayStatus
            ? $product->display_status
            : ProductDisplayStatus::tryFrom((string) $product->display_status);
        $sales = $product->sales_status instanceof ProductSalesStatus
            ? $product->sales_status
            : ProductSalesStatus::tryFrom((string) $product->sales_status);

        return [
            'id' => $product->id,
            'name' => $product->getLocalizedName(),
            'display_status' => $display?->value,
            'display_status_label' => $display?->label(),
            'sales_status' => $sales?->value,
            'sales_status_label' => $sales?->label(),
        ];
    }
}
