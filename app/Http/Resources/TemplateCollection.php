<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * 템플릿 컬렉션 리소스
 *
 * 템플릿 목록을 다양한 형태로 변환하여 반환합니다.
 */
class TemplateCollection extends ResourceCollection
{
    /**
     * 템플릿 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 템플릿 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(function ($template) {
                return new TemplateResource($template);
            }),
        ];
    }

    /**
     * 마켓플레이스용 템플릿 목록 배열을 반환합니다.
     *
     * @return array<string, mixed> 마켓플레이스용 템플릿 목록
     */
    public function toMarketplaceArray(): array
    {
        return [
            'data' => $this->collection->map(function ($template) {
                return (new TemplateResource($template))->toMarketplaceArray();
            })->values(),
        ];
    }

    /**
     * 설치된 템플릿 목록을 활성/비활성으로 구분하여 반환합니다.
     *
     * @return array<string, mixed> 활성/비활성 템플릿 목록
     */
    public function toInstalledArray(): array
    {
        $active = $this->collection->filter(fn ($t) => ($t['status'] ?? '') === 'active');
        $inactive = $this->collection->filter(fn ($t) => ($t['status'] ?? '') === 'inactive');

        return [
            'active' => $active->map(function ($template) {
                return (new TemplateResource($template))->toDependencyArray();
            })->values(),
            'inactive' => $inactive->map(function ($template) {
                return (new TemplateResource($template))->toDependencyArray();
            })->values(),
        ];
    }

    /**
     * admin/user 템플릿으로 구분하여 배열을 반환합니다.
     *
     * @return array<string, mixed> admin 템플릿과 user 템플릿 목록
     */
    public function toByTypeArray(): array
    {
        $admin = $this->collection->filter(fn ($t) => ($t['type'] ?? '') === 'admin');
        $user = $this->collection->filter(fn ($t) => ($t['type'] ?? '') === 'user');

        return [
            'admin' => $admin->map(function ($template) {
                return (new TemplateResource($template))->toDependencyArray();
            })->values(),
            'user' => $user->map(function ($template) {
                return (new TemplateResource($template))->toMarketplaceArray();
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
                'total_templates' => $collection->count(),
                'active_templates' => $collection->filter(fn ($t) => ($t['status'] ?? '') === 'active')->count(),
                'admin_templates' => $collection->filter(fn ($t) => ($t['type'] ?? '') === 'admin')->count(),
                'user_templates' => $collection->filter(fn ($t) => ($t['type'] ?? '') === 'user')->count(),
                'installed_templates' => $collection->filter(fn ($t) => ($t['status'] ?? '') !== 'not_installed')->count(),
            ],
        ];
    }
}
