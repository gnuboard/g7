<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\PayKginicis\Listeners\EnsureAdminOrderListTestBadgeLayoutListener;

class EnsureAdminOrderListTestBadgeLayoutListenerTest extends TestCase
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

    public function test_ensures_kginicis_test_mode_data_source_and_badge_on_order_list(): void
    {
        $listener = new EnsureAdminOrderListTestBadgeLayoutListener();

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
        $listener = new EnsureAdminOrderListTestBadgeLayoutListener();

        $layout = $this->makeOrderListLayout([]);

        $once = $listener->ensureTestBadgeLayout($layout, 1);
        $twice = $listener->ensureTestBadgeLayout($once, 1);

        $this->assertSame(1, $this->countDataSources($twice, 'kginicis_test_map'));
        $this->assertSame(1, $this->countBadgeConditions($twice));
    }

    public function test_leaves_other_layouts_unchanged(): void
    {
        $listener = new EnsureAdminOrderListTestBadgeLayoutListener();

        $layout = [
            'layout_name' => 'admin_ecommerce_settings',
            'components' => [],
        ];

        $this->assertSame($layout, $listener->ensureTestBadgeLayout($layout, 1));
    }

    /**
     * @param array<int, array<string, mixed>> $dataSources
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
     * @param array<string, mixed> $layout
     */
    private function countDataSources(array $layout, string $id): int
    {
        return count(array_filter(
            $layout['data_sources'] ?? [],
            static fn ($dataSource): bool => is_array($dataSource) && ($dataSource['id'] ?? null) === $id
        ));
    }

    /**
     * @param array<string, mixed> $node
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
