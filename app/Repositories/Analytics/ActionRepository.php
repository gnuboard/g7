<?php

namespace App\Repositories\Analytics;

use App\Contracts\Repositories\Analytics\ActionRepositoryInterface;
use App\Enums\Analytics\ActionType;
use App\Models\Analytics\AnalyticsAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 액션 원장 Repository
 */
class ActionRepository implements ActionRepositoryInterface
{
    /**
     * 액션을 기록합니다.
     *
     * @param  array<string, mixed>  $attributes  액션 속성
     * @return AnalyticsAction 생성된 액션
     */
    public function create(array $attributes): AnalyticsAction
    {
        return AnalyticsAction::query()->create($attributes);
    }

    /**
     * 액션을 갱신합니다.
     *
     * @param  AnalyticsAction  $action  대상 액션
     * @param  array<string, mixed>  $attributes  갱신할 속성
     * @return bool 갱신 성공 여부
     */
    public function update(AnalyticsAction $action, array $attributes): bool
    {
        return $action->fill($attributes)->save();
    }

    /**
     * 액션 한 건을 지웁니다.
     *
     * @param  AnalyticsAction  $action  대상 액션
     * @return bool 삭제 성공 여부
     */
    public function delete(AnalyticsAction $action): bool
    {
        return (bool) $action->delete();
    }

    /**
     * 한 방문의 모든 액션에 복제된 방문자 키를 바꿉니다.
     *
     * @param  int  $visitId  방문 ID
     * @param  string  $visitorKey  새 방문자 키
     * @return int 갱신한 행 수
     */
    public function renameVisitorKey(int $visitId, string $visitorKey): int
    {
        return AnalyticsAction::query()
            ->where('visit_id', $visitId)
            ->update(['visitor_key' => $visitorKey]);
    }

    /**
     * 방문의 가장 최근 액션을 조회합니다.
     *
     * @param  int  $visitId  방문 ID
     * @param  string|null  $actionType  액션 종류로 좁히려면 지정
     * @return AnalyticsAction|null 최근 액션 (없으면 null)
     */
    public function findLatestForVisit(int $visitId, ?string $actionType = null): ?AnalyticsAction
    {
        return AnalyticsAction::query()
            ->where('visit_id', $visitId)
            ->when($actionType !== null, fn ($q) => $q->where('action_type', $actionType))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 서버 폴백으로 남긴 같은 URL 의 최근 액션을 찾습니다.
     *
     * 서버 폴백이 먼저 페이지뷰를 적고 곧이어 브라우저 비콘이 같은 페이지를 알리면,
     * 새 행을 만들 경우 페이지뷰가 두 배가 됩니다. 방금 서버가 적은 행을 찾아
     * 그 행을 비콘 정보로 채우는 것이 이 조회의 목적입니다.
     *
     * @param  int  $visitId  방문 ID
     * @param  int  $actionNameId  이름 사전 ID
     * @param  Carbon  $since  이 시각 이후 액션만 대상
     * @return AnalyticsAction|null 병합 대상 액션 (없으면 null)
     */
    public function findRecentServerPageview(int $visitId, int $actionNameId, Carbon $since): ?AnalyticsAction
    {
        return AnalyticsAction::query()
            ->where('visit_id', $visitId)
            ->where('action_name_id', $actionNameId)
            ->where('action_type', ActionType::Pageview->value)
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 방문 목록에 속한 액션을 조회합니다.
     *
     * @param  array<int, int>  $visitIds  방문 ID 목록
     * @return Collection<int, AnalyticsAction> 액션 목록
     */
    public function findForVisits(array $visitIds): Collection
    {
        if ($visitIds === []) {
            return new Collection;
        }

        return AnalyticsAction::query()
            ->whereIn('visit_id', $visitIds)
            ->orderBy('visit_id')
            ->orderBy('occurred_at')
            ->get();
    }

    /**
     * 보존기간이 지난 액션을 나눠서 삭제합니다.
     *
     * @param  Carbon  $before  이 시각 이전 액션이 대상
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteOlderThanInBatches(Carbon $before, int $chunkSize = 1000): int
    {
        $deleted = 0;

        do {
            $count = AnalyticsAction::query()
                ->where('occurred_at', '<', $before)
                ->limit($chunkSize)
                ->delete();

            $deleted += $count;
        } while ($count > 0);

        return $deleted;
    }

    /**
     * 방문 ID 목록에 속한 액션을 삭제합니다.
     *
     * @param  array<int, int>  $visitIds  방문 ID 목록
     * @return int 삭제한 행 수
     */
    public function deleteForVisits(array $visitIds): int
    {
        if ($visitIds === []) {
            return 0;
        }

        return AnalyticsAction::query()->whereIn('visit_id', $visitIds)->delete();
    }

    /**
     * 액션 원장의 전체 행 수를 셉니다.
     *
     * @return int 행 수
     */
    public function countAll(): int
    {
        return AnalyticsAction::query()->count();
    }

    /**
     * 여러 방문의 액션을 방문별로 묶어 시간순으로 읽습니다.
     *
     * @param  array<int, int>  $visitIds  방문 ID 목록
     * @return Collection<int|string, Collection<int, AnalyticsAction>> 방문 ID 로 묶인 액션
     */
    public function groupedForVisits(array $visitIds): Collection
    {
        if ($visitIds === []) {
            return collect();
        }

        return AnalyticsAction::query()
            ->whereIn('visit_id', $visitIds)
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('visit_id');
    }

    /**
     * 기준 시각보다 오래된 액션 수를 셉니다.
     *
     * @param  Carbon  $before  이 시각 이전이 대상
     * @return int 해당 건수
     */
    public function countOlderThan(Carbon $before): int
    {
        return AnalyticsAction::query()->where('occurred_at', '<', $before)->count();
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

        AnalyticsAction::query()
            ->whereIn('visit_id', $visitIds)
            ->whereNotNull('custom_dimensions')
            // 칸을 비우면 필터(`whereNotNull`)에서 빠지므로 OFFSET 순회는 다음 쪽을 건너뛴다 — 키셋으로 돈다.
            ->eachById(function (AnalyticsAction $row) use ($keys, &$changed): void {
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
