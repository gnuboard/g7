<?php

namespace Tests\Unit\Support;

use App\Support\ExtensionDependencyOrder;
use PHPUnit\Framework\TestCase;

/**
 * 여러 확장을 한 번에 처리하는 코어 경로의 공용 정렬(종류 횡단 안정 위상 정렬) 검증 (#153).
 */
class ExtensionDependencyOrderTest extends TestCase
{
    /**
     * 노드 키 목록으로 정렬한다.
     *
     * @param  array<int, string>  $keys  기본 순서
     * @param  array<string, array<int, string>>  $deps  키 → 의존 키
     * @return array{ordered: array<int, string>, cycles: array<int, array<int, string>>, payloads: array<int, string>} 정렬된 키·순환·보존된 노드 값
     */
    private static function sortKeys(array $keys, array $deps): array
    {
        $result = ExtensionDependencyOrder::order(
            array_map(fn (string $key): array => ['key' => $key, 'payload' => strtoupper($key)], $keys),
            fn (string $key): array => $deps[$key] ?? []
        );

        return ['ordered' => array_column($result['ordered'], 'key'), 'cycles' => $result['cycles'], 'payloads' => array_column($result['ordered'], 'payload')];
    }

    /**
     * 의존이 없으면 순서를 한 칸도 바꾸지 않고, 노드 값을 보존한다.
     *
     * @scenario surface=bundled_update, edge=module_to_plugin
     *
     * @effects dependency_order_is_stable_without_dependencies
     *
     * @return void 반환값 없음
     */
    public function test_order_is_stable_without_dependencies(): void
    {
        $result = self::sortKeys(['module:b', 'module:a', 'plugin:c'], ['plugin:c' => ['module:zzz-not-in-list']]);

        $this->assertSame(['module:b', 'module:a', 'plugin:c'], $result['ordered']);
        $this->assertSame(['MODULE:B', 'MODULE:A', 'PLUGIN:C'], $result['payloads']);
        $this->assertSame([], $result['cycles']);
    }

    /**
     * 모듈이 플러그인에 의존하면 플러그인이 먼저 — 종류 기본 순서(모듈 → 플러그인)를 넘어 앞당긴다. 3단 사슬도 풀린다.
     *
     * @scenario surface=bundled_update, edge=chain
     *
     * @effects dependency_order_crosses_extension_types
     *
     * @return void 반환값 없음
     */
    public function test_order_crosses_types_and_resolves_chain(): void
    {
        $this->assertSame(
            ['plugin:ckeditor5', 'module:page', 'module:board'],
            self::sortKeys(['module:page', 'module:board', 'plugin:ckeditor5'], ['module:page' => ['plugin:ckeditor5']])['ordered']
        );

        // 플러그인 koreaexim → 모듈 ecommerce → 플러그인 marketing
        $this->assertSame(
            ['plugin:marketing', 'module:ecommerce', 'plugin:koreaexim'],
            self::sortKeys(['module:ecommerce', 'plugin:koreaexim', 'plugin:marketing'], [
                'module:ecommerce' => ['plugin:marketing'],
                'plugin:koreaexim' => ['module:ecommerce'],
            ])['ordered']
        );
    }

    /**
     * 같은 식별자가 모듈·플러그인 양쪽에 있어도 종류가 다른 노드로 본다.
     *
     * @scenario surface=bundled_update, edge=module_to_plugin
     *
     * @effects dependency_order_keeps_same_identifier_types_apart
     *
     * @return void 반환값 없음
     */
    public function test_same_identifier_in_two_types_is_two_nodes(): void
    {
        $this->assertSame(
            ['plugin:dup', 'module:dup'],
            self::sortKeys(['module:dup', 'plugin:dup'], ['module:dup' => ['plugin:dup']])['ordered']
        );
    }

    /**
     * 순환이면 그 구간은 원래 순서로 두고 경로를 남긴다 — 노드를 잃지 않고 순환 밖 의존은 그대로 앞당긴다.
     *
     * @scenario surface=bundled_update, edge=cycle
     *
     * @effects dependency_order_reports_cycle_and_keeps_original_order
     *
     * @return void 반환값 없음
     */
    public function test_cycle_keeps_original_order_and_is_reported(): void
    {
        $result = self::sortKeys(['module:a', 'plugin:b', 'module:c', 'plugin:d'], [
            'module:a' => ['plugin:b'],
            'plugin:b' => ['module:a'],
            'module:c' => ['plugin:d'],
        ]);

        $this->assertSame(['module:a', 'plugin:b', 'plugin:d', 'module:c'], $result['ordered']);
        $this->assertSame([['module:a', 'plugin:b', 'module:a']], $result['cycles']);
    }

    /**
     * 자기 자신 의존은 순환으로 보지 않는다.
     *
     * @scenario surface=bundled_update, edge=cycle
     *
     * @effects dependency_order_ignores_self_dependency
     *
     * @return void 반환값 없음
     */
    public function test_self_dependency_is_ignored(): void
    {
        $result = self::sortKeys(['module:a', 'module:b'], ['module:a' => ['module:a']]);

        $this->assertSame(['module:a', 'module:b'], $result['ordered']);
        $this->assertSame([], $result['cycles']);
    }

    /**
     * 비활성화처럼 의존하는 쪽부터 처리해야 하면 결과를 뒤집는다.
     *
     * @scenario surface=auto_deactivate, edge=module_to_plugin
     *
     * @effects dependency_order_reverse_puts_dependents_first
     *
     * @return void 반환값 없음
     */
    public function test_reverse_puts_dependents_first(): void
    {
        $ordered = ExtensionDependencyOrder::order(
            [['key' => 'module:ecommerce'], ['key' => 'plugin:tosspayments']],
            fn (string $key): array => $key === 'plugin:tosspayments' ? ['module:ecommerce'] : []
        )['ordered'];

        $this->assertSame(['plugin:tosspayments', 'module:ecommerce'], array_column(ExtensionDependencyOrder::reverse($ordered), 'key'));
    }

    /**
     * manifest dependencies(객체형·목록형)를 노드 키로 바꾼다 — 템플릿 의존은 노드가 아니다.
     *
     * @scenario surface=bundled_update, edge=module_to_plugin
     *
     * @effects dependency_order_reads_manifest_dependency_keys
     *
     * @return void 반환값 없음
     */
    public function test_dependency_keys_from_manifest_declaration(): void
    {
        $this->assertSame(
            ['module:sirsoft-board', 'plugin:sirsoft-ckeditor5', 'plugin:sirsoft-marketing'],
            ExtensionDependencyOrder::dependencyKeys([
                'modules' => ['sirsoft-board' => '>=1.0.0'],
                'plugins' => ['sirsoft-ckeditor5', 'sirsoft-marketing'],
                'templates' => ['sirsoft-basic' => '*'],
            ])
        );
        $this->assertSame([], ExtensionDependencyOrder::dependencyKeys(null));
    }
}
