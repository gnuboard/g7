<?php

namespace Tests\Unit\Extension;

use App\Extension\AbstractModule;
use App\Extension\ModuleManager;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/**
 * resolveForceUpdateSource() 회귀 테스트.
 *
 * 운영 정책 (2026-04-14): --force 시 번들 우선, 번들 없으면 GitHub 폴백.
 *
 * 본 테스트는 ModuleManager 의 private 메서드를 Reflection으로 직접 검증한다.
 * AbstractModule 은 getIdentifier/getVendor 가 final 이므로 Mockery 로 모킹한다.
 */
class ModuleForceUpdateSourceTest extends TestCase
{
    /**
     * Mockery 기대를 검증하고 닫은 뒤 부모 정리를 실행한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * private 메서드 resolveForceUpdateSource 를 리플렉션으로 호출한다.
     *
     * @param  ModuleManager  $manager  대상 매니저
     * @param  string  $identifier  모듈 식별자
     * @return string|null 판정된 출처(bundled 또는 github), 쓸 출처가 없으면 null
     */
    private function invokeResolve(ModuleManager $manager, string $identifier): ?string
    {
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('resolveForceUpdateSource');
        $method->setAccessible(true);

        return $method->invoke($manager, $identifier);
    }

    /**
     * 매니저의 번들 모듈 목록을 시험 값으로 바꾼다.
     *
     * @param  ModuleManager  $manager  대상 매니저
     * @param  array<string, array<string, mixed>>  $bundled  식별자별 번들 메타
     * @return void 반환값 없음
     */
    private function setBundledModules(ModuleManager $manager, array $bundled): void
    {
        $reflection = new ReflectionClass($manager);
        $prop = $reflection->getProperty('bundledModules');
        $prop->setAccessible(true);
        $prop->setValue($manager, $bundled);
    }

    /**
     * 매니저가 로드한 모듈 목록을 시험 값으로 바꾼다.
     *
     * @param  ModuleManager  $manager  대상 매니저
     * @param  array<string, AbstractModule>  $modules  식별자별 모듈 인스턴스
     * @return void 반환값 없음
     */
    private function setLoadedModules(ModuleManager $manager, array $modules): void
    {
        $reflection = new ReflectionClass($manager);
        $prop = $reflection->getProperty('modules');
        $prop->setAccessible(true);
        $prop->setValue($manager, $modules);
    }

    /**
     * getGithubUrl 만 답하는 모듈 목을 만든다.
     *
     * @param  string|null  $githubUrl  모듈이 답할 GitHub 주소, 없으면 null
     * @return AbstractModule 모듈 목
     */
    private function makeFakeModule(?string $githubUrl): AbstractModule
    {
        $mock = Mockery::mock(AbstractModule::class);
        $mock->shouldReceive('getGithubUrl')->andReturn($githubUrl);

        return $mock;
    }

    /**
     * 번들 목록에 있는 모듈은 강제 업데이트 출처가 bundled 로 판정된다.
     *
     * @scenario case=bundled_only
     *
     * @effects force_update_source_is_bundled_when_bundle_exists
     *
     * @return void 반환값 없음
     */
    public function test_resolves_bundled_when_in_memory_cache(): void
    {
        $manager = app(ModuleManager::class);
        $this->setBundledModules($manager, [
            'test-mod' => ['version' => '1.0.0'],
        ]);

        $this->assertSame('bundled', $this->invokeResolve($manager, 'test-mod'));
    }

    /**
     * 번들이 없고 모듈에 GitHub 주소가 있으면 출처가 github 로 판정된다.
     *
     * @scenario case=github_only
     *
     * @effects force_update_source_falls_back_to_github_without_bundle
     *
     * @return void 반환값 없음
     */
    public function test_resolves_github_when_no_bundle_but_github_url_present(): void
    {
        $manager = app(ModuleManager::class);
        $this->setBundledModules($manager, []);
        $this->setLoadedModules($manager, [
            'test-mod' => $this->makeFakeModule('https://github.com/test/repo'),
        ]);

        $this->assertSame('github', $this->invokeResolve($manager, 'test-mod'));
    }

    /**
     * 번들도 GitHub 주소도 없으면 출처 판정이 null 이다.
     *
     * @scenario case=no_source
     *
     * @effects force_update_source_is_null_without_bundle_or_github
     *
     * @return void 반환값 없음
     */
    public function test_returns_null_when_neither_bundle_nor_github_available(): void
    {
        $manager = app(ModuleManager::class);
        $this->setBundledModules($manager, []);
        $this->setLoadedModules($manager, [
            'test-mod' => $this->makeFakeModule(null),
        ]);

        $this->assertNull($this->invokeResolve($manager, 'test-mod'));
    }

    /**
     * 번들과 GitHub 주소가 둘 다 있으면 bundled 가 먼저다.
     *
     * @scenario case=bundled_and_github
     *
     * @effects force_update_source_prefers_bundled_over_github
     *
     * @return void 반환값 없음
     */
    public function test_prefers_bundled_over_github_when_both_exist(): void
    {
        $manager = app(ModuleManager::class);
        // 번들과 GitHub 모두 존재하는 시나리오 — 운영 정책상 bundled 우선
        $this->setBundledModules($manager, [
            'test-mod' => ['version' => '1.0.0'],
        ]);
        $this->setLoadedModules($manager, [
            'test-mod' => $this->makeFakeModule('https://github.com/test/repo'),
        ]);

        $this->assertSame('bundled', $this->invokeResolve($manager, 'test-mod'));
    }
}
