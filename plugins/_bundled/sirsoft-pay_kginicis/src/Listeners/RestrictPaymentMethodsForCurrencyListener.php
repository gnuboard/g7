<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 결제 통화별 KG 이니시스 결제수단 제한 (#639 ⑤, 결정 D17)
 *
 * 쇼핑몰 필터 훅 `sirsoft-ecommerce.checkout.filter_payment_methods_for_currency` 에 답한다. 예전에는 브라우저에서
 * 주문서 응답을 고치고(window.fetch 덮어쓰기) 그려진 버튼을 숨겼다 — 서버는 그 판정을 몰라 직접 POST 하면 그대로
 * 주문이 만들어졌다. 이제 쇼핑몰이 주문서 표시와 주문 생성 검증 양쪽에서 이 판정을 쓴다.
 *
 * - 엔화(JPY): 운영자가 「엔화 결제수단 제한」을 켰으면 이 플러그인 소유 수단 중 `card`·일본 PayPay·편의점 외를 뺀다.
 * - 엔화가 아니면: 일본 전용 수단(PayPay·편의점)은 항상 뺀다.
 * - 판정 대상은 `KNOWN` 목록뿐이다 — 이 플러그인의 간편결제·일본 수단 id 와, KG 이니시스가 결제창을 여는 코어 수단
 *   (`card`·`vbank`·`dbank`·`bank`·`phone`)이다. 그래서 엔화 제한을 켜면 코어 수단 중 `card` 외(예: 다른 PG 가 처리하는
 *   `vbank`)도 빠진다 — 종전 브라우저 제한기와 같은 집합이다. 목록에 없는 다른 플러그인 고유 id(toss_* 등)는 건드리지 않는다.
 */
class RestrictPaymentMethodsForCurrencyListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    /** 엔화에서 허용하는 수단 */
    private const ALLOWED_FOR_JPY = ['card', 'kginicis_japan_paypay', 'kginicis_japan_cvs'];

    /** 일본 전용 수단 (엔화에서만) */
    private const JAPAN_ONLY = ['kginicis_japan_paypay', 'kginicis_japan_cvs'];

    /** 이 플러그인이 판정하는 수단 (KG 이니시스가 결제창을 여는 id) */
    private const KNOWN = [
        'card', 'vbank', 'dbank', 'bank', 'phone',
        'kginicis_samsung_pay', 'kginicis_naverpay', 'kginicis_lpay', 'kginicis_kakaopay',
        'kginicis_japan_paypay', 'kginicis_japan_cvs',
    ];

    /**
     * 구독할 훅 매핑 반환.
     *
     * @return array<string, array<string, mixed>> 훅 정의
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.checkout.filter_payment_methods_for_currency' => [
                'method' => 'filter',
                'priority' => 20,
                'type' => 'filter',
                'sync' => true,
            ],
        ];
    }

    /**
     * 인터페이스 표준 진입점 — 실제 진입점은 filter() 다.
     *
     * @param  mixed  ...$args  사용 안 함
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // no-op
    }

    /**
     * 통화에서 고를 수 없는 이 플러그인의 수단을 뺍니다.
     *
     * @param  mixed  $methodIds  후보 결제수단 id
     * @param  mixed  $currency  결제 통화
     * @return array<int, string> 남길 결제수단 id
     */
    public function filter(mixed $methodIds, mixed $currency = null): array
    {
        $ids = is_array($methodIds) ? array_values(array_filter($methodIds, 'is_string')) : [];
        $isJpy = is_string($currency) && strtoupper($currency) === 'JPY';

        if ($isJpy && ! $this->restrictsJpy()) {
            return $ids;
        }

        return array_values(array_filter($ids, function (string $id) use ($isJpy): bool {
            if (! in_array($id, self::KNOWN, true)) {
                return true;
            }

            return $isJpy
                ? in_array($id, self::ALLOWED_FOR_JPY, true)
                : ! in_array($id, self::JAPAN_ONLY, true);
        }));
    }

    /**
     * 운영자가 엔화 결제수단 제한을 켰는지 반환합니다.
     *
     * @return bool 제한 여부
     */
    private function restrictsJpy(): bool
    {
        return (bool) (plugin_settings(self::PLUGIN_IDENTIFIER)['japan_restrict_jpy_payment_methods'] ?? false);
    }
}
