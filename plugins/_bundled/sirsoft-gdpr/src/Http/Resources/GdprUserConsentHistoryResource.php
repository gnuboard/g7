<?php

namespace Plugins\Sirsoft\Gdpr\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * GDPR 동의 변경 이력 API 리소스
 *
 * `gdpr_user_consent_histories` (immutable append-only) 단일 행을 응답으로 변환.
 * 마이페이지의 동의 이력 표시 + DPO 감사 조회용.
 *
 * 민감 정보 처리:
 * - 사용자(마이페이지) 컨텍스트는 자기 IP/UA를 그대로 보여줘도 무방
 * - 관리자(consent log) 컨텍스트는 GdprConsentLogResource 별도 사용
 */
class GdprUserConsentHistoryResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param Request $request HTTP 요청
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'consent_key' => $this->consent_key,
            'action' => $this->action,
            'source' => $this->source,
            'policy_version' => $this->policy_version,
            'categories' => $this->categories,
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
        ];
    }
}
