<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 플러그인 컬렉션 리소스
 *
 * 플러그인 목록을 다양한 형태로 변환하여 반환합니다.
 */
class PluginCollection extends BaseApiCollection
{
    /**
     * 플러그인 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 플러그인 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(function ($plugin) {
                return new PluginResource($plugin);
            }),
        ];
    }

    /**
     * 마켓플레이스용 플러그인 목록 배열을 반환합니다.
     *
     * @return array<string, mixed> 마켓플레이스용 플러그인 목록
     */
    public function toMarketplaceArray(): array
    {
        return [
            'data' => $this->collection->map(function ($plugin) {
                return (new PluginResource($plugin))->toBasicArray();
            })->values(),
        ];
    }

    /**
     * 설치된 플러그인 목록을 활성/비활성으로 구분하여 반환합니다.
     *
     * @return array<string, mixed> 활성/비활성 플러그인 목록
     */
    public function toInstalledArray(): array
    {
        $active = $this->collection->filter(fn ($p) => ($p['status'] ?? '') === 'active');
        $inactive = $this->collection->filter(fn ($p) => ($p['status'] ?? '') === 'inactive');

        return [
            'active' => $active->map(function ($plugin) {
                return (new PluginResource($plugin))->toBasicArray();
            })->values(),
            'inactive' => $inactive->map(function ($plugin) {
                return (new PluginResource($plugin))->toBasicArray();
            })->values(),
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
        $collection = $this->collection;

        return [
            'meta' => [
                'total_plugins' => $collection->count(),
                'active_plugins' => $collection->filter(fn ($p) => ($p['status'] ?? '') === 'active')->count(),
                'inactive_plugins' => $collection->filter(fn ($p) => ($p['status'] ?? '') === 'inactive')->count(),
                'installed_plugins' => $collection->filter(fn ($p) => ($p['status'] ?? '') !== 'uninstalled')->count(),
                'uninstalled_plugins' => $collection->filter(fn ($p) => ($p['status'] ?? '') === 'uninstalled')->count(),
            ],
        ];
    }
}
