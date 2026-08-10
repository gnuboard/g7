<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Extensions;

use PHPUnit\Framework\TestCase;

/**
 * 주문 목록 컬럼 스냅샷 동기 회귀 테스트
 *
 * 이 플러그인은 관리자 주문 목록의 `payment_method` 컬럼에만 테스트결제 배지를 덧붙인다.
 * 그런데 주입 방식이 `inject_props` 로 **컬럼 배열 전체를 교체**하는 형태라, 나머지 10개
 * 컬럼을 이커머스 모듈에서 그대로 복사해 들고 있다.
 *
 * 그 복사본은 모듈이 컬럼을 개선해도 따라가지 않는다. 실제로 부분취소 배지·적립예정 포인트·
 * 마일리지 사용 표기가 모듈에만 추가되고 이 복사본에는 반영되지 않아, 결제 플러그인이 설치된
 * 사이트에서는 세 표시가 화면에서 통째로 사라져 있었다. 오류도 경고도 없이 표시만 없어지므로
 * 사람이 알아채기 어렵다.
 *
 * 이 테스트는 그 드리프트를 잡는다 — `payment_method` 를 제외한 모든 컬럼이 모듈의 현재
 * 정의와 완전히 같아야 한다.
 */
class AdminOrderListColumnSnapshotTest extends TestCase
{
    /** 이 플러그인이 실제로 변경하는 컬럼 (나머지는 모듈 정의를 그대로 따라야 한다) */
    private const OWNED_COLUMN = 'payment_method';

    /** 이 플러그인의 확장 파일 경로 (저장소 루트 기준) */
    private const EXTENSION_PATH = 'plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/admin_order_list_test_badge.json';

    /** 컬럼 정의의 원본(SSoT) — 이커머스 모듈 레이아웃 */
    private const MODULE_LAYOUT_PATH = 'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_list/_partial_order_datagrid.json';

    /**
     * 저장소 루트 절대 경로를 반환합니다.
     *
     * @return string 저장소 루트
     */
    private function repoRoot(): string
    {
        return dirname(__DIR__, 6);
    }

    /**
     * 이커머스 모듈의 주문 목록 컬럼 정의를 읽습니다.
     *
     * @return array<int, array<string, mixed>> 컬럼 목록
     */
    private function moduleColumns(): array
    {
        $path = $this->repoRoot().'/'.self::MODULE_LAYOUT_PATH;

        if (! is_file($path)) {
            $this->markTestSkipped('이커머스 모듈 레이아웃을 찾을 수 없습니다: '.$path);
        }

        $columns = $this->findDataGridColumns(json_decode(file_get_contents($path), true));

        $this->assertNotNull($columns, '모듈 레이아웃에서 DataGrid 컬럼 정의를 찾지 못했습니다');

        return $columns;
    }

    /**
     * 이 플러그인이 주입하는 컬럼 정의를 읽습니다.
     *
     * @return array<int, array<string, mixed>> 컬럼 목록
     */
    private function pluginColumns(): array
    {
        $json = json_decode(file_get_contents($this->repoRoot().'/'.self::EXTENSION_PATH), true);

        foreach ($json['injections'] ?? [] as $injection) {
            if (($injection['target_id'] ?? null) === 'order_datagrid') {
                return $injection['props']['columns'] ?? [];
            }
        }

        $this->fail('order_datagrid 주입을 찾지 못했습니다');
    }

    /**
     * 트리에서 DataGrid 의 columns 배열을 찾습니다.
     *
     * @param  mixed  $node  탐색 노드
     * @return array<int, array<string, mixed>>|null 찾은 컬럼 목록
     */
    private function findDataGridColumns(mixed $node): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        if (($node['name'] ?? null) === 'DataGrid' && isset($node['props']['columns'])) {
            return $node['props']['columns'];
        }

        foreach ($node as $child) {
            $found = $this->findDataGridColumns($child);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 컬럼 목록에서 특정 field 의 정의를 찾습니다.
     *
     * @param  array<int, array<string, mixed>>  $columns  컬럼 목록
     * @param  string  $field  찾을 field
     * @return array<string, mixed>|null 컬럼 정의
     */
    private function columnByField(array $columns, string $field): ?array
    {
        foreach ($columns as $column) {
            if (($column['field'] ?? '') === $field) {
                return $column;
            }
        }

        return null;
    }

    public function test_column_set_matches_the_module_definition(): void
    {
        $this->assertSame(
            array_column($this->moduleColumns(), 'field'),
            array_column($this->pluginColumns(), 'field'),
            '컬럼 구성(개수·순서)이 모듈 정의와 다릅니다 — 모듈에 컬럼이 추가/삭제되면 여기도 맞춰야 합니다'
        );
    }

    public function test_only_the_payment_method_column_differs_from_the_module(): void
    {
        $module = $this->moduleColumns();

        foreach ($this->pluginColumns() as $column) {
            $field = $column['field'] ?? '';

            if ($field === self::OWNED_COLUMN) {
                continue;
            }

            $this->assertSame(
                json_encode($this->columnByField($module, $field), JSON_UNESCAPED_UNICODE),
                json_encode($column, JSON_UNESCAPED_UNICODE),
                "컬럼 \"{$field}\" 이 모듈 정의와 어긋났습니다 — 이 플러그인이 바꾸는 컬럼은 "
                    .self::OWNED_COLUMN.' 뿐이므로 나머지는 모듈 정의를 그대로 따라야 합니다'
            );
        }
    }

    public function test_payment_method_column_keeps_the_test_mode_badge(): void
    {
        $paymentMethod = $this->columnByField($this->pluginColumns(), self::OWNED_COLUMN);

        $this->assertNotNull($paymentMethod, 'payment_method 컬럼이 사라졌습니다');

        $json = json_encode($paymentMethod, JSON_UNESCAPED_UNICODE);

        // 모듈 정의를 되돌려 붙이면서 이 플러그인의 본래 목적(테스트결제 배지)을 지우면 안 된다
        $this->assertStringContainsString('test_mode_badge', $json);
        $this->assertStringContainsString('row.payment?.payment_method_label', $json);
    }
}
