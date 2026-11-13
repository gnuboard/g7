<?php

namespace Plugins\Sirsoft\Gdpr\Http\Resources;

use App\DisplayName\DisplayNameAreaRegistry;
use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * GDPR 정책 버전 API 리소스
 *
 * `gdpr_policy_versions` 단일 행을 관리자 이력 모달용으로 변환합니다.
 * 발행 운영자 정보 (id/uuid/name/email) 는 createdBy 관계가 로드된 경우만 포함됩니다.
 */
class GdprPolicyVersionResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'change_type' => $this->change_type->value,
            'memo' => $this->memo,
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            // 발행 운영자 정보 (관계 로드 시에만). raw FK (created_by) 는 노출하지 않고
            // 관계 데이터 (uuid/name/email) 만 노출 — 민감 정보 노출 최소화.
            // 이름은 관리자 영역(`core:admin`) 표시 이름이고, 실명은 회원 조회 권한이 있을 때만 `real_name` 으로 더해진다.
            'publisher' => $this->whenLoaded('createdBy', fn () => $this->createdBy !== null ? [
                'uuid' => $this->createdBy->uuid,
                ...$this->memberNameFields($this->createdBy, $request, DisplayNameAreaRegistry::AREA_ADMIN),
                'email' => $this->canExposeMemberEmail($request, $this->createdBy->id) ? $this->createdBy->email : null,
            ] : null),
        ];
    }
}
