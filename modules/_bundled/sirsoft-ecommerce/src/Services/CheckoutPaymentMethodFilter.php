<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;

/**
 * 결제 통화별 결제수단 제한 (#639 ⑤, 결정 D17)
 *
 * 「이 통화로 결제할 때 고를 수 있는 수단」을 필터 훅
 * `sirsoft-ecommerce.checkout.filter_payment_methods_for_currency(array $methodIds, string $currency): array` 로
 * 확장에게 묻습니다. 카탈로그 훅 `sirsoft-ecommerce.settings.filter_available_payment_methods` 가 「어떤 수단이
 * 존재하는가」라면 이 훅은 「이 통화에서 고를 수 있는가」입니다(예: 엔화 결제는 일부 PG 수단만 받는다).
 *
 * 주문서 표시(체크아웃 응답의 `allowed_payment_methods`)와 주문 생성 검증(`CreateOrderRequest`)이 같은 판정을 씁니다 —
 * 표시만 거르면 직접 POST 로 우회되고, 검증만 하면 고를 수 없는 버튼이 보입니다.
 *
 * 확장은 목록에서 **뺄 수만** 있습니다. 원래 목록에 없던 id 를 돌려줘도 무시합니다.
 */
class CheckoutPaymentMethodFilter
{
    /** 통화별 결제수단 필터 훅 */
    public const HOOK = 'sirsoft-ecommerce.checkout.filter_payment_methods_for_currency';

    /**
     * 결제 통화 해석기를 주입합니다.
     *
     * @param  OrderProcessingService  $orderProcessingService  주문 생성 경로의 결제 통화 해석기
     */
    public function __construct(
        private OrderProcessingService $orderProcessingService,
    ) {}

    /**
     * 주어진 통화에서 고를 수 있는 결제수단만 남깁니다 (입력 순서 보존).
     *
     * @param  array<int, string>  $methodIds  후보 결제수단 id
     * @param  string  $currency  결제 통화 (ISO 4217)
     * @return array<int, string> 허용 결제수단 id
     */
    public function allowedFor(array $methodIds, string $currency): array
    {
        $candidates = array_values(array_unique(array_filter($methodIds, 'is_string')));
        if ($candidates === []) {
            return [];
        }

        $filtered = HookManager::applyFilters(self::HOOK, $candidates, strtoupper($currency));
        if (! is_array($filtered)) {
            return $candidates;
        }

        $kept = array_flip(array_filter($filtered, 'is_string'));

        return array_values(array_filter($candidates, static fn (string $id): bool => isset($kept[$id])));
    }

    /**
     * 한 결제수단이 주어진 통화에서 허용되는지 판정합니다.
     *
     * @param  string  $methodId  결제수단 id
     * @param  string  $currency  결제 통화
     * @return bool 허용 여부
     */
    public function isAllowed(string $methodId, string $currency): bool
    {
        return $this->allowedFor([$methodId], $currency) !== [];
    }

    /**
     * 지금 요청의 결제 통화를 주문 생성과 같은 규칙으로 구합니다.
     *
     * (회원 영속 통화 → `X-Currency` 헤더 → 기본 통화, 등록되지 않은 통화면 기본 통화)
     *
     * @return string 결제 통화
     */
    public function currentCurrency(): string
    {
        return $this->orderProcessingService->resolvePaymentCurrency();
    }
}
