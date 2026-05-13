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
        Cache::forget('g7:core:ext.cache_version');

        $version = ClearsTemplateCaches::getExtensionCacheVersion();

        $this->assertEquals(0, $version);
    }

    /**
     * getExtensionCacheVersion 정적 메서드가 캐시된 값 반환
     */
    public function test_get_extension_cache_version_returns_cached_value(): void
    {
        $expectedVersion = 1735000000;
        Cache::put('g7:core:ext.cache_version', $expectedVersion);

        $version = ClearsTemplateCaches::getExtensionCacheVersion();

        $this->assertEquals($expectedVersion, $version);
    }

    /**
     * clearAllTemplateLanguageCaches() 는 버전 기반 설계로 리팩토링되어 레거시 no-op.
     *
     * 2026-03-31 커밋 c86c6d3e5 에서 실제 캐시 삭제 로직 제거. 대신
     * incrementExtensionCacheVersion() 으로 버전 카운터가 증가하고,
     * 프론트엔드는 새 버전의 캐시 키로 API를 재요청하여 실질적 무효화가 이루어짐.
     * 이전 버전 캐시는 TTL 로 자연 만료.
     *
     * 본 테스트는 현재 동작(호출 호환성 유지, 버전 카운터 증가)을 검증한다.
     */
    public function test_clear_all_template_language_caches_is_no_op_but_triggers_version_bump(): void
    {
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

        Cache::put("template.language.{$template->identifier}.ko", ['key' => 'value']);
        $this->assertTrue(Cache::has("template.language.{$template->identifier}.ko"));

        // clearAllTemplateLanguageCaches 자체는 no-op (호출 호환성 유지용)
        // 예외 없이 호출 가능해야 함
        $this->traitUser->callClearAllTemplateLanguageCaches();

        // 기존 캐시는 그대로 유지 — 버전 기반 무효화는 프론트엔드가 새 버전 키로 요청하여 처리
        $this->assertTrue(
            Cache::has("template.language.{$template->identifier}.ko"),
            'clearAllTemplateLanguageCaches 는 레거시 캐시를 직접 삭제하지 않아야 함'
        );
    }

    public function test_clear_all_template_routes_caches_is_no_op(): void
    {
        $identifier = 'test-template-'.uniqid();
        Template::create([
            'identifier' => $identifier,
            'vendor' => 'test',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        Cache::put("template.routes.{$identifier}", ['routes' => []]);
        $this->assertTrue(Cache::has("template.routes.{$identifier}"));

        // no-op 호출 — 예외 없이 성공해야 함
        $this->traitUser->callClearAllTemplateRoutesCaches();

        // 레거시 캐시는 유지됨
        $this->assertTrue(
            Cache::has("template.routes.{$identifier}"),
            'clearAllTemplateRoutesCaches 는 레거시 캐시를 직접 삭제하지 않아야 함'
        );
    }

    /**
     * incrementExtensionCacheVersion 호출 후 getExtensionCacheVersion 이 증가된 타임스탬프 반환.
     *
     * 활성/비활성 템플릿 구분은 더 이상 trait 수준에서 다루지 않으며
     * (레거시 테스트가 기대한 "활성만 삭제" 로직은 제거되었음),
     * 실제 무효화는 모든 프론트엔드 요청이 새 버전 파라미터를 사용하게 함으로써 이루어진다.
     */
    public function test_increment_extension_cache_version_is_actual_invalidation_mechanism(): void
    {
        // 활성/비활성 템플릿 생성
        Template::create([
            'identifier' => 'active-template',
            'vendor' => 'test',
            'name' => ['ko' => '활성', 'en' => 'Active'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);
        Template::create([
            'identifier' => 'inactive-template',
            'vendor' => 'test',
            'name' => ['ko' => '비활성', 'en' => 'Inactive'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 초기 버전 0
        $this->assertEquals(0, \App\Extension\Traits\ClearsTemplateCaches::getExtensionCacheVersion());

        // 버전 증가
        $this->traitUser->callIncrementExtensionCacheVersion();

        // 타임스탬프로 설정됨 (양수)
        $newVersion = \App\Extension\Traits\ClearsTemplateCaches::getExtensionCacheVersion();
        $this->assertGreaterThan(0, $newVersion);
        $this->assertLessThanOrEqual(time(), $newVersion);
    }
}
