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
 *
 * ## 공용 업로더 별칭은 **서버가** 더한다
 *
 * 공용 업로더 컴포넌트는 `original_filename` · `order` · `size_formatted` · `is_image` 라는
 * 이름을 읽는다. 그 매핑을 화면(레이아웃 JSON)에서 하면 `.map()` 이 매 렌더 새 배열 정체성을
 * 만들어 업로더의 동기화 effect 가 렌더 루프에 빠질 수 있고, 관리자 화면에만 규칙이 생겨
 * 계약의 두 번째 출처가 된다. 상품 이미지 리소스가 이미 같은 방식이다.
 *
 * **가산만 한다** — 기존 키(`original_name`·`sort_order`·`size`)는 사용자·비회원 경로의
 * 핸들러와 레이아웃이 읽으므로 그대로 둔다.
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
            'original_filename' => $this->original_name,   // 공용 업로더 호환
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'size_formatted' => $this->formatFileSize($this->size),
            'is_image' => str_starts_with((string) $this->mime_type, 'image/'),
            'width' => $this->width,
            'height' => $this->height,
            'sort_order' => $this->sort_order,
            'order' => $this->sort_order,                  // 공용 업로더 호환
            // 분쟁 시 "어느 쪽이 낸 증거인가" 가 판정을 가른다. `unknown` 은 화면이 뱃지를
            // 그리지 않으므로 조용한 거짓 대신 공백이 남는다.
            'uploader_type' => $this->uploader_type?->value,
            'uploader_type_label' => $this->uploader_type?->isDisplayable() ? $this->uploader_type->label() : null,
            'uploader_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
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
