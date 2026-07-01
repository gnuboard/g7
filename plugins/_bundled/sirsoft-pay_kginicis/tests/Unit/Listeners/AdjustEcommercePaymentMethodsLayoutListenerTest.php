<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\PayKginicis\Listeners\AdjustEcommercePaymentMethodsLayoutListener;

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
            'markEasyPayMethodsAsPgNotRequired',
            $hooks['core.layout_extension.after_apply']['method'] ?? null
        );
    }

    public function test_marks_kginicis_easy_pay_methods_as_pg_not_required_in_admin_ecommerce_settings(): void
    {
        $listener = new AdjustEcommercePaymentMethodsLayoutListener();

        $layout = [
            'layout_name' => 'admin_ecommerce_settings',
            'components' => [
                [
                    'type' => 'basic',
                    'name' => 'Select',
                    'if' => "{{!['point','deposit','free','dbank'].includes(\$method.id) && (_local.form?.available_pg_providers ?? []).length > 0}}",
                ],
                [
                    'type' => 'composite',
                    'name' => 'Toggle',
                    'props' => [
                        'disabled' => "{{_computed.isReadOnly || !['point','deposit','free','dbank'].includes(\$method.id) && !\$method.pg_provider && !_local.form?.order_settings?.default_pg_provider}}",
                    ],
                ],
            ],
        ];

        $result = $listener->markEasyPayMethodsAsPgNotRequired($layout, 1);
        $json = json_encode($result, JSON_UNESCAPED_SLASHES);

        $this->assertIsString($json);
        $this->assertStringContainsString('kginicis_samsung_pay', $json);
        $this->assertStringContainsString('kginicis_naverpay', $json);
        $this->assertStringContainsString('kginicis_lpay', $json);
        $this->assertStringContainsString('kginicis_kakaopay', $json);
        $this->assertStringContainsString('kginicis_japan_paypay', $json);
        $this->assertStringContainsString('kginicis_japan_cvs', $json);
        $this->assertStringNotContainsString("['point','deposit','free','dbank'].includes", $json);
    }

    public function test_leaves_other_layouts_unchanged(): void
    {
        $listener = new AdjustEcommercePaymentMethodsLayoutListener();

        $layout = [
            'layout_name' => 'shop/checkout',
            'components' => [
                [
                    'if' => "{{!['point','deposit','free','dbank'].includes(\$method.id)}}",
                ],
            ],
        ];

        $this->assertSame($layout, $listener->markEasyPayMethodsAsPgNotRequired($layout, 1));
    }
}
