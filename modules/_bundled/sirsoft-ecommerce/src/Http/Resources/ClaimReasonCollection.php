<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;

/**
 * 클래임 사유 컬렉션 리소스
 *
 * 클래임 사유 목록을 반환합니다.
 */
class ClaimReasonCollection extends BaseApiCollection
{
    use HasAbilityCheck;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-ecommerce.settings.update',
            'can_update' => 'sirsoft-ecommerce.settings.update',
            'can_delete' => 'sirsoft-ecommerce.settings.update',
        ];
    }

    /**
     * 클래임 사유 컬렉션을 배열로 변환합니다.
     *
     * @param Request $request HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 클래임 사유 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->mapWithRowNumber(function ($reason) {
                return (new ClaimReasonResource($reason))->toArray(request());
            }),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
        ];
    }
}
