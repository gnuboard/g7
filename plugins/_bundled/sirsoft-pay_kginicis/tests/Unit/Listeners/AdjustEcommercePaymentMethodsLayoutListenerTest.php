<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use Plugins\Sirsoft\PayKginicis\Listeners\AdjustEcommercePaymentMethodsLayoutListener;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

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
class AdjustEcommercePaymentMethodsLayoutListenerTest extends PluginTestCase
{
    /**
     * 레이아웃 확장 적용 뒤 필터 훅을 구독한다.
     *
     * @scenario case=subscription
     *
     * @effects subscribes_layout_after_apply_filter
     *
     * @return void 반환값 없음
     */
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

    /**
     * 코어 결제수단 분기 표현식을 문자열로 바꾸지 않는다.
     *
     * @scenario case=pg_branch
     *
     * @effects core_pg_branch_expressions_preserved
     *
     * @return void 반환값 없음
     */
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

    /**
     * 주문 설정 탭의 결제수단 카드 앞에 테스트 모드 경고를 한 번만 넣는다(두 번 적용해도 중복 없음).
     *
     * @scenario case=test_mode_notice
     *
     * @effects test_mode_notice_injected_once
     *
     * @return void 반환값 없음
     */
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

    /**
     * 다른 레이아웃은 그대로 둔다.
     *
     * @scenario case=other_layout
     *
     * @effects other_layouts_unchanged
     *
     * @return void 반환값 없음
     */
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

    /**
     * 주입한 테스트모드 경고는 폭 분기를 엔진 responsive 로 한다 — Tailwind 폭 접두(`paykginicis:sm:` 등) 금지(#869).
     *
     * 모집단은 주입된 경고 노드 아래 모든 className(responsive 구간 포함)이다.
     *
     * @scenario case=test_mode_notice_width
     *
     * @effects test_mode_notice_width_branch_uses_engine_responsive
     *
     * @return void 반환값 없음
     */
    public function test_injected_test_mode_notice_uses_engine_responsive_instead_of_tailwind_breakpoints(): void
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
        $notice = $this->findNodeById($result, 'payment_test_mode_order_settings_notice');
        $this->assertIsArray($notice);

        $classNames = [];
        $this->collectClassNames($notice, 'notice', $classNames);
        $this->assertNotEmpty($classNames);
        foreach ($classNames as $where => $className) {
            $this->assertDoesNotMatchRegularExpression('/(^|\s)(?:[a-z0-9]+:)?(?:sm|md|lg|xl|2xl):/', $className, "Tailwind 폭 접두: {$where}");
        }

        $row = $this->findNodeById($notice, 'kginicis_test_mode_order_settings_notice');
        $this->assertIsArray($row);
        $this->assertStringContainsString('paykginicis:flex-col', $row['props']['className']);
        $rowWide = $row['responsive']['640-']['props']['className'] ?? '';
        $this->assertStringContainsString('paykginicis:flex-row', $rowWide);
        $this->assertStringContainsString('paykginicis:items-center', $rowWide);
        $this->assertStringContainsString('paykginicis:justify-between', $rowWide);
        $this->assertStringNotContainsString('paykginicis:flex-col', $rowWide);

        $button = $this->findNodeByName($row, 'Button');
        $this->assertIsArray($button);
        $this->assertStringContainsString('paykginicis:w-full', $button['props']['className']);
        $buttonWide = $button['responsive']['640-']['props']['className'] ?? '';
        $this->assertStringContainsString('paykginicis:w-auto', $buttonWide);
        $this->assertStringNotContainsString('paykginicis:w-full', $buttonWide);
        $this->assertStringContainsString('pay-kginicis-btn-sm', $buttonWide);
    }

    /**
     * id 로 노드를 찾습니다.
     *
     * @param  array<mixed>  $node  탐색 시작 노드
     * @param  string  $id  찾을 id
     * @return array<string, mixed>|null 찾은 노드
     */
    private function findNodeById(array $node, string $id): ?array
    {
        if (($node['id'] ?? null) === $id) {
            return $node;
        }
        foreach ($node as $value) {
            if (is_array($value) && ($found = $this->findNodeById($value, $id)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 컴포넌트 이름으로 첫 노드를 찾습니다.
     *
     * @param  array<mixed>  $node  탐색 시작 노드
     * @param  string  $name  컴포넌트 이름
     * @return array<string, mixed>|null 찾은 노드
     */
    private function findNodeByName(array $node, string $name): ?array
    {
        if (($node['name'] ?? null) === $name) {
            return $node;
        }
        foreach ($node as $value) {
            if (is_array($value) && ($found = $this->findNodeByName($value, $name)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 노드 아래 모든 className 을 경로와 함께 모읍니다.
     *
     * @param  array<mixed>  $node  노드
     * @param  string  $path  현재 경로
     * @param  array<string, string>  $out  결과
     * @return void 반환값 없음
     */
    private function collectClassNames(array $node, string $path, array &$out): void
    {
        foreach ($node as $key => $value) {
            if ($key === 'className' && is_string($value)) {
                $out[$path.'.className'] = $value;
            } elseif (is_array($value)) {
                $this->collectClassNames($value, $path.'.'.$key, $out);
            }
        }
    }
}
