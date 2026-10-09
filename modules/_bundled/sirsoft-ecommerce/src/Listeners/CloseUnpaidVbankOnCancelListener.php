<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\UnpaidVbankCloser;

/**
 * 입금 전에 취소된 주문의 가상계좌를 PG 에서 닫는 리스너 (P-D1)
 *
 * 큐 기본값(커밋 뒤 실행)을 그대로 쓴다 — PG 호출은 취소 트랜잭션 밖이어야 한다. 트랜잭션 안에서 계좌를 닫았는데
 * 뒤이은 쓰기가 실패해 취소가 되돌아가면, 살아 있는 주문의 입금 계좌만 사라진다.
 */
class CloseUnpaidVbankOnCancelListener implements HookListenerInterface
{
    /**
     * @param  UnpaidVbankCloser  $closer  가상계좌 폐쇄 서비스
     */
    public function __construct(
        protected UnpaidVbankCloser $closer,
    ) {}

    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.order.after_cancel' => [
                'method' => 'closeVbank',
                'priority' => 30,
            ],
        ];
    }

    /**
     * 기본 핸들러 (사용하지 않음)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 전체 취소된 주문의 미입금 가상계좌를 닫습니다.
     *
     * @param  Order  $order  취소된 주문
     * @param  array  $context  취소 컨텍스트
     * @param  array  $snapshot  취소 전 주문 스냅샷
     * @return void 반환값 없음
     */
    public function closeVbank(Order $order, array $context = [], array $snapshot = []): void
    {
        $this->closer->close($order, $snapshot);
    }
}
