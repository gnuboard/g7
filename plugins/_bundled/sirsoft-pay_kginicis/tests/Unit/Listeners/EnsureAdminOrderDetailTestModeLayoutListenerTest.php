<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\PayKginicis\Listeners\EnsureAdminOrderDetailTestModeLayoutListener;

class EnsureAdminOrderDetailTestModeLayoutListenerTest extends TestCase
{
    public function test_subscribes_to_layout_after_apply_as_filter(): void
    {
        $hooks = EnsureAdminOrderDetailTestModeLayoutListener::getSubscribedHooks();

        $this->assertSame(
            'filter',
            $hooks['core.layout_extension.after_apply']['type'] ?? null
        );
        $this->assertSame(
            'ensureTestModeLayout',
            $hooks['core.layout_extension.after_apply']['method'] ?? null
        );
    }

    public function test_ensures_kginicis_payment_query_layout_on_order_detail(): void
    {
        $listener = new EnsureAdminOrderDetailTestModeLayoutListener();

        $result = $listener->ensureTestModeLayout($this->makeOrderDetailLayout(), 1);
        $json = json_encode($result, JSON_UNESCAPED_SLASHES);

        $this->assertSame(1, $this->countDataSources($result, 'kginicis_status'));
        $this->assertSame(1, $this->countDataSources($result, 'kginicis_cbt_reconciliation'));
        $this->assertSame(1, $this->countDataSources($result, 'kginicis_cbt_cvs'));
        $this->assertSame(1, $this->countDataSources($result, 'kginicis_escrow_delivery'));
        $this->assertSame(1, $this->countTestModeNotices($result));
        $this->assertSame(1, $this->countNodeId($result, 'kginicis_verify_panel'));
        $this->assertSame(1, $this->countNodeId($result, 'kginicis_escrow_delivery_panel'));
        $this->assertSame(1, $this->countNodeId($result, 'kginicis_escrow_deny_confirm_panel'));
        $this->assertSame(1, $this->countNodeId($result, 'kginicis_cash_receipt_panel'));
        $this->assertIsString($json);
        $this->assertStringContainsString('/api/plugins/sirsoft-pay_kginicis/admin/orders/{{route.orderNumber}}/transaction-status', $json);
        $this->assertStringContainsString('kginicis_status.data?._is_test_mode === true', $json);
        $this->assertStringContainsString('sirsoft-pay_kginicis.admin.test_mode_warning_badge', $json);
        $this->assertStringContainsString('sirsoft-pay_kginicis.admin.test_mode_order_notice', $json);
        $this->assertStringContainsString('sirsoft-pay_kginicis.admin.order_verify_button', $json);
        $this->assertStringContainsString('_kginicisResult', $json);
        $this->assertStringContainsString('_pay_method_label', $json);

        $header = $this->findNodeById($result, 'page_header_section');
        $tabContent = $this->findNodeById($result, 'tab_content_area');
        $wrapper = $this->findNodeById($result, 'order_detail_wrapper');

        $this->assertIsArray($header);
        $this->assertIsArray($tabContent);
        $this->assertIsArray($wrapper);
        $this->assertSame(1, $this->countNodeId($header, 'kginicis_test_mode_notice'));
        $this->assertSame(1, $this->countNodeId($tabContent, 'kginicis_verify_panel'));
        $this->assertSame(0, $this->countDirectChildId($wrapper, 'kginicis_verify_panel'));
    }

    public function test_keeps_order_detail_payment_query_layout_idempotent(): void
    {
        $listener = new EnsureAdminOrderDetailTestModeLayoutListener();

        $once = $listener->ensureTestModeLayout($this->makeOrderDetailLayout(), 1);
        $twice = $listener->ensureTestModeLayout($once, 1);

        $this->assertSame(1, $this->countDataSources($twice, 'kginicis_status'));
        $this->assertSame(1, $this->countDataSources($twice, 'kginicis_cbt_reconciliation'));
        $this->assertSame(1, $this->countDataSources($twice, 'kginicis_cbt_cvs'));
        $this->assertSame(1, $this->countDataSources($twice, 'kginicis_escrow_delivery'));
        $this->assertSame(1, $this->countTestModeNotices($twice));
        $this->assertSame(1, $this->countNodeId($twice, 'kginicis_verify_panel'));
        $this->assertSame(1, $this->countNodeId($twice, 'kginicis_escrow_delivery_panel'));
        $this->assertSame(1, $this->countNodeId($twice, 'kginicis_escrow_deny_confirm_panel'));
        $this->assertSame(1, $this->countNodeId($twice, 'kginicis_cash_receipt_panel'));
    }

    public function test_uses_order_detail_wrapper_when_tab_content_area_is_renamed(): void
    {
        $listener = new EnsureAdminOrderDetailTestModeLayoutListener();
        $layout = $this->makeOrderDetailLayout(includeTabContentArea: false);

        $result = $listener->ensureTestModeLayout($layout, 1);
        $json = json_encode($result, JSON_UNESCAPED_SLASHES);

        $this->assertSame(1, $this->countNodeId($result, 'kginicis_verify_panel'));
        $this->assertIsString($json);
        $this->assertStringContainsString('sirsoft-pay_kginicis.admin.order_verify_button', $json);

        $wrapper = $this->findNodeById($result, 'order_detail_wrapper');

        $this->assertIsArray($wrapper);
        $this->assertSame(1, $this->countDirectChildId($wrapper, 'kginicis_verify_panel'));
    }

    public function test_leaves_other_layouts_unchanged(): void
    {
        $listener = new EnsureAdminOrderDetailTestModeLayoutListener();

        $layout = [
            'layout_name' => 'admin_ecommerce_order_list',
            'data_sources' => [],
            'slots' => ['content' => []],
        ];

        $this->assertSame($layout, $listener->ensureTestModeLayout($layout, 1));
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
                    [
                        'id' => 'page_header',
                        'type' => 'basic',
                        'name' => 'Div',
                    ],
                ],
            ],
        ];

        if ($includeTabContentArea) {
            $children[] = [
                'id' => 'tab_content_area',
                'type' => 'basic',
                'name' => 'Div',
            ];
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
     * @param array<string, mixed> $node
     */
    private function countTestModeNotices(array $node): int
    {
        return $this->countNodeId($node, 'kginicis_test_mode_notice');
    }

    /**
     * @param array<string, mixed> $node
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
