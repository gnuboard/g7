<?php

namespace Tests\Unit\Support\Template;

use App\Support\Template\ExtensionCssOverrideResolver;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * `ExtensionCssOverrideResolver` — 템플릿의 확장 CSS 덮기 파일 판정.
 *
 * 판정 표(컴포넌트 대체와 같은 규칙):
 *   대상 활성 + 버전 호환 → 포함 / 대상 활성 + 버전 비호환 → 제외 + Log::info /
 *   대상 비활성·미설치 → 조용히 제외 / 같은 target 중복 → 검증 오류(런타임은 첫 선언).
 */
class ExtensionCssOverrideResolverTest extends TestCase
{
    private string $root;

    /**
     * 임시 템플릿 루트에 덮기 파일 두 개(hello·board)를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/g7-css-override-'.uniqid();
        mkdir($this->root.'/dist/css/overrides', 0777, true);
        file_put_contents($this->root.'/dist/css/overrides/hello.css', '.hellomodule-card__title{color:red}');
        file_put_contents($this->root.'/dist/css/overrides/board.css', '.board-title{color:red}');
    }

    /**
     * 임시 템플릿 루트와 덮기 파일을 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach (['hello.css', 'board.css'] as $f) {
            @unlink($this->root.'/dist/css/overrides/'.$f);
        }
        @rmdir($this->root.'/dist/css/overrides');
        @rmdir($this->root.'/dist/css');
        @rmdir($this->root.'/dist');
        @rmdir($this->root);
        parent::tearDown();
    }

    /**
     * 덮기 선언만 담은 최소 template.json 배열을 만든다.
     *
     * @param  array<int, array<string, mixed>>  $entries  extension_css_overrides 선언 목록
     * @return array<string, mixed> template.json 내용
     */
    private function manifest(array $entries): array
    {
        return ['identifier' => 't', 'extension_css_overrides' => $entries];
    }

    /**
     * 대상 활성 + 버전 호환 → 포함(서빙 경로는 dist/ 를 뗀 형태).
     *
     * @scenario case=css_override_c01
     *
     * @effects template_css_override_resolved_by_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_active_and_compatible_target_is_included(): void
    {
        $out = (new ExtensionCssOverrideResolver)->resolve('t', $this->manifest([
            ['target' => 'gnuboard7-hello_module', 'targetVersion' => '^0.3', 'path' => 'dist/css/overrides/hello.css'],
        ]), $this->root, ['gnuboard7-hello_module' => '0.3.0']);

        $this->assertSame([['target' => 'gnuboard7-hello_module', 'path' => 'css/overrides/hello.css']], $out);
    }

    /**
     * 대상 활성 + 버전 비호환 → 제외하고 Log::info 로 이유를 남긴다(확장 원본 모양 폴백).
     *
     * @scenario case=css_override_c02
     *
     * @effects template_css_override_resolved_by_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_incompatible_version_is_excluded_with_info_log(): void
    {
        Log::spy();

        $out = (new ExtensionCssOverrideResolver)->resolve('t', $this->manifest([
            ['target' => 'gnuboard7-hello_module', 'targetVersion' => '^0.3', 'path' => 'dist/css/overrides/hello.css'],
        ]), $this->root, ['gnuboard7-hello_module' => '0.2.1']);

        $this->assertSame([], $out);
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => ($context['constraint'] ?? null) === '^0.3')->once();
    }

    /**
     * 대상 비활성·미설치 → 조용히 제외(로그 없음).
     *
     * @scenario case=css_override_c03
     *
     * @effects template_css_override_resolved_by_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_inactive_target_is_silently_excluded(): void
    {
        Log::spy();

        $out = (new ExtensionCssOverrideResolver)->resolve('t', $this->manifest([
            ['target' => 'gnuboard7-hello_module', 'targetVersion' => '^0.3', 'path' => 'dist/css/overrides/hello.css'],
        ]), $this->root, ['sirsoft-board' => '1.3.0']);

        $this->assertSame([], $out);
        Log::shouldNotHaveReceived('info');
    }

    /**
     * 같은 target 중복 — 검증은 오류, 런타임은 첫 선언만 쓴다.
     *
     * @scenario case=css_override_c04
     *
     * @effects template_css_override_resolved_by_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_duplicate_target_is_validation_error_and_runtime_uses_first(): void
    {
        $manifest = $this->manifest([
            ['target' => 'sirsoft-board', 'path' => 'dist/css/overrides/board.css'],
            ['target' => 'sirsoft-board', 'path' => 'dist/css/overrides/hello.css'],
        ]);
        $resolver = new ExtensionCssOverrideResolver;

        $errors = $resolver->validationErrors($manifest);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('sirsoft-board', $errors[0]);

        $this->assertSame(
            [['target' => 'sirsoft-board', 'path' => 'css/overrides/board.css']],
            $resolver->resolve('t', $manifest, $this->root, ['sirsoft-board' => '1.3.0'])
        );
    }

    /**
     * 형식 검증 — dist/ 밖 경로·상위 경로·target 누락·해석 불가 제약·목록 아님.
     *
     * @scenario case=css_override_c05
     *
     * @effects template_css_override_resolved_by_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_validation_rejects_malformed_declarations(): void
    {
        $resolver = new ExtensionCssOverrideResolver;

        $this->assertSame([], $resolver->validationErrors(['identifier' => 't']));
        $this->assertCount(1, $resolver->validationErrors(['extension_css_overrides' => ['a' => 1]]));
        $this->assertCount(3, $resolver->validationErrors($this->manifest([
            ['target' => 'x', 'path' => 'src/styles/x.css'],
            ['target' => 'y', 'path' => 'dist/../secret.css'],
            ['path' => 'dist/css/z.css'],
        ])));
        $this->assertCount(1, $resolver->validationErrors($this->manifest([
            ['target' => 'x', 'targetVersion' => 'not a constraint !!', 'path' => 'dist/css/x.css'],
        ])));
    }

    /**
     * 선언은 있는데 빌드 산출물이 없으면 제외한다(요청 실패를 남기지 않는다).
     *
     * @scenario case=css_override_c06
     *
     * @effects template_css_override_resolved_by_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_missing_build_output_is_excluded(): void
    {
        $out = (new ExtensionCssOverrideResolver)->resolve('t', $this->manifest([
            ['target' => 'sirsoft-board', 'path' => 'dist/css/overrides/missing.css'],
        ]), $this->root, ['sirsoft-board' => '1.3.0']);

        $this->assertSame([], $out);
    }

    /**
     * 제약 미선언은 항상 호환이다.
     *
     * @scenario case=css_override_c07
     *
     * @effects template_css_override_resolved_by_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_missing_constraint_is_compatible(): void
    {
        $out = (new ExtensionCssOverrideResolver)->resolve('t', $this->manifest([
            ['target' => 'sirsoft-board', 'path' => 'dist/css/overrides/board.css'],
        ]), $this->root, ['sirsoft-board' => '9.9.9']);

        $this->assertCount(1, $out);
    }
}
