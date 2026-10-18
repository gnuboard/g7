<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Concerns;

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
