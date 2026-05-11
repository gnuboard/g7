<?php

namespace App\Http\Resources\Identity;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * IdentityPolicy Resource.
 *
 * 관리자 UI 의 S1d 서브섹션 DataGrid 및 편집 모달에 사용됩니다.
 */
class PolicyResource extends BaseApiResource
{
    /**
     * Resource 수준 abilities 매핑.
     *
     * @return array<string, string>
     */
    public function abilityMap(): array
    {
        return [
            'can_update' => 'core.admin.identity.policies.manage',
            'can_delete' => 'core.admin.identity.policies.manage',
        ];
    }

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 직렬화된 정책 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'scope' => $this->scope,
            'target' => $this->target,
            'purpose' => $this->purpose,
            'provider_id' => $this->provider_id,
            'grace_minutes' => (int) $this->grace_minutes,
            'enabled' => (bool) $this->enabled,
            'priority' => (int) $this->priority,
            'conditions' => $this->conditions,
            'source_type' => $this->source_type,
            'source_identifier' => $this->source_identifier,
            'applies_to' => $this->applies_to,
            'fail_mode' => $this->fail_mode,
            'user_overrides' => $this->user_overrides ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            ...$this->resourceMeta($request),
        ];
    }
}
