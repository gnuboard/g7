<?php

namespace Tests\Unit\Support;

use App\Support\ExtensionDependencies;
use PHPUnit\Framework\TestCase;

/**
 * 필수 의존 선언(`dependencies`)의 목록형 해석 (#822 D6)
 *
 * `{"modules": ["sirsoft-board"]}` 목록형을 키로 순회하면 식별자 자리에 `0`·`1` 이 들어가 의존 검사·역조회·정렬이
 * 엉뚱한 확장을 찾았다. 의존을 읽는 곳은 모두 이 정규화기를 거친다 — 목록형은 「버전 무관(*)」, 객체형은 그대로.
 */
class ExtensionDependenciesConstraintsTest extends TestCase
{
    /**
     * 목록형은 식별자 => '*', 객체형은 제약을 그대로 두고 빈 제약은 '*' 로 받는다.
     *
     * @scenario case=dependency_list_shape
     *
     * @effects list_dependencies_resolve_to_identifiers
     *
     * @return void 반환값 없음
     */
    public function test_list_shape_maps_identifiers_to_any_version(): void
    {
        $this->assertSame(
            [
                'modules' => ['sirsoft-board' => '*', 'sirsoft-page' => '*'],
                'plugins' => ['sirsoft-daum_postcode' => '>=1.0.0', 'acme-pay' => '*'],
                'templates' => [],
            ],
            ExtensionDependencies::constraints([
                'modules' => ['sirsoft-board', ' sirsoft-page ', '', 42],
                'plugins' => ['sirsoft-daum_postcode' => '>=1.0.0', 'acme-pay' => null],
            ]),
        );
    }

    /**
     * 식별자 목록은 목록형에서도 숫자 키가 아닌 실제 식별자를 돌려준다.
     *
     * @scenario case=dependency_list_shape
     *
     * @effects list_dependencies_resolve_to_identifiers
     *
     * @return void 반환값 없음
     */
    public function test_identifiers_never_return_numeric_keys(): void
    {
        $this->assertSame(['acme-a', 'acme-b'], ExtensionDependencies::identifiers(['modules' => ['acme-a', 'acme-b']], 'modules'));
        $this->assertSame(['acme-a'], ExtensionDependencies::identifiers(['modules' => ['acme-a' => '^1.0']], 'modules'));
        $this->assertSame([], ExtensionDependencies::identifiers(['modules' => ['acme-a']], 'plugins'));
    }

    /**
     * 잘못된 모양은 빈 맵이다.
     *
     * @scenario case=dependency_malformed
     *
     * @effects malformed_dependencies_are_ignored
     *
     * @return void 반환값 없음
     */
    public function test_malformed_input_yields_empty_maps(): void
    {
        $empty = ['modules' => [], 'plugins' => [], 'templates' => []];

        $this->assertSame($empty, ExtensionDependencies::constraints(null));
        $this->assertSame($empty, ExtensionDependencies::constraints('sirsoft-board'));
        $this->assertSame($empty, ExtensionDependencies::constraints(['modules' => 'sirsoft-board']));
        $this->assertSame([], ExtensionDependencies::constraintsOf(null));
    }
}
