<?php

namespace Tests\Unit\Extension;

use App\Extension\Traits\ValidatesLayoutFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 레이아웃 루트 탐지(`getLayoutsBaseDirectory`)의 회귀 봉인.
 *
 * `partials/…` 로 시작하는 include 는 **layouts 루트 기준**으로 적는 것이 이 프로젝트의 관례이고,
 * 해석기는 "현재 디렉토리 기준으로 없으면 layouts 루트로 폴백" 한다. 그 폴백이 성립하려면 임의 깊이의
 * partial 디렉토리에서 layouts 루트를 찾아낼 수 있어야 한다.
 *
 * 종전 구현은 `templates/{name}/layouts` 처럼 **세그먼트 수를 고정한 정규식**이라
 * `templates/_bundled/{name}/layouts` 를 못 맞췄다. 그 결과 폴백이 조용히 무효화되고, include 는
 * 깨진 채 `app.debug=false` 에서 **빈 배열로 사라진다**(에러 컴포넌트조차 안 남는다).
 *
 * 활성 디렉토리에서는 경로 모양이 맞아떨어져 프로덕션은 무사했고, `_bundled` 를 직접 읽는
 * 도구·테스트만 어긋났다 — 즉 **소스 SSoT 디렉토리에서만 다르게 동작**하고 있었다.
 */
class LayoutPartialBaseDirectoryTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function layoutRootProvider(): array
    {
        return [
            // 활성 디렉토리 (종전에도 통과하던 모양 — 대조군)
            '템플릿 활성' => [
                'templates/sirsoft-admin_basic/layouts/partials/admin_settings',
                'templates/sirsoft-admin_basic/layouts',
            ],
            '모듈 활성' => [
                'modules/sirsoft-board/resources/layouts/admin/partials/detail',
                'modules/sirsoft-board/resources/layouts',
            ],
            '플러그인 활성' => [
                'plugins/sirsoft-gdpr/resources/layouts/admin/partials/settings',
                'plugins/sirsoft-gdpr/resources/layouts',
            ],
            // _bundled (소스 SSoT — 종전 실패 지점)
            '템플릿 _bundled' => [
                'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings',
                'templates/_bundled/sirsoft-admin_basic/layouts',
            ],
            '모듈 _bundled' => [
                'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/detail',
                'modules/_bundled/sirsoft-board/resources/layouts',
            ],
            '플러그인 _bundled' => [
                'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/partials/settings',
                'plugins/_bundled/sirsoft-gdpr/resources/layouts',
            ],
            // 루트 자기 자신
            'layouts 루트 자신' => [
                'templates/_bundled/sirsoft-admin_basic/layouts',
                'templates/_bundled/sirsoft-admin_basic/layouts',
            ],
            // 깊은 중첩
            '깊은 중첩' => [
                'templates/_bundled/sirsoft-basic/layouts/partials/board/show/modals',
                'templates/_bundled/sirsoft-basic/layouts',
            ],
        ];
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects layout_root_detected_at_any_directory_depth
     */
    #[DataProvider('layoutRootProvider')]
    public function test_layouts_root_is_detected_at_any_depth(string $basePath, string $expected): void
    {
        $resolver = new class
        {
            use ValidatesLayoutFiles;

            public function dir(string $path): string
            {
                return $this->getLayoutsBaseDirectory($path);
            }
        };

        $this->assertSame(
            $this->normalize(base_path($expected)),
            $this->normalize($resolver->dir(base_path($basePath))),
        );
    }

    /**
     * layouts 가 경로에 없으면 입력을 그대로 돌려준다 (폴백 대상이 아님).
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects layout_root_falls_back_to_input_when_absent
     */
    public function test_returns_input_when_no_layouts_segment(): void
    {
        $resolver = new class
        {
            use ValidatesLayoutFiles;

            public function dir(string $path): string
            {
                return $this->getLayoutsBaseDirectory($path);
            }
        };

        $path = base_path('templates/_bundled/sirsoft-admin_basic/src/components');

        $this->assertSame($this->normalize($path), $this->normalize($resolver->dir($path)));
    }

    /**
     * 구분자를 통일해 Windows/Unix 양쪽에서 같은 기준으로 비교합니다.
     *
     * @param  string  $path  경로
     * @return string 정규화된 경로
     */
    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
