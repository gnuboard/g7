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
     * 일 집계와 같은 정의(방문자 유형별 순방문자의 합)로 셉니다.
     *
     * @param  string  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return int 순방문자 수 (유형별 순방문자의 합)
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
     * @param  array<string, mixed>  $subject  검색 조건 (ip, ip_hash, user_id, user_hash, visitor_key 중 하나 이상)
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

    /**
     * 그 날짜에 시작한 방문 수를 셉니다.
     *
     * 집계 진행률의 분모입니다.
     *
     * @param  string  $date  방문 일자 (Y-m-d, 사이트 표준시 기준)
     * @return int 방문 수
     */
    public function countOnDate(string $date): int;

    /**
     * 방문은 있는데 일 집계가 확정되지 않은 날을 최근 날부터 찾습니다.
     *
     * 스케줄러가 멈춘 동안 지나간 날은 「어제 확정」이 돌지 않아 숫자가 미확정으로 남는다.
     * 밀린 날을 골라 확정하는 따라잡기의 후보 목록이다.
     *
     * @param  string  $floor  이 날짜 **이후**만 본다 (Y-m-d, 제외 — 보존기간 경계)
     * @param  string  $today  이 날짜 **이전**만 본다 (Y-m-d, 제외 — 오늘은 확정하지 않는다)
     * @param  int  $limit  최대 일수
     * @return array<string, int> 날짜(Y-m-d) => 그날 방문 수, 최근 날부터
     */
    public function unfinalizedDatesWithVisits(string $floor, string $today, int $limit): array;

    /**
     * 원장에서 가장 먼저 기록된 방문의 시작 시각을 돌려줍니다.
     *
     * 스케줄러 생존 판정의 근거 시각이다 — 설치 직후 첫 예약 실행 전에 「스케줄러가 없다」고
     * 오판하지 않도록, 기록이 쌓이기 시작한 뒤 충분한 시간이 지났는지를 본다.
     *
     * @return Carbon|null 첫 방문 시작 시각 (UTC, 원장이 비었으면 null)
     */
    public function firstTrackedAt(): ?Carbon;

    /**
     * 그 날짜의 방문을 ID 순서로 나눠 읽습니다.
     *
     * 건너뛰기(OFFSET)가 아니라 마지막 ID 기준으로 나눕니다 — 읽는 도중 행이
     * 지워지거나 들어와도 건너뛰는 방문이 없습니다.
     *
     * @param  string  $date  방문 일자 (Y-m-d, 사이트 표준시 기준)
     * @param  int  $chunkSize  한 번에 읽을 건수
     * @param  callable  $callback  청크마다 부를 콜백
     * @return void 없음
     */
    public function chunkByDate(string $date, int $chunkSize, callable $callback): void;

    /**
     * 그 날짜보다 먼저 시작해 그 날짜까지 이어진 방문을 ID 순서로 나눠 읽습니다.
     *
     * 자정을 넘긴 방문의 뒷부분 액션은 그 날짜의 시각에 떨어지는데, 방문은 시작한 날에
     * 속하므로 그 날짜의 방문 목록(`chunkByDate`)에는 나오지 않습니다.
     *
     * @param  string  $date  방문 일자 (Y-m-d, 사이트 표준시 기준)
     * @param  Carbon  $dayStart  그 날짜의 시작 시각 (UTC)
     * @param  int  $chunkSize  한 번에 읽을 건수
     * @param  callable  $callback  청크마다 부를 콜백
     * @return void 없음
     */
    public function chunkCarriedIntoDate(string $date, Carbon $dayStart, int $chunkSize, callable $callback): void;

    /**
     * 기준 시각보다 오래된 방문 수를 셉니다.
     *
     * 마른 실행이 「무엇을 얼마나 지울지」를 미리 보이는 데 씁니다.
     *
     * @param  Carbon  $before  이 시각 이전이 대상
     * @return int 해당 건수
     */
    public function countOlderThan(Carbon $before): int;

    /**
     * 일자 구간 안의 방문 수를 셉니다.
     *
     * @param  string|null  $from  시작일 (Y-m-d, 없으면 처음부터)
     * @param  string|null  $to  종료일 (Y-m-d, 없으면 끝까지)
     * @return int 방문 수
     */
    public function countInDateRange(?string $from, ?string $to): int;

    /**
     * 한 유입처(호스트)의 전체 유입 주소를 방문 수 순으로 셉니다.
     *
     * 집계표는 유입처를 호스트(`referrer_name`)까지만 쌓아 「어느 글에서 왔는지」를 답하지
     * 못합니다. 전체 주소는 원장에만 있으므로 여기서 직접 읽습니다. 날짜 구간이 스캔을
     * 묶어 주지만 `referrer_url` 자체에는 인덱스가 없으니 기간을 넓게 잡으면 느립니다.
     *
     * @param  string  $host  유입처 호스트 (`referrer_name`)
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<string, mixed>  $options  page·per_page·visitor_types
     * @return array{rows: array<int, array{url: string, visits: int, hits: int}>, page: int, per_page: int, has_more: bool} 주소 목록과 쪽 정보
     */
    public function referrerUrls(string $host, string $from, string $to, array $options = []): array;

    /**
     * 일자 구간 안의 방문을 ID 순서로 나눠 읽습니다.
     *
     * @param  string|null  $from  시작일 (Y-m-d)
     * @param  string|null  $to  종료일 (Y-m-d)
     * @param  int  $chunkSize  한 번에 읽을 건수
     * @param  callable  $callback  청크마다 부를 콜백
     * @return void 없음
     */
    public function chunkInDateRange(?string $from, ?string $to, int $chunkSize, callable $callback): void;

    /**
     * 모든 방문을 지웁니다.
     *
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteAllInBatches(int $chunkSize = 1000): int;

    /**
     * 최근 활동이 있었던 서로 다른 방문자 수를 셉니다.
     *
     * @param  int  $minutes  최근 몇 분 안을 볼지
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return int 방문자 수
     */
    public function realtimeCount(int $minutes, array $visitorTypes = []): int;

    /**
     * 가장 최근에 적재된 방문의 활동 시각을 돌려줍니다.
     *
     * 진단 화면이 「마지막으로 뭔가 세어진 때」를 보여 주는 값입니다.
     *
     * @return Carbon|null 마지막 활동 시각 (기록이 없으면 null)
     */
    public function lastTrackedAt(): ?Carbon;

    /**
     * 주어진 방문을 지웁니다.
     *
     * 정보주체 삭제에서 씁니다 — 액션·전환을 먼저 지운 뒤 부릅니다.
     *
     * @param  array<int, int>  $ids  방문 ID 목록
     * @return int 삭제한 행 수
     */
    public function deleteByIds(array $ids): int;

    /**
     * 방문자 키별로 묶은 목록을 조회합니다.
     *
     * 방문자 화면의 첫 장입니다 — 방문 하나하나가 아니라 「사람」 단위로 몇 번 왔고
     * 마지막이 언제였는지를 봅니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<string, mixed>  $options  조회 옵션 (search, visitor_type, page, per_page)
     * @return array{rows: array<int, object>, has_more: bool} 방문자 목록
     */
    public function visitorSummaries(string $from, string $to, array $options = []): array;
}
