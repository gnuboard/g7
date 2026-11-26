<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Concerns;

/**
 * 토스 주문서형 결제수단(toss_*) ↔ SDK method / easyPay provider / 코어 결제수단 매핑 SSoT.
 *
 * 두 리스너가 공유한다:
 *  - RegisterTossPaymentMethodsListener: 활성 토글된 수단만 이커머스 결제수단 목록에 entry 로 주입
 *  - RegisterPgProviderListener::getClientConfig: enabled_methods 를 프론트 SDK 설정으로 내림
 *
 * 관리자 주문설정 화면의 PG 표시는 결제수단 카탈로그의 `pg_locked` / `needs_pg` 선언을 코어
 * 레이아웃이 직접 읽어 3분기(PG 고정 배지 / PG 선택 / PG 불필요)하므로, 과거처럼 레이아웃
 * 표현식의 no-PG 리스트 리터럴을 정규식으로 재작성하는 리스너는 두지 않는다.
 *
 * core 값은 체크아웃이 서버로 보낼 코어 PaymentMethodEnum 값이다. toss_* id 는 코어 enum 이
 * 거부하므로, 프론트는 이 core 값을 payment_method 로 전송하고 toss_* 선택값은 _local 에만
 * 유지해 SDK 호출 시 참조한다 (프론트 하드코딩 금지).
 */
trait MapsTossPaymentMethods
{
    /**
     * toss_* entry id → { setting, method, easy_pay, core }.
     *
     * @var array<string, array{setting:string, method:string, easy_pay:?string, core:string}>
     */
    private const TOSS_METHOD_MAP = [
        'toss_card' => ['setting' => 'method_card', 'method' => 'CARD', 'easy_pay' => null, 'core' => 'card'],
        'toss_virtual_account' => ['setting' => 'method_virtual_account', 'method' => 'VIRTUAL_ACCOUNT', 'easy_pay' => null, 'core' => 'vbank'],
        'toss_transfer' => ['setting' => 'method_transfer', 'method' => 'TRANSFER', 'easy_pay' => null, 'core' => 'bank'],
        'toss_mobile_phone' => ['setting' => 'method_mobile_phone', 'method' => 'MOBILE_PHONE', 'easy_pay' => null, 'core' => 'phone'],
        'toss_tosspay' => ['setting' => 'method_tosspay', 'method' => 'CARD', 'easy_pay' => '토스페이', 'core' => 'card'],
        'toss_kakaopay' => ['setting' => 'method_kakaopay', 'method' => 'CARD', 'easy_pay' => '카카오페이', 'core' => 'card'],
        'toss_naverpay' => ['setting' => 'method_naverpay', 'method' => 'CARD', 'easy_pay' => '네이버페이', 'core' => 'card'],
        'toss_payco' => ['setting' => 'method_payco', 'method' => 'CARD', 'easy_pay' => '페이코', 'core' => 'card'],
        'toss_samsungpay' => ['setting' => 'method_samsungpay', 'method' => 'CARD', 'easy_pay' => '삼성페이', 'core' => 'card'],
        // 토스 공식 간편결제사 코드(한글) — 「L.pay」 는 화면 표기일 뿐 SDK 코드는 「엘페이」다
        'toss_applepay' => ['setting' => 'method_applepay', 'method' => 'CARD', 'easy_pay' => '애플페이', 'core' => 'card'],
        'toss_lpay' => ['setting' => 'method_lpay', 'method' => 'CARD', 'easy_pay' => '엘페이', 'core' => 'card'],
        'toss_ssgpay' => ['setting' => 'method_ssgpay', 'method' => 'CARD', 'easy_pay' => 'SSG페이', 'core' => 'card'],
    ];

    /**
     * 승인 응답 `easyPay.provider` 의 옛 표기 → toss_* id.
     *
     * 토스는 토스페이를 과거 「토스결제」 로 내려줬다 — 그 시기 결제 행도 같은 이름으로 보여야 한다.
     *
     * @var array<string, string>
     */
    private const TOSS_EASY_PAY_ALIASES = [
        '토스결제' => 'toss_tosspay',
    ];

    /**
     * 결제 결과의 간편결제사 이름(`easyPay.provider`)으로 toss_* 결제수단 id 를 찾습니다.
     *
     * @param  string  $provider  간편결제사 이름 (예: 「카카오페이」)
     * @return string|null toss_* id (모르는 간편결제사면 null)
     */
    protected function tossMethodIdForEasyPayProvider(string $provider): ?string
    {
        $provider = trim($provider);
        if ($provider === '') {
            return null;
        }

        foreach (self::TOSS_METHOD_MAP as $id => $meta) {
            if ($meta['easy_pay'] === $provider) {
                return $id;
            }
        }

        return self::TOSS_EASY_PAY_ALIASES[$provider] ?? null;
    }

    /**
     * 등록 가능한 전체 toss_* 결제수단 id 목록을 선언 순서대로 반환합니다 (활성 토글 무관).
     *
     * @return list<string> 전체 toss_* id 목록
     */
    protected function allTossMethodIds(): array
    {
        return array_keys(self::TOSS_METHOD_MAP);
    }

    /**
     * 플러그인 설정에서 활성 토글된 toss_* 결제수단 id 목록을 선언 순서대로 반환합니다.
     *
     * @param  array<string, mixed>  $settings  플러그인 설정값
     * @return list<string> 활성 toss_* id 목록
     */
    protected function enabledTossMethodIds(array $settings): array
    {
        $enabled = [];
        foreach (self::TOSS_METHOD_MAP as $id => $meta) {
            if ((bool) ($settings[$meta['setting']] ?? false)) {
                $enabled[] = $id;
            }
        }

        return $enabled;
    }
}
