<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\MessageBizppurio\Services\SmsChannelDriver;

/**
 * 비회원 주문 알림에 수신 전화번호를 실어주는 extract_data 필터 리스너 (D1).
 *
 * 코어 GuestNotifiable 과 이커머스 guest_recipient 표준 키는 email/name/locale 만 담고
 * 전화번호가 없다. 따라서 비회원 SMS/알림톡을 보내려면 각 도메인의 extract_data 훅을
 * 구독해 발송 채널 드라이버가 읽을 수 있는 표준 키(SmsChannelDriver::RECIPIENT_PHONE_KEY)로
 * 전화번호를 data 에 채워야 한다. 이 리스너가 그 역할을 이커머스 주문 알림에 대해 수행한다.
 *
 * 의존 방향: 플러그인 → 이커머스(훅 구독 + 공개 관계 필드 읽기). 이커머스는 우리 전용 키를
 * 모르고, 우리는 이커머스가 이미 GuestOrderAuthService·OrderResource 에서 하듯 배송지의
 * orderer_phone 을 직접 읽는다(이커머스 모듈 무수정). 이커머스 미설치 시 이 훅은 발화하지
 * 않으며, 설령 호출돼도 instanceof Order 게이트에서 무해하게 통과한다.
 * 이커머스 자체 extract_data 리스너(기본 priority 10) 뒤에 실행되도록 priority 20 으로 둔다.
 */
class GuestPhoneExtractListener implements HookListenerInterface
{
    /** 전화번호를 보강할 이커머스 주문 알림 유형 */
    private const ORDER_NOTIFICATION_TYPES = [
        'order_confirmed',
        'order_pending_deposit',
        'order_shipped',
        'order_delivered',
        'order_completed',
        'order_cancelled',
    ];

    /**
     * 구독할 훅 목록 반환.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.notification.extract_data' => [
                'method' => 'injectGuestPhone',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 필터 메서드로 처리).
     *
     * @param  mixed  ...$args
     */
    public function handle(...$args): void {}

    /**
     * 비회원 주문 알림의 data 에 수신 전화번호를 주입합니다.
     *
     * 이커머스 리스너가 만든 extract_data 결과(`{notifiable, notifiables, data, context}`)를
     * 받아, 대상이 주문 알림이고 비회원 주문이면 data 에 전화번호를 덧붙여 반환한다.
     * 그 외에는 원본을 그대로 통과시킨다(회원 주문은 드라이버가 회원 mobile 을 사용).
     *
     * @param  array<string, mixed>  $result  이커머스 extract_data 결과
     * @param  string  $type  알림 정의 유형
     * @param  array<int, mixed>  $args  훅 원본 인수 ([$order, ...])
     * @return array<string, mixed> 전화번호가 보강된(또는 원본) 결과
     */
    public function injectGuestPhone(array $result, string $type, array $args): array
    {
        if (! in_array($type, self::ORDER_NOTIFICATION_TYPES, true)) {
            return $result;
        }

        $order = $args[0] ?? null;
        if (! $order instanceof Order || ! $order->isGuestOrder()) {
            return $result;
        }

        // 주문자 전화번호(배송지 orderer_phone)를 이커머스 자체 관행(GuestOrderAuthService·
        // OrderResource 와 동일)대로 관계 필드에서 직접 읽는다. 이커머스 모듈 무수정.
        $phone = $order->shippingAddress?->orderer_phone;
        if ($phone === null || trim((string) $phone) === '') {
            return $result;
        }

        $result['data'] = array_merge(
            $result['data'] ?? [],
            [SmsChannelDriver::RECIPIENT_PHONE_KEY => $phone],
        );

        return $result;
    }
}
