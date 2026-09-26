<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

/**
 * 종결 주문 개인정보 보유기간 처리 Repository 인터페이스 (이슈 #636)
 *
 * 주문 한 건에 딸린 행들의 개인정보를 지우거나 행째 영구 삭제합니다. 무엇을 지우는지(컬럼·테이블
 * 전수)는 이 저장소가, 언제·어떤 순서로 하는지는 서비스가 정합니다.
 */
interface OrderPrivacyRetentionRepositoryInterface
{
    /**
     * 주문 한 건에 딸린 개인정보를 익명화합니다 (수정 시각 보존).
     *
     * @param  int  $orderId  주문 ID
     * @param  array<string, mixed>  $orderMeta  익명화 표지를 더한 주문 메타
     * @param  string  $maskedValue  NOT NULL 칸에 넣는 치환값
     * @return void 반환값 없음
     */
    public function anonymizeOrder(int $orderId, array $orderMeta, string $maskedValue): void;

    /**
     * 주문의 주문 옵션 ID 목록을 반환합니다.
     *
     * @param  int  $orderId  주문 ID
     * @return array<int, int> 주문 옵션 ID 목록
     */
    public function optionIds(int $orderId): array;

    /**
     * 주문과 그 참조 행을 영구 삭제합니다 (리뷰는 호출자가 먼저 지운다).
     *
     * 쿠폰 발급·마일리지 거래는 행을 남기고 주문 연결만 끊는다.
     *
     * @param  int  $orderId  주문 ID
     * @return void 반환값 없음
     */
    public function purgeOrder(int $orderId): void;
}
