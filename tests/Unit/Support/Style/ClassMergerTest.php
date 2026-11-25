<?php

namespace Tests\Unit\Support\Style;

use App\Support\Style\ClassMerger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SEO 클래스 병합기 — 엔진 mergeClasses·applyResponsiveClassMerge 패리티 (7.2.1, engine-v1.98.0 짝)
 *
 * 공용 픽스처 `tests/Fixtures/class-merge/cases.json` 을 엔진 시험(`StyleHelpers.classMergeParity.test.ts`)과
 * 함께 쓴다. 그룹 표 이름 집합이 엔진 원본과 같은지도 확인한다(한쪽만 고치면 봇 화면 클래스가 달라진다).
 */
class ClassMergerTest extends TestCase
{
    /**
     * 공용 픽스처를 읽습니다.
     *
     * @return array{prefixes: list<string>, cases: list<array<string, mixed>>} 픽스처
     */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 4).'/tests/Fixtures/class-merge/cases.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * mergeClasses 사례.
     *
     * @return iterable<string, array{0: string|null, 1: string|null, 2: string}> 사례
     */
    public static function mergeCases(): iterable
    {
        foreach (self::fixture()['cases'] as $case) {
            if (in_array($case['fn'], ['both', 'mergeClasses'], true)) {
                yield $case['id'] => [$case['base'], $case['override'], $case['expected']];
            }
        }
    }

    /**
     * applyResponsiveClassMerge 사례.
     *
     * @return iterable<string, array{0: string|null, 1: string|null, 2: string}> 사례
     */
    public static function responsiveCases(): iterable
    {
        foreach (self::fixture()['cases'] as $case) {
            if (in_array($case['fn'], ['both', 'applyResponsive'], true)) {
                yield $case['id'] => [$case['base'], $case['override'], $case['expected']];
            }
        }
    }

    /**
     * merge() 가 엔진 mergeClasses 와 같은 결과를 낸다.
     *
     * @scenario surface=fixture
     *
     * @effects seo_class_merger_matches_engine_fixture
     *
     * @param  string|null  $base  기본 클래스
     * @param  string|null  $override  덮을 클래스
     * @param  string  $expected  기대 결과
     * @return void 반환값 없음
     */
    #[DataProvider('mergeCases')]
    public function test_merge_matches_engine_fixture(?string $base, ?string $override, string $expected): void
    {
        $this->assertSame($expected, ClassMerger::merge($base, $override, self::fixture()['prefixes']));
    }

    /**
     * applyResponsive() 가 엔진 applyResponsiveClassMerge 와 같은 결과를 낸다.
     *
     * @scenario surface=fixture
     *
     * @effects seo_class_merger_matches_engine_fixture
     *
     * @param  string|null  $base  기본 클래스
     * @param  string|null  $override  덮을 클래스
     * @param  string  $expected  기대 결과
     * @return void 반환값 없음
     */
    #[DataProvider('responsiveCases')]
    public function test_apply_responsive_matches_engine_fixture(?string $base, ?string $override, string $expected): void
    {
        $this->assertSame($expected, ClassMerger::applyResponsive($base, $override, self::fixture()['prefixes']));
    }

    /**
     * 그룹 표·상위 그룹 표의 이름과 순서가 엔진 원본(StyleHelpers.ts)과 같다.
     *
     * @scenario surface=fixture
     *
     * @effects seo_class_merger_matches_engine_fixture
     *
     * @return void 반환값 없음
     */
    public function test_group_tables_match_engine_source(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/resources/js/core/template-engine/helpers/StyleHelpers.ts');

        $groupsBlock = substr($source, (int) strpos($source, 'const TAILWIND_CLASS_GROUPS'));
        $groupsBlock = substr($groupsBlock, 0, (int) strpos($groupsBlock, '};'));
        preg_match_all('/^\s+(\w+): \/(.+)\/,\s*$/m', $groupsBlock, $groups);
        $this->assertSame($groups[1], array_keys(ClassMerger::GROUPS));
        foreach ($groups[1] as $i => $name) {
            $this->assertSame('#'.$groups[2][$i].'#', ClassMerger::GROUPS[$name], $name);
        }

        $conflictBlock = substr($source, (int) strpos($source, 'const CONFLICTING_CLASS_GROUPS'));
        $conflictBlock = substr($conflictBlock, 0, (int) strpos($conflictBlock, '};'));
        preg_match_all('/(\w+): \[([^\]]*)\]/s', $conflictBlock, $conflicts);
        $this->assertSame($conflicts[1], array_keys(ClassMerger::CONFLICTING));
        foreach ($conflicts[1] as $i => $name) {
            preg_match_all("/'(\w+)'/", $conflicts[2][$i], $lower);
            $this->assertSame($lower[1], ClassMerger::CONFLICTING[$name], $name);
        }
    }
}
