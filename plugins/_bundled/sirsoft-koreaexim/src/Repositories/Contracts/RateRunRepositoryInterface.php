<?php

namespace Plugins\Sirsoft\Koreaexim\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Koreaexim\Models\KoreaeximRateRun;

interface RateRunRepositoryInterface
{
    /**
     * 조건에 맞는 실행 요약을 페이지 단위로 반환합니다.
     *
     * @param  array  $filters  filters 입력값
     * @return LengthAwarePaginator 처리 결과
     */
    public function paginate(array $filters): LengthAwarePaginator;

    /**
     * 실행 한 건과 통화별 스냅샷을 조회합니다.
     *
     * @param  int  $id  id 입력값
     * @return KoreaeximRateRun 처리 결과
     */
    public function find(int $id): KoreaeximRateRun;

    /**
     * 가장 최근 실행의 요약을 조회합니다.
     *
     * @return ?KoreaeximRateRun 처리 결과
     */
    public function latest(): ?KoreaeximRateRun;

    /**
     * 재적용 가능한 최신 성공 응답을 조회합니다.
     *
     * @return ?KoreaeximRateRun 처리 결과
     */
    public function latestSuccessful(): ?KoreaeximRateRun;

    /**
     * 한국시간 당일 실행을 호출 예산·재시도 판정용으로 조회합니다.
     *
     * @return Collection 처리 결과
     */
    public function todayRuns(): Collection;

    /**
     * 당일 실제 외부 API 요청 횟수를 합산합니다.
     *
     * @return int 처리 결과
     */
    public function sumTodayRequestCount(): int;

    /**
     * 실행과 항목을 하나의 트랜잭션으로 저장합니다.
     *
     * @param  array  $data  data 입력값
     * @param  array  $items  items 입력값
     * @return KoreaeximRateRun 처리 결과
     */
    public function create(array $data, array $items = []): KoreaeximRateRun;

    /**
     * 최신 성공 응답을 보존하고 만료된 이력을 삭제합니다.
     *
     * @param  int  $days  days 입력값
     * @return int 처리 결과
     */
    public function prune(int $days): int;
}
