<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Unit\Listeners;

use App\Extension\Helpers\LayoutFragmentSourceHelper;
use Plugins\Sirsoft\PayNicepayments\Listeners\EnsureAdminOrderDetailPaymentQueryLayoutListener;
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

    /**
     * 주문 상세 리스너가 테스트 결제 안내와 결제 조회 패널·데이터 소스를 한 번씩 채우는지 검증한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects extension_badge_injection_is_idempotent_and_scoped
     *
     * @return void 없음
     */
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

    /**
     * 탭 본문 영역 id 가 바뀐 레이아웃에서는 상세 래퍼 바로 아래에 패널을 붙이는지 검증한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects extension_badge_injection_is_idempotent_and_scoped
     *
     * @return void 없음
     */
    public function test_detail_listener_uses_wrapper_when_tab_content_area_is_renamed(): void
    {
        $listener = new EnsureAdminOrderDetailPaymentQueryLayoutListener;

        $result = $listener->ensurePaymentQueryLayout($this->makeOrderDetailLayout(includeTabContentArea: false), 1);
        $wrapper = $this->findNodeById($result, 'order_detail_wrapper');

        $this->assertIsArray($wrapper);
        $this->assertSame(1, $this->countDirectChildId($wrapper, 'nicepay_verify_panel'));
    }

    /**
     * 같은 레이아웃에 두 번 적용해도 데이터 소스·안내·패널이 중복되지 않는지 검증한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects extension_badge_injection_is_idempotent_and_scoped
     *
     * @return void 없음
     */
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

    /**
     * 대상이 아닌 레이아웃은 한 글자도 바꾸지 않는지 검증한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects extension_badge_injection_is_idempotent_and_scoped
     *
     * @return void 없음
     */
    public function test_leaves_other_layouts_unchanged(): void
    {
        $detailListener = new EnsureAdminOrderDetailPaymentQueryLayoutListener;
        $layout = [
            'layout_name' => 'shop/checkout',
            'data_sources' => [],
            'components' => [],
        ];

        $this->assertSame($layout, $detailListener->ensurePaymentQueryLayout($layout, 1));
    }

    /**
     * 주문 상세 레이아웃 표본을 만듭니다.
     *
     * @param  bool  $includeTabContentArea  탭 본문 영역(`tab_content_area`)을 포함할지
     * @return array<string, mixed> 레이아웃 JSON
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
     * 레이아웃에서 지정 id 의 데이터 소스 개수를 셉니다.
     *
     * @param  array<string, mixed>  $layout  레이아웃 JSON
     * @param  string  $id  데이터 소스 id
     * @return int 개수
     */
    private function countDataSources(array $layout, string $id): int
    {
        return count(array_filter(
            $layout['data_sources'] ?? [],
            static fn ($dataSource): bool => is_array($dataSource) && ($dataSource['id'] ?? null) === $id
        ));
    }

    /**
     * 트리에서 지정 id 값이 나타나는 횟수를 셉니다.
     *
     * @param  array<string|int, mixed>  $node  탐색할 노드
     * @param  string  $id  찾을 id
     * @return int 횟수
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
     * 트리에서 지정 id 의 노드를 찾습니다.
     *
     * @param  array<string, mixed>  $node  탐색할 노드
     * @param  string  $id  찾을 id
     * @return array<string, mixed>|null 찾은 노드 (없으면 null)
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
     * 노드의 직계 자식 중 지정 id 의 개수를 셉니다.
     *
     * @param  array<string, mixed>  $node  부모 노드
     * @param  string  $id  찾을 id
     * @return int 개수
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
     *
     * @return void 없음
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
