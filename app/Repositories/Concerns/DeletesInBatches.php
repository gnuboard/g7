<?php

namespace App\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * 보존 기간 초과 행을 배치로 나눠 삭제하는 트레이트
 *
 * 정리 예약은 도입 직후 첫 실행에서 이미 쌓여 있던 과거 데이터를 전부 지운다.
 * 수년치가 한 문장으로 삭제되면 그 한 건이 테이블을 오래 잠그고, 트랜잭션 로그도
 * 그만큼 부풀어 정리 배치가 사이트를 멈추게 한다 — 정리하려던 문제를 정리 작업이
 * 다시 만드는 셈이다.
 *
 * 그래서 기본키 배치로 끊어 지운다. 각 DELETE 는 최대 batchSize 건의 기본키 IN 절이라
 * 잠금 구간이 짧고, 중간에 중단돼도 이미 지운 만큼은 확정된다(정리는 멱등이라
 * 다음 실행이 남은 몫을 이어서 처리한다).
 */
trait DeletesInBatches
{
    /**
     * 기본 배치 크기
     */
    protected int $deleteBatchSize = 1000;

    /**
     * 조건에 맞는 행을 배치로 나눠 삭제합니다.
     *
     * 기본키를 먼저 모아 그 키로만 지운다 — `DELETE ... LIMIT` 은 드라이버마다 지원이
     * 갈리지만 기본키 IN 절은 어디서나 같게 동작한다.
     *
     * @param  Builder  $query  삭제 대상 조건이 적용된 쿼리 (정렬·제한 미적용 상태)
     * @param  int|null  $batchSize  배치 크기 (미지정 시 $deleteBatchSize)
     * @return int 삭제된 총 건수
     */
    protected function deleteInBatches(Builder $query, ?int $batchSize = null): int
    {
        $batchSize = max(1, $batchSize ?? $this->deleteBatchSize);
        $model = $query->getModel();
        $keyName = $model->getKeyName();
        $total = 0;

        while (true) {
            // audit:allow query-repeated-execution 배치 삭제는 같은 술어를 의도적으로 반복 평가한다 — 매 회차의 모집단이 직전 삭제로 줄어든다
            $keys = (clone $query)->orderBy($keyName)->limit($batchSize)->pluck($keyName);

            if ($keys->isEmpty()) {
                return $total;
            }

            $total += $model->newQuery()->whereIn($keyName, $keys)->delete();

            // 마지막 배치 — 더 조회할 것이 없다
            if ($keys->count() < $batchSize) {
                return $total;
            }
        }
    }
}
