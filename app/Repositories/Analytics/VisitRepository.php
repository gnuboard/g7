<?php

namespace App\Repositories\Analytics;

use App\Analytics\VisitorRecognizer;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 방문 원장 Repository
 */
class VisitRepository implements VisitRepositoryInterface
{
    /**
     * 아직 이어지는 방문을 찾아 잠급니다.
     *
     * 한 사람이 탭 두 개를 동시에 열면 두 요청이 같은 순간에 「이 방문자의 열린 방문이
     * 있나」를 묻습니다. 그냥 조회하면 둘 다 「없음」을 보고 방문을 두 개 만들어
     * 방문 수가 부풀어 오릅니다. 행을 잠근 채 읽으면 뒤따라온 요청이 앞 요청의
     * 결과를 보고 같은 방문에 붙습니다. 호출자는 트랜잭션 안에서 불러야 합니다.
     *
     * @param  string  $visitorKey  방문자 키
     * @param  Carbon  $since  이 시각 이후에 활동이 있어야 같은 방문으로 본다
     * @return AnalyticsVisit|null 이어지는 방문 (없으면 null)
     */
    public function findOpenVisitForUpdate(string $visitorKey, Carbon $since): ?AnalyticsVisit
    {
        return AnalyticsVisit::query()
            ->where('visitor_key', $visitorKey)
            ->where('last_action_at', '>=', $since)
            ->orderByDesc('last_action_at')
            ->lockForUpdate()
            ->first();
    }

    /**
     * 쿠키 없는 서버 폴백이 임시 키로 만든 방문을 새 쿠키 키로 이어받습니다.
     *
     * 임시 키 방문을 잠근 채 찾아야 같은 순간의 두 비콘이 한 방문을 두 키로 나눠 갖지
     * 않습니다. 호출자는 트랜잭션 안에서 불러야 합니다.
     *
     * @param  string  $provisionalKey  서버 폴백이 쓴 임시 키
     * @param  string  $newKey  응답에 심은 쿠키 값
     * @param  Carbon  $since  이 시각 이후에 활동이 있어야 이어받는다
     * @return AnalyticsVisit|null 이어받은 방문 (없으면 null)
     */
    public function adoptServerVisit(string $provisionalKey, string $newKey, Carbon $since): ?AnalyticsVisit
    {
        $visit = AnalyticsVisit::query()
            ->where('visitor_key', $provisionalKey)
            ->where('visitor_key_type', VisitorRecognizer::KEY_TYPE_SERVER)
            ->where('last_action_at', '>=', $since)
            ->orderByDesc('last_action_at')
            ->lockForUpdate()
            ->first();

        if ($visit === null) {
            return null;
        }

        $visit->fill(['visitor_key' => $newKey, 'visitor_key_type' => 'cookie'])->save();

        return $visit;
    }

    /**
     * 방문을 새로 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  방문 속성
     * @return AnalyticsVisit 생성된 방문
     */
    public function create(array $attributes): AnalyticsVisit
    {
        return AnalyticsVisit::query()->create($attributes);
    }

    /**
     * 방문의 집계 컬럼을 갱신합니다.
     *
     * @param  AnalyticsVisit  $visit  대상 방문
     * @param  array<string, mixed>  $attributes  갱신할 속성
     * @return bool 갱신 성공 여부
     */
    public function update(AnalyticsVisit $visit, array $attributes): bool
    {
        return $visit->fill($attributes)->save();
    }

    /**
     * 최근 N 분 안에 활동이 있는 방문을 조회합니다.
     *
     * @param  int  $minutes  현재 접속자로 볼 시간 창 (분)
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @param  int  $limit  최대 건수
     * @return Collection<int, AnalyticsVisit> 방문 목록
     */
    public function realtime(int $minutes, array $visitorTypes = [], int $limit = 50): Collection
    {
        return AnalyticsVisit::query()
            ->select([
                'id', 'visitor_key', 'visitor_type', 'user_id', 'last_action_at',
                'exit_action_name_id', 'ip', 'country_code', 'device_type', 'browser_name',
            ])
            ->where('last_action_at', '>=', now()->subMinutes($minutes))
            ->when($visitorTypes !== [], fn (Builder $q) => $q->whereIn('visitor_type', $visitorTypes))
            ->orderByDesc('last_action_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 지정한 날짜의 순방문자 수를 원장에서 직접 셉니다.
     *
     * @param  string  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return int 순방문자 수
     */
    public function uniqueVisitorsOnDate(string $date, array $visitorTypes = []): int
    {
        return AnalyticsVisit::query()
            ->where('visit_date', $date)
            ->when($visitorTypes !== [], fn (Builder $q) => $q->whereIn('visitor_type', $visitorTypes))
            ->distinct()
            ->count('visitor_key');
    }

    /**
     * 보존기간이 지난 방문을 나눠서 삭제합니다.
     *
     * 한 번에 지우면 큰 사이트에서 잠금이 오래 걸리므로 나눠서 지웁니다.
     *
     * @param  Carbon  $before  이 시각 이전에 끝난 방문이 대상
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteOlderThanInBatches(Carbon $before, int $chunkSize = 1000): int
    {
        $deleted = 0;

        do {
            $count = AnalyticsVisit::query()
                ->where('last_action_at', '<', $before)
                ->limit($chunkSize)
                ->delete();

            $deleted += $count;
        } while ($count > 0);

        return $deleted;
    }

    /**
     * 정보주체 기준으로 방문을 찾습니다.
     *
     * @param  array<string, mixed>  $subject  검색 조건 (ip, ip_hash, user_id, visitor_key 중 하나 이상)
     * @param  string|null  $from  조회 시작일 (Y-m-d)
     * @param  string|null  $to  조회 종료일 (Y-m-d)
     * @return Collection<int, AnalyticsVisit> 방문 목록
     */
    public function findBySubject(array $subject, ?string $from = null, ?string $to = null): Collection
    {
        $query = AnalyticsVisit::query();
        $matched = false;

        if (! empty($subject['visitor_key'])) {
            $query->where('visitor_key', $subject['visitor_key']);
            $matched = true;
        }

        if (! empty($subject['user_id'])) {
            $query->where('user_id', $subject['user_id']);
            $matched = true;
        }

        if (! empty($subject['ip'])) {
            $query->where('ip', inet_pton($subject['ip']) ?: null);
            $matched = true;
        }

        if (! empty($subject['ip_hash'])) {
            $hashes = (array) $subject['ip_hash'];
            $query->whereIn('ip_hash', $hashes);
            $matched = true;
        }

        // 조건이 하나도 없으면 원장 전체가 나온다 — 정보주체 조회의 의미가 사라지므로 막는다.
        if (! $matched) {
            return new Collection;
        }

        return $query
            ->when($from !== null, fn (Builder $q) => $q->where('visit_date', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('visit_date', '<=', $to))
            ->orderByDesc('first_action_at')
            ->get();
    }

    /**
     * 방문 원장의 전체 행 수를 셉니다.
     *
     * @return int 행 수
     */
    public function countAll(): int
    {
        return AnalyticsVisit::query()->count();
    }
}
