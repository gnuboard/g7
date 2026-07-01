<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\PayKginicis\Listeners\EnsureAdminOrderDetailPaymentQueryLayoutListener;

class EnsureAdminOrderDetailPaymentQueryLayoutListenerTest extends TestCase
{
    public function test_subscribes_to_layout_after_apply_as_filter(): void
    {
        $hooks = EnsureAdminOrderDetailPaymentQueryLayoutListener::getSubscribedHooks();

        $this->assertSame(
            'filter',
            $hooks['core.layout_extension.after_apply']['type'] ?? null
        );
        $this->assertSame(
            'ensurePaymentQueryLayout',
            $hooks['core.layout_extension.after_apply']['method'] ?? null
        );
    }

    public function test_ensures_payment_query_panel_inside_tab_content_area(): void
    {
        $listener = new EnsureAdminOrderDetailPaymentQueryLayoutListener();

        $result = $listener->ensurePaymentQueryLayout($this->makeOrderDetailLayout(), 1);
        $tabContent = $this->findNodeById($result, 'tab_content_area');
        $wrapper = $this->findNodeById($result, 'order_detail_wrapper');
        $json = json_encode($result, JSON_UNESCAPED_SLASHES);

        $this->assertSame(1, $this->countDataSources($result, 'kginicis_status'));
        $this->assertSame(1, $this->countDataSources($result, 'kginicis_cbt_reconciliation'));
        $this->assertSame(1, $this->countDataSources($result, 'kginicis_cbt_cvs'));
        $this->assertSame(1, $this->countDataSources($result, 'kginicis_escrow_delivery'));
        $this->assertIsArray($tabContent);
        $this->assertIsArray($wrapper);
        $this->assertSame(1, $this->countNodeId($tabContent, 'kginicis_verify_panel'));
        $this->assertSame(1, $this->countNodeId($tabContent, 'kginicis_escrow_delivery_panel'));
        $this->assertSame(1, $this->countNodeId($tabContent, 'kginicis_escrow_deny_confirm_panel'));
        $this->assertSame(1, $this->countNodeId($tabContent, 'kginicis_cash_receipt_panel'));
        $this->assertSame(0, $this->countDirectChildId($wrapper, 'kginicis_verify_panel'));
        $this->assertIsString($json);
        $this->assertStringContainsString('sirsoft-pay_kginicis.admin.order_verify_button', $json);
        $this->assertStringContainsString('_kginicisResult', $json);
    }

    public function test_keeps_payment_query_panel_idempotent(): void
    {
        $listener = new EnsureAdminOrderDetailPaymentQueryLayoutListener();

        $once = $listener->ensurePaymentQueryLayout($this->makeOrderDetailLayout(), 1);
        $twice = $listener->ensurePaymentQueryLayout($once, 1);

        $this->assertSame(1, $this->countDataSources($twice, 'kginicis_status'));
        $this->assertSame(1, $this->countNodeId($twice, 'kginicis_verify_panel'));
        $this->assertSame(1, $this->countNodeId($twice, 'kginicis_escrow_delivery_panel'));
        $this->assertSame(1, $this->countNodeId($twice, 'kginicis_escrow_deny_confirm_panel'));
        $this->assertSame(1, $this->countNodeId($twice, 'kginicis_cash_receipt_panel'));
    }

    public function test_uses_order_detail_wrapper_when_tab_content_area_is_renamed(): void
    {
        $listener = new EnsureAdminOrderDetailPaymentQueryLayoutListener();
        $layout = $this->makeOrderDetailLayout(includeTabContentArea: false);

        $result = $listener->ensurePaymentQueryLayout($layout, 1);
        $wrapper = $this->findNodeById($result, 'order_detail_wrapper');

        $this->assertIsArray($wrapper);
        $this->assertSame(1, $this->countDirectChildId($wrapper, 'kginicis_verify_panel'));
    }

    public function test_leaves_other_layouts_unchanged(): void
    {
        $listener = new EnsureAdminOrderDetailPaymentQueryLayoutListener();

        $layout = [
            'layout_name' => 'admin_ecommerce_order_list',
            'data_sources' => [],
            'slots' => ['content' => []],
        ];

        $this->assertSame($layout, $listener->ensurePaymentQueryLayout($layout, 1));
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
                'children' => [],
            ],
        ];

        if ($includeTabContentArea) {
            $children[] = [
                'id' => 'tab_content_area',
                'type' => 'basic',
                'name' => 'Div',
                'children' => [],
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
