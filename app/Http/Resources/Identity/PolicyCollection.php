<?php

namespace App\Http\Resources\Identity;

use App\Http\Resources\BaseApiCollection;
use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;

/**
 * IdentityPolicy 컬렉션 Resource.
 *
 * S1d 서브섹션 DataGrid 응답. 페이지 레벨 "정책 추가" 버튼 등을
 * abilityMap 으로 제어합니다.
 */
class PolicyCollection extends BaseApiCollection
{
    use HasAbilityCheck;

    /**
     * 컬렉션 레벨 능력 매핑.
     *
     * @return array<string, string> 능력 키 => 권한 식별자 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'core.admin.identity.policies.manage',
            'can_update' => 'core.admin.identity.policies.manage',
            'can_delete' => 'core.admin.identity.policies.manage',
        ];
    }

    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 데이터 + abilities
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(fn ($policy) => new PolicyResource($policy)),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
        ];
    }
}
