<?php

namespace App\Http\Resources;

use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use Illuminate\Http\Request;

class UserNotificationCollection extends BaseApiCollection
{

    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param Request $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        $sortOrder = $request->input('sort_order', 'desc');

        // 알림 type 식별자 → 다국어 라벨 맵을 컬렉션 단위로 한 번만 로드 (N+1 회피)
        $user = $request->user();
        $typeLabelMap = app(NotificationDefinitionRepositoryInterface::class)
            ->getLabelMap($user?->locale);

        return [
            'data' => $this->mapWithRowNumber(function ($notification) use ($request, $typeLabelMap) {
                return (new UserNotificationResource($notification))
                    ->withTypeLabelMap($typeLabelMap)
                    ->toArray($request);
            }, $sortOrder),
            'pagination' => [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ],
        ];
    }
}
