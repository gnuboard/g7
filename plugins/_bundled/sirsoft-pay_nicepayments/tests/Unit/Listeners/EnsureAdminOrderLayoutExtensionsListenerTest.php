<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Unit\Listeners;

use App\Extension\Helpers\LayoutFragmentSourceHelper;
use Plugins\Sirsoft\PayNicepayments\Listeners\EnsureAdminOrderDetailPaymentQueryLayoutListener;
use Plugins\Sirsoft\PayNicepayments\Listeners\EnsureAdminOrderListTestBadgeLayoutListener;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;
use Tests\Support\CompilesFragmentMarkup;
use Tests\Unit\Support\LayoutMarkup\GateMembershipGuardTest;

/**
 * 확장점 주입 리스너 테스트.
 *
 * S18 P1 이 확장점 읽기를 코어 진입점(`config()` 의존)으로 옮기면서 Laravel 부팅이 필요해졌다.
 * 종전 순수 `PHPUnit\Framework\TestCase` 상태로는 error 였고, 종료 게이트 밖이라 30차까지
 * 관측되지 않았다 — 게이트 소속은 이제
 * {@see GateMembershipGuardTest} 가 잠근다.
 */
class EnsureAdminOrderLayoutExtensionsListenerTest extends PluginTestCase
{
    use CompilesFragmentMarkup;

    public function test_list_listener_ensures_test_mode_data_source_and_badge(): void
    {
        $listener = new EnsureAdminOrderListTestBadgeLayoutListener;

        $result = $listener->ensureTestBadgeLayout($this->makeOrderListLayout(), 1);
        $json = json_encode($result, JSON_UNESCAPED_SLASHES);

        $this->assertSame(1, $this->countDataSources($result, 'nicepay_test_map'));
        $this->assertSame(1, $this->countDataSources($result, 'nicepay_easy_pay_display_map'));
        $this->assertSame(1, $this->countString($result, 'nicepay_test_map.data?.[row.order_number] === true'));
        $this->assertSame(1, $this->countString($result, 'nicepay_easy_pay_display_map.data?.[row.order_number]?.payment_method_display_label ?? row.payment?.payment_method_label'));
        $this->assertIsString($json);
        $this->assertStringContainsString('/api/plugins/sirsoft-pay_nicepayments/admin/orders/test-mode-map', $json);
        $this->assertStringContainsString('/api/plugins/sirsoft-pay_nicepayments/admin/orders/easy-pay-display-map', $json);
        $this->assertStringContainsString('sirsoft-pay_nicepayments.admin.test_mode_badge', $json);
    }

    public function test_list_listener_is_idempotent(): void
    {
        $listener = new EnsureAdminOrderListTestBadgeLayoutListener;

        $once = $listener->ensureTestBadgeLayout($this->makeOrderListLayout(), 1);
        $twice = $listener->ensureTestBadgeLayout($once, 1);

        $this->assertSame(1, $this->countDataSources($twice, 'nicepay_test_map'));
        $this->assertSame(1, $this->countDataSources($twice, 'nicepay_easy_pay_display_map'));
        $this->assertSame(1, $this->countString($twice, 'nicepay_test_map.data?.[row.order_number] === true'));
        $this->assertSame(1, $this->countString($twice, 'nicepay_easy_pay_display_map.data?.[row.order_number]?.payment_method_display_label ?? row.payment?.payment_method_label'));
    }

    public function test_detail_listener_ensures_test_notice_and_payment_panels(): void
    {
        $listener = new EnsureAdminOrderDetailPaymentQueryLayoutListener;

        $result = $listener->ensurePaymentQueryLayout($this->makeOrderDetailLayout(), 1);
        $header = $this->findNodeById($result, 'page_header_section');
        $tabContent = $this->findNodeById($result, 'tab_content_area');
        $wrapper = $this->findNodeById($result, 'order_detail_wrapper');
        $json = json_encode($result, JSON_UNESCAPED_SLASHES);

        $this->assertSame(1, $this->countDataSources($result, 'nicepay_status'));
        $this->assertSame(1, $this->countDataSources($result, 'nicepay_escrow'));
        $this->assertIsArray($header);
        $this->assertIsArray($tabContent);
        $this->assertIsArray($wrapper);
        $this->assertSame(1, $this->countNodeId($header, 'nicepay_test_mode_notice'));
        $this->assertSame(1, $this->countNodeId($tabContent, 'nicepay_verify_panel'));
        $this->assertSame(1, $this->countNodeId($tabContent, 'nicepay_escrow_panel'));
        $this->assertSame(1, $this->countNodeId($tabContent, 'nicepay_vbank_notifications_panel'));
        $this->assertSame(0, $this->countDirectChildId($wrapper, 'nicepay_verify_panel'));
        $this->assertIsString($json);
        $this->assertStringContainsString('nicepay_status.data?._is_test_mode === true', $json);
        $this->assertStringContainsString('sirsoft-pay_nicepayments.admin.order_verify_title', $json);
    }

    public function test_detail_listener_uses_wrapper_when_tab_content_area_is_renamed(): void
    {
        $listener = new EnsureAdminOrderDetailPaymentQueryLayoutListener;

        $result = $listener->ensurePaymentQueryLayout($this->makeOrderDetailLayout(includeTabContentArea: false), 1);
        $wrapper = $this->findNodeById($result, 'order_detail_wrapper');

        $this->assertIsArray($wrapper);
        $this->assertSame(1, $this->countDirectChildId($wrapper, 'nicepay_verify_panel'));
    }

    public function test_detail_listener_is_idempotent(): void
    {
        $listener = new EnsureAdminOrderDetailPaymentQueryLayoutListener;

        $once = $listener->ensurePaymentQueryLayout($this->makeOrderDetailLayout(), 1);
        $twice = $listener->ensurePaymentQueryLayout($once, 1);

        $this->assertSame(1, $this->countDataSources($twice, 'nicepay_status'));
        $this->assertSame(1, $this->countNodeId($twice, 'nicepay_test_mode_notice'));
        $this->assertSame(1, $this->countNodeId($twice, 'nicepay_verify_panel'));
        $this->assertSame(1, $this->countNodeId($twice, 'nicepay_escrow_panel'));
        $this->assertSame(1, $this->countNodeId($twice, 'nicepay_vbank_notifications_panel'));
    }

    public function test_leaves_other_layouts_unchanged(): void
    {
        $listListener = new EnsureAdminOrderListTestBadgeLayoutListener;
        $detailListener = new EnsureAdminOrderDetailPaymentQueryLayoutListener;
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
     * @param  array<string|int, mixed>  $node
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
     * @param  array<string|int, mixed>  $node
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
     * 이 리스너가 소비하는 확장점 정의가 **저작본**에서 온다 (S18 P1 테스트 5-b, 31차 신설).
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
            "저작본이 없습니다: {$jsonPath} — 이 단언은 짝이 병존할 때만 소스 선택을 관측한다");

        // 32차 ⚠️ — 아래 값 비교의 red 경로는 "`.json` 이 이기고 그 `.json` 이 컴파일 산출과
        // 다를 때" 하나뿐이라, 짝이 수렴하면 관측력이 0 이 된다. S18 P2 가 짝을 기계로
        // 수렴시키면 전건이 그 상태가 되므로, 값이 아니라 **선택 자체**를 함께 단언한다.
        $this->assertFragmentSourceIsAuthoringMarkup($jsonPath);

        $this->assertSame(
            $this->compileFragmentMarkupDirectly($markupPath),
            LayoutFragmentSourceHelper::read($jsonPath),
            '확장점 정의가 저작본으로 해석되지 않았습니다 — `.g7.html` 만 고치면 이 리스너의 주입이 '
            .'조용히 낡은 `.json` 을 쓴다'
        );
    }
}
