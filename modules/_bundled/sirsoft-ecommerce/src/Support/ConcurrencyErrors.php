<?php

namespace Modules\Sirsoft\Ecommerce\Support;

use Illuminate\Database\DetectsConcurrencyErrors;
use Throwable;

/**
 * 동시성 오류(교착·직렬화 실패)를 삼키지 않게 하는 도우미
 *
 * 트랜잭션 안의 catch 가 교착(MySQL 1213 · SQLSTATE 40001)을 삼키면, InnoDB 는 바깥 트랜잭션을 이미 통째로 되돌린 뒤인데
 * 이후 쓰기는 autocommit 으로 한 줄씩 반영된다 — 앞부분(환불 누적·쿠폰 사용)은 사라지고 뒷부분(완료 표시)만 남는다.
 * 부가 작업 실패를 삼키는 catch 는 첫 줄에서 이 도우미를 불러 동시성 오류만은 다시 던진다.
 */
final class ConcurrencyErrors
{
    use DetectsConcurrencyErrors;

    /**
     * 동시성 오류면 다시 던집니다.
     *
     * @param  Throwable  $e  잡힌 예외
     * @return void 반환값 없음
     *
     * @throws Throwable 동시성 오류일 때 그대로
     */
    public static function rethrowIfConcurrencyError(Throwable $e): void
    {
        if ((new self)->causedByConcurrencyError($e)) {
            throw $e;
        }
    }
}
