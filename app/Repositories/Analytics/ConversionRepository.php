<?php

namespace App\Repositories\Analytics;

use App\Analytics\AnalyticsClock;
use App\Contracts\Repositories\Analytics\ConversionRepositoryInterface;
use App\Models\Analytics\AnalyticsConversion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 전환 원장 Repository
 */
class ConversionRepository implements ConversionRepositoryInterface
{
    /**
     * 전환을 기록합니다.
     *
     * @param  array<string, mixed>  $attributes  전환 속성
     * @return AnalyticsConversion 생성된 전환
     */
    public function create(array $attributes): AnalyticsConversion
    {
        // 생성 시각은 앱이 UTC 로 찍는다. DB 기본값(`CURRENT_TIMESTAMP`)에 맡기면 DB 세션 시간대가
        // 서울인 서버에서 같은 행의 다른 시각(UTC)과 9시간 어긋난 벽시계가 남는다 — 다른 원장 표(방문·액션·
        // 봇·실패)는 이미 이렇게 찍는다(7.1.0 실측 O-E11).
        $attributes['created_at'] ??= AnalyticsClock::now();

        return AnalyticsConversion::query()->create($attributes);
    }

    /**
     * 방문 목록에 속한 전환을 조회합니다.
     *
     * @param  array<int, int>  $visitIds  방문 ID 목록
     * @return Collection<int, AnalyticsConversion> 전환 목록
     */
    public function findForVisits(array $visitIds): Collection
    {
        if ($visitIds === []) {
            return new Collection;
        }

        return AnalyticsConversion::query()
            ->whereIn('visit_id', $visitIds)
            ->orderBy('occurred_at')
            ->get();
    }

    /**
     * 보존기간이 지난 전환을 나눠서 삭제합니다.
     *
     * @param  Carbon  $before  이 시각 이전 전환이 대상
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteOlderThanInBatches(Carbon $before, int $chunkSize = 1000): int
    {
        $deleted = 0;

        do {
            $count = AnalyticsConversion::query()
                ->where('occurred_at', '<', $before)
                ->limit($chunkSize)
                ->delete();

            $deleted += $count;
        } while ($count > 0);

        return $deleted;
    }

    /**
     * 방문 ID 목록에 속한 전환을 삭제합니다.
     *
     * @param  array<int, int>  $visitIds  방문 ID 목록
     * @return int 삭제한 행 수
     */
    public function deleteForVisits(array $visitIds): int
    {
        if ($visitIds === []) {
            return 0;
        }

        return AnalyticsConversion::query()->whereIn('visit_id', $visitIds)->delete();
    }

    /**
     * 확장이 남긴 전환을 삭제합니다.
     *
     * @param  string  $extensionIdentifier  확장 식별자
     * @return int 삭제한 행 수
     */
    public function deleteForExtension(string $extensionIdentifier): int
    {
        return AnalyticsConversion::query()
            ->where('extension_identifier', $extensionIdentifier)
            ->delete();
    }

    /**
     * 같은 목표의 전환이 이미 있는지 확인합니다.
     *
     * @param  string  $goalKey  정규화 목표 키
     * @param  int|null  $visitId  방문 ID (방문 기준일 때)
     * @param  string|null  $visitorKey  방문자 키 (방문자 기준일 때)
     * @return bool 이미 있으면 true
     */
    public function existsForGoal(string $goalKey, ?int $visitId = null, ?string $visitorKey = null): bool
    {
        if ($visitId === null && $visitorKey === null) {
            return false;
        }

        return AnalyticsConversion::query()
            ->where('goal_key', $goalKey)
            ->when($visitId !== null, fn ($query) => $query->where('visit_id', $visitId))
            ->when($visitorKey !== null, fn ($query) => $query->where('visitor_key', $visitorKey))
            ->exists();
    }

    /**
     * 기준 시각보다 오래된 전환 수를 셉니다.
     *
     * @param  Carbon  $before  이 시각 이전이 대상
     * @return int 해당 건수
     */
    public function countOlderThan(Carbon $before): int
    {
        return AnalyticsConversion::query()->where('occurred_at', '<', $before)->count();
    }

    /**
     * 방문들의 차원 칸에서 지정한 이름을 지웁니다.
     *
     * 개인정보로 선언된 확장 차원을 소급 익명화 때 걷어 낼 때 씁니다. 데이터베이스마다 JSON
     * 함수가 달라 행을 읽어 고쳐 씁니다 — 익명화는 청크 단위 배치라 비용을 감당합니다.
     *
     * @param  array<int, int>  $visitIds  방문 ID 목록
     * @param  array<int, string>  $keys  지울 차원 이름
     * @return int 고친 행 수
     */
    public function forgetDimensionsForVisits(array $visitIds, array $keys): int
    {
        if ($visitIds === [] || $keys === []) {
            return 0;
        }

        $changed = 0;

        AnalyticsConversion::query()
            ->whereIn('visit_id', $visitIds)
            ->whereNotNull('custom_dimensions')
            // 칸을 비우면 필터(`whereNotNull`)에서 빠지므로 OFFSET 순회는 다음 쪽을 건너뛴다 — 키셋으로 돈다.
            ->eachById(function (AnalyticsConversion $row) use ($keys, &$changed): void {
                $dimensions = (array) ($row->custom_dimensions ?? []);
                $remaining = array_diff_key($dimensions, array_flip($keys));

                if (count($remaining) !== count($dimensions)) {
                    $row->custom_dimensions = $remaining === [] ? null : $remaining;
                    $row->save();
                    $changed++;
                }
            });

        return $changed;
    }
}
