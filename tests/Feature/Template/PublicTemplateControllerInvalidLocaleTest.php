<?php

namespace Tests\Feature\Template;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 공개 템플릿 언어 파일 — 서비스의 형식 위반 사유를 404 로 응답 (#148)
 *
 * 라우트 형식을 언어팩 규칙(BCP 47)으로 넓히면 서비스 1단계 형식 검사(20자 상한 등)가 실패 사유를 내는 경로가
 * 컨트롤러까지 도달한다 — match 에 사유가 없어 default 500 이었다.
 */
class PublicTemplateControllerInvalidLocaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 서비스가 `invalid_locale` 을 돌려주면 500 이 아니라 404 로 응답한다.
     *
     * @scenario shape=over_length
     *
     * @effects invalid_locale_maps_to_404
     *
     * @return void 반환값 없음
     */
    public function test_serve_language_maps_invalid_locale_to_404(): void
    {
        Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);
        Cache::flush();

        $service = \Mockery::mock(app(TemplateService::class));
        $service->shouldReceive('getLanguageDataWithModules')
            ->andReturn(['success' => false, 'data' => null, 'error' => 'invalid_locale']);
        $this->app->instance(TemplateService::class, $service);

        $response = $this->getJson('/api/templates/sirsoft-admin_basic/lang/en.json');

        $response->assertStatus(404);
        $this->assertSame(
            __('templates.errors.locale_not_supported', ['template' => 'sirsoft-admin_basic', 'locale' => 'en']),
            $response->json('message')
        );
    }
}
