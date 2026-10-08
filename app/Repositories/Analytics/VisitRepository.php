<?php

namespace App\Repositories\Analytics;

use App\Analytics\VisitorRecognizer;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Models\Analytics\AnalyticsStatsDaily;
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
     * 일 집계와 같은 정의로 셉니다 — 방문자 유형별 순방문자를 구해 더합니다. 하루 안에
     * 게스트로 왔다가 로그인한 사람은 일 집계에서 게스트·회원 행에 하나씩 잡히므로, 여기서만
     * 유형을 가로질러 하나로 세면 같은 날의 「오늘」과 「최대」·자정 뒤의 「어제」가 어긋납니다.
     *
     * @param  string  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return int 순방문자 수 (유형별 순방문자의 합)
     */
    public function uniqueVisitorsOnDate(string $date, array $visitorTypes = []): int
    {
        return (int) AnalyticsVisit::query()
            ->where('visit_date', $date)
            ->when($visitorTypes !== [], fn (Builder $q) => $q->whereIn('visitor_type', $visitorTypes))
            ->selectRaw('visitor_type, COUNT(DISTINCT visitor_key) as visitors')
            ->groupBy('visitor_type')
            ->get()
            ->sum('visitors');
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

        // 회원 번호는 모드에 따라 원값으로 남기도, 해시로만 남기도 한다. 주소와 같은
        // 이유로 둘을 OR 로 묶는다 — AND 로 묶으면 가명화된 구간의 기록이 통째로
        // 안 잡혀 삭제 요청이 아무것도 못 지운다.
        if (! empty($subject['user_id']) || ! empty($subject['user_hash'])) {
            $query->where(function (Builder $q) use ($subject): void {
                if (! empty($subject['user_id'])) {
                    $q->orWhere('user_id', $subject['user_id']);
                }

                if (! empty($subject['user_hash'])) {
                    $q->orWhere('user_hash', $subject['user_hash']);
                }
            });

            $matched = true;
        }

        $binaryIp = ! empty($subject['ip']) ? (inet_pton($subject['ip']) ?: null) : null;
        $hashes = array_values(array_filter((array) ($subject['ip_hash'] ?? $subject['ip_hashes'] ?? [])));

        // 주소는 모드에 따라 원값으로 저장되기도, 해시로만 남기도 한다. 둘을 AND 로
        // 묶으면 어느 쪽도 맞지 않아 같은 사람의 기록이 나뉘어 보이지 않는다.
        if ($binaryIp !== null || $hashes !== []) {
            $query->where(function (Builder $q) use ($binaryIp, $hashes): void {
                if ($binaryIp !== null) {
                    $q->orWhere('ip', $binaryIp);
                }

                if ($hashes !== []) {
                    $q->orWhereIn('ip_hash', $hashes);
                }
            });

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

    /**
     * 그 날짜에 시작한 방문 수를 셉니다.
     *
     * @param  string  $date  방문 일자 (Y-m-d, 사이트 표준시 기준)
     * @return int 방문 수
     */
    public function countOnDate(string $date): int
    {
        return AnalyticsVisit::query()->where('visit_date', $date)->count();
    }

    /**
     * 방문은 있는데 일 집계가 확정되지 않은 날을 최근 날부터 찾습니다.
     *
     * @param  string  $floor  이 날짜 이후만 본다 (Y-m-d, 제외)
     * @param  string  $today  이 날짜 이전만 본다 (Y-m-d, 제외)
     * @param  int  $limit  최대 일수
     * @return array<string, int> 날짜(Y-m-d) => 그날 방문 수, 최근 날부터
     */
    public function unfinalizedDatesWithVisits(string $floor, string $today, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        return AnalyticsVisit::query()
            ->selectRaw('visit_date, COUNT(*) as visits')
            ->where('visit_date', '>', $floor)
            ->where('visit_date', '<', $today)
            ->whereNotIn('visit_date', AnalyticsStatsDaily::query()
                ->select('date')
                ->where('date', '>', $floor)
                ->where('date', '<', $today)
                ->where('is_final', true))
            ->groupBy('visit_date')
            ->orderByDesc('visit_date')
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn ($row): array => [
                Carbon::parse($row->visit_date)->toDateString() => (int) $row->visits,
            ])
            ->all();
    }

    /**
     * 원장에서 가장 먼저 기록된 방문의 시작 시각을 돌려줍니다.
     *
     * 기본키 순서의 첫 행을 읽는다 — `MIN(first_action_at)` 은 인덱스가 없어 원장 전체를 훑는다.
     * 방문은 시작 순서대로 쌓이므로 첫 행이 가장 이른 방문이다.
     *
     * @return Carbon|null 첫 방문 시작 시각 (UTC, 원장이 비었으면 null)
     */
    public function firstTrackedAt(): ?Carbon
    {
        $value = AnalyticsVisit::query()->orderBy('id')->value('first_action_at');

        return $value !== null ? Carbon::parse($value) : null;
    }

    /**
     * 그 날짜의 방문을 ID 순서로 나눠 읽습니다.
     *
     * @param  string  $date  방문 일자 (Y-m-d, 사이트 표준시 기준)
     * @param  int  $chunkSize  한 번에 읽을 건수
     * @param  callable  $callback  청크마다 부를 콜백
     * @return void 없음
     */
    public function chunkByDate(string $date, int $chunkSize, callable $callback): void
    {
        AnalyticsVisit::query()
            ->where('visit_date', $date)
            ->orderBy('id')
            ->chunkById(max(1, $chunkSize), $callback);
    }

    /**
     * {@inheritDoc}
     */
    public function chunkCarriedIntoDate(string $date, Carbon $dayStart, int $chunkSize, callable $callback): void
    {
        AnalyticsVisit::query()
            ->where('last_action_at', '>=', $dayStart)
            ->where('visit_date', '<', $date)
            ->orderBy('id')
            ->chunkById(max(1, $chunkSize), $callback);
    }

    /**
     * 기준 시각보다 오래된 방문 수를 셉니다.
     *
     * @param  Carbon  $before  이 시각 이전이 대상
     * @return int 해당 건수
     */
    public function countOlderThan(Carbon $before): int
    {
        return AnalyticsVisit::query()->where('last_action_at', '<', $before)->count();
    }

    /**
     * 일자 구간 안의 방문 수를 셉니다.
     *
     * @param  string|null  $from  시작일 (Y-m-d)
     * @param  string|null  $to  종료일 (Y-m-d)
     * @return int 방문 수
     */
    public function countInDateRange(?string $from, ?string $to): int
    {
        return $this->dateRangeQuery($from, $to)->count();
    }

    /**
     * 한 유입처(호스트)의 전체 유입 주소를 방문 수 순으로 셉니다.
     *
     * 집계표(`analytics_dimension_stats`)는 유입처를 호스트까지만 쌓는다 — 「google.com 에서
     * 왔다」는 알아도 어느 검색 결과·어느 글에서 왔는지는 원장에만 있다. 날짜 구간이
     * `idx_analytics_visits_date_type` 로 스캔을 묶지만 `referrer_url` 에는 인덱스가 없으므로
     * 기간을 넓게 잡으면 느리다 — 화면은 기간 바가 고른 구간으로만 부른다.
     * 다음 쪽이 있는지는 한 행을 더 받아 판단한다(전체 개수를 다시 세지 않는다).
     *
     * @param  string  $host  유입처 호스트 (`referrer_name`)
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<string, mixed>  $options  page·per_page·visitor_types
     * @return array{rows: array<int, array{url: string, visits: int, hits: int}>, page: int, per_page: int, has_more: bool} 주소 목록과 쪽 정보
     */
    public function referrerUrls(string $host, string $from, string $to, array $options = []): array
    {
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($options['per_page'] ?? 25)));
        $visitorTypes = array_values(array_filter((array) ($options['visitor_types'] ?? [])));

        $rows = AnalyticsVisit::query()
            ->selectRaw('referrer_url as url, COUNT(*) as visits, COALESCE(SUM(pageviews_count), 0) as hits')
            ->where('referrer_name', $host)
            ->whereNotNull('referrer_url')
            ->where('referrer_url', '!=', '')
            ->where('visit_date', '>=', $from)
            ->where('visit_date', '<=', $to)
            ->when($visitorTypes !== [], fn (Builder $q) => $q->whereIn('visitor_type', $visitorTypes))
            ->groupBy('referrer_url')
            ->orderByDesc('visits')
            ->orderBy('referrer_url')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->get();

        $hasMore = $rows->count() > $perPage;

        return [
            'rows' => $rows->take($perPage)->map(fn ($row): array => [
                'url' => (string) $row->url,
                'visits' => (int) $row->visits,
                'hits' => (int) $row->hits,
            ])->values()->all(),
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
        ];
    }

    /**
     * 일자 구간 안의 방문을 ID 순서로 나눠 읽습니다.
     *
     * @param  string|null  $from  시작일 (Y-m-d)
     * @param  string|null  $to  종료일 (Y-m-d)
     * @param  int  $chunkSize  한 번에 읽을 건수
     * @param  callable  $callback  청크마다 부를 콜백
     * @return void 없음
     */
    public function chunkInDateRange(?string $from, ?string $to, int $chunkSize, callable $callback): void
    {
        $this->dateRangeQuery($from, $to)->orderBy('id')->chunkById(max(1, $chunkSize), $callback);
    }

    /**
     * 모든 방문을 지웁니다.
     *
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteAllInBatches(int $chunkSize = 1000): int
    {
        $deleted = 0;

        do {
            $count = AnalyticsVisit::query()->limit(max(1, $chunkSize))->delete();
            $deleted += $count;
        } while ($count > 0);

        return $deleted;
    }

    /**
     * 일자 구간 조건을 붙인 질의를 만듭니다.
     *
     * @param  string|null  $from  시작일 (Y-m-d)
     * @param  string|null  $to  종료일 (Y-m-d)
     * @return Builder 질의
     */
    private function dateRangeQuery(?string $from, ?string $to): Builder
    {
        return AnalyticsVisit::query()
            ->when($from !== null, fn (Builder $q) => $q->where('visit_date', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('visit_date', '<=', $to));
    }

    /**
     * 최근 활동이 있었던 서로 다른 방문자 수를 셉니다.
     *
     * @param  int  $minutes  최근 몇 분 안을 볼지
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return int 방문자 수
     */
    public function realtimeCount(int $minutes, array $visitorTypes = []): int
    {
        return AnalyticsVisit::query()
            ->where('last_action_at', '>=', now()->subMinutes(max(1, $minutes)))
            ->when($visitorTypes !== [], fn (Builder $q) => $q->whereIn('visitor_type', $visitorTypes))
            ->distinct()
            ->count('visitor_key');
    }

    /**
     * 가장 최근에 적재된 방문의 활동 시각을 돌려줍니다.
     *
     * @return Carbon|null 마지막 활동 시각 (기록이 없으면 null)
     */
    public function lastTrackedAt(): ?Carbon
    {
        $value = AnalyticsVisit::query()->max('last_action_at');

        return $value !== null ? Carbon::parse($value) : null;
    }

    /**
     * 주어진 방문을 지웁니다.
     *
     * @param  array<int, int>  $ids  방문 ID 목록
     * @return int 삭제한 행 수
     */
    public function deleteByIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return AnalyticsVisit::query()->whereIn('id', $ids)->delete();
    }

    /**
     * 방문자 키별로 묶은 목록을 조회합니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<string, mixed>  $options  조회 옵션 (search, visitor_type, page, per_page)
     * @return array{rows: array<int, object>, has_more: bool} 방문자 목록
     */
    public function visitorSummaries(string $from, string $to, array $options = []): array
    {
        $perPage = max(1, min(100, (int) ($options['per_page'] ?? 25)));
        $page = max(1, (int) ($options['page'] ?? 1));
        $search = $options['search'] ?? null;
        $visitorType = $options['visitor_type'] ?? null;

        $rows = AnalyticsVisit::query()
            ->selectRaw('visitor_key, MIN(visitor_type) as visitor_type, COUNT(*) as visits, '
                .'SUM(pageviews_count) as pageviews, MAX(last_action_at) as last_action_at, '
                .'MIN(country_code) as country_code, MIN(device_type) as device_type, '
                .'MIN(browser_name) as browser_name, MAX(user_id) as user_id')
            ->whereBetween('visit_date', [$from, $to])
            ->when($visitorType !== null, fn (Builder $q) => $q->where('visitor_type', $visitorType))
            ->when($search !== null && $search !== '', function (Builder $q) use ($search): void {
                // `#번호` 는 그 회원의 방문만 — 목록 회원 메뉴 「이 회원으로 검색」이 이 꼴로 보낸다.
                // 숫자만 넣으면 아래 한 칸 검색이 방문자 키 앞부분에도 대 보아, 키가 같은 숫자로
                // 시작하는 비회원 방문이 섞인다(목록이 지워진 회원을 `#번호` 로 보여 주는 것과 같은 표기).
                if (preg_match('/^#(\d+)$/', trim((string) $search), $member) === 1) {
                    $q->where('user_id', (int) $member[1]);

                    return;
                }

                // 운영자는 방문자 키·주소·회원 번호 중 무엇으로 찾을지 미리 고르지 않는다 —
                // 한 칸에 넣은 값을 셋 모두에 대 본다.
                $binary = inet_pton((string) $search) ?: null;

                $q->where(function (Builder $inner) use ($search, $binary): void {
                    $inner->where('visitor_key', 'like', $search.'%');

                    if ($binary !== null) {
                        $inner->orWhere('ip', $binary);
                    }

                    if (ctype_digit((string) $search)) {
                        $inner->orWhere('user_id', (int) $search);
                    }
                });
            })
            ->groupBy('visitor_key')
            ->orderByDesc('last_action_at')
            ->offset(($page - 1) * $perPage)
            // 다음 쪽이 있는지 알려면 한 건 더 읽어 보는 편이 COUNT 를 한 번 더 도는 것보다 싸다.
            ->limit($perPage + 1)
            ->get();

        return [
            'rows' => $rows->take($perPage)->all(),
            'has_more' => $rows->count() > $perPage,
        ];
    }
}
