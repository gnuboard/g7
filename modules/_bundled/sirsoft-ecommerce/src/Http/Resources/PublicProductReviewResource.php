<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Support\AuthorNameMasker;

/**
 * 공개 상품 후기 리소스
 *
 * 공개 후기 목록은 인증 없이 열린다. 관리자·본인 응답과 같은 모양을 쓰되 회원 uuid 는 빼고,
 * 작성자 이름은 환경설정(리뷰 설정 → 작성자 이름 가림)에 따라 가린다.
 */
class PublicProductReviewResource extends ProductReviewResource
{
    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 공개 후기 응답
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        unset($data['user_id']);

        $data['author_name'] = AuthorNameMasker::forPublicReview($this->resource->displayAuthorName());

        if ($this->resource->relationLoaded('user')) {
            $data['user'] = $this->resource->user === null ? null : [
                'name' => AuthorNameMasker::forPublicReview($this->resource->user->name),
                'email' => $this->canExposeMemberEmail($request, $this->resource->user->id)
                    ? $this->resource->user->email
                    : null,
            ];
        }

        return $data;
    }
}
