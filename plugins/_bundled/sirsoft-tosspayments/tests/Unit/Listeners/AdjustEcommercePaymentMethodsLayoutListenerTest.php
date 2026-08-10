<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Listeners;

use Plugins\Sirsoft\Tosspayments\Listeners\AdjustEcommercePaymentMethodsLayoutListener;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * AdjustEcommercePaymentMethodsLayoutListener 단위 테스트.
 *
 * no-PG 결제수단 리스트 리터럴에 toss_* id 를 멱등하게 병합하는지 검증한다.
 *
 * @scenario kg_coexists=true
 *
 * @effects no_pg_list_idempotent_merge_preserves_kg,
 *          toss_layout_listener_runs_after_kginicis
 */
class AdjustEcommercePaymentMethodsLayoutListenerTest extends PluginTestCase
{
    /** KG 플러그인 레이아웃 리스너의 priority (sirsoft-pay_kginicis). */
    private const KGINICIS_PRIORITY = 20;

    /** KG 가 str_replace 대상으로 삼는 정확 리터럴 (닫는 대괄호 포함). */
    private const KGINICIS_ANCHOR = "['point','deposit','free','dbank']";

    private AdjustEcommercePaymentMethodsLayoutListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new AdjustEcommercePaymentMethodsLayoutListener;
    }

    public function test_subscribes_to_layout_after_apply_as_filter(): void
    {
        $hooks = AdjustEcommercePaymentMethodsLayoutListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.layout_extension.after_apply', $hooks);
        $this->assertSame('filter', $hooks['core.layout_extension.after_apply']['type']);
        $this->assertSame('markTossMethodsAsPgNotRequired', $hooks['core.layout_extension.after_apply']['method']);
    }

    public function test_ignores_non_target_layouts(): void
    {
        $layout = [
            'layout_name' => 'some_other_layout',
            'expr' => "{{['point','deposit','free','dbank'].includes(\$method.id)}}",
        ];

        $result = $this->listener->markTossMethodsAsPgNotRequired($layout, 1);

        $this->assertSame($layout, $result);
    }

    public function test_appends_toss_methods_to_no_pg_list(): void
    {
        $layout = [
            'layout_name' => 'admin_ecommerce_settings',
            'expr' => "{{['point','deposit','free','dbank'].includes(\$method.id)}}",
        ];

        $result = $this->listener->markTossMethodsAsPgNotRequired($layout, 1);

        $this->assertStringContainsString("'toss_card'", $result['expr']);
        $this->assertStringContainsString("'toss_virtual_account'", $result['expr']);
        $this->assertStringContainsString("'toss_samsungpay'", $result['expr']);
        // 코어 앵커는 보존
        $this->assertStringContainsString("'point','deposit','free','dbank'", $result['expr']);
    }

    public function test_merge_is_idempotent(): void
    {
        $layout = [
            'layout_name' => 'admin_ecommerce_settings',
            'expr' => "{{['point','deposit','free','dbank'].includes(\$method.id)}}",
        ];

        $once = $this->listener->markTossMethodsAsPgNotRequired($layout, 1);
        $twice = $this->listener->markTossMethodsAsPgNotRequired($once, 1);

        // 두 번 적용해도 toss_card 가 한 번만 등장 (멱등)
        $this->assertSame(1, substr_count($twice['expr'], "'toss_card'"));
        $this->assertSame($once, $twice);
    }

    public function test_preserves_kg_ids_when_both_active(): void
    {
        // KG 가 먼저 실행되어 자기 id 를 이미 추가한 상태
        $layout = [
            'layout_name' => 'admin_ecommerce_settings',
            'expr' => "{{['point','deposit','free','dbank','kginicis_samsung_pay','kginicis_kakaopay'].includes(\$method.id)}}",
        ];

        $result = $this->listener->markTossMethodsAsPgNotRequired($layout, 1);

        // KG id 는 소실되지 않고, toss_* 가 추가된다
        $this->assertStringContainsString("'kginicis_samsung_pay'", $result['expr']);
        $this->assertStringContainsString("'kginicis_kakaopay'", $result['expr']);
        $this->assertStringContainsString("'toss_card'", $result['expr']);
    }

    /**
     * KG 플러그인은 no-PG 리스트를 "닫는 대괄호까지 포함한 통짜 리터럴" 로 str_replace 한다.
     * 토스가 먼저 실행되어 리스트에 toss_* 를 append 하면 KG 의 매치가 실패해 kginicis_*
     * 가 영영 주입되지 않는다. 따라서 토스 priority 는 KG(20) 보다 반드시 커야 하며
     * (HookManager 는 ksort 오름차순), 이 값은 플러그인 로드 순서와 무관하게
     * "KG 먼저" 를 불변식으로 고정한다.
     */
    public function test_priority_runs_after_kginicis_literal_replacement(): void
    {
        $hooks = AdjustEcommercePaymentMethodsLayoutListener::getSubscribedHooks();

        $this->assertGreaterThan(
            self::KGINICIS_PRIORITY,
            $hooks['core.layout_extension.after_apply']['priority'],
            '토스 리스너는 KG(priority 20) 이후에 실행되어야 한다 — 먼저 실행되면 KG 의 통짜 리터럴 치환이 매치 실패한다.'
        );
    }

    /**
     * 토스가 KG 보다 먼저 실행되는 (금지된) 순서를 재현하면 KG 치환이 실패함을 명시적으로 잠근다.
     * 이 테스트가 깨지면 KG 의 치환 방식이 바뀐 것이므로 priority 계약을 재검토해야 한다.
     */
    public function test_toss_first_order_would_break_kginicis_replacement(): void
    {
        $expr = "{{['point','deposit','free','dbank'].includes(\$method.id)}}";

        // 토스가 먼저 실행된 결과
        $afterToss = $this->listener->markTossMethodsAsPgNotRequired(
            ['layout_name' => 'admin_ecommerce_settings', 'expr' => $expr],
            1
        )['expr'];

        // KG 는 이 정확 리터럴(닫는 대괄호 포함)을 찾는다
        $this->assertStringNotContainsString(
            self::KGINICIS_ANCHOR,
            $afterToss,
            '토스 선행 시 KG 앵커가 파괴된다 — priority 로 KG 를 먼저 실행시켜야 하는 근거.'
        );

        // 반대로 KG 선행 순서에서는 토스가 KG id 를 보존한다 (test_preserves_kg_ids_when_both_active 참조)
        $this->assertStringContainsString(self::KGINICIS_ANCHOR, $expr);
    }

    public function test_processes_nested_expressions(): void
    {
        $layout = [
            'layout_name' => 'admin_ecommerce_settings',
            'children' => [
                [
                    'if' => "{{!['point','deposit','free','dbank'].includes(\$method.id)}}",
                ],
            ],
        ];

        $result = $this->listener->markTossMethodsAsPgNotRequired($layout, 1);

        $this->assertStringContainsString("'toss_card'", $result['children'][0]['if']);
    }
}
