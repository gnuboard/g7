<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * 클레임 첨부 이미지 리소스
 *
 * `download_url` 은 한시 서명 URL 이다 — 첨부 서빙은 신청자 본인·운영자만 통과하는 게이트 뒤에 있고,
 * 화면의 `<img>` 는 인증 헤더(Bearer / 비회원 토큰)를 실을 수 없으므로 응답을 받을 자격이 곧
 * 열람 자격이라는 위임으로 서명을 발급한다. 직접(CDN) URL 은 노출하지 않는다.
 */
class ClaimImageResource extends BaseApiResource
{
    /**
     * 서명 URL 유효 시간 (분)
     */
    public const SIGNED_DOWNLOAD_TTL_MINUTES = 30;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 응답 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hash' => $this->hash,
            'claimable_type' => $this->claimable_type,
            'claimable_id' => $this->claimable_id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'sort_order' => $this->sort_order,
            'download_url' => URL::temporarySignedRoute(
                'api.modules.sirsoft-ecommerce.claim-image.download',
                now()->addMinutes(self::SIGNED_DOWNLOAD_TTL_MINUTES),
                ['hash' => $this->hash],
                absolute: false
            ),
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
        ];
    }
}
