<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use Illuminate\Http\Request;

/**
 * 마이페이지 내 리뷰 리소스 (#952)
 *
 * 리뷰 리소스에 「본인이 지울 수 있는가」(`is_deletable`)를 더한다. 관리자 능력(`abilities.can_delete`)은 관리자 권한
 * 기준이라 회원 화면이 쓰지 않는다.
 */
class UserProductReviewResource extends ProductReviewResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 리뷰 값
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'is_deletable' => $request->user() !== null && (int) $this->user_id === (int) $request->user()->id,
        ];
    }
}
