<?php

namespace App\Contracts\Repositories\Analytics;

use App\Models\Analytics\AnalyticsCollectFailure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * 적재 실패 로그 Repository 인터페이스
 */
interface CollectFailureRepositoryInterface
{
    /**
     * 실패를 기록합니다.
     *
     * @param  array<string, mixed>  $attributes  실패 속성
     * @return AnalyticsCollectFailure 생성된 실패 로그
     */
    public function create(array $attributes): AnalyticsCollectFailure;

    /**
     * 실패 목록을 페이지네이션하여 조회합니다.
     *
     * @param  array<string, mixed>  $filters  필터 조건 (reason)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션된 실패 목록
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * 최근 N 시간의 실패 건수를 셉니다.
     *
     * @param  int  $hours  기준 시간
     * @return int 실패 건수
     */
    public function countSince(int $hours = 24): int;

    /**
     * 보존기간이 지난 실패 로그를 나눠서 삭제합니다.
     *
     * @param  Carbon  $before  이 시각 이전 로그가 대상
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteOlderThanInBatches(Carbon $before, int $chunkSize = 1000): int;

    /**
     * 기준 시각보다 오래된 적재 실패 수를 셉니다.
     *
     * 마른 실행이 「무엇을 얼마나 지울지」를 미리 보이는 데 씁니다.
     *
     * @param  Carbon  $before  이 시각 이전이 대상
     * @return int 해당 건수
     */
    public function countOlderThan(Carbon $before): int;

    /**
     * 기간 안의 실패 로그를 나눠서 순회합니다.
     *
     * 소급 익명화가 이 로그의 주소도 방문 원장과 같은 규칙으로 바꾸는 데 씁니다.
     *
     * @param  string|null  $from  시작 일자 (Y-m-d, null 이면 처음부터)
     * @param  string|null  $to  끝 일자 (Y-m-d, null 이면 끝까지)
     * @param  int  $chunkSize  한 번에 읽을 건수
     * @param  callable  $callback  청크마다 부를 콜백
     * @return void 없음
     */
    public function chunkInDateRange(?string $from, ?string $to, int $chunkSize, callable $callback): void;

    /**
     * 실패 로그의 주소를 덮어씁니다.
     *
     * @param  AnalyticsCollectFailure  $failure  대상 로그
     * @param  string|null  $ip  새 주소 (null 이면 지움)
     * @return void 없음
     */
    public function updateIp(AnalyticsCollectFailure $failure, ?string $ip): void;
}
