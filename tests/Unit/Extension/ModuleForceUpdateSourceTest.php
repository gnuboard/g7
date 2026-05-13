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
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function invokeResolve(ModuleManager $manager, string $identifier): ?string
    {
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('resolveForceUpdateSource');
        $method->setAccessible(true);

        return $method->invoke($manager, $identifier);
    }

    private function setBundledModules(ModuleManager $manager, array $bundled): void
    {
        $reflection = new ReflectionClass($manager);
        $prop = $reflection->getProperty('bundledModules');
        $prop->setAccessible(true);
        $prop->setValue($manager, $bundled);
    }

    private function setLoadedModules(ModuleManager $manager, array $modules): void
    {
        $reflection = new ReflectionClass($manager);
        $prop = $reflection->getProperty('modules');
        $prop->setAccessible(true);
        $prop->setValue($manager, $modules);
    }

    private function makeFakeModule(?string $githubUrl): AbstractModule
    {
        $mock = Mockery::mock(AbstractModule::class);
        $mock->shouldReceive('getGithubUrl')->andReturn($githubUrl);

        return $mock;
    }

    public function test_resolves_bundled_when_in_memory_cache(): void
    {
        $manager = app(ModuleManager::class);
        $this->setBundledModules($manager, [
            'test-mod' => ['version' => '1.0.0'],
        ]);

        $this->assertSame('bundled', $this->invokeResolve($manager, 'test-mod'));
    }

    public function test_resolves_github_when_no_bundle_but_github_url_present(): void
    {
        $manager = app(ModuleManager::class);
        $this->setBundledModules($manager, []);
        $this->setLoadedModules($manager, [
            'test-mod' => $this->makeFakeModule('https://github.com/test/repo'),
        ]);

        $this->assertSame('github', $this->invokeResolve($manager, 'test-mod'));
    }

    public function test_returns_null_when_neither_bundle_nor_github_available(): void
    {
        $manager = app(ModuleManager::class);
        $this->setBundledModules($manager, []);
        $this->setLoadedModules($manager, [
            'test-mod' => $this->makeFakeModule(null),
        ]);

        $this->assertNull($this->invokeResolve($manager, 'test-mod'));
    }

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
