<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\PayNicepayments\Concerns\ResolvesEasyPayDisplay;

/**
 * 관리자 주문 목록의 결제수단 표시 라벨을 나이스페이먼츠 간편결제 원 수단으로 채운다.
 *
 * 이커머스는 PG 를 모르므로 간편결제(PAYCO·네이버페이 등)로 결제된 주문도 기본 결제수단 라벨만 안다.
 * 이 리스너가 이커머스 필터 훅으로 자기 결제의 표시 라벨(「원 수단 (기본 수단)」)을 채운다. 한 목록에
 * 여러 PG 의 주문이 섞이므로 자기 결제가 아니거나 이미 다른 구독자가 채운 값은 건드리지 않는다.
 */
class ProvidePaymentMethodDisplayLabelListener implements HookListenerInterface
{
    use ResolvesEasyPayDisplay;

    /** 이 플러그인이 결제 레코드에 남기는 PG 식별자 */
    private const PG_PROVIDERS = ['nicepayments', 'nicepay'];

    /**
     * 구독할 훅 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.order.filter_payment_method_display_label' => [
                'method' => 'provideDisplayLabel',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인수
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 자기 결제의 간편결제 표시 라벨을 채웁니다.
     *
     * @param  string|null  $label  앞선 구독자가 채운 표시 라벨 (없으면 null)
     * @param  mixed  $payment  주문 결제 레코드 (이커머스 OrderPayment)
     * @return string|null 표시 라벨 — 간편결제가 아니거나 자기 결제가 아니면 받은 값 그대로
     */
    public function provideDisplayLabel(?string $label, mixed $payment = null): ?string
    {
        if ($label !== null && $label !== '') {
            return $label;
        }

        if (! is_object($payment) || ! in_array($payment->pg_provider ?? null, self::PG_PROVIDERS, true)) {
            return $label;
        }

        $display = $this->resolvePaymentDisplay($payment);

        // 간편결제 원 수단을 알아낸 결제만 라벨을 바꾼다 — 일반 카드·계좌이체는 이커머스 기본 라벨이 맞다.
        if (($display['embedded_pg_provider_label'] ?? null) === null) {
            return $label;
        }

        return $display['payment_method_display_label'] ?? $label;
    }
}
