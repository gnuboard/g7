<?php

namespace App\Http\Resources\Identity;

use App\Enums\IdentityPolicyScope;
use App\Http\Resources\BaseApiResource;
use App\Identity\IdentityCheckpointRegistry;
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
            'can_update' => 'core.admin.identity.policies.update',
            'can_delete' => 'core.admin.identity.policies.update',
        ];
    }

    /**
     * 범위 값을 문자열로 반환합니다. (캐스트 여부와 무관하게)
     *
     * @return string 범위 값
     */
    private function scopeValue(): string
    {
        $scope = $this->scope;

        return $scope instanceof \BackedEnum ? (string) $scope->value : (string) $scope;
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
            // 범위가 「사용자 지정」일 때 대상이 활성 확장이 선언한 확장 지점인지 (아니면 어디서도 강제되지 않는다, 7.2.1)
            'checkpoint_registered' => $this->scopeValue() === IdentityPolicyScope::Custom->value
                ? app(IdentityCheckpointRegistry::class)->has((string) $this->target)
                : null,
            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }
}
