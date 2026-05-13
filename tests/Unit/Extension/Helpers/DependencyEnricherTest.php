<?php

namespace Tests\Unit\Extension\Helpers;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Extension\Helpers\DependencyEnricher;
use App\Models\Module;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DependencyEnricherTest extends TestCase
{
    #[Test]
    public function enrich_빈_의존성은_빈_배열을_반환합니다(): void
    {
        $result = DependencyEnricher::enrich([]);

        $this->assertSame([], $result);
    }

    #[Test]
    public function enrich_modules_plugins_키가_없으면_빈_배열을_반환합니다(): void
    {
        $this->assertSame([], DependencyEnricher::enrich(['foo' => 'bar']));
    }

    #[Test]
    public function enrich_미설치_모듈은_installed_version_null_과_is_met_false를_반환합니다(): void
    {
        $moduleRepo = $this->createMock(ModuleRepositoryInterface::class);
        $moduleRepo->method('findByIdentifier')->willReturn(null);
        $moduleRepo->method('findActiveByIdentifier')->willReturn(null);
        $this->app->instance(ModuleRepositoryInterface::class, $moduleRepo);

        $pluginRepo = $this->createMock(PluginRepositoryInterface::class);
        $this->app->instance(PluginRepositoryInterface::class, $pluginRepo);

        $result = DependencyEnricher::enrich([
            'modules' => ['sirsoft-ecommerce' => '>=1.0.0'],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('sirsoft-ecommerce', $result[0]['identifier']);
        $this->assertSame('sirsoft-ecommerce', $result[0]['name']);
        $this->assertSame('module', $result[0]['type']);
        $this->assertSame('>=1.0.0', $result[0]['required_version']);
        $this->assertNull($result[0]['installed_version']);
        $this->assertFalse($result[0]['is_active']);
        $this->assertFalse($result[0]['is_met']);
    }

    #[Test]
    public function enrich_설치_활성_모듈은_is_met_true를_반환합니다(): void
    {
        $module = new Module(['name' => ['ko' => '상점', 'en' => 'Shop'], 'version' => '1.2.0']);

        $moduleRepo = $this->createMock(ModuleRepositoryInterface::class);
        $moduleRepo->method('findByIdentifier')->willReturn($module);
        $moduleRepo->method('findActiveByIdentifier')->willReturn($module);
        $this->app->instance(ModuleRepositoryInterface::class, $moduleRepo);

        $pluginRepo = $this->createMock(PluginRepositoryInterface::class);
        $this->app->instance(PluginRepositoryInterface::class, $pluginRepo);

        $result = DependencyEnricher::enrich([
            'modules' => ['sirsoft-ecommerce' => '>=1.0.0'],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('1.2.0', $result[0]['installed_version']);
        $this->assertTrue($result[0]['is_active']);
        $this->assertTrue($result[0]['is_met']);
    }

    #[Test]
    public function enrich_설치되었지만_비활성_모듈은_is_met_false를_반환합니다(): void
    {
        $module = new Module(['name' => ['ko' => '상점'], 'version' => '1.2.0']);

        $moduleRepo = $this->createMock(ModuleRepositoryInterface::class);
        $moduleRepo->method('findByIdentifier')->willReturn($module);
        $moduleRepo->method('findActiveByIdentifier')->willReturn(null);
        $this->app->instance(ModuleRepositoryInterface::class, $moduleRepo);

        $pluginRepo = $this->createMock(PluginRepositoryInterface::class);
        $this->app->instance(PluginRepositoryInterface::class, $pluginRepo);

        $result = DependencyEnricher::enrich([
            'modules' => ['sirsoft-ecommerce' => '>=1.0.0'],
        ]);

        $this->assertSame('1.2.0', $result[0]['installed_version']);
        $this->assertFalse($result[0]['is_active']);
        $this->assertFalse($result[0]['is_met']);
    }

    #[Test]
    public function enrich_버전_미충족_모듈은_is_met_false를_반환합니다(): void
    {
        $module = new Module(['name' => ['ko' => '상점'], 'version' => '0.9.0']);

        $moduleRepo = $this->createMock(ModuleRepositoryInterface::class);
        $moduleRepo->method('findByIdentifier')->willReturn($module);
        $moduleRepo->method('findActiveByIdentifier')->willReturn($module);
        $this->app->instance(ModuleRepositoryInterface::class, $moduleRepo);

        $pluginRepo = $this->createMock(PluginRepositoryInterface::class);
        $this->app->instance(PluginRepositoryInterface::class, $pluginRepo);

        $result = DependencyEnricher::enrich([
            'modules' => ['sirsoft-ecommerce' => '>=1.0.0'],
        ]);

        $this->assertSame('0.9.0', $result[0]['installed_version']);
        $this->assertTrue($result[0]['is_active']);
        $this->assertFalse($result[0]['is_met']);
    }

    #[Test]
    public function enrich_modules_와_plugins를_순서대로_반환합니다(): void
    {
        $moduleRepo = $this->createMock(ModuleRepositoryInterface::class);
        $moduleRepo->method('findByIdentifier')->willReturn(null);
        $moduleRepo->method('findActiveByIdentifier')->willReturn(null);
        $this->app->instance(ModuleRepositoryInterface::class, $moduleRepo);

        $pluginRepo = $this->createMock(PluginRepositoryInterface::class);
        $pluginRepo->method('findByIdentifier')->willReturn(null);
        $pluginRepo->method('findActiveByIdentifier')->willReturn(null);
        $this->app->instance(PluginRepositoryInterface::class, $pluginRepo);

        $result = DependencyEnricher::enrich([
            'modules' => ['sirsoft-board' => '>=1.0.0'],
            'plugins' => ['sirsoft-tosspayments' => '>=0.1.0'],
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('module', $result[0]['type']);
        $this->assertSame('sirsoft-board', $result[0]['identifier']);
        $this->assertSame('plugin', $result[1]['type']);
        $this->assertSame('sirsoft-tosspayments', $result[1]['identifier']);
    }

    #[Test]
    public function enrich_다국어_이름_배열에서_현재_로케일을_선택합니다(): void
    {
        app()->setLocale('ko');

        $module = new Module(['name' => ['ko' => '게시판', 'en' => 'Board'], 'version' => '1.0.0']);

        $moduleRepo = $this->createMock(ModuleRepositoryInterface::class);
        $moduleRepo->method('findByIdentifier')->willReturn($module);
        $moduleRepo->method('findActiveByIdentifier')->willReturn($module);
        $this->app->instance(ModuleRepositoryInterface::class, $moduleRepo);

        $pluginRepo = $this->createMock(PluginRepositoryInterface::class);
        $this->app->instance(PluginRepositoryInterface::class, $pluginRepo);

        $result = DependencyEnricher::enrich([
            'modules' => ['sirsoft-board' => '*'],
        ]);

        $this->assertSame('게시판', $result[0]['name']);
    }
}
