<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 언어팩 컬렉션 API 리소스.
 */
class LanguagePackCollection extends BaseApiCollection
{
    /**
     * {@inheritDoc}
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_install' => 'core.language_packs.install',
            'can_activate' => 'core.language_packs.manage',
            'can_deactivate' => 'core.language_packs.manage',
            'can_uninstall' => 'core.language_packs.manage',
            'can_refresh_cache' => 'core.language_packs.manage',
            'can_check_updates' => 'core.language_packs.update',
            'can_update' => 'core.language_packs.update',
        ];
    }

    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 데이터 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(function ($pack) {
                return new LanguagePackResource($pack);
            }),
        ];
    }

    /**
     * 응답 메타데이터를 추가합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 메타데이터
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'total' => $this->collection->count(),
                'active' => $this->collection->where('status', 'active')->count(),
                'installed' => $this->collection->where('status', 'installed')->count(),
                'inactive' => $this->collection->where('status', 'inactive')->count(),
                'error' => $this->collection->where('status', 'error')->count(),
                'uninstalled' => $this->collection->where('status', 'uninstalled')->count(),
            ],
        ];
    }
}
