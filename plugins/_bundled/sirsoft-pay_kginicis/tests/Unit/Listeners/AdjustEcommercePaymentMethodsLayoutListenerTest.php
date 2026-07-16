<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\PayKginicis\Listeners\AdjustEcommercePaymentMethodsLayoutListener;

/**
 * 이커머스 결제수단 설정 레이아웃 보정 리스너 테스트 — 이슈 #475
 *
 * 과거에는 이 리스너가 코어 레이아웃의 "PG 불필요 결제수단" 하드코딩 배열을 문자열 치환해
 * 간편결제를 그 목록에 끼워 넣었다(= 관리자 화면에 "PG 불필요" 로 표시). 그러나 간편결제는
 * PG 가 불필요한 것이 아니라 특정 PG 에 고정된 수단이며, 이 표시 왜곡은 서버가 간편결제를
 * PG 없는 주문으로 오인하던 결함과 짝을 이뤘다.
 *
 * 이제 결제수단 카탈로그가 pg_locked / needs_pg 를 내려주고 코어 레이아웃이 그 값으로 직접
 * 분기하므로, 이 리스너의 책임은 테스트모드 경고 주입 하나만 남았다.
 */
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
        // 코어 레이아웃은 이제 pg_locked / needs_pg 로 직접 분기한다.
        // 리스너가 표현식을 치환하면 코어의 분기 로직을 훼손하고,
        // 플러그인이 여럿 설치되면 서로의 치환 결과 위에 누적 적용되어 충돌한다.
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
        $this->assertStringNotContainsString('kginicis_naverpay', $json);
        $this->assertStringNotContainsString('kginicis_lpay', $json);
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

        // 멱등성: 두 번 적용해도 경고 노드가 중복되지 않아야 한다.
        $result = $listener->adjustPaymentMethodsLayout($layout, 1);
        $result = $listener->adjustPaymentMethodsLayout($result, 1);
        $json = json_encode($result, JSON_UNESCAPED_SLASHES);

        $this->assertIsString($json);
        $this->assertStringContainsString('kginicis_test_mode_status', $json);
        $this->assertStringContainsString('/api/plugins/sirsoft-pay_kginicis/admin/settings/test-mode-status', $json);
        $this->assertStringContainsString('payment_test_mode_order_settings_notice', $json);
        $this->assertStringContainsString('kginicis_test_mode_order_settings_notice', $json);
        $this->assertStringNotContainsString('sirsoft-pay_kginicis.admin.test_mode_settings_summary_title', $json);
        $this->assertStringNotContainsString('sirsoft-pay_kginicis.admin.test_mode_settings_summary_body', $json);
        $this->assertStringContainsString('sirsoft-pay_kginicis.admin.test_mode_settings_warning_plugin', $json);
        $this->assertStringContainsString('sirsoft-pay_kginicis.admin.test_mode_settings_warning_body', $json);
        $this->assertStringContainsString('/admin/plugins/sirsoft-pay_kginicis/settings', $json);
        $this->assertSame(1, substr_count($json, 'payment_test_mode_order_settings_notice'));
        $this->assertSame(1, substr_count($json, 'kginicis_test_mode_order_settings_notice'));
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
                ['if' => '{{$method.needs_pg}}'],
            ],
        ];

        $this->assertSame($layout, $listener->adjustPaymentMethodsLayout($layout, 1));
    }
}
