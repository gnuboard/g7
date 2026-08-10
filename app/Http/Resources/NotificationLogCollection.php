<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class NotificationLogCollection extends BaseApiCollection
{
    /**
     * {@inheritDoc}
     */
    protected function abilityMap(): array
    {
        return [
            'can_delete' => 'core.notification-logs.delete',
        ];
    }

    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        $sortOrder = $request->input('sort_order', 'desc');
        $abilities = $this->resolveCollectionAbilities($request);

        return [
            'data' => $this->mapWithRowNumber(function ($log) use ($request) {
                return (new NotificationLogResource($log))->toArray($request);
            }, $sortOrder),
            // 표준 메타를 쓴다. 이 목록은 커서 요청이면 CursorPaginator 로, 그 외에는 상한형
            // 페이지로 온다. 손으로 조립하면 커서 결과에 없는 total()/lastPage() 를 불러
            // 그 요청만 500 이 되고, 상한형에서는 정확도 메타가 빠진다.
            ...$this->paginationMeta(),
            ...($abilities ? ['abilities' => $abilities] : []),
        ];
    }
}
