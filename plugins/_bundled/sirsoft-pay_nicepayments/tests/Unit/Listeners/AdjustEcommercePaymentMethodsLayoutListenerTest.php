<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Unit\Listeners;

use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\PayNicepayments\Listeners\AdjustEcommercePaymentMethodsLayoutListener;

class AdjustEcommercePaymentMethodsLayoutListenerTest extends TestCase
{
    public function test_subscribes_to_layout_after_apply_as_filter(): void
    {
        $hooks = AdjustEcommercePaymentMethodsLayoutListener::getSubscribedHooks();

        $this->assertSame(
            'filter',
            $hooks['core.layout_extension.after_apply']['type'] ?? null
        );
        $this->assertSame(
            'adjustPaymentMethodsLayout',
            $hooks['core.layout_extension.after_apply']['method'] ?? null
        );
    }

    public function test_does_not_string_substitute_pg_branch_expressions(): void
    {
        // 코어 레이아웃은 이제 pg_locked / needs_pg 로 직접 분기한다(#475).
        // 리스너가 표현식을 치환하면 코어의 분기 로직을 훼손하고, 플러그인이 여럿 설치되면
        // 서로의 치환 결과 위에 누적 적용되어 충돌한다 (과거 이 테스트의 fixture 에
        // kginicis / nhnkcp 의 ID 가 이미 섞여 있던 것이 그 증거다).
        $listener = new AdjustEcommercePaymentMethodsLayoutListener;

        $layout = [
            'layout_name' => 'admin_ecommerce_settings',
            'components' => [
                [
                    'type' => 'basic',
                    'name' => 'Select',
                    'if' => '{{!$method.pg_locked && $method.needs_pg && (_local.form?.available_pg_providers ?? []).length > 0}}',
                ],
            ],
        ];

        $result = $listener->adjustPaymentMethodsLayout($layout, 1);
        $json = json_encode($result, JSON_UNESCAPED_SLASHES);

        $this->assertIsString($json);
        // 코어 분기 표현식이 그대로 보존되어야 한다.
        $this->assertStringContainsString('$method.pg_locked', $json);
        $this->assertStringContainsString('$method.needs_pg', $json);
        // 간편결제 ID 를 표현식에 끼워 넣지 않는다.
        $this->assertStringNotContainsString('nicepay_naverpay', $json);
        $this->assertStringNotContainsString('nicepay_kakaopay', $json);
    }

    public function test_adds_test_mode_warning_to_order_settings_tab(): void
    {
        $listener = new AdjustEcommercePaymentMethodsLayoutListener;

        $layout = [
            'layout_name' => 'admin_ecommerce_settings',
            'children' => [
                [
                    'id' => 'tab_content_order_settings',
                    'children' => [
                        ['id' => 'default_pg_card'],
                        ['id' => 'payment_methods_card'],
                    ],
                ],
            ],
        ];

        $result = $listener->adjustPaymentMethodsLayout($layout, 1);
        $result = $listener->adjustPaymentMethodsLayout($result, 1);
        $json = json_encode($result, JSON_UNESCAPED_SLASHES);

        $this->assertIsString($json);
        $this->assertStringContainsString('nicepay_test_mode_status', $json);
        $this->assertStringContainsString('/api/plugins/sirsoft-pay_nicepayments/admin/settings/test-mode-status', $json);
        $this->assertStringContainsString('payment_test_mode_order_settings_notice', $json);
        $this->assertStringContainsString('nicepay_test_mode_order_settings_notice', $json);
        $this->assertStringNotContainsString('sirsoft-pay_nicepayments.admin.test_mode_settings_summary_title', $json);
        $this->assertStringNotContainsString('sirsoft-pay_nicepayments.admin.test_mode_settings_summary_body', $json);
        $this->assertStringContainsString('sirsoft-pay_nicepayments.admin.test_mode_settings_warning_plugin', $json);
        $this->assertStringContainsString('sirsoft-pay_nicepayments.admin.test_mode_settings_warning_body', $json);
        $this->assertStringContainsString('/admin/plugins/sirsoft-pay_nicepayments/settings', $json);
        $this->assertSame(1, substr_count($json, 'payment_test_mode_order_settings_notice'));
        $this->assertSame(1, substr_count($json, 'nicepay_test_mode_order_settings_notice'));
        $this->assertLessThan(
            strpos($json, 'payment_methods_card'),
            strpos($json, 'payment_test_mode_order_settings_notice')
        );
    }

    public function test_leaves_other_layouts_unchanged(): void
    {
        $listener = new AdjustEcommercePaymentMethodsLayoutListener;

        $layout = [
            'layout_name' => 'shop/checkout',
            'components' => [
                [
                    'if' => "{{!['point','deposit','free','dbank'].includes(\$method.id)}}",
                ],
            ],
        ];

        $this->assertSame($layout, $listener->adjustPaymentMethodsLayout($layout, 1));
    }
}
