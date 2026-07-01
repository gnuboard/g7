<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 이커머스 결제수단 설정 화면에서 KG 이니시스 간편결제를 PG 선택 불필요 항목으로 표시한다.
 */
class AdjustEcommercePaymentMethodsLayoutListener implements HookListenerInterface
{
    private const TARGET_LAYOUT = 'admin_ecommerce_settings';

    private const CORE_NO_PG_METHODS = "['point','deposit','free','dbank']";

    private const KGINICIS_NO_PG_METHODS = "['point','deposit','free','dbank','kginicis_samsung_pay','kginicis_naverpay','kginicis_lpay','kginicis_kakaopay','kginicis_japan_paypay','kginicis_japan_cvs']";

    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout_extension.after_apply' => [
                'method' => 'markEasyPayMethodsAsPgNotRequired',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용).
     *
     * @param mixed ...$args
     */
    public function handle(...$args): void {}

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    public function markEasyPayMethodsAsPgNotRequired(array $layout, int $templateId): array
    {
        if (($layout['layout_name'] ?? '') !== self::TARGET_LAYOUT) {
            return $layout;
        }

        return $this->replaceNoPgMethodExpressions($layout);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function replaceNoPgMethodExpressions(array $node): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->replaceNoPgMethodExpressions($value);
                continue;
            }

            if (is_string($value) && str_contains($value, self::CORE_NO_PG_METHODS)) {
                $node[$key] = str_replace(
                    self::CORE_NO_PG_METHODS,
                    self::KGINICIS_NO_PG_METHODS,
                    $value
                );
            }
        }

        return $node;
    }
}
