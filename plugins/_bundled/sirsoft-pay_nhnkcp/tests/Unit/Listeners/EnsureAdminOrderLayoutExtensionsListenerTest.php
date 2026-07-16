<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Listeners;

use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\PayNhnkcp\Listeners\EnsureAdminOrderDetailPaymentQueryLayoutListener;
use Plugins\Sirsoft\PayNhnkcp\Listeners\EnsureAdminOrderListTestBadgeLayoutListener;

class EnsureAdminOrderLayoutExtensionsListenerTest extends TestCase
{
    public function test_list_listener_ensures_test_mode_data_source_and_badge(): void
    {
        $listener = new EnsureAdminOrderListTestBadgeLayoutListener();

        $result = $listener->ensureTestBadgeLayout($this->makeOrderListLayout(), 1);
        $json = json_encode($result, JSON_UNESCAPED_SLASHES);

        $this->assertSame(1, $this->countDataSources($result, 'nhnkcp_test_map'));
        $this->assertSame(1, $this->countDataSources($result, 'nhnkcp_easy_pay_display_map'));
        $this->assertSame(1, $this->countString($result, 'nhnkcp_test_map.data?.[row.order_number] === true'));
        $this->assertSame(1, $this->countString($result, 'nhnkcp_easy_pay_display_map.data?.[row.order_number]?.payment_method_display_label ?? row.payment?.payment_method_label'));
        $this->assertIsString($json);
        $this->assertStringContainsString('/api/plugins/sirsoft-pay_nhnkcp/admin/orders/test-mode-map', $json);
        $this->assertStringContainsString('/api/plugins/sirsoft-pay_nhnkcp/admin/orders/easy-pay-display-map', $json);
        $this->assertStringContainsString('sirsoft-pay_nhnkcp.admin.test_mode_badge', $json);
    }

    public function test_list_listener_is_idempotent(): void
    {
        $listener = new EnsureAdminOrderListTestBadgeLayoutListener();

        $once = $listener->ensureTestBadgeLayout($this->makeOrderListLayout(), 1);
        $twice = $listener->ensureTestBadgeLayout($once, 1);

        $this->assertSame(1, $this->countDataSources($twice, 'nhnkcp_test_map'));
        $this->assertSame(1, $this->countDataSources($twice, 'nhnkcp_easy_pay_display_map'));
        $this->assertSame(1, $this->countString($twice, 'nhnkcp_test_map.data?.[row.order_number] === true'));
        $this->assertSame(1, $this->countString($twice, 'nhnkcp_easy_pay_display_map.data?.[row.order_number]?.payment_method_display_label ?? row.payment?.payment_method_label'));
    }

    public function test_detail_listener_ensures_test_notice_and_payment_panels(): void
    {
        $listener = new EnsureAdminOrderDetailPaymentQueryLayoutListener();

        $result = $listener->ensurePaymentQueryLayout($this->makeOrderDetailLayout(), 1);
        $header = $this->findNodeById($result, 'page_header_section');
        $tabContent = $this->findNodeById($result, 'tab_content_area');
        $wrapper = $this->findNodeById($result, 'order_detail_wrapper');
        $json = json_encode($result, JSON_UNESCAPED_SLASHES);

        $this->assertSame(1, $this->countDataSources($result, 'kcp_status'));
        $this->assertSame(1, $this->countDataSources($result, 'kcp_escrow_delivery'));
        $this->assertIsArray($header);
        $this->assertIsArray($tabContent);
        $this->assertIsArray($wrapper);
        $this->assertSame(1, $this->countNodeId($header, 'nhnkcp_test_mode_notice'));
        $this->assertSame(1, $this->countNodeId($tabContent, 'kcp_info_panel'));
        $this->assertSame(1, $this->countNodeId($tabContent, 'kcp_escrow_delivery_panel'));
        $this->assertSame(0, $this->countDirectChildId($wrapper, 'kcp_info_panel'));
        $this->assertIsString($json);
        $this->assertStringContainsString('kcp_status.data?._is_test_mode === true', $json);
        $this->assertStringContainsString('sirsoft-pay_nhnkcp.admin.order_info_title', $json);
        $this->assertStringContainsString("kcp_status.data?.payment_status === 'cancelled'", $json);
        $this->assertStringContainsString("kcp_status.data?.refund_status === 'completed'", $json);
        $this->assertStringContainsString('sirsoft-pay_nhnkcp.admin.pay_status_cancel_completed', $json);
        $this->assertStringContainsString('sirsoft-pay_nhnkcp.admin.result_payment_status', $json);
        $this->assertStringContainsString('sirsoft-pay_nhnkcp.admin.result_cancelled_amount', $json);
        $this->assertStringContainsString('sirsoft-pay_nhnkcp.admin.result_refund_status', $json);
    }

    public function test_detail_listener_uses_wrapper_when_tab_content_area_is_renamed(): void
    {
        $listener = new EnsureAdminOrderDetailPaymentQueryLayoutListener();

        $result = $listener->ensurePaymentQueryLayout($this->makeOrderDetailLayout(includeTabContentArea: false), 1);
        $wrapper = $this->findNodeById($result, 'order_detail_wrapper');

        $this->assertIsArray($wrapper);
        $this->assertSame(1, $this->countDirectChildId($wrapper, 'kcp_info_panel'));
    }

    public function test_detail_listener_is_idempotent(): void
    {
        $listener = new EnsureAdminOrderDetailPaymentQueryLayoutListener();

        $once = $listener->ensurePaymentQueryLayout($this->makeOrderDetailLayout(), 1);
        $twice = $listener->ensurePaymentQueryLayout($once, 1);

        $this->assertSame(1, $this->countDataSources($twice, 'kcp_status'));
        $this->assertSame(1, $this->countNodeId($twice, 'nhnkcp_test_mode_notice'));
        $this->assertSame(1, $this->countNodeId($twice, 'kcp_info_panel'));
        $this->assertSame(1, $this->countNodeId($twice, 'kcp_escrow_delivery_panel'));
    }

    public function test_leaves_other_layouts_unchanged(): void
    {
        $listListener = new EnsureAdminOrderListTestBadgeLayoutListener();
        $detailListener = new EnsureAdminOrderDetailPaymentQueryLayoutListener();
        $layout = [
            'layout_name' => 'shop/checkout',
            'data_sources' => [],
            'components' => [],
        ];

        $this->assertSame($layout, $listListener->ensureTestBadgeLayout($layout, 1));
        $this->assertSame($layout, $detailListener->ensurePaymentQueryLayout($layout, 1));
    }

    /**
     * @return array<string, mixed>
     */
    private function makeOrderListLayout(): array
    {
        return [
            'layout_name' => 'admin_ecommerce_order_list',
            'data_sources' => [],
            'components' => [
                [
                    'id' => 'order_datagrid',
                    'type' => 'composite',
                    'name' => 'DataGrid',
                    'props' => [
                        'columns' => [
                            ['field' => 'order_number', 'cellChildren' => []],
                            [
                                'field' => 'payment_method',
                                'cellChildren' => [
                                    [
                                        'type' => 'basic',
                                        'name' => 'Div',
                                        'children' => [
                                            ['type' => 'basic', 'name' => 'Span', 'text' => '{{row.payment?.payment_method_label}}'],
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
     * @return array<string, mixed>
     */
    private function makeOrderDetailLayout(bool $includeTabContentArea = true): array
    {
        $children = [
            [
                'id' => 'page_header_section',
                'type' => 'basic',
                'name' => 'Div',
                'children' => [
                    ['id' => 'page_header', 'type' => 'basic', 'name' => 'Div'],
                ],
            ],
        ];

        if ($includeTabContentArea) {
            $children[] = ['id' => 'tab_content_area', 'type' => 'basic', 'name' => 'Div', 'children' => []];
        }

        return [
            'layout_name' => 'admin_ecommerce_order_detail',
            'data_sources' => [],
            'slots' => [
                'content' => [
                    [
                        'id' => 'order_detail_wrapper',
                        'type' => 'basic',
                        'name' => 'Div',
                        'children' => $children,
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
     * @param array<string|int, mixed> $node
     */
    private function countString(array $node, string $needle): int
    {
        $count = 0;

        foreach ($node as $value) {
            if (is_array($value)) {
                $count += $this->countString($value, $needle);
                continue;
            }

            if (is_string($value) && str_contains($value, $needle)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string|int, mixed> $node
     */
    private function countNodeId(array $node, string $id): int
    {
        $count = 0;

        foreach ($node as $value) {
            if (is_array($value)) {
                $count += $this->countNodeId($value, $id);
                continue;
            }

            if ($value === $id) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function findNodeById(array $node, string $id): ?array
    {
        if (($node['id'] ?? null) === $id) {
            return $node;
        }

        foreach ($node as $value) {
            if (! is_array($value)) {
                continue;
            }

            $found = $this->findNodeById($value, $id);

            if (is_array($found)) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function countDirectChildId(array $node, string $id): int
    {
        return count(array_filter(
            $node['children'] ?? [],
            static fn ($child): bool => is_array($child) && ($child['id'] ?? null) === $id
        ));
    }
}
