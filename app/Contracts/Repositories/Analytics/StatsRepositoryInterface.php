<?php

namespace App\Contracts\Repositories\Analytics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 집계 Repository 인터페이스
 *
 * 시간·일·차원 세 집계 표를 함께 다룹니다. 세 표는 언제나 한 배치가 같은 기간을
 * 한꺼번에 다시 쓰므로 표마다 Repository 를 쪼개면 트랜잭션 경계가 흩어집니다.
 */
interface StatsRepositoryInterface
{
    /**
     * 지정한 시각의 시간 집계 행을 **방문자 유형 구분 없이 전부** 지우고 새로 넣습니다.
     *
     * 갱신이 아니라 「지우고 넣기」인 이유는, 다시 계산했을 때 순위에서 빠진 라벨이
     * 남아 있으면 안 되기 때문입니다.
     *
     * 유형별로 나눠 부르면 안 됩니다 — 첫 호출이 다른 유형 행까지 지우고 두 번째
     * 호출이 그것을 되살리지 않아 봇 집계가 통째로 사라집니다. 한 시각의 전 유형
     * 행을 한 번에 넘기세요.
     *
     * @param  string  $hourStart  사이트 표준시 기준 시각 (Y-m-d H:00:00)
     * @param  array<int, array<string, mixed>>  $rows  넣을 행 목록
     * @return int 넣은 행 수
     */
    public function replaceHourly(string $hourStart, array $rows): int;

    /**
     * 지정한 일자의 일 집계 행을 지우고 새로 넣습니다.
     *
     * @param  string  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @param  array<int, array<string, mixed>>  $rows  넣을 행 목록
     * @return int 넣은 행 수
     */
    public function replaceDaily(string $date, array $rows): int;

    /**
     * 지정한 일자의 차원 집계 행을 지우고 새로 넣습니다.
     *
     * @param  string  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @param  array<int, array<string, mixed>>  $rows  넣을 행 목록
     * @return int 넣은 행 수
     */
    public function replaceDimensions(string $date, array $rows): int;

    /**
     * 기간의 일 집계를 조회합니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return Collection<int, object> 일 집계 행 목록
     */
    public function daily(string $from, string $to, array $visitorTypes = []): Collection;

    /**
     * 기간의 시간 집계를 조회합니다.
     *
     * @param  string  $from  시작 시각 (Y-m-d H:i:s)
     * @param  string  $to  종료 시각 (Y-m-d H:i:s)
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return Collection<int, object> 시간 집계 행 목록
     */
    public function hourly(string $from, string $to, array $visitorTypes = []): Collection;

    /**
     * 차원 집계를 라벨별로 합산해 조회합니다.
     *
     * @param  string  $dimension  차원 이름
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<string, mixed>  $options  정렬·검색·페이지 옵션
     * @return Collection<int, object> 합산된 차원 행 목록
     */
    public function dimension(string $dimension, string $from, string $to, array $options = []): Collection;

    /**
     * 차원 집계의 기간 합계(요청 수·라벨 수)를 조회합니다.
     *
     * @param  string  $dimension  차원 이름
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return array{hits: int, labels: int} 기간 합계
     */
    public function dimensionTotals(string $dimension, string $from, string $to, array $visitorTypes = []): array;

    /**
     * 라벨별 부가 값(`extra`)을 기간 내내 합산해 조회합니다.
     *
     * @param  string  $dimension  차원 이름
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<int, string>  $labelHashes  합산할 라벨 묶음 키
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return array<string, array<string, int>> 라벨 묶음 키별 부가 값
     */
    public function dimensionExtra(string $dimension, string $from, string $to, array $labelHashes, array $visitorTypes = []): array;

    /**
     * 차원 라벨 하나의 날짜별 합계를 조회합니다.
     *
     * 리포트 목록 한 줄의 추이를 그리는 재료다. 방문자 유형 행을 날짜로 묶어 더한다.
     *
     * @param  string  $dimension  차원 이름
     * @param  string  $labelHash  라벨 묶음 키 (`md5(label)`)
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return Collection<int, object> 날짜별 합계 행 (date, visits, hits, bounces, total_time_seconds, entries, exits)
     */
    public function dimensionSeries(string $dimension, string $labelHash, string $from, string $to, array $visitorTypes = []): Collection;

    /**
     * 일 집계에서 방문자 수가 가장 많았던 날을 찾습니다.
     *
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return object|null 날짜와 방문자 수 (집계가 없으면 null)
     */
    public function maxDailyVisitors(array $visitorTypes = []): ?object;

    /**
     * 일 집계의 방문자 수를 모두 더합니다.
     *
     * 방문자 키는 날마다 회전할 수 있으므로 이 값은 「일별 순방문자의 합」입니다.
     *
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @param  string|null  $before  이 일자 이전까지만 (Y-m-d)
     * @return int 방문자 수 합계
     */
    public function sumDailyVisitors(array $visitorTypes = [], ?string $before = null): int;

    /**
     * 보존기간이 지난 시간 집계를 삭제합니다.
     *
     * 일·월·년 숫자는 일 집계에서 나오므로 시간 집계만 지워도 과거 통계는 남습니다.
     *
     * @param  Carbon  $before  이 시각 이전 집계가 대상
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteHourlyOlderThanInBatches(Carbon $before, int $chunkSize = 1000): int;

    /**
     * 확장이 등록한 차원의 집계 행과 그 확장 목표의 전환 집계 행을 삭제합니다.
     *
     * 전환 집계는 `goal` 차원에 목표 키(`{식별자}.{키}`)를 라벨로 쌓이므로 `ext:` 행만 지우면
     * 전환 탭에 그 확장 목표가 수치·매출과 함께 남는다.
     *
     * @param  string  $extensionIdentifier  확장 식별자
     * @return int 삭제한 행 수
     */
    public function deleteDimensionsForExtension(string $extensionIdentifier): int;

    /**
     * 집계 표에 행이 남아 있는 확장 차원 이름을 반환합니다.
     *
     * 확장을 끄거나 데이터를 보존하고 지운 뒤에도 쌓인 집계를 읽을 수 있게, 등록부가 아닌
     * 집계 표에서 이름을 모읍니다.
     *
     * @return array<int, string> 차원 이름 목록 (ext:식별자:키)
     */
    public function extensionDimensionNames(): array;

    /**
     * 그 날짜의 집계가 확정됐는지 확인합니다.
     *
     * @param  string  $date  일자 (Y-m-d)
     * @return bool 확정된 행이 하나라도 있으면 true
     */
    public function isDailyFinal(string $date): bool;

    /**
     * 기준 시각보다 오래된 시간 집계 수를 셉니다.
     *
     * @param  Carbon  $before  이 시각 이전이 대상
     * @return int 해당 건수
     */
    public function countHourlyOlderThan(Carbon $before): int;

    /**
     * 집계 표 셋을 모두 비웁니다.
     *
     * 전체 삭제에서만 씁니다 — 정리 배치는 집계를 지우지 않습니다.
     *
     * @return int 삭제한 행 수
     */
    public function deleteAllStats(): int;
}
