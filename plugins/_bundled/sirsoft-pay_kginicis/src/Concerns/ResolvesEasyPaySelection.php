<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use Illuminate\Http\Request;

trait ResolvesEasyPaySelection
{
    private function resolveSelectedEasyPayMethod(Request $request): ?string
    {
        $method = $request->query('selectedPaymentMethod');

        if (! is_string($method) || $method === '') {
            return null;
        }

        return $this->normalizeEasyPayMethod($method);
    }

    private function normalizeEasyPayMethod(?string $method): ?string
    {
        if (! is_string($method) || $method === '') {
            return null;
        }

        return array_key_exists($method, $this->kginicisEasyPayMethodMap())
            ? $method
            : null;
    }

    private function resolveEmbeddedPgProvider(?string $selectedEasyPayMethod): ?string
    {
        if ($selectedEasyPayMethod === null) {
            return null;
        }

        return $this->kginicisEasyPayMethodMap()[$selectedEasyPayMethod]['provider'] ?? null;
    }

    private function buildEasyPayPaymentMeta(?string $selectedEasyPayMethod): array
    {
        if ($selectedEasyPayMethod === null) {
            return [];
        }

        $context = $this->kginicisEasyPayMethodMap()[$selectedEasyPayMethod] ?? null;
        if (! $context) {
            return [];
        }

        return [
            'selected_payment_method' => $selectedEasyPayMethod,
            'embedded_pg_provider' => $context['provider'],
            'embedded_pg_provider_label' => $context['label'],
        ];
    }

    private function buildEasyPayLogContext(?string $selectedEasyPayMethod): array
    {
        return [
            'selected_payment_method' => $selectedEasyPayMethod,
            'embedded_pg_provider' => $this->resolveEmbeddedPgProvider($selectedEasyPayMethod),
        ];
    }

    private function kginicisEasyPayMethodMap(): array
    {
        return [
            'kginicis_samsung_pay' => ['provider' => 'samsungpay', 'label' => '삼성페이'],
            'kginicis_naverpay' => ['provider' => 'naverpay', 'label' => '네이버페이'],
            'kginicis_lpay' => ['provider' => 'lpay', 'label' => 'L.pay'],
            'kginicis_kakaopay' => ['provider' => 'kakaopay', 'label' => '카카오페이'],
        ];
    }
}
