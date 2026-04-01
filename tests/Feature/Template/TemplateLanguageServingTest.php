<?php

namespace Tests\Feature\Template;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TemplateLanguageServingTest extends TestCase
{
    use RefreshDatabase;

    private Template $activeTemplate;

    private Template $inactiveTemplate;

    private TemplateService $templateService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->templateService = app(TemplateService::class);

        // sirsoft-admin_basic 템플릿 설치 및 활성화
        $this->templateService->installTemplate('sirsoft-admin_basic');
        $this->activeTemplate = Template::where('identifier', 'sirsoft-admin_basic')->first();
        $this->templateService->activateTemplate($this->activeTemplate->id);

        // 비활성화된 템플릿 생성 (테스트용)
        $this->inactiveTemplate = Template::create([
            'identifier' => 'sirsoft-user_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => 'User Basic', 'en' => 'User Basic'],
            'version' => '1.0.0',
            'description' => ['ko' => '기본 사용자 템플릿', 'en' => 'Basic user template'],
            'type' => 'user',
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // 캐시 초기화
        Cache::flush();
    }

    /**
     * 한국어 언어 파일 조회 성공 테스트
     */
    public function test_can_retrieve_korean_language_file(): void
    {
        $response = $this->getJson('/api/templates/sirsoft-admin_basic/lang/ko.json');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'common',
                'auth',
            ]);
    }

    /**
     * 영어 언어 파일 조회 성공 테스트
     */
    public function test_can_retrieve_english_language_file(): void
    {
        $response = $this->getJson('/api/templates/sirsoft-admin_basic/lang/en.json');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'common',
                'auth',
            ]);
    }

    /**
     * 지원하지 않는 로케일 요청 시 404 반환
     */
    public function test_unsupported_locale_returns_404(): void
    {
        $response = $this->getJson('/api/templates/sirsoft-admin_basic/lang/fr.json');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonFragment([
                'message' => __('templates.errors.locale_not_supported', [
                    'template' => 'sirsoft-admin_basic',
                    'locale' => 'fr',
                ]),
            ]);
    }

    /**
     * 존재하지 않는 템플릿 요청 시 404 반환
     */
    public function test_nonexistent_template_returns_404(): void
    {
        $response = $this->getJson('/api/templates/nonexistent-template/lang/ko.json');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonFragment([
                'message' => __('templates.errors.not_found', ['template' => 'nonexistent-template']),
            ]);
    }

    /**
     * 비활성화된 템플릿 요청 시 404 반환
     */
    public function test_inactive_template_returns_404(): void
    {
        $response = $this->getJson('/api/templates/sirsoft-user_basic/lang/ko.json');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonFragment([
                'message' => __('templates.errors.not_found', ['template' => 'sirsoft-user_basic']),
            ]);
    }

    /**
     * 언어 파일이 캐싱되는지 확인
     */
    public function test_language_file_is_cached(): void
    {
        $cacheKey = 'template.language.sirsoft-admin_basic.ko';

        // 첫 번째 요청
        $this->getJson('/api/templates/sirsoft-admin_basic/lang/ko.json');

        // 캐시 확인
        $this->assertTrue(Cache::has($cacheKey));

        // 캐시된 데이터 확인
        $cachedData = Cache::get($cacheKey);
        $this->assertIsArray($cachedData);
        $this->assertArrayHasKey('success', $cachedData);
        $this->assertTrue($cachedData['success']);
        $this->assertArrayHasKey('data', $cachedData);
    }

    /**
     * Cache-Control 헤더가 설정되는지 확인
     */
    public function test_cache_control_header_is_set(): void
    {
        $response = $this->getJson('/api/templates/sirsoft-admin_basic/lang/ko.json');

        $response->assertStatus(200);

        // Cache-Control 헤더 존재 여부 및 max-age 값 확인
        $this->assertTrue($response->headers->has('Cache-Control'));
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=3600', $cacheControl);
        $this->assertStringContainsString('public', $cacheControl);
    }

    /**
     * 잘못된 로케일 형식 요청 시 404 반환
     */
    public function test_invalid_locale_format_returns_404(): void
    {
        // 3자리 로케일 - 라우트 매칭 실패로 404
        $response = $this->getJson('/api/templates/sirsoft-admin_basic/lang/kor.json');
        $response->assertStatus(404);

        // 숫자 포함 로케일 - 라우트 매칭 실패로 404
        $response = $this->getJson('/api/templates/sirsoft-admin_basic/lang/k1.json');
        $response->assertStatus(404);

        // 대문자 로케일 - 라우트 매칭 실패로 404
        $response = $this->getJson('/api/templates/sirsoft-admin_basic/lang/KO.json');
        $response->assertStatus(404);
    }
}