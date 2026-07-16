<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Concerns;

use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;

trait ResolvesEasyPayDisplay
{
    private const EASY_PAY_METHODS = [
        'nicepay_naverpay' => [
            'provider' => 'naverpay',
            'label' => ['ko' => '네이버페이', 'en' => 'NaverPay'],
        ],
        'nicepay_kakaopay' => [
            'provider' => 'kakaopay',
            'label' => ['ko' => '카카오페이', 'en' => 'KakaoPay'],
        ],
        'nicepay_samsungpay' => [
            'provider' => 'samsungpay',
            'label' => ['ko' => '삼성페이', 'en' => 'Samsung Pay'],
        ],
        'nicepay_applepay' => [
            'provider' => 'applepay',
            'label' => ['ko' => '애플페이', 'en' => 'Apple Pay'],
        ],
        'nicepay_payco' => [
            'provider' => 'payco',
            'label' => ['ko' => 'PAYCO (페이코)', 'en' => 'PAYCO'],
        ],
        'nicepay_skpay' => [
            'provider' => 'skpay',
            'label' => ['ko' => 'SK pay', 'en' => 'SK pay'],
        ],
        'nicepay_ssgpay' => [
            'provider' => 'ssgpay',
            'label' => ['ko' => 'SSG PAY', 'en' => 'SSG PAY'],
        ],
        'nicepay_lpay' => [
            'provider' => 'lpay',
            'label' => ['ko' => 'L.pay', 'en' => 'L.pay'],
        ],
    ];

    /**
     * @return array{method: string, provider: string, label: array{ko: string, en: string}}|array{}
     */
    protected function resolveEasyPayMeta(array $payload): array
    {
        $method = $this->resolveEasyPayMethod($payload);
        if ($method === null || ! isset(self::EASY_PAY_METHODS[$method])) {
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
        $selectedPaymentMethod = $this->stringOrNull($paymentMeta['nicepay_easy_pay_method'] ?? null);
        $provider = $this->stringOrNull(
            $payment->embedded_pg_provider
                ?? $paymentMeta['nicepay_easy_pay_provider']
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

    protected function paymentMethodLabel(?string $method): ?string
    {
        if ($method === null || $method === '') {
            return null;
        }

        return PaymentMethodEnum::tryFrom($method)?->label() ?? $method;
    }

    protected function paymentMethodDisplayLabel(?string $baseLabel, ?string $embeddedLabel): ?string
    {
        if ($baseLabel === null || $baseLabel === '') {
            return $embeddedLabel;
        }

        if ($embeddedLabel === null || $embeddedLabel === '') {
            return $baseLabel;
        }

        return $embeddedLabel . ' (' . $baseLabel . ')';
    }

    /**
     * @param array<string, mixed> $paymentMeta
     */
    protected function embeddedPgProviderLabel(?string $provider, array $paymentMeta = []): ?string
    {
        $metaLabel = $this->localizedLabel($paymentMeta['nicepay_easy_pay_label'] ?? null)
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
     * @param array<string, mixed> $payload
     */
    private function resolveEasyPayMethod(array $payload): ?string
    {
        foreach (['nicepay_easy_pay_method', 'MallReserved1'] as $key) {
            $method = $this->stringOrNull($payload[$key] ?? null);
            if ($method !== null && isset(self::EASY_PAY_METHODS[$method])) {
                return $method;
            }
        }

        $mallReserved = $this->stringOrNull($payload['MallReserved'] ?? null);
        if ($mallReserved === null) {
            return null;
        }

        $decoded = json_decode($mallReserved, true);
        if (is_array($decoded)) {
            $method = $this->stringOrNull($decoded['nicepay_easy_pay_method'] ?? null);
            if ($method !== null && isset(self::EASY_PAY_METHODS[$method])) {
                return $method;
            }
        }

        parse_str($mallReserved, $parsed);
        $method = $this->stringOrNull($parsed['nicepay_easy_pay_method'] ?? null);
        if ($method !== null && isset(self::EASY_PAY_METHODS[$method])) {
            return $method;
        }

        return isset(self::EASY_PAY_METHODS[$mallReserved]) ? $mallReserved : null;
    }

    private function localizedLabel(mixed $label): ?string
    {
        if (is_string($label) && trim($label) !== '') {
            return $label;
        }

        if (! is_array($label)) {
            return null;
        }

        $locale = app()->getLocale();

        return $this->stringOrNull($label[$locale] ?? null)
            ?? $this->stringOrNull($label['ko'] ?? null)
            ?? $this->stringOrNull($label['en'] ?? null);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
