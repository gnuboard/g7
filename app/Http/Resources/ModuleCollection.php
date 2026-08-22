<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ModuleCollection extends BaseApiCollection
{
    /**
     * {@inheritDoc}
     */
    protected function abilityMap(): array
    {
        return [
            'can_install' => 'core.modules.install',
            'can_activate' => 'core.modules.activate',
            'can_uninstall' => 'core.modules.uninstall',
        ];
    }

    /**
     * 모듈 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 모듈 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(function ($module) {
                return new ModuleResource($module);
            }),
        ];
    }

    /**
     * 마켓플레이스용 모듈 목록 배열을 반환합니다.
     *
     * @return array<string, mixed> 마켓플레이스용 모듈 목록 (설치 횟수 순 정렬)
     */
    public function toMarketplaceArray(): array
    {
        return [
            'data' => $this->collection->map(function ($module) {
                return (new ModuleResource($module))->toMarketplaceArray();
            })->sortByDesc('install_count')->values(),
        ];
    }

    /**
     * 설치된 모듈 목록을 활성/비활성으로 구분하여 반환합니다.
     *
     * @return array<string, mixed> 활성/비활성 모듈 목록
     */
    public function toInstalledArray(): array
    {
        $active = $this->collection->where('is_active', true);
        $inactive = $this->collection->where('is_active', false);

        return [
            'active' => $active->map(function ($module) {
                return (new ModuleResource($module))->toDependencyArray();
            })->sortBy('name')->values(),
            'inactive' => $inactive->map(function ($module) {
                return (new ModuleResource($module))->toDependencyArray();
            })->sortBy('name')->values(),
        ];
    }

    /**
     * 의존성 그래프용 데이터 배열을 반환합니다.
     *
     * @return array<string, mixed> 노드와 엣지 정보가 포함된 의존성 그래프 데이터
     */
    public function toDependencyGraph(): array
    {
        return [
            'nodes' => $this->collection->map(function ($module) {
                return [
                    'id' => $module->slug,
                    'name' => $module->name,
                    'version' => $module->version,
                    'is_active' => $module->is_active,
                    'is_system' => $module->is_system,
                ];
            }),
            'edges' => $this->collection->flatMap(function ($module) {
                if (! $module->relationLoaded('dependencies')) {
                    return [];
                }

                return $module->dependencies->map(function ($dependency) use ($module) {
                    return [
                        'from' => $module->slug,
                        'to' => $dependency->slug,
                        'required_version' => $dependency->pivot->required_version ?? null,
                    ];
                });
            })->values(),
        ];
    }

    /**
     * 시스템/사용자 모듈로 구분하여 배열을 반환합니다.
     *
     * @return array<string, mixed> 시스템 모듈과 사용자 모듈 목록
     */
    public function toByCategoryArray(): array
    {
        $system = $this->collection->where('is_system', true);
        $user = $this->collection->where('is_system', false);

        return [
            'system' => $system->map(function ($module) {
                return (new ModuleResource($module))->toDependencyArray();
            })->sortBy('name')->values(),
            'user' => $user->map(function ($module) {
                return (new ModuleResource($module))->toMarketplaceArray();
            })->sortByDesc('install_count')->values(),
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
                'total_modules' => $this->collection->count(),
                'active_modules' => $this->collection->where('is_active', true)->count(),
                'system_modules' => $this->collection->where('is_system', true)->count(),
                'user_modules' => $this->collection->where('is_system', false)->count(),
                'total_installs' => $this->collection->sum('install_count'),
                'average_rating' => $this->collection->where('rating', '>', 0)->avg('rating'),
                'latest_version' => $this->collection->max('version'),
                'categories' => $this->collection->pluck('tags')->flatten()->unique()->values(),
                'dependency_count' => $this->collection->sum('dependencies_count'),
            ],
        ];
    }
}
