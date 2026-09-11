<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use Plugins\Sirsoft\PayKginicis\Listeners\EnsureAdminOrderListTestBadgeLayoutListener;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;
use Tests\Unit\Support\LayoutMarkup\GateMembershipGuardTest;

/**
 * 주문 목록 테스트 배지 확장점 주입 리스너 테스트.
 *
 * 자매 리스너 테스트 넷은 30차에 `PluginTestCase` 로 전환됐는데 이 파일만 순수
 * `PHPUnit\Framework\TestCase` 로 남아 있었다. 지금은 이 리스너가 코어 진입점을 쓰지 않아 error
 * 가 나지 않지만, 쓰게 되는 순간 `config()` 부재로 30차와 같은 실패가 재발하는 자리다.
 *
 * **31차까지 드러나지 않은 이유**는 {@see GateMembershipGuardTest} 의 상주 모집단이 손으로 쓴
 * 디렉토리 2줄이라, 게이트 필터 `EnsureAdminOrder` 에 걸려 실제로 도는 이 파일이 그 목록 밖에
 * 있었기 때문이다. 모집단을 `phpunit.xml` 도출로 반전하자 즉시 검출됐다.
 */
class EnsureAdminOrderListTestBadgeLayoutListenerTest extends PluginTestCase
{
    public function test_subscribes_to_layout_after_apply_as_filter(): void
    {
        $hooks = EnsureAdminOrderListTestBadgeLayoutListener::getSubscribedHooks();

        $this->assertSame(
            'filter',
            $hooks['core.layout_extension.after_apply']['type'] ?? null
        );
        $this->assertSame(
            'ensureTestBadgeLayout',
            $hooks['core.layout_extension.after_apply']['method'] ?? null
        );
    }

    /**
     * 주문 목록 레이아웃에 테스트모드 데이터소스와 배지를 주입한다 (대상 레이아웃 한정).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects extension_badge_injection_is_idempotent_and_scoped
     */
    public function test_ensures_kginicis_test_mode_data_source_and_badge_on_order_list(): void
    {
        $listener = new EnsureAdminOrderListTestBadgeLayoutListener;

        $layout = $this->makeOrderListLayout([
            [
                'id' => 'nhnkcp_test_map',
                'type' => 'api',
                'endpoint' => '/api/plugins/sirsoft-pay_nhnkcp/admin/orders/test-mode-map',
            ],
        ]);

        $result = $listener->ensureTestBadgeLayout($layout, 1);
        $json = json_encode($result, JSON_UNESCAPED_SLASHES);

        $this->assertSame(1, $this->countDataSources($result, 'kginicis_test_map'));
        $this->assertSame(1, $this->countBadgeConditions($result));
        $this->assertIsString($json);
        $this->assertStringContainsString('/api/plugins/sirsoft-pay_kginicis/admin/orders/test-mode-map', $json);
        $this->assertStringContainsString('kginicis_test_map.data?.[row.order_number] === true', $json);
        $this->assertStringContainsString('sirsoft-pay_kginicis.admin.test_mode_badge', $json);
    }

    public function test_keeps_order_list_badge_injection_idempotent(): void
    {
        $listener = new EnsureAdminOrderListTestBadgeLayoutListener;

        $layout = $this->makeOrderListLayout([]);

        $once = $listener->ensureTestBadgeLayout($layout, 1);
        $twice = $listener->ensureTestBadgeLayout($once, 1);

        $this->assertSame(1, $this->countDataSources($twice, 'kginicis_test_map'));
        $this->assertSame(1, $this->countBadgeConditions($twice));
    }

    public function test_leaves_other_layouts_unchanged(): void
    {
        $listener = new EnsureAdminOrderListTestBadgeLayoutListener;

        $layout = [
            'layout_name' => 'admin_ecommerce_settings',
            'components' => [],
        ];

        $this->assertSame($layout, $listener->ensureTestBadgeLayout($layout, 1));
    }

    /**
     * @param  array<int, array<string, mixed>>  $dataSources
     * @return array<string, mixed>
     */
    private function makeOrderListLayout(array $dataSources): array
    {
        return [
            'layout_name' => 'admin_ecommerce_order_list',
            'data_sources' => $dataSources,
            'components' => [
                [
                    'id' => 'order_datagrid',
                    'type' => 'composite',
                    'name' => 'DataGrid',
                    'props' => [
                        'columns' => [
                            [
                                'field' => 'order_number',
                                'cellChildren' => [
                                    ['type' => 'basic', 'name' => 'Span', 'text' => '{{row.order_number}}'],
                                ],
                            ],
                            [
                                'field' => 'payment_method',
                                'cellChildren' => [
                                    [
                                        'type' => 'basic',
                                        'name' => 'Div',
                                        'props' => ['className' => 'row-stack'],
                                        'children' => [
                                            [
                                                'type' => 'basic',
                                                'name' => 'Span',
                                                'text' => '{{row.payment?.payment_method_label}}',
                                            ],
                                            [
                                                'type' => 'basic',
                                                'name' => 'Span',
                                                'if' => '{{nhnkcp_test_map.data?.[row.order_number] === true}}',
                                                'text' => '($t:sirsoft-pay_nhnkcp.admin.test_mode_badge)',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $layout
     */
    private function countDataSources(array $layout, string $id): int
    {
        return count(array_filter(
            $layout['data_sources'] ?? [],
            static fn ($dataSource): bool => is_array($dataSource) && ($dataSource['id'] ?? null) === $id
        ));
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function countBadgeConditions(array $node): int
    {
        $count = 0;

        foreach ($node as $value) {
            if (is_array($value)) {
                $count += $this->countBadgeConditions($value);

                continue;
            }

            if (is_string($value) && str_contains($value, 'kginicis_test_map.data?.[row.order_number] === true')) {
                $count++;
            }
        }

        return $count;
    }
}
