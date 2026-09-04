<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\TemplateManager;
use App\Extension\Traits\ResolvesLayoutInstallSource;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 에러 레이아웃 존재 검증의 소스 선택 테스트 (34차 — ❌2).
 *
 * 템플릿 설치는 같은 트랜잭션에서 두 단계를 연달아 밟는다 —
 * `registerLayouts()` 가 레이아웃을 등록하고, 곧바로 `validateErrorLayouts()` 가 필수 에러
 * 레이아웃의 실재를 확인한다. 그런데 앞은 소스를 알고(`scanMarkupLayoutFiles()` 가
 * `layouts/errors/*.g7.html` 를 명시적으로 스캔) 뒤는 `.json` 만 봤다. 두 단계가 서로 다른
 * SSoT 를 보고 있었으므로, **마크업 전용 에러 레이아웃을 가진 템플릿은 레이아웃이 정상
 * 등록된 뒤 "에러 레이아웃이 없다" 는 이유로 설치가 세워진다.**
 *
 * 번들 템플릿 넷은 `errors/` 짝이 전건 병존해 오늘 화면 결함은 0 이지만, S18 의 목표 상태가
 * "마크업은 `.g7.html` 만" 이고 P3 매트릭스가 `markup-only × install` 을 축으로 선언하므로 그
 * 조합이 그대로 실패한다. P2 가 `.json` 을 기계 산출로 바꾼 뒤 마크업 전용 신규 템플릿을
 * 만드는 순간도 같다.
 *
 * @see ResolvesLayoutInstallSource::layoutSourceCandidates()
 */
class MarkupErrorLayoutValidationTest extends TestCase
{
    /**
     * 검증이 읽는 경로는 `base_path("templates/{id}")` 라 임시 템플릿도 그 아래 두어야 한다.
     */
    private string $templateId;

    private string $templatePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->templateId = 'markup-error_layout_probe_'.uniqid();
        $this->templatePath = base_path('templates/'.$this->templateId);

        File::ensureDirectoryExists($this->templatePath.'/layouts/errors');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->templatePath);

        parent::tearDown();
    }

    /**
     * 마크업 전용 에러 레이아웃도 존재로 인정된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects error_layout_validation_accepts_authoring_markup
     */
    public function test_markup_only_error_layouts_pass_validation(): void
    {
        $this->writeErrorLayouts('g7.html');

        $this->assertTrue($this->validate(),
            '마크업 전용 에러 레이아웃이 존재로 인정되지 않았다 — 등록 경로는 '
            .'`layouts/errors/*.g7.html` 를 스캔하는데 검증 경로만 `.json` 을 본다면 소스 SSoT 를 '
            .'따른 템플릿이 설치되지 않는다');
    }

    /**
     * `.json` 전용(기존 형태)도 그대로 인정된다 — 회귀 방지.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects error_layout_validation_accepts_json_source
     */
    public function test_json_only_error_layouts_still_pass_validation(): void
    {
        $this->writeErrorLayouts('json');

        $this->assertTrue($this->validate(), '`.json` 전용 에러 레이아웃 검증이 회귀했다');
    }

    /**
     * 짝이 병존해도 인정된다 — 번들 템플릿 넷의 현재 형태.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects error_layout_validation_accepts_paired_sources
     */
    public function test_paired_error_layouts_pass_validation(): void
    {
        $this->writeErrorLayouts('g7.html');
        $this->writeErrorLayouts('json');

        $this->assertTrue($this->validate(), '짝 병존 에러 레이아웃 검증이 회귀했다');
    }

    /**
     * 어느 소스도 없으면 설치를 세운다 — 검증이 무력화되지 않았음을 잠근다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects error_layout_validation_still_rejects_missing_sources
     */
    public function test_missing_error_layouts_still_fail_validation(): void
    {
        $this->expectException(\Exception::class);

        $this->validate();
    }

    /**
     * 필수 에러 코드 세 벌을 주어진 확장자로 씁니다.
     *
     * @param  string  $extension  `g7.html` 또는 `json`
     */
    private function writeErrorLayouts(string $extension): void
    {
        $body = $extension === 'json'
            ? '{"layout_name":"errors_404","components":[]}'
            : '<layout name="errors_404"><div/></layout>';

        foreach ([404, 403, 500] as $code) {
            File::put($this->templatePath."/layouts/errors/{$code}.{$extension}", $body);
        }
    }

    /**
     * `validateErrorLayouts()` 를 직접 호출합니다 (protected — 설치 전체를 태우지 않는다).
     *
     * @return bool 검증 결과
     */
    private function validate(): bool
    {
        $manager = app(TemplateManager::class);

        $method = new \ReflectionMethod($manager, 'validateErrorLayouts');
        $method->setAccessible(true);

        return $method->invoke($manager, $this->templateId, [
            'error_config' => [
                'layouts' => [
                    '404' => 'errors/404',
                    '403' => 'errors/403',
                    '500' => 'errors/500',
                ],
            ],
        ]);
    }
}
