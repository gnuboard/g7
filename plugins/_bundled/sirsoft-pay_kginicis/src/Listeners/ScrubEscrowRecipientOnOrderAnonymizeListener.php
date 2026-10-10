<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\PayKginicis\Repositories\EscrowDeliveryRepositoryInterface;

/**
 * KG 이니시스 에스크로 배송 등록 수령인 사본 파기 리스너 (이슈 #636).
 *
 * 관리자가 에스크로 배송을 등록하면 결제 행 `payment_meta.escrow_delivery` 에 수령인 이름·주소가
 * 남는다(AdminEscrowDeliveryController). 이커머스가 보유기간이 지난 종결 주문을 익명화하면서 주문
 * 한 건마다 `sirsoft-ecommerce.order.after_anonymize` 를 발행하므로, 그 자리에서 이 플러그인이 남긴
 * 사본을 지운다 — 주소 테이블만 가려지면 「주문자·수령인 정보가 가려집니다」라는 안내가 거짓이 된다.
 *
 * 익명화 트랜잭션 안에서 **동기**로 돈다. 여기서 던지면 익명화 표지까지 롤백되어 다음 실행이 그 주문을
 * 다시 잡는다(큐로 미루면 표지만 남고 사본이 남을 수 있다). 송장번호·택배사·PG 응답은 배송 거래
 * 기록이라 남긴다.
 */
class ScrubEscrowRecipientOnOrderAnonymizeListener implements HookListenerInterface
{
    private const PG_PROVIDER_ID = 'kginicis';

    /**
     * `escrow_delivery` 안에서 NULL 로 지우는 수령인 칸.
     *
     * @var array<int, string>
     */
    private const RECIPIENT_KEYS = ['recv_name', 'recv_tel', 'recv_addr'];

    /**
     * @param  EscrowDeliveryRepositoryInterface  $escrowDeliveryRepository  에스크로 배송 등록 기록 저장소
     */
    public function __construct(
        private readonly EscrowDeliveryRepositoryInterface $escrowDeliveryRepository,
    ) {}

    /**
     * 구독할 훅 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.order.after_anonymize' => [
                'method' => 'scrubEscrowRecipient',
                'type' => 'action',
                'priority' => 10,
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 익명화된 주문의 KG 이니시스 결제 행에서 에스크로 배송 등록 수령인 정보를 지웁니다.
     *
     * @param  int  $orderId  익명화된 주문 ID
     * @return void 반환값 없음
     */
    public function scrubEscrowRecipient(int $orderId): void
    {
        $this->escrowDeliveryRepository->scrubDeliveryKeys($orderId, self::PG_PROVIDER_ID, self::RECIPIENT_KEYS);
    }
}
