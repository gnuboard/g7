<?php

namespace App\Http\Resources;

use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;

class MenuCollection extends BaseApiCollection
{
    use HasAbilityCheck;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 키 => 권한 식별자 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'core.menus.create',
            'can_update' => 'core.menus.update',
            'can_delete' => 'core.menus.delete',
        ];
    }

    /**
     * 메뉴 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 메뉴 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(function ($menu) {
                return new MenuResource($menu);
            }),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
        ];
    }

    /**
     * 네비게이션용 계층 구조 메뉴 배열을 반환합니다.
     *
     * @return array<string, mixed> 계층 구조 메뉴 배열
     */
    public function toNavigationArray(): array
    {
        // 최상위 메뉴만 필터링 (parent_id가 null인 것들)
        $topLevelMenus = $this->collection->filter(function ($menu) {
            return is_null($menu->parent_id);
        });

        return [
            'data' => $topLevelMenus->map(function ($menu) {
                return (new MenuResource($menu))->toNavigationArray();
            })->sortBy('order')->values(),
        ];
    }

    /**
     * 플랫 구조의 메뉴 목록을 반환합니다 (관리자용).
     *
     * @return array<string, mixed> 플랫 구조 메뉴 목록
     */
    public function toFlatArray(): array
    {
        return [
            'data' => $this->collection->map(function ($menu) {
                return [
                    'id' => $menu->id,
                    'name' => $menu->name,
                    'slug' => $menu->slug,
                    'url' => $menu->url,
                    'icon' => $menu->icon,
                    'order' => $menu->order,
                    'is_active' => $menu->is_active,
                    'parent_id' => $menu->parent_id,
                    'module_name' => $menu->module?->name,
                ];
            })->sortBy(['parent_id', 'order'])->values(),
        ];
    }

    /**
     * 응답에 추가 메타데이터를 포함합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 메타데이터 배열
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'total_menus' => $this->collection->count(),
                'active_menus' => $this->collection->where('is_active', true)->count(),
                'top_level_menus' => $this->collection->whereNull('parent_id')->count(),
                'modules_used' => $this->collection->pluck('module.name')->filter()->unique()->values(),
            ],
        ];
    }
}
