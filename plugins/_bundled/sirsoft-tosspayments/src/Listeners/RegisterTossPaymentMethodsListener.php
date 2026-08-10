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

    /** RegisterPgProviderListener 가 등록하는 PG 제공자 id 와 동일해야 한다. */
    private const PG_PROVIDER_ID = 'tosspayments';

    /**
     * toss_* id → 아이콘·브랜드 마크.
     *
     * 간편결제 수단은 brand_mark 로 브랜드 색상 배지를 노출한다. 같은 브랜드는 어느 PG 를
     * 통하든 같은 배지로 보여야 하므로 text/class 값을 다른 PG 플러그인과 동일하게 맞춘다
     * (naverpay N/녹색, kakaopay K/노랑, samsungpay S/파랑, payco P/빨강). 미선언 시
     * 이커머스가 `_cached_brand_mark` 를 null 로 채워 회색 기본 아이콘만 표시된다.
     *
     * 카드·가상계좌·계좌이체·휴대폰은 브랜드가 아니라 결제 형태이므로 코어 빌트인 수단과
     * 동일하게 아이콘만 둔다.
     *
     * @var array<string, array{icon:string, brand_mark?:array{text:string, class:string}}>
     */
    private const METHOD_PRESENTATION = [
        'toss_card' => ['icon' => 'credit-card'],
        'toss_virtual_account' => ['icon' => 'building-columns'],
        'toss_transfer' => ['icon' => 'money-bill-transfer'],
        'toss_mobile_phone' => ['icon' => 'mobile-screen-button'],
        'toss_tosspay' => [
            'icon' => 'wallet',
            'brand_mark' => ['text' => 'T', 'class' => 'bg-blue-500 text-white'],
        ],
        'toss_kakaopay' => [
            'icon' => 'wallet',
            'brand_mark' => ['text' => 'K', 'class' => 'bg-yellow-400 text-gray-950'],
        ],
        'toss_naverpay' => [
            'icon' => 'wallet',
            'brand_mark' => ['text' => 'N', 'class' => 'bg-green-500 text-white'],
        ],
        'toss_payco' => [
            'icon' => 'wallet',
            'brand_mark' => ['text' => 'P', 'class' => 'bg-red-500 text-white'],
        ],
        'toss_samsungpay' => [
            'icon' => 'mobile-screen-button',
            'brand_mark' => ['text' => 'S', 'class' => 'bg-blue-600 text-white'],
        ],
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

        $entry = [
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
                // 토스 결제수단은 토스 결제창을 통해서만 처리되므로 PG 를 자기 자신으로 고정한다.
                // null 로 두면 코어가 PG 없는 결제수단으로 오인해 결제 실패 주문에 관리자 알림이
                // 발송되고 임시주문이 삭제되어 재결제가 막힌다(#475). 또한 관리자 화면이 "PG 고정"
                // 배지 대신 선택지가 비어 있는 PG 셀렉트를 그린다 — 토스 PG 는 자기 결제수단 id 를
                // supported_methods 로 선언하지 않으므로 고를 수 있는 항목이 하나도 없다.
                'pg_provider' => self::PG_PROVIDER_ID,
                'pg_locked' => true,
                'needs_pg' => true,
                'refund_method' => 'pg',
                'is_active' => false,
                'min_order_amount' => 0,
                'stock_deduction_timing' => 'payment_complete',
                // 체크아웃이 서버로 보낼 코어 PaymentMethodEnum 값. 코어 enum 은 toss_* 를 거부하므로
                // 프론트는 이 값을 payment_method 로 전송하고 toss_* 는 _local 에만 유지한다.
                'core_payment_method' => self::TOSS_METHOD_MAP[$id]['core'],
            ],
        ];

        // 브랜드 마크는 간편결제 수단에만 있다. 없는 수단에 null 키를 남기지 않는다 —
        // 이커머스 병합부가 `$definition['brand_mark'] ?? null` 로 읽으므로 결과는 같지만,
        // 키 유무가 곧 "브랜드 수단인가" 의 선언이라 다른 PG 플러그인과 형식을 맞춘다.
        if (isset(self::METHOD_PRESENTATION[$id]['brand_mark'])) {
            $entry['brand_mark'] = self::METHOD_PRESENTATION[$id]['brand_mark'];
        }

        return $entry;
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
