<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentLookup;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\Tosspayments\Concerns\MapsTossPaymentMethods;

/**
 * 주문 결제수단 표시 라벨을 토스 간편결제 이름으로 채운다.
 *
 * 토스 간편결제 주문은 결제수단 칸이 코어 값 `card` 라 이커머스는 「신용카드」만 안다. 승인 콜백이 결제 결과의
 * 간편결제사 이름(`easyPay.provider` → 결제 행 `embedded_pg_provider`, 예: 「카카오페이」)을 남기므로, 이 리스너가
 * 이커머스 필터 훅으로 「카카오페이 (토스페이먼츠)」 를 채운다 — 관리자 주문 목록(이커머스 1.4.0)과 관리자 주문 상세·
 * 마이페이지 주문 상세(이커머스 1.4.1)가 같은 이름을 보인다.
 *
 * 한 목록에 여러 PG 의 주문이 섞이므로 자기 결제가 아니거나 앞선 구독자가 채운 값은 건드리지 않는다. 카드·가상계좌처럼
 * 간편결제사가 없는 결제는 값을 채우지 않아 화면이 기본 라벨을 쓴다.
 */
class ProvidePaymentMethodDisplayLabelListener implements HookListenerInterface
{
    use MapsTossPaymentMethods;

    /** RegisterPgProviderListener 가 등록하고 결제 행 `pg_provider` 에 남는 PG 식별자 */
    private const PG_PROVIDER_ID = 'tosspayments';

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
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 토스 간편결제 결제의 표시 라벨을 채웁니다.
     *
     * @param  string|null  $label  앞선 구독자가 채운 표시 라벨 (없으면 null)
     * @param  mixed  $payment  주문 결제 레코드 (이커머스 OrderPayment 또는 같은 속성의 객체)
     * @return string|null 표시 라벨 — 토스 간편결제가 아니면 받은 값 그대로
     */
    public function provideDisplayLabel(?string $label, mixed $payment = null): ?string
    {
        if ($label !== null && $label !== '') {
            return $label;
        }

        if (! is_object($payment) || ($payment->pg_provider ?? null) !== self::PG_PROVIDER_ID) {
            return $label;
        }

        // 이커머스 결제 행이면 계약 DTO 로 읽고(훅 인자 Model 은 속성 읽기만), 그 밖의 객체는 같은 이름의 속성을 읽는다.
        $provider = $payment instanceof OrderPayment
            ? app(PaymentLookup::class)->describePayment($payment)->embeddedPgProvider
            : ($payment->embedded_pg_provider ?? null);

        $provider = is_string($provider) ? trim($provider) : '';
        if ($provider === '') {
            return $label;
        }

        $methodId = $this->tossMethodIdForEasyPayProvider($provider);
        if ($methodId !== null) {
            return __("sirsoft-tosspayments::payment_methods.{$methodId}.name");
        }

        // 이 플러그인이 모르는 간편결제사(예: 핀페이)도 원문 이름에 PG 이름을 붙여 보인다 — 「신용카드」로 숨지 않는다.
        return $provider.' ('.__('sirsoft-tosspayments::provider.name').')';
    }
}
