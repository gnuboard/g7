<?php

namespace Tests\Feature\Template;

use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 템플릿 언어 파일 서빙 — 언어팩 설치 규칙(BCP 47) 로케일 (#148)
 *
 * 라우트·서비스가 소문자 두 글자만 받아 `fil`·`fil-PH`·`zh-Hant`·`es-419` 언어팩을 켜도 언어 파일이 404 였다.
 * 형식 판정은 `App\Support\LocaleTag` 하나로 통일하고, 형식은 맞지만 지원하지 않는 언어는 서비스 단계에서 404 다.
 */
class TemplateLanguageBcp47ServingTest extends TestCase
{
    use RefreshDatabase;

    private TemplateService $templateService;

    /**
     * sirsoft-admin_basic 템플릿을 설치·활성화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->templateService = app(TemplateService::class);
        $this->templateService->installTemplate('sirsoft-admin_basic');
        $template = Template::where('identifier', 'sirsoft-admin_basic')->first();
        $this->templateService->activateTemplate($template->id);

        Cache::flush();
    }

    /**
     * 형식에 맞지 않는 로케일은 404 다 — 세 글자 `kor` 는 형식은 통과하지만 지원 언어가 아니라 서비스 단계에서 404.
     *
     * @scenario shape=malformed
     *
     * @effects invalid_locale_maps_to_404
     *
     * @return void 반환값 없음
     */
    public function test_malformed_or_unsupported_locale_returns_404(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'fil-PH']]);

        // 형식은 통과, 지원 언어가 아니어서 locale_not_supported 404
        $this->getJson('/api/templates/sirsoft-admin_basic/lang/kor.json')->assertStatus(404);
        // 정규형이 아닌 지역 표기(소문자 지역·밑줄) - 라우트 매칭 실패로 404
        $this->getJson('/api/templates/sirsoft-admin_basic/lang/fil-ph.json')->assertStatus(404);
        $this->getJson('/api/templates/sirsoft-admin_basic/lang/fil_PH.json')->assertStatus(404);
    }

    /**
     * 언어팩으로 켠 세 글자 언어(fil)의 언어 파일이 200 으로 서빙된다.
     *
     * 템플릿 자체 lang/fil.json 은 없고 활성 언어팩이 번역을 공급하는 경로다(ja 와 같은 조건).
     *
     * @scenario shape=three_letter
     *
     * @effects template_lang_route_serves_bcp47_pack_locale
     *
     * @return void 반환값 없음
     */
    public function test_three_letter_pack_locale_is_served(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'fil']]);

        $this->getJson('/api/templates/sirsoft-admin_basic/lang/fil.json')->assertStatus(200);
        $this->getJson('/api/templates/sirsoft-admin_basic/lang/fil')->assertStatus(200);
    }

    /**
     * 지역·문자 표기·숫자 지역이 붙은 언어 코드도 200 으로 서빙된다.
     *
     * @scenario shape=region_tagged
     *
     * @effects template_lang_route_serves_bcp47_pack_locale
     *
     * @return void 반환값 없음
     */
    public function test_region_and_script_tagged_pack_locales_are_served(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'fil-PH', 'zh-Hant', 'es-419']]);

        foreach (['fil-PH', 'zh-Hant', 'es-419'] as $locale) {
            $this->getJson("/api/templates/sirsoft-admin_basic/lang/{$locale}.json")
                ->assertStatus(200);
        }
    }

    /**
     * 서비스 1단계 형식 검사가 언어팩 규칙을 따르고 컬럼 길이(20자)를 넘으면 거부한다.
     *
     * @scenario shape=over_length
     *
     * @effects template_lang_route_serves_bcp47_pack_locale, invalid_locale_maps_to_404
     *
     * @return void 반환값 없음
     */
    public function test_get_language_file_path_accepts_pack_only_bcp47_locale(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'zh-Hant']]);

        $result = $this->templateService->getLanguageFilePath('sirsoft-admin_basic', 'zh-Hant');
        $this->assertTrue($result['success']);
        $this->assertNull($result['langPath']);
        $this->assertNull($result['error']);

        $result = $this->templateService->getLanguageFilePath('sirsoft-admin_basic', 'fil-Latn-419-abcdefgh');
        $this->assertFalse($result['success']);
        $this->assertSame('invalid_locale', $result['error']);
    }
}
