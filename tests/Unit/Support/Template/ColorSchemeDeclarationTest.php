<?php

namespace Tests\Unit\Support\Template;

use App\Support\Template\ColorSchemeDeclaration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `ColorSchemeDeclaration` — 템플릿 다크 방식 선언(`features.color_scheme`) 정규화·검증.
 *
 * 화면은 이 값을 `G7Config.colorScheme` 으로 받아 `<html data-g7-color-scheme>` 를 단다. 틀린 선언이
 * 조용히 다크로 굳으면 확장 화면이 라이트 템플릿에서 다크로 뜬다 — 그래서 틀린 값은 `none`(라이트)으로 떨어진다.
 */
class ColorSchemeDeclarationTest extends TestCase
{
    /**
     * 정규화 사례 — 선언 3종·미선언·매니페스트 없음·틀린 값과 그 기대 결과.
     *
     * @return array<string, array{0: array<string, mixed>|null, 1: array<string, string>}> 사례 이름 => [template.json 내용, 기대 선언]
     */
    public static function declarationProvider(): array
    {
        return [
            '미선언' => [['features' => []], ['strategy' => 'none']],
            '매니페스트 없음' => [null, ['strategy' => 'none']],
            'ancestor-class' => [['features' => ['color_scheme' => ['strategy' => 'ancestor-class', 'selector' => ' .dark ']]], ['strategy' => 'ancestor-class', 'selector' => '.dark']],
            'ancestor-class 선택자 없음' => [['features' => ['color_scheme' => ['strategy' => 'ancestor-class']]], ['strategy' => 'none']],
            'media-query' => [['features' => ['color_scheme' => ['strategy' => 'media-query']]], ['strategy' => 'media-query']],
            'none' => [['features' => ['color_scheme' => ['strategy' => 'none']]], ['strategy' => 'none']],
            '모르는 방식' => [['features' => ['color_scheme' => ['strategy' => 'class']]], ['strategy' => 'none']],
        ];
    }

    /**
     * 선언 3종과 미선언·틀린 값의 정규화.
     *
     * @param  array<string, mixed>|null  $manifest  template.json 내용(없으면 null)
     * @param  array<string, string>  $expected  기대하는 정규화 선언
     *
     * @scenario case=color_decl_c01
     *
     * @effects color_scheme_declaration_validated
     *
     * @return void 반환값 없음
     */
    #[DataProvider('declarationProvider')]
    public function test_from_manifest(?array $manifest, array $expected): void
    {
        $this->assertSame($expected, ColorSchemeDeclaration::fromManifest($manifest));
    }

    /**
     * 검증 — 미선언은 유효, 모르는 방식·선택자 없는 ancestor-class 는 오류.
     *
     * @scenario case=color_decl_c02
     *
     * @effects color_scheme_declaration_validated
     *
     * @return void 반환값 없음
     */
    public function test_validation_errors(): void
    {
        $this->assertSame([], ColorSchemeDeclaration::validationErrors(['features' => []]));
        $this->assertSame([], ColorSchemeDeclaration::validationErrors(['features' => ['color_scheme' => ['strategy' => 'none']]]));
        $this->assertCount(1, ColorSchemeDeclaration::validationErrors(['features' => ['color_scheme' => ['strategy' => 'x']]]));
        $this->assertCount(1, ColorSchemeDeclaration::validationErrors(['features' => ['color_scheme' => ['strategy' => 'ancestor-class', 'selector' => '']]]));
    }

    /**
     * 번들 템플릿 4종의 선언이 계획대로다(Tailwind 템플릿 2종은 `.dark` 조상, 학습용 2종은 none).
     *
     * @scenario case=color_decl_c03
     *
     * @effects color_scheme_declaration_validated
     *
     * @return void 반환값 없음
     */
    public function test_bundled_templates_declare_color_scheme(): void
    {
        $expected = [
            'sirsoft-basic' => ['strategy' => 'ancestor-class', 'selector' => '.dark'],
            'sirsoft-admin_basic' => ['strategy' => 'ancestor-class', 'selector' => '.dark'],
            'gnuboard7-hello_user_template' => ['strategy' => 'none'],
            'gnuboard7-hello_admin_template' => ['strategy' => 'none'],
        ];

        foreach ($expected as $identifier => $declaration) {
            $manifest = json_decode((string) file_get_contents(base_path("templates/_bundled/{$identifier}/template.json")), true);
            $this->assertSame([], ColorSchemeDeclaration::validationErrors($manifest), $identifier);
            $this->assertArrayHasKey('color_scheme', $manifest['features'] ?? [], "{$identifier} 는 color_scheme 을 명시 선언한다");
            $this->assertSame($declaration, ColorSchemeDeclaration::fromManifest($manifest), $identifier);
        }
    }
}
