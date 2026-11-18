<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Concerns;

use App\Support\LocalizedValue;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentRecord;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;

trait ResolvesEasyPayDisplay
{
    private const EASY_PAY_METHODS = [
        'nicepay_naverpay' => [
            'provider' => 'naverpay',
            'label_key' => 'naverpay',
        ],
        'nicepay_kakaopay' => [
            'provider' => 'kakaopay',
            'label_key' => 'kakaopay',
        ],
        'nicepay_samsungpay' => [
            'provider' => 'samsungpay',
            'label_key' => 'samsungpay',
        ],
        'nicepay_applepay' => [
            'provider' => 'applepay',
            'label_key' => 'applepay',
        ],
        'nicepay_payco' => [
            'provider' => 'payco',
            'label_key' => 'payco',
        ],
        'nicepay_skpay' => [
            'provider' => 'skpay',
            'label_key' => 'skpay',
        ],
        'nicepay_ssgpay' => [
            'provider' => 'ssgpay',
            'label_key' => 'ssgpay',
        ],
        'nicepay_lpay' => [
            'provider' => 'lpay',
            'label_key' => 'lpay',
        ],
    ];

    /**
     * 페이로드가 고른 나이스 간편결제 수단을 결제 meta 에 남길 값으로 해석합니다.
     *
     * 라벨은 지원 언어(활성 언어팩) 전부로 만든다 — 결제 meta 에 그대로 남아 주문 상세·영수증·주문 목록이 화면 언어로 고른다.
     * 이미 저장된 결제 기록의 라벨(종전 ko·en)은 고치지 않는다(데이터 수정 없음).
     *
     * @param  array<string, mixed>  $payload  PG 승인 응답·요청 페이로드
     * @return array{method: string, provider: string, label: array<string, string>}|array{} 간편결제 값 (간편결제가 아니면 빈 배열)
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
            'label' => $this->easyPayLabel($definition),
        ];
    }

    /**
     * 결제 메타를 배열로 풉니다 (JSON 문자열도 받는다).
     *
     * @param  mixed  $paymentMeta  결제 메타(배열 또는 JSON 문자열)
     * @return array<string, mixed> 결제 메타 배열 (풀 수 없으면 빈 배열)
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
     * 이커머스 결제 계약의 결제 행 DTO 로 표시 라벨을 만듭니다.
     *
     * @param  PaymentRecord  $payment  결제 행
     * @return array{
     *     payment_method_label: string|null,
     *     payment_method_display_label: string|null,
     *     selected_payment_method: string|null,
     *     embedded_pg_provider: string|null,
     *     embedded_pg_provider_label: string|null
     * } 결제수단 라벨, 표시 라벨, 고른 간편결제 수단, 간편결제 제공자와 그 라벨
     */
    protected function resolvePaymentDisplay(PaymentRecord $payment): array
    {
        return $this->resolvePaymentDisplayFields($payment->paymentMeta, $payment->embeddedPgProvider, $payment->paymentMethod);
    }

    /**
     * 결제 메타·간편결제 제공자·결제수단 값으로 표시 라벨을 만듭니다.
     *
     * 결제 행 DTO 가 아닌 값(다른 화면이 넘긴 일반 객체 등)을 받는 곳이 쓴다.
     *
     * @param  mixed  $paymentMeta  결제 메타(배열 또는 JSON 문자열)
     * @param  mixed  $embeddedPgProvider  결제 행의 간편결제 제공자 값
     * @param  mixed  $paymentMethod  결제 행의 결제수단 값
     * @return array{
     *     payment_method_label: string|null,
     *     payment_method_display_label: string|null,
     *     selected_payment_method: string|null,
     *     embedded_pg_provider: string|null,
     *     embedded_pg_provider_label: string|null
     * } 결제수단 라벨, 표시 라벨, 고른 간편결제 수단, 간편결제 제공자와 그 라벨
     */
    protected function resolvePaymentDisplayFields(mixed $paymentMeta, mixed $embeddedPgProvider, mixed $paymentMethod): array
    {
        $paymentMeta = $this->decodePaymentMeta($paymentMeta);
        $selectedPaymentMethod = $this->stringOrNull($paymentMeta['nicepay_easy_pay_method'] ?? null);
        $provider = $this->stringOrNull(
            $embeddedPgProvider
                ?? $paymentMeta['nicepay_easy_pay_provider']
                ?? $paymentMeta['embedded_pg_provider']
                ?? null
        );

        if ($provider === null && $selectedPaymentMethod !== null) {
            $provider = $this->stringOrNull(self::EASY_PAY_METHODS[$selectedPaymentMethod]['provider'] ?? null);
        }

        $baseLabel = $this->paymentMethodLabel($this->stringOrNull($paymentMethod));
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
     * 간편결제 제공자의 표시 라벨을 반환합니다 (결제 meta 에 저장한 라벨 우선).
     *
     * @param  string|null  $provider  간편결제 제공자 코드
     * @param  array<string, mixed>  $paymentMeta  결제 메타(라벨 저장값 우선)
     * @return string|null 제공자 라벨 (없으면 null)
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
                return $this->localizedLabel($this->easyPayLabel($definition));
            }
        }

        return null;
    }

    /**
     * 페이로드의 예약 필드에서 나이스 간편결제 수단 코드를 찾습니다.
     *
     * @param  array<string, mixed>  $payload  PG 승인 응답·요청 페이로드
     * @return string|null 간편결제 수단 코드 (없으면 null)
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
     * 간편결제 정의의 표시 라벨을 지원 언어 전부로 만듭니다.
     *
     * @param  array<string, mixed>  $definition  EASY_PAY_METHODS 의 한 항목
     * @return array<string, string> 언어 코드 => 라벨 (`app.supported_locales` 순서)
     */
    private function easyPayLabel(array $definition): array
    {
        $key = 'sirsoft-pay_nicepayments::payment_methods.'.($definition['label_key'] ?? '').'.label';

        return LocalizedValue::translateAll(fn () => __($key));
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
