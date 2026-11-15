<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIssueStatus;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;

/**
 * 주문 현금영수증 이력 Repository 인터페이스
 */
interface OrderCashReceiptRepositoryInterface
{
    /**
     * 이력을 생성합니다.
     *
     * @param  array<string, mixed>  $data  이력 데이터
     * @return OrderCashReceipt 생성된 이력
     */
    public function create(array $data): OrderCashReceipt;

    /**
     * 주문의 모든 이력을 최신순으로 조회합니다.
     *
     * @param  Order  $order  주문 모델
     * @return Collection<int, OrderCashReceipt> 이력 컬렉션
     */
    public function findByOrder(Order $order): Collection;

    /**
     * 주문의 활성 영수증(가장 최근 발급 완료 건 중 이후 취소되지 않은 것)을 조회합니다.
     *
     * @param  Order  $order  주문 모델
     * @return OrderCashReceipt|null 활성 영수증 (없으면 null)
     */
    public function findActiveReceipt(Order $order): ?OrderCashReceipt;

    /**
     * 주문의 활성 영수증 전체를 조회합니다.
     *
     * 정상 흐름에서는 항상 0~1건이지만, 발급 실패·중복 발급 등 이상 상태에서 2건 이상이
     * 남을 수 있으므로 전액취소 시에는 이 목록 전체를 대상으로 한다.
     *
     * @param  Order  $order  주문 모델
     * @return Collection<int, OrderCashReceipt> 활성 영수증 컬렉션
     */
    public function findActiveReceipts(Order $order): Collection;

    /**
     * 영수증 키로 이력을 조회합니다.
     *
     * @param  string  $receiptKey  프로바이더 영수증 키
     * @return OrderCashReceipt|null 이력 (없으면 null)
     */
    public function findByReceiptKey(string $receiptKey): ?OrderCashReceipt;

    /**
     * 주문의 발급(issue) 시도 횟수를 반환합니다. (성공/실패 무관)
     *
     * 프로바이더가 발급 요청 식별자를 회차로 구분해야 할 때 쓰인다.
     * 예: 토스페이먼츠는 동일 orderId 재사용을 중복으로 거부하므로 회차 접미가 필요하다.
     *
     * 실패한 시도까지 세는 이유 — 프로바이더에 요청이 도달한 뒤 응답만 실패했을 수 있고,
     * 그 경우 같은 식별자를 재사용하면 중복 거부된다.
     *
     * @param  Order  $order  주문 모델
     * @return int 발급 이력 건수
     */
    public function countIssues(Order $order): int;

    /**
     * 발급 슬롯을 예약합니다. (처리 중 발급 행 INSERT)
     *
     * 주문당 활성 발급 1건은 DB 유니크(`active_issue_order_key`)가 지킨다. 이미 다른 행이 슬롯을 쥐고 있으면
     * INSERT 가 유니크 위반으로 실패하고 null 을 돌려준다 — 호출부는 PG 를 부르지 않는다.
     *
     * @param  array<string, mixed>  $attributes  이력 데이터 (거래 유형·상태는 이 메서드가 정한다)
     * @return OrderCashReceipt|null 예약된 처리 중 발급 행 (슬롯이 이미 차 있으면 null)
     */
    public function reserveIssue(array $attributes): ?OrderCashReceipt;

    /**
     * 주문의 발급 슬롯을 쥔 행(발급·처리 중/완료·슬롯 미해제)을 조회합니다.
     *
     * @param  Order  $order  주문 모델
     * @return OrderCashReceipt|null 슬롯 보유 행 (없으면 null)
     */
    public function findSlotHolder(Order $order): ?OrderCashReceipt;

    /**
     * 발급 행에 대한 취소를 예약합니다. (처리 중 취소 행 INSERT)
     *
     * 발급 행당 열린 취소(처리 중·완료) 1건은 DB 유니크(`open_cancel_target_key`)가 지킨다. 이미 열린 취소가
     * 있으면 null 을 돌려준다 — 호출부는 PG 취소를 부르지 않는다.
     *
     * @param  OrderCashReceipt  $issue  취소할 발급 행
     * @param  array<string, mixed>  $attributes  이력 데이터 (거래 유형·상태·취소 대상은 이 메서드가 정한다)
     * @return OrderCashReceipt|null 예약된 처리 중 취소 행 (열린 취소가 이미 있으면 null)
     */
    public function reserveCancel(OrderCashReceipt $issue, array $attributes): ?OrderCashReceipt;

    /**
     * 발급 행에 걸린 열린 취소(처리 중·완료)를 조회합니다.
     *
     * @param  OrderCashReceipt  $issue  발급 행
     * @return OrderCashReceipt|null 열린 취소 행 (없으면 null)
     */
    public function findOpenCancel(OrderCashReceipt $issue): ?OrderCashReceipt;

    /**
     * 행의 상태가 기대값일 때만 갱신합니다. (조건부 상태 전이)
     *
     * @param  int  $id  이력 ID
     * @param  CashReceiptIssueStatus  $expected  갱신 직전 기대 상태
     * @param  array<string, mixed>  $attributes  갱신할 값 (모델 캐스트를 거쳐 저장)
     * @return int 갱신된 행 수 (상태가 이미 바뀌었으면 0)
     */
    public function updateIfStatus(int $id, CashReceiptIssueStatus $expected, array $attributes): int;

    /**
     * 취소를 완료로 기록하고 대상 발급 행의 슬롯을 놓습니다. (한 트랜잭션)
     *
     * @param  OrderCashReceipt  $cancel  처리 중 취소 행
     * @param  OrderCashReceipt  $issue  취소 대상 발급 행
     * @param  array<string, mixed>  $attributes  취소 행에 함께 기록할 값 (영수증 키·원응답 등)
     * @return bool 취소 행이 처리 중이어서 완료로 바꿨으면 true, 이미 다른 상태였으면 false
     */
    public function completeCancel(OrderCashReceipt $cancel, OrderCashReceipt $issue, array $attributes): bool;

    /**
     * 주어진 ID 이하의 발급 이력 건수를 셉니다. (예약 행 기준 발급 회차)
     *
     * 예약 행을 먼저 INSERT 한 뒤 「내 행까지 몇 번째인가」 로 회차를 정하므로, 겹친 요청이 같은 회차를 받지 않는다.
     *
     * @param  Order  $order  주문 모델
     * @param  int  $id  기준 이력 ID (포함)
     * @return int 발급 이력 건수
     */
    public function countIssuesUpTo(Order $order, int $id): int;

    /**
     * 주문의 처리 중 발급 행(슬롯 보유)을 조회합니다.
     *
     * @param  Order  $order  주문 모델
     * @return OrderCashReceipt|null 처리 중 발급 행 (없으면 null)
     */
    public function findInProgressIssue(Order $order): ?OrderCashReceipt;
}
