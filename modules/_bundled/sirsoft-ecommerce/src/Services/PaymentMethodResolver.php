<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;

/**
 * 결제수단 능력(capability) 해석기
 *
 * "이 결제수단이 PG 를 쓰는가 / 라벨이 뭔가 / 환불수단이 뭔가" 를 답하는 단일 SSoT.
 *
 * 코어 8종(card/vbank/dbank/bank/phone/point/deposit/free)은 `PaymentMethodEnum` 이
 * 정체성을 갖지만, PG 플러그인이 `filter_available_payment_methods` 훅으로 등록하는
 * 간편결제 수단(예: `nhnkcp_naverpay`)은 enum case 가 없어 enum 메서드로는 능력을
 * 물을 수 없다. 그래서 능력 질의를 **결제수단 설정 카탈로그**(`order_settings.payment_methods`)
 * 기준으로 옮긴다 — 카탈로그는 builtin + 플러그인 등록분을 모두 포함하므로 확장 ID 도
 * 자연스럽게 답이 나온다.
 *
 * 폴백 순서가 핵심이다:
 *
 *   카탈로그 선언 → enum(builtin) → 안전한 기본값
 *
 * 카탈로그에 선언이 없으면 enum 으로, enum 에도 없으면 기본값으로 내려간다. 그래서
 * 플러그인이 신규 키를 선언하지 않아도 기존 동작이 유지되고(하위호환), 선언하면 그
 * 값이 이긴다.
 *
 * 싱글톤으로 등록되어 요청 내 카탈로그 조회를 1회로 캐시한다.
 *
 * @see https://github.com/gnuboard/dev-g7/issues/475
 */
class PaymentMethodResolver
{
    /**
     * 요청 내 결제수단 카탈로그 캐시 (id => config). null = 아직 미조회
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $catalogCache = null;

    /**
     * @param  EcommerceSettingsService  $settingsService  이커머스 설정 서비스
     */
    public function __construct(
        private EcommerceSettingsService $settingsService
    ) {}

    /**
     * PG 결제창이 필요한 결제수단인지 판정합니다.
     *
     * 폴백: 카탈로그 `needs_pg` → enum `needsPgProvider()` → true(확장 수단 기본).
     *
     * 미등록 확장 ID 의 기본값이 true 인 이유: 플러그인이 등록하는 결제수단은
     * 대부분 PG 결제창을 거친다. false 로 오판하면 결제 실패 주문에 관리자 알림이
     * 발송되고 TempOrder 가 삭제되어 재결제가 막히는(이슈 #475) 실패 모드가 된다.
     * 반대 방향의 오판(PG 불필요 수단을 PG 로 봄)은 `determinePgProvider()` 가
     * 'none'/'manual'/'internal' 을 반환해 걸러내므로 안전하다.
     *
     * @param  string  $methodId  결제수단 ID (builtin 8종 또는 확장 ID)
     * @return bool PG 결제창 필요 여부
     */
    public function needsPgProvider(string $methodId): bool
    {
        $declared = $this->catalogValue($methodId, 'needs_pg');

        if ($declared !== null) {
            return (bool) $declared;
        }

        return PaymentMethodEnum::tryFrom($methodId)?->needsPgProvider() ?? true;
    }

    /**
     * 결제수단의 다국어 표시명을 반환합니다.
     *
     * 폴백: 카탈로그 `_cached_name` 의 현재 locale → enum `label()` → raw id.
     *
     * @param  string  $methodId  결제수단 ID
     * @return string 현재 locale 의 표시명
     */
    public function label(string $methodId): string
    {
        $name = $this->catalogValue($methodId, '_cached_name');

        if (is_array($name)) {
            $locale = app()->getLocale();
            $resolved = $name[$locale]
                ?? $name[config('app.fallback_locale', 'ko')]
                ?? (! empty($name) ? array_values($name)[0] : null);

            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        }

        return PaymentMethodEnum::tryFrom($methodId)?->label() ?? $methodId;
    }

    /**
     * 결제수단에 대응하는 환불 수단을 반환합니다.
     *
     * 폴백: 카탈로그 `refund_method` → enum 기반 분류 → BANK(기본).
     *
     * @param  string  $methodId  결제수단 ID
     * @return RefundMethodEnum 환불 수단
     */
    public function refundMethod(string $methodId): RefundMethodEnum
    {
        $declared = $this->catalogValue($methodId, 'refund_method');

        if (is_string($declared) && ($enum = RefundMethodEnum::tryFrom($declared)) !== null) {
            return $enum;
        }

        return match (PaymentMethodEnum::tryFrom($methodId)) {
            PaymentMethodEnum::CARD,
            PaymentMethodEnum::BANK,
            PaymentMethodEnum::VBANK,
            PaymentMethodEnum::PHONE => RefundMethodEnum::PG,
            PaymentMethodEnum::POINT => RefundMethodEnum::POINTS,
            PaymentMethodEnum::DBANK => RefundMethodEnum::BANK,
            // 확장 결제수단(enum case 없음)은 PG 결제 여부로 판정한다.
            // 미선언 확장수단이 BANK 로 떨어지면 카드 취소가 누락된다(안전 이슈).
            default => $this->needsPgProvider($methodId)
                ? RefundMethodEnum::PG
                : RefundMethodEnum::BANK,
        };
    }

    /**
     * 관리자가 PG 를 변경할 수 없는(플러그인이 자기 PG 로 고정한) 결제수단인지 판정합니다.
     *
     * 간편결제 수단(네이버페이 등)은 특정 PG 에 종속되므로 다른 PG 로 바꿀 수 없다.
     * 관리자 주문설정 UI 가 PG 선택 Select 대신 "PG 고정" 배지를 표시하는 근거.
     *
     * @param  string  $methodId  결제수단 ID
     * @return bool PG 고정 여부
     */
    public function isPgLocked(string $methodId): bool
    {
        return (bool) $this->catalogValue($methodId, 'pg_locked');
    }

    /**
     * 코어 builtin 8종 결제수단인지 판정합니다.
     *
     * @param  string  $methodId  결제수단 ID
     * @return bool builtin 여부
     */
    public function isBuiltin(string $methodId): bool
    {
        return PaymentMethodEnum::tryFrom($methodId) !== null;
    }

    /**
     * 검증 화이트리스트 — builtin 8종 + 카탈로그에 등록된 확장 수단 전부.
     *
     * `CreateOrderRequest` / `OrderListRequest` 의 `Rule::in()` 에 사용한다.
     * 확장 ID 를 1급 결제수단으로 받되, 카탈로그에 없는 임의 문자열은 여전히 거부한다.
     *
     * @return array<int, string> 유효한 결제수단 ID 목록
     */
    public function allValidIds(): array
    {
        return array_values(array_unique(array_merge(
            PaymentMethodEnum::values(),
            array_keys($this->catalog())
        )));
    }

    /**
     * 카탈로그에서 특정 결제수단의 특정 키 값을 조회합니다.
     *
     * @param  string  $methodId  결제수단 ID
     * @param  string  $key  조회할 키
     * @return mixed 값 (미선언 시 null)
     */
    private function catalogValue(string $methodId, string $key): mixed
    {
        return $this->catalog()[$methodId][$key] ?? null;
    }

    /**
     * 결제수단 카탈로그를 ID 키 맵으로 반환합니다 (요청 내 1회 캐시).
     *
     * @return array<string, array<string, mixed>> 결제수단 ID => 설정
     */
    private function catalog(): array
    {
        if ($this->catalogCache !== null) {
            return $this->catalogCache;
        }

        $methods = $this->settingsService->getSetting('order_settings.payment_methods') ?? [];

        $keyed = [];
        foreach ($methods as $method) {
            $id = $method['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $keyed[$id] = $method;
            }
        }

        return $this->catalogCache = $keyed;
    }

    /**
     * 카탈로그 캐시를 비웁니다 (설정 저장 직후 재조회 강제).
     */
    public function flushCache(): void
    {
        $this->catalogCache = null;
    }
}
