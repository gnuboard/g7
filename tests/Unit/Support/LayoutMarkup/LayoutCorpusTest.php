<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Support\LayoutMarkup\LayoutCorpus;

/**
 * 코퍼스 모집단 정의(SSoT)의 회귀 봉인.
 *
 * 이 프로젝트가 두 번 겪은 사고가 같은 형태였다 — **모집단이 조용히 좁아진 채 green 이 나오는 것**.
 * ㉓ 은 fragment 42건이 통째로 계량 밖인 상태에서 "escape-0" 판정을 받았고, ㉘ 은 fragment 실물
 * 등가성 게이트가 `templates/_bundled/*​/extensions` 를 못 보는 것을 찾아냈다. 둘 다 게이트가 아무
 * 말도 하지 않았다 — 스캔이 좁아지면 실패가 아니라 **대상이 사라질 뿐**이기 때문이다.
 *
 * 그래서 루트 구성을 값으로 못박는다. 확장 유형이 셋(템플릿·모듈·플러그인)인 한, 본 레이아웃과
 * fragment 양쪽 모두 셋을 다 봐야 한다.
 */
class LayoutCorpusTest extends LayoutMarkupTestCase
{
    /**
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects corpus_roots_cover_all_three_extension_types
     */
    public function test_layout_roots_cover_all_three_extension_types(): void
    {
        $roots = array_map($this->relative(...), LayoutCorpus::layoutRoots());

        $this->assertEqualsCanonicalizing([
            'templates/_bundled/*/layouts',
            'modules/_bundled/*/resources/layouts',
            'plugins/_bundled/*/resources/layouts',
        ], $roots, '본 레이아웃 루트가 세 확장 유형을 모두 덮지 않습니다.');
    }

    /**
     * fragment 루트에 **템플릿이 포함**되어야 한다 (㉘ 이 등재한 사각).
     *
     * 템플릿도 fragment 를 낸다(`TemplateManager`). 모듈·플러그인만 훑으면 템플릿 fragment 가
     * 이관돼도 설치 등가성 검증을 타지 않는다 — W4 에 실물이 1건 있다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_roots_include_template_extensions
     */
    public function test_fragment_roots_include_template_extensions(): void
    {
        $roots = array_map($this->relative(...), LayoutCorpus::fragmentRoots());

        $this->assertEqualsCanonicalizing([
            'templates/_bundled/*/extensions',
            'modules/_bundled/*/resources/extensions',
            'plugins/_bundled/*/resources/extensions',
        ], $roots, 'fragment 루트가 세 확장 유형을 모두 덮지 않습니다 (템플릿 누락이 ㉘ 의 사각이었다).');
    }

    /**
     * 전체 루트 = 본 레이아웃 + fragment (한쪽만 넓히는 표류 차단).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects corpus_roots_are_layout_roots_plus_fragment_roots
     */
    public function test_roots_are_layout_roots_plus_fragment_roots(): void
    {
        // 32차 ⚠️ — 양변이 모두 도출이라 셋이 함께 죽으면 `[] == []` 로 통과한다. 하한을 먼저 세운다.
        $this->assertNotEmpty(LayoutCorpus::roots(),
            '코퍼스 루트가 0건이다 — 도출이 죽으면 합집합 동일성은 아무것도 뜻하지 않는다');

        $this->assertEqualsCanonicalizing(
            [...LayoutCorpus::layoutRoots(), ...LayoutCorpus::fragmentRoots()],
            LayoutCorpus::roots(),
        );
    }

    /**
     * fragment 스캔은 `layouts/` 를 포함하지 않는다 (축 분리).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_scan_excludes_main_layouts
     */
    public function test_fragment_scan_excludes_main_layouts(): void
    {
        $fragments = LayoutCorpus::scanFragments('.json');

        // 하한 없이 순회만 하면 스캔이 0건이 되어도 루프 본문이 돌지 않아 초록이다 (31차 근본 D).
        $this->assertNotEmpty($fragments,
            'fragment 스캔이 0건이다 — 글롭이 좁아졌는지 먼저 확인해야 한다 '
            .'("본 레이아웃이 섞이지 않았다" 는 검사 대상이 있을 때만 뜻을 갖는다)');

        foreach ($fragments as $file) {
            $relative = str_replace('\\', '/', $this->relative($file));

            $this->assertStringContainsString('/extensions/', $relative, "fragment 스캔에 본 레이아웃이 섞였습니다: {$relative}");
        }
    }

    /**
     * 절대 경로를 저장소 상대 경로로 줄입니다.
     *
     * @param  string  $path  절대 경로
     * @return string 상대 경로 (슬래시 정규화)
     */
    private function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
    }
}
