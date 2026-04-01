<?php

namespace Tests\Unit\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ClearsTemplateCaches trait 테스트
 *
 * 모듈/플러그인 활성화/비활성화 시 캐시 무효화 기능을 테스트합니다.
 */
class ClearsTemplateCachesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * trait을 사용하는 테스트용 클래스
     */
    private object $traitUser;

    protected function setUp(): void
    {
        parent::setUp();

        // trait을 사용하는 익명 클래스 생성
        $this->traitUser = new class
        {
            use ClearsTemplateCaches;

            // protected 메서드를 public으로 노출
            public function callIncrementExtensionCacheVersion(): void
            {
                $this->incrementExtensionCacheVersion();
            }

            public function callClearAllTemplateLanguageCaches(): void
            {
                $this->clearAllTemplateLanguageCaches();
            }

            public function callClearAllTemplateRoutesCaches(): void
            {
                $this->clearAllTemplateRoutesCaches();
            }
        };

        // 캐시 초기화
        Cache::flush();
    }

    /**
     * 캐시 버전 증가 테스트
     */
    public function test_increment_extension_cache_version(): void
    {
        // 초기 상태: 캐시 버전이 0
        $this->assertEquals(0, ClearsTemplateCaches::getExtensionCacheVersion());

        // 캐시 버전 증가
        $beforeTime = time();
        $this->traitUser->callIncrementExtensionCacheVersion();
        $afterTime = time();

        // 캐시 버전이 현재 타임스탬프로 설정됨
        $version = ClearsTemplateCaches::getExtensionCacheVersion();
        $this->assertGreaterThanOrEqual($beforeTime, $version);
        $this->assertLessThanOrEqual($afterTime, $version);
    }

    /**
     * 캐시 버전 연속 증가 테스트
     */
    public function test_increment_extension_cache_version_multiple_times(): void
    {
        // 첫 번째 증가
        $this->traitUser->callIncrementExtensionCacheVersion();
        $firstVersion = ClearsTemplateCaches::getExtensionCacheVersion();

        // 1초 대기 (time() 기반이므로)
        sleep(1);

        // 두 번째 증가
        $this->traitUser->callIncrementExtensionCacheVersion();
        $secondVersion = ClearsTemplateCaches::getExtensionCacheVersion();

        // 두 번째 버전이 첫 번째보다 크거나 같음 (동일 초 내에서는 같을 수 있음)
        $this->assertGreaterThanOrEqual($firstVersion, $secondVersion);
    }

    /**
     * getExtensionCacheVersion 정적 메서드 테스트
     */
    public function test_get_extension_cache_version_returns_zero_when_not_set(): void
    {
        Cache::forget('extension_cache_version');

        $version = ClearsTemplateCaches::getExtensionCacheVersion();

        $this->assertEquals(0, $version);
    }

    /**
     * getExtensionCacheVersion 정적 메서드가 캐시된 값 반환
     */
    public function test_get_extension_cache_version_returns_cached_value(): void
    {
        $expectedVersion = 1735000000;
        Cache::put('extension_cache_version', $expectedVersion);

        $version = ClearsTemplateCaches::getExtensionCacheVersion();

        $this->assertEquals($expectedVersion, $version);
    }

    /**
     * 템플릿 언어 캐시 무효화 테스트
     */
    public function test_clear_all_template_language_caches(): void
    {
        // 활성 템플릿 생성 - 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        $identifier = 'test-template-'.uniqid();
        $template = Template::create([
            'identifier' => $identifier,
            'vendor' => 'test',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 언어 캐시 설정
        Cache::put("template.language.{$template->identifier}.ko", ['key' => 'value']);
        Cache::put("template.language.{$template->identifier}.en", ['key' => 'value']);

        // 캐시 존재 확인
        $this->assertTrue(Cache::has("template.language.{$template->identifier}.ko"));
        $this->assertTrue(Cache::has("template.language.{$template->identifier}.en"));

        // 캐시 무효화
        $this->traitUser->callClearAllTemplateLanguageCaches();

        // 캐시가 삭제됨
        $this->assertFalse(Cache::has("template.language.{$template->identifier}.ko"));
        $this->assertFalse(Cache::has("template.language.{$template->identifier}.en"));
    }

    /**
     * 템플릿 routes 캐시 무효화 테스트
     */
    public function test_clear_all_template_routes_caches(): void
    {
        // 활성 템플릿 생성 - 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        $identifier = 'test-template-'.uniqid();
        $template = Template::create([
            'identifier' => $identifier,
            'vendor' => 'test',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // routes 캐시 설정
        Cache::put("template.routes.{$template->identifier}", ['routes' => []]);

        // 캐시 존재 확인
        $this->assertTrue(Cache::has("template.routes.{$template->identifier}"));

        // 캐시 무효화
        $this->traitUser->callClearAllTemplateRoutesCaches();

        // 캐시가 삭제됨
        $this->assertFalse(Cache::has("template.routes.{$template->identifier}"));
    }

    /**
     * 비활성 템플릿의 캐시는 무효화하지 않음
     */
    public function test_clear_caches_only_affects_active_templates(): void
    {
        // 활성 템플릿
        $activeTemplate = Template::create([
            'identifier' => 'active-template',
            'vendor' => 'test',
            'name' => ['ko' => '활성', 'en' => 'Active'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 비활성 템플릿
        $inactiveTemplate = Template::create([
            'identifier' => 'inactive-template',
            'vendor' => 'test',
            'name' => ['ko' => '비활성', 'en' => 'Inactive'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 두 템플릿의 언어 캐시 설정
        Cache::put("template.language.{$activeTemplate->identifier}.ko", ['key' => 'active']);
        Cache::put("template.language.{$inactiveTemplate->identifier}.ko", ['key' => 'inactive']);

        // 캐시 무효화
        $this->traitUser->callClearAllTemplateLanguageCaches();

        // 활성 템플릿 캐시만 삭제됨
        $this->assertFalse(Cache::has("template.language.{$activeTemplate->identifier}.ko"));
        // 비활성 템플릿 캐시는 유지됨
        $this->assertTrue(Cache::has("template.language.{$inactiveTemplate->identifier}.ko"));
    }
}
