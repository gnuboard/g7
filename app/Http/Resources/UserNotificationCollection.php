<?php

namespace App\Http\Resources;

use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use Illuminate\Http\Request;

/**
 * 알림함 컬렉션
 */
class UserNotificationCollection extends BaseApiCollection
{
    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열
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
            // 표준 메타를 쓴다. 손으로 조립하면 페이지 결과의 형태(전체건수형/상한형/단순형)를
            // 구분하지 못해, 상한에 걸려 잘린 총 건수가 정확한 값처럼 나가고 마지막 페이지도
            // 계산할 수 없는데 숫자가 채워진다.
            ...$this->paginationMeta(),
        ];
    }
}
