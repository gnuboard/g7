<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Concerns;

use App\Support\LocalizedValue;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;

trait ResolvesEasyPayDisplay
{
    private const EASY_PAY_METHODS = [
        'nhnkcp_payco' => [
            'provider' => 'payco',
            'label' => ['ko' => 'PAYCO (페이코)', 'en' => 'PAYCO'],
        ],
        'nhnkcp_naverpay' => [
            'provider' => 'naverpay',
            'label' => ['ko' => '네이버페이', 'en' => 'NaverPay'],
        ],
        'nhnkcp_naverpay_point' => [
            'provider' => 'naverpay_point',
            'label' => ['ko' => '네이버페이 포인트결제', 'en' => 'NaverPay Point'],
        ],
        'nhnkcp_kakaopay' => [
            'provider' => 'kakaopay',
            'label' => ['ko' => '카카오페이', 'en' => 'KakaoPay'],
        ],
        'nhnkcp_applepay' => [
            'provider' => 'applepay',
            'label' => ['ko' => '애플페이', 'en' => 'Apple Pay'],
        ],
    ];

    /**
     * @param  array<string, mixed>  $payload  PG 승인 응답·요청 페이로드
     * @return array{method: string, provider: string, label: array{ko: string, en: string}}|array{}
     */
    protected function resolveEasyPayMeta(array $payload): array
    {
        $method = strtolower(trim((string) ($payload['nhnkcp_easy_pay_method'] ?? $payload['param_opt_1'] ?? '')));
        if (! isset(self::EASY_PAY_METHODS[$method])) {
            return [];
        }

        $definition = self::EASY_PAY_METHODS[$method];

        return [
            'method' => $method,
            'provider' => $definition['provider'],
            'label' => $definition['label'],
        ];
    }

    /**
     * @param  mixed  $paymentMeta  결제 메타(배열 또는 JSON 문자열)
     * @return array<string, mixed>
     */
    protected function decodePaymentMeta(mixed $paymentMeta): array
    {
        if (is_array($paymentMeta)) {
            return $paymentMeta;
        }

        if (! is_string($paymentMeta) || trim($paymentMeta) === '') {
            return [];
        }

        $decoded = json_decode($paymentMeta, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  object  $payment  결제 행
     * @return array{
     *     payment_method_label: string|null,
     *     payment_method_display_label: string|null,
     *     selected_payment_method: string|null,
     *     embedded_pg_provider: string|null,
     *     embedded_pg_provider_label: string|null
     * }
     */
    protected function resolvePaymentDisplay(object $payment): array
    {
        $paymentMeta = $this->decodePaymentMeta($payment->payment_meta ?? null);
        $selectedPaymentMethod = $this->stringOrNull($paymentMeta['nhnkcp_easy_pay_method'] ?? null);
        $provider = $this->stringOrNull(
            $payment->embedded_pg_provider
                ?? $paymentMeta['nhnkcp_easy_pay_provider']
                ?? $paymentMeta['embedded_pg_provider']
                ?? null
        );

        if ($provider === null && $selectedPaymentMethod !== null) {
            $provider = $this->stringOrNull(self::EASY_PAY_METHODS[$selectedPaymentMethod]['provider'] ?? null);
        }

        $baseLabel = $this->paymentMethodLabel($this->stringOrNull($payment->payment_method ?? null));
        $embeddedLabel = $this->embeddedPgProviderLabel($provider, $paymentMeta);

        return [
            'payment_method_label' => $baseLabel,
            'payment_method_display_label' => $this->paymentMethodDisplayLabel($baseLabel, $embeddedLabel),
            'selected_payment_method' => $selectedPaymentMethod,
            'embedded_pg_provider' => $provider,
            'embedded_pg_provider_label' => $embeddedLabel,
        ];
    }

    /**
     * 결제수단 코드의 표시 라벨을 반환합니다.
     *
     * @param  string|null  $method  결제수단 코드
     * @return string|null 라벨 (코드가 비면 null)
     */
    protected function paymentMethodLabel(?string $method): ?string
    {
        if ($method === null || $method === '') {
            return null;
        }

        return PaymentMethodEnum::tryFrom($method)?->label() ?? $method;
    }

    /**
     * 간편결제 라벨과 기본 결제수단 라벨을 합친 표시 라벨을 반환합니다.
     *
     * @param  string|null  $baseLabel  기본 결제수단 라벨
     * @param  string|null  $embeddedLabel  간편결제 라벨
     * @return string|null 표시 라벨 (둘 다 비면 null)
     */
    protected function paymentMethodDisplayLabel(?string $baseLabel, ?string $embeddedLabel): ?string
    {
        if ($baseLabel === null || $baseLabel === '') {
            return $embeddedLabel;
        }

        if ($embeddedLabel === null || $embeddedLabel === '') {
            return $baseLabel;
        }

        return $embeddedLabel.' ('.$baseLabel.')';
    }

    /**
     * @param  string|null  $provider  간편결제 제공자 코드
     * @param  array<string, mixed>  $paymentMeta  결제 메타(라벨 저장값 우선)
     * @return string|null 제공자 라벨 (없으면 null)
     */
    protected function embeddedPgProviderLabel(?string $provider, array $paymentMeta = []): ?string
    {
        $metaLabel = $this->localizedLabel($paymentMeta['nhnkcp_easy_pay_label'] ?? null)
            ?? $this->localizedLabel($paymentMeta['embedded_pg_provider_label'] ?? null);

        if ($metaLabel !== null) {
            return $metaLabel;
        }

        if ($provider === null || $provider === '') {
            return null;
        }

        foreach (self::EASY_PAY_METHODS as $definition) {
            if (($definition['provider'] ?? null) === $provider) {
                return $this->localizedLabel($definition['label'] ?? null);
            }
        }

        return null;
    }

    /**
     * 다국어 라벨에서 현재 로케일 → 사이트 기본 언어 → 영어 순으로 값을 고릅니다.
     *
     * @param  mixed  $label  문자열 또는 다국어 배열
     * @return string|null 라벨 (없으면 null)
     */
    private function localizedLabel(mixed $label): ?string
    {
        if (is_string($label) && trim($label) !== '') {
            return $label;
        }

        if (! is_array($label)) {
            return null;
        }

        // 현재 로케일 → 사이트 기본 언어 → 영어 (#148)
        return $this->stringOrNull(LocalizedValue::pick($label, anyAsLast: false));
    }

    /**
     * 공백을 지운 비어 있지 않은 문자열이면 그 값을, 아니면 null 을 반환합니다.
     *
     * @param  mixed  $value  검사할 값
     * @return string|null 문자열 또는 null
     */
    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
