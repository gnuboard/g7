<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 나이스페이먼츠 간편결제를 이커머스 결제수단 목록에 등록한다.
 *
 * 각 결제수단 ID는 프론트 requestPaymentHandler 의 nicepay_* 매핑과 일치한다.
 */
class RegisterEasyPayMethodsListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nicepayments';

    /**
     * 이 플러그인이 제공하는 PG 식별자 (RegisterPgProviderListener 의 provider id 와 일치).
     */
    private const PG_PROVIDER_ID = 'nicepayments';

    private const EASY_PAY_METHODS = [
        [
            'id' => 'nicepay_naverpay',
            'name_key' => 'sirsoft-pay_nicepayments::payment_methods.naverpay.name',
            'description_key' => 'sirsoft-pay_nicepayments::payment_methods.naverpay.description',
            'icon' => 'wallet',
            'brand_mark' => ['text' => 'N', 'class' => 'bg-green-500 text-white'],
        ],
        [
            'id' => 'nicepay_kakaopay',
            'name_key' => 'sirsoft-pay_nicepayments::payment_methods.kakaopay.name',
            'description_key' => 'sirsoft-pay_nicepayments::payment_methods.kakaopay.description',
            'icon' => 'comment',
            'brand_mark' => ['text' => 'K', 'class' => 'bg-yellow-400 text-gray-950'],
        ],
        [
            'id' => 'nicepay_samsungpay',
            'name_key' => 'sirsoft-pay_nicepayments::payment_methods.samsungpay.name',
            'description_key' => 'sirsoft-pay_nicepayments::payment_methods.samsungpay.description',
            'icon' => 'mobile-screen',
            'brand_mark' => ['text' => 'S', 'class' => 'bg-blue-600 text-white'],
        ],
        [
            'id' => 'nicepay_applepay',
            'name_key' => 'sirsoft-pay_nicepayments::payment_methods.applepay.name',
            'description_key' => 'sirsoft-pay_nicepayments::payment_methods.applepay.description',
            'icon' => 'mobile-screen',
            'brand_mark' => ['text' => 'A', 'class' => 'bg-gray-900 text-white'],
            // 애플페이는 iOS 기기에서만 노출(과거 injector iOS 게이팅 이관).
            'requires_ios' => true,
        ],
        [
            'id' => 'nicepay_payco',
            'name_key' => 'sirsoft-pay_nicepayments::payment_methods.payco.name',
            'description_key' => 'sirsoft-pay_nicepayments::payment_methods.payco.description',
            'icon' => 'wallet',
            'brand_mark' => ['text' => 'P', 'class' => 'bg-red-500 text-white'],
        ],
        [
            'id' => 'nicepay_skpay',
            'name_key' => 'sirsoft-pay_nicepayments::payment_methods.skpay.name',
            'description_key' => 'sirsoft-pay_nicepayments::payment_methods.skpay.description',
            'icon' => 'wallet',
            'brand_mark' => ['text' => '11', 'class' => 'bg-orange-500 text-white'],
        ],
        [
            'id' => 'nicepay_ssgpay',
            'name_key' => 'sirsoft-pay_nicepayments::payment_methods.ssgpay.name',
            'description_key' => 'sirsoft-pay_nicepayments::payment_methods.ssgpay.description',
            'icon' => 'shopping-bag',
            'brand_mark' => ['text' => 'SSG', 'class' => 'bg-red-700 text-white'],
        ],
        [
            'id' => 'nicepay_lpay',
            'name_key' => 'sirsoft-pay_nicepayments::payment_methods.lpay.name',
            'description_key' => 'sirsoft-pay_nicepayments::payment_methods.lpay.description',
            'icon' => 'wallet',
            'brand_mark' => ['text' => 'L', 'class' => 'bg-purple-600 text-white'],
        ],
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.settings.filter_available_payment_methods' => [
                'method' => 'injectEasyPayMethods',
                'type' => 'filter',
                'priority' => 40,
            ],
        ];
    }

    /**
     * 기본 핸들러 — 이 리스너는 필터 훅(`injectEasyPayMethods`)만 쓰므로 하는 일이 없다.
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 없음
     */
    public function handle(...$args): void {}

    /**
     * @param  array<int, array<string, mixed>>  $methods
     * @return array<int, array<string, mixed>>
     */
    public function injectEasyPayMethods(array $methods): array
    {
        $easyPayMethods = array_map(
            fn (array $method): array => $this->buildEntry(
                id: $method['id'],
                nameKey: $method['name_key'],
                descriptionKey: $method['description_key'],
                icon: $method['icon'],
                brandMark: $method['brand_mark'] ?? null,
                requiresIos: $method['requires_ios'] ?? false,
            ),
            self::EASY_PAY_METHODS
        );

        $easyPayIds = array_column($easyPayMethods, 'id');
        $methods = array_values(array_filter(
            $methods,
            fn (array $method): bool => ! in_array($method['id'] ?? null, $easyPayIds, true)
        ));

        $insertAfter = $this->resolveInsertionIndex($methods);
        if ($insertAfter === null) {
            return array_merge($methods, $easyPayMethods);
        }

        return array_merge(
            array_slice($methods, 0, $insertAfter + 1),
            $easyPayMethods,
            array_slice($methods, $insertAfter + 1),
        );
    }

    /**
     * 간편결제 수단을 끼워 넣을 위치를 찾는다 — 휴대폰 결제와 그 뒤에 이어진 PG 수단들의 마지막 자리.
     *
     * @param  array<int, array<string, mixed>>  $methods  결제수단 목록
     * @return int|null 이 인덱스 뒤에 넣는다. 휴대폰 결제가 없으면 null(목록 끝에 붙인다)
     */
    private function resolveInsertionIndex(array $methods): ?int
    {
        $phoneIndex = null;
        foreach ($methods as $index => $method) {
            if (($method['id'] ?? null) === 'phone') {
                $phoneIndex = $index;
                break;
            }
        }

        if ($phoneIndex === null) {
            return null;
        }

        $insertAfter = $phoneIndex;
        for ($index = $phoneIndex + 1, $count = count($methods); $index < $count; $index++) {
            $id = (string) ($methods[$index]['id'] ?? '');
            if (
                ! str_starts_with($id, 'kginicis_')
                && ! str_starts_with($id, 'nhnkcp_')
                && ! str_starts_with($id, 'nicepay_')
            ) {
                break;
            }
            $insertAfter = $index;
        }

        return $insertAfter;
    }

    /**
     * 간편결제 수단 한 건의 결제수단 항목을 만든다.
     * 형식은 코어 EcommerceSettingsService 의 builtin 결제수단 항목과 같다.
     *
     * @param  string  $id  결제수단 ID
     * @param  string  $nameKey  이름 번역 키
     * @param  string  $descriptionKey  설명 번역 키
     * @param  string  $icon  대체 아이콘 이름(Font Awesome — 브랜드 마크가 없을 때 그린다)
     * @param  array{text: string, class: string}|null  $brandMark  배지 브랜드 마크(텍스트+색상 클래스)
     * @param  bool  $requiresIos  iOS 기기에서만 노출되는 수단 여부(애플페이)
     * @return array<string, mixed> 결제수단 항목
     */
    private function buildEntry(
        string $id,
        string $nameKey,
        string $descriptionKey,
        string $icon,
        ?array $brandMark = null,
        bool $requiresIos = false,
    ): array {
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
            'icon' => $icon,
            'source' => 'plugin:'.self::PLUGIN_IDENTIFIER,
            'defaults' => [
                // 간편결제는 나이스페이먼츠 결제창을 통해서만 처리되므로 PG 를 자기 자신으로 고정한다.
                // null 로 두면 코어가 PG 없는 결제수단으로 오인해 결제 실패 주문에 관리자 알림이
                // 발송되고 임시주문이 삭제되어 재결제가 막힌다(#475).
                'pg_provider' => self::PG_PROVIDER_ID,
                'pg_locked' => true,
                'needs_pg' => true,
                'refund_method' => 'pg',
                // 간편결제는 PG 결제취소 API 가 그 자리에서 환불을 집행한다 — 사람의 개입이 없으므로
                // 코어 환불 자동 승인 목록에 기본 포함된다 (코어 P16·P17 능력 키).
                'auto_approve_refund' => true,
                'is_active' => false,
                'min_order_amount' => 0,
                'stock_deduction_timing' => 'payment_complete',
                'mileage_deduction_timing' => 'payment_complete',
            ],
        ];

        // 브랜드 마크(색 배지) — 레이아웃 BrandMark 컴포넌트가 text+class 로 렌더한다.
        // 과거 checkoutEasyPayInjector 가 DOM 후처리로 주입하던 markText/markClassName 이관.
        if ($brandMark !== null) {
            $entry['brand_mark'] = $brandMark;
        }

        // iOS 전용 수단(애플페이)은 비-iOS 기기에서 체크아웃 레이아웃이 렌더하지 않는다.
        if ($requiresIos) {
            $entry['requires_ios'] = true;
        }

        return $entry;
    }
}
