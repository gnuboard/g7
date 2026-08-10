<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\Tosspayments\Concerns\MapsTossPaymentMethods;

/**
 * 토스페이먼츠 주문서형 결제수단을 이커머스 결제수단 목록에 동적으로 등록한다.
 *
 * 코어 sirsoft-ecommerce.settings.filter_available_payment_methods 필터 훅을 구독해
 * builtin 결제수단 배열의 'phone' 뒤, 'point' 앞에 활성 토글된 toss_* 결제수단을 삽입한다.
 *
 * order_sheet_mode 가 false 면 아무것도 주입하지 않는다 — 결제창형에서는 기존 card 하나로
 * 통합결제창이 뜬다. 각 entry 의 defaults.pg_provider 는 null(PG 선택 불필요)이며,
 * defaults.core_payment_method 로 체크아웃이 서버에 보낼 코어 PaymentMethodEnum 값을 선언한다.
 */
class RegisterTossPaymentMethodsListener implements HookListenerInterface
{
    use MapsTossPaymentMethods;

    private const PLUGIN_IDENTIFIER = 'sirsoft-tosspayments';

    /**
     * toss_* id → 아이콘·다국어 키.
     *
     * @var array<string, array{icon:string}>
     */
    private const METHOD_PRESENTATION = [
        'toss_card' => ['icon' => 'credit-card'],
        'toss_virtual_account' => ['icon' => 'building-columns'],
        'toss_transfer' => ['icon' => 'money-bill-transfer'],
        'toss_mobile_phone' => ['icon' => 'mobile-screen-button'],
        'toss_tosspay' => ['icon' => 'wallet'],
        'toss_kakaopay' => ['icon' => 'wallet'],
        'toss_naverpay' => ['icon' => 'wallet'],
        'toss_payco' => ['icon' => 'wallet'],
        'toss_samsungpay' => ['icon' => 'mobile-screen-button'],
    ];

    /**
     * 구독할 훅 매핑 반환.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.settings.filter_available_payment_methods' => [
                'method' => 'injectTossMethods',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용).
     *
     * @param  mixed  ...$args
     */
    public function handle(...$args): void {}

    /**
     * 이커머스 결제수단 목록에 토스 주문서형 결제수단 inject.
     *
     * @param  array<int, array<string, mixed>>  $methods  builtin 결제수단 배열
     * @return array<int, array<string, mixed>> toss_* entry 가 phone~point 사이에 삽입된 배열
     */
    public function injectTossMethods(array $methods): array
    {
        $settings = $this->getPluginSettings();

        // order_sheet_mode OFF → 결제창형: 아무것도 주입하지 않고 원본 그대로 반환
        if (! (bool) ($settings['order_sheet_mode'] ?? false)) {
            return $methods;
        }

        $tossMethods = [];
        foreach ($this->enabledTossMethodIds($settings) as $id) {
            $tossMethods[] = $this->buildEntry($id);
        }

        if ($tossMethods === []) {
            return $methods;
        }

        // 'phone' 뒤, 'point' 앞에 삽입. phone 이 없으면 끝에 append (KG 와 동일 규칙).
        $insertAfter = null;
        foreach ($methods as $index => $method) {
            if (($method['id'] ?? null) === 'phone') {
                $insertAfter = $index;
                break;
            }
        }

        if ($insertAfter === null) {
            return array_merge($methods, $tossMethods);
        }

        return array_merge(
            array_slice($methods, 0, $insertAfter + 1),
            $tossMethods,
            array_slice($methods, $insertAfter + 1),
        );
    }

    /**
     * 결제수단 entry 1건 빌더 — EcommerceSettingsService::getBuiltinPaymentMethods 와 동일 형식.
     *
     * @param  string  $id  toss_* 결제수단 id
     * @return array<string, mixed>
     */
    private function buildEntry(string $id): array
    {
        $nameKey = "sirsoft-tosspayments::payment_methods.{$id}.name";
        $descriptionKey = "sirsoft-tosspayments::payment_methods.{$id}.description";

        return [
            'id' => $id,
            'name' => [
                'ko' => __($nameKey, [], 'ko'),
                'en' => __($nameKey, [], 'en'),
            ],
            'description' => [
                'ko' => __($descriptionKey, [], 'ko'),
                'en' => __($descriptionKey, [], 'en'),
            ],
            'icon' => self::METHOD_PRESENTATION[$id]['icon'] ?? 'credit-card',
            'source' => 'plugin:sirsoft-tosspayments',
            'defaults' => [
                // PG 선택 불필요 — payment_handler 정공법으로 토스 결제 흐름이 발화한다.
                'pg_provider' => null,
                'is_active' => false,
                'min_order_amount' => 0,
                'stock_deduction_timing' => 'payment_complete',
                // 체크아웃이 서버로 보낼 코어 PaymentMethodEnum 값. 코어 enum 은 toss_* 를 거부하므로
                // 프론트는 이 값을 payment_method 로 전송하고 toss_* 는 _local 에만 유지한다.
                'core_payment_method' => self::TOSS_METHOD_MAP[$id]['core'],
            ],
        ];
    }

    /**
     * 플러그인 설정 조회.
     *
     * @return array<string, mixed>
     */
    private function getPluginSettings(): array
    {
        if (! \function_exists('plugin_settings')) {
            return [];
        }

        return \plugin_settings(self::PLUGIN_IDENTIFIER);
    }
}
