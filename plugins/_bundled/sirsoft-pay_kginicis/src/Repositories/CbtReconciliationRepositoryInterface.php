<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Repositories;

interface CbtReconciliationRepositoryInterface
{
    /**
     * 주문 메타에 저장된 조정 레코드를 조회합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $key  주문 메타 키
     * @return array<string, mixed>|null 조정 레코드
     */
    public function findRecord(string $orderNumber, string $key): ?array;

    /**
     * 주문 메타에 조정 레코드를 저장합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $key  주문 메타 키
     * @param  array<string, mixed>  $record  저장할 조정 레코드
     * @return array<string, mixed>|null 저장된 조정 레코드
     */
    public function saveRecord(string $orderNumber, string $key, array $record): ?array;

    /**
     * 주문 row 를 잠근 상태로 조정 레코드를 원자적으로 변경합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $key  주문 메타 키
     * @param  callable(array<string, mixed>): (array<string, mixed>|null)  $mutator  레코드 변경 콜백
     * @return array<string, mixed>|null 변경된 조정 레코드
     */
    public function mutateRecordWithLock(string $orderNumber, string $key, callable $mutator): ?array;
}
