<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 활동 로그 컬렉션 리소스
 *
 * 활동 로그 목록을 페이지네이션과 함께 반환합니다.
 */
class ActivityLogCollection extends BaseApiCollection
{
    /**
     * {@inheritDoc}
     */
    protected function abilityMap(): array
    {
        return [
            'can_delete' => 'core.activities.delete',
        ];
    }

    /**
     * 활동 로그 컬렉션을 배열로 변환합니다.
     *
     * @param Request $request HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        $abilities = $this->resolveCollectionAbilities($request);

        return [
            'data' => $this->mapWithRowNumber(function ($activityLog) {
                return (new ActivityLogResource($activityLog))->toArray(request());
            }),
            // 메타를 손으로 조립하면 정확도 필드(total_relation·total_is_exact·result_cap)가
            // 빠진다. 그러면 상한에 걸려 잘린 건수가 화면에서 정확한 값처럼 읽힌다
            // (실측: 실제 88,792 건이 "10000건" 으로 표기). 표준 메타 한 곳만 쓴다.
            ...$this->paginationMeta(),
            ...($abilities ? ['abilities' => $abilities] : []),
        ];
    }
}
