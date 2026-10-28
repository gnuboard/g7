<?php

namespace Plugins\Sirsoft\Popup\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use Illuminate\Http\Request;

/**
 * 팝업 목록 컬렉션 — 목록 행·쪽·컬렉션 능력·통계.
 */
class PopupCollection extends BaseApiCollection
{
    /**
     * 통계 (상태별 건수)
     *
     * @var array<string, mixed>
     */
    private array $statistics = [];

    /**
     * 통계를 함께 싣습니다.
     *
     * @param  array<string, mixed>  $statistics  통계
     * @return static 자기 자신
     */
    public function withStatistics(array $statistics): static
    {
        $this->statistics = $statistics;

        return $this;
    }

    /**
     * 컬렉션 능력 매핑을 반환합니다 — 목록 단추 표시용.
     *
     * @return array<string, string> 능력 => 권한
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-popup.popups.update',
            'can_update' => 'sirsoft-popup.popups.update',
            'can_delete' => 'sirsoft-popup.popups.delete',
        ];
    }

    /**
     * 컬렉션을 배열로 바꿉니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 목록 응답
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(fn ($popup) => (new PopupResource($popup))->toListArray($request))->values()->all(),
            'abilities' => $this->resolveCollectionAbilities($request),
            'statistics' => $this->statistics,
            ...$this->paginationMeta(),
        ];
    }
}
