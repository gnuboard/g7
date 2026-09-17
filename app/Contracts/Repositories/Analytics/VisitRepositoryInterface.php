<?php

namespace App\Contracts\Repositories\Analytics;

use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 방문 원장 Repository 인터페이스
 */
interface VisitRepositoryInterface
{
    /**
     * 아직 이어지는 방문을 찾아 잠급니다.
     *
     * 같은 방문자의 요청이 동시에 들어오면 둘 다 「방문 없음」으로 읽어 방문을 두 개
     * 만들 수 있습니다. 구현은 트랜잭션 안에서 행을 잠근 채로 돌려줘야 합니다.
     *
     * @param  string  $visitorKey  방문자 키
     * @param  Carbon  $since  이 시각 이후에 활동이 있어야 같은 방문으로 본다
     * @return AnalyticsVisit|null 이어지는 방문 (없으면 null)
     */
    public function findOpenVisitForUpdate(string $visitorKey, Carbon $since): ?AnalyticsVisit;

    /**
     * 쿠키 없는 서버 폴백이 임시 키로 만든 방문을 새 쿠키 키로 이어받습니다.
     *
     * 첫 비콘이 부릅니다. 임시 키의 방문을 잠근 채 찾아 방문자 키와 출처를 바꾸고
     * 돌려줍니다 — 없으면 null 이고 호출자는 평소처럼 새 방문을 만듭니다.
     *
     * @param  string  $provisionalKey  서버 폴백이 쓴 임시 키
     * @param  string  $newKey  응답에 심은 쿠키 값
     * @param  Carbon  $since  이 시각 이후에 활동이 있어야 이어받는다
     * @return AnalyticsVisit|null 이어받은 방문 (없으면 null)
     */
    public function adoptServerVisit(string $provisionalKey, string $newKey, Carbon $since): ?AnalyticsVisit;

    /**
     * 방문을 새로 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  방문 속성
     * @return AnalyticsVisit 생성된 방문
     */
    public function create(array $attributes): AnalyticsVisit;

    /**
     * 방문의 집계 컬럼을 갱신합니다.
     *
     * @param  AnalyticsVisit  $visit  대상 방문
     * @param  array<string, mixed>  $attributes  갱신할 속성
     * @return bool 갱신 성공 여부
     */
    public function update(AnalyticsVisit $visit, array $attributes): bool;

    /**
     * 최근 N 분 안에 활동이 있는 방문을 조회합니다.
     *
     * @param  int  $minutes  현재 접속자로 볼 시간 창 (분)
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @param  int  $limit  최대 건수
     * @return Collection<int, AnalyticsVisit> 방문 목록
     */
    public function realtime(int $minutes, array $visitorTypes = [], int $limit = 50): Collection;

    /**
     * 지정한 날짜의 순방문자 수를 원장에서 직접 셉니다.
     *
     * 오늘 숫자는 집계 배치를 기다리면 최대 한 시간 늦으므로 원장을 바로 읽습니다.
     *
     * @param  string  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return int 순방문자 수
     */
    public function uniqueVisitorsOnDate(string $date, array $visitorTypes = []): int;

    /**
     * 보존기간이 지난 방문을 나눠서 삭제합니다.
     *
     * @param  Carbon  $before  이 시각 이전에 끝난 방문이 대상
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteOlderThanInBatches(Carbon $before, int $chunkSize = 1000): int;

    /**
     * 정보주체 기준으로 방문을 찾습니다.
     *
     * @param  array<string, mixed>  $subject  검색 조건 (ip, ip_hash, user_id, visitor_key 중 하나 이상)
     * @param  string|null  $from  조회 시작일 (Y-m-d)
     * @param  string|null  $to  조회 종료일 (Y-m-d)
     * @return Collection<int, AnalyticsVisit> 방문 목록
     */
    public function findBySubject(array $subject, ?string $from = null, ?string $to = null): Collection;

    /**
     * 방문 원장의 전체 행 수를 셉니다.
     *
     * @return int 행 수
     */
    public function countAll(): int;
}
