<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use App\Extension\Helpers\LayoutFragmentSourceHelper;
use Plugins\Sirsoft\PayKginicis\Listeners\EnsureAdminOrderDetailTestModeLayoutListener;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;
use Tests\Support\CompilesFragmentMarkup;

/**
 * 확장점 주입 리스너 테스트.
 *
 * Laravel 부팅이 필요한 이유와 그것이 30차까지 드러나지 않은 사유는
 * {@see EnsureAdminOrderDetailPaymentQueryLayoutListenerTest} 독블록 참조.
 */
class EnsureAdminOrderDetailTestModeLayoutListenerTest extends PluginTestCase
{
    use CompilesFragmentMarkup;

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
        $listener = new EnsureAdminOrderDetailTestModeLayoutListener;

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
        // 현금영수증 발행은 공용 프로바이더 축으로 이관 — KG 전용 패널은 제거되었다.
        $this->assertSame(0, $this->countNodeId($result, 'kginicis_cash_receipt_panel'));
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
        $listener = new EnsureAdminOrderDetailTestModeLayoutListener;

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
        $this->assertSame(0, $this->countNodeId($twice, 'kginicis_cash_receipt_panel'));
    }

    public function test_uses_order_detail_wrapper_when_tab_content_area_is_renamed(): void
    {
        $listener = new EnsureAdminOrderDetailTestModeLayoutListener;
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
        $listener = new EnsureAdminOrderDetailTestModeLayoutListener;

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
    private function countTestModeNotices(array $node): int
    {
        return $this->countNodeId($node, 'kginicis_test_mode_notice');
    }

    /**
     * @param  array<string, mixed>  $node
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
     * @param  array<string, mixed>  $node
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
     * @param  array<string, mixed>  $node
     */
    private function countDirectChildId(array $node, string $id): int
    {
        return count(array_filter(
            $node['children'] ?? [],
            static fn ($child): bool => is_array($child) && ($child['id'] ?? null) === $id
        ));
    }

    /**
     * 이 리스너가 소비하는 확장점 정의가 **소스**에서 온다 (S18 P1 테스트 5-b, 31차 신설).
     *
     * 종전 이 파일의 단언들은 실 디스크의 짝 병존 확장점을 읽어 노드 id 존재만 확인했다. 그런데
     * 단언하는 id 는 `.g7.html` 과 `.json` 양쪽에 다 있어서, 코어 진입점이 `.json` 우선으로 회귀하든
     * 리스너가 `json_decode` 사본으로 되돌아가든 전건 초록이었다 — 계획서가 "현행 red" 로 적은
     * 소비 축의 실패 모드가 이 축에서는 세워진 적이 없다.
     *
     * 기대값은 소스 선택을 거치지 않는 **직접 컴파일**이다. 양변이 모두 선택 경로를 통과하면
     * 비교가 항등식이 되기 때문이며, 그 형태는 `OracleIndependenceGuardTest` 가 상주 감시한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects consumed_extension_definition_comes_from_the_authoring_source
     */
    public function test_consumed_extension_definition_comes_from_the_authoring_source(): void
    {
        $jsonPath = dirname(__DIR__, 3).'/resources/extensions/admin_order_payment_query.json';
        $markupPath = $this->markupSiblingOf($jsonPath);

        $this->assertNotNull($markupPath,
            "소스가 없습니다: {$jsonPath} — 이 단언은 짝이 병존할 때만 소스 선택을 관측한다");

        // 32차 ⚠️ — 아래 값 비교의 red 경로는 "`.json` 이 이기고 그 `.json` 이 컴파일 산출과
        // 다를 때" 하나뿐이라, 짝이 수렴하면 관측력이 0 이 된다. S18 P2 가 짝을 기계로
        // 수렴시키면 전건이 그 상태가 되므로, 값이 아니라 **선택 자체**를 함께 단언한다.
        $this->assertFragmentSourceIsAuthoringMarkup($jsonPath);

        $this->assertSame(
            $this->compileFragmentMarkupDirectly($markupPath),
            LayoutFragmentSourceHelper::read($jsonPath),
            '확장점 정의가 소스로 해석되지 않았습니다 — `.g7.html` 만 고치면 이 리스너의 주입이 '
            .'조용히 낡은 `.json` 을 쓴다'
        );
    }
}
