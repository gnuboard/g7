<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Extension\TemplateManager;
use App\Services\TemplateService;
use Tests\TestCase;

/**
 * 템플릿 로드 멱등성 + 서비스 공유 인스턴스화 (C-2)
 *
 * `TemplateService` 생성자가 가드 없이 `TemplateManager::loadTemplates()` 를 호출했다.
 * 그 메서드는 공유 싱글톤의 템플릿 맵을 통째로 리셋한 뒤 디렉토리를 다시 스캔하므로,
 * 서비스가 주입될 때마다 풀스캔이 반복되고 싱글톤 상태가 매번 변형됐다.
 * `TemplateManager` 안에서 `TemplateService` 를 해석하는 재진입 경로까지 있다.
 *
 * 생성자 로드를 단순 제거할 수는 없다 — 웹/serve/test 는 CoreServiceProvider::boot 가
 * 로드를 보장하지만 그 외 콘솔 경로는 로딩을 건너뛰므로, 커맨드가 생성자 로드에 의존한다.
 * 그래서 "아직 로드하지 않았을 때만 로드하는" 멱등 진입점을 둔다.
 */
class TemplateManagerEnsureLoadedTest extends TestCase
{
    /**
     * 매니저의 템플릿 맵을 반환합니다. (protected 프로퍼티 조회)
     *
     * @param  TemplateManager  $manager  대상 매니저
     * @return array 템플릿 맵
     */
    private function templatesOf(TemplateManager $manager): array
    {
        $prop = (new \ReflectionClass($manager))->getProperty('templates');
        $prop->setAccessible(true);

        return $prop->getValue($manager);
    }

    /**
     * 계약에 멱등 로드 진입점이 선언되어 있다. (실패-먼저)
     */
    public function test_interface_declares_ensure_loaded(): void
    {
        $this->assertTrue(
            method_exists(TemplateManagerInterface::class, 'ensureLoaded'),
            '멱등 로드 진입점이 계약에 없습니다.'
        );
    }

    /**
     * ensureLoaded 는 두 번 호출해도 재스캔하지 않는다. (실패-먼저)
     */
    public function test_ensure_loaded_is_idempotent(): void
    {
        /** @var TemplateManager $manager */
        $manager = $this->app->make(TemplateManager::class);
        $manager->loadTemplates();

        $before = $this->templatesOf($manager);
        // 로드 완료 표식을 남긴 뒤에는 맵을 리셋하지 않아야 한다
        $manager->ensureLoaded();

        $this->assertSame(
            array_keys($before),
            array_keys($this->templatesOf($manager)),
            'ensureLoaded 가 이미 로드된 맵을 다시 만들었습니다.'
        );
    }

    /**
     * 명시적 loadTemplates() 는 강제 재스캔 계약을 유지한다. (비회귀 pin)
     */
    public function test_explicit_load_templates_still_rescans(): void
    {
        /** @var TemplateManager $manager */
        $manager = $this->app->make(TemplateManager::class);
        $manager->ensureLoaded();

        $prop = (new \ReflectionClass($manager))->getProperty('templates');
        $prop->setAccessible(true);
        $prop->setValue($manager, ['stale-marker' => 'x']);

        $manager->loadTemplates();

        $this->assertArrayNotHasKey(
            'stale-marker',
            $this->templatesOf($manager),
            '명시적 재스캔이 기존 맵을 갱신하지 않았습니다.'
        );
    }

    /**
     * TemplateService 생성자는 공유 싱글톤의 템플릿 맵을 리셋하지 않는다. (실패-먼저)
     */
    public function test_service_construction_does_not_reset_shared_map(): void
    {
        /** @var TemplateManager $manager */
        $manager = $this->app->make(TemplateManager::class);
        $manager->loadTemplates();

        $prop = (new \ReflectionClass($manager))->getProperty('templates');
        $prop->setAccessible(true);
        $prop->setValue($manager, ['sentinel-template' => ['identifier' => 'sentinel-template']]);

        $this->app->forgetInstance(TemplateService::class);
        $this->app->make(TemplateService::class);

        $this->assertArrayHasKey(
            'sentinel-template',
            $this->templatesOf($manager),
            'TemplateService 주입이 공유 템플릿 맵을 통째로 재스캔했습니다.'
        );
    }

    /**
     * TemplateService 는 공유 인스턴스로 해석된다. (실패-먼저)
     */
    public function test_template_service_is_shared_instance(): void
    {
        $this->assertSame(
            $this->app->make(TemplateService::class),
            $this->app->make(TemplateService::class),
            'TemplateService 가 해석할 때마다 새로 만들어집니다.'
        );
    }
}
