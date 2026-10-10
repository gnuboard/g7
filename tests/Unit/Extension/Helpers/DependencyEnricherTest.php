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
    /**
     * enrich: 빈 의존성은 빈 배열을 반환합니다.
     *
     * @scenario case=empty
     *
     * @effects dependency_status_reported
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function enrich_빈_의존성은_빈_배열을_반환합니다(): void
    {
        $result = DependencyEnricher::enrich([]);

        $this->assertSame([], $result);
    }

    /**
     * enrich: modules plugins 키가 없으면 빈 배열을 반환합니다.
     *
     * @scenario case=missing_type_keys
     *
     * @effects dependency_status_reported
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function enrich_modules_plugins_키가_없으면_빈_배열을_반환합니다(): void
    {
        $this->assertSame([], DependencyEnricher::enrich(['foo' => 'bar']));
    }

    /**
     * enrich: 미설치 모듈은 installed version null 과 is met false를 반환합니다.
     *
     * @scenario case=not_installed
     *
     * @effects dependency_status_reported
     *
     * @return void 반환값 없음
     */
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

    /**
     * enrich: 설치 활성 모듈은 is met true를 반환합니다.
     *
     * @scenario case=installed_active
     *
     * @effects dependency_status_reported
     *
     * @return void 반환값 없음
     */
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

    /**
     * enrich: 설치되었지만 비활성 모듈은 is met false를 반환합니다.
     *
     * @scenario case=installed_inactive
     *
     * @effects dependency_status_reported
     *
     * @return void 반환값 없음
     */
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

    /**
     * enrich: 버전 미충족 모듈은 is met false를 반환합니다.
     *
     * @scenario case=version_unmet
     *
     * @effects dependency_status_reported
     *
     * @return void 반환값 없음
     */
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

    /**
     * enrich: modules 와 plugins를 순서대로 반환합니다.
     *
     * @scenario case=type_order
     *
     * @effects dependency_status_reported
     *
     * @return void 반환값 없음
     */
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

    /**
     * enrich: 다국어 이름 배열에서 현재 로케일을 선택합니다.
     *
     * @scenario case=localized_name
     *
     * @effects dependency_status_reported
     *
     * @return void 반환값 없음
     */
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

    /**
     * 이름이 빈 문자열·빈 다국어 값이면 식별자를 이름으로 쓴다 (7.1.0 전수 실측 O-F10).
     *
     * 화면은 이 이름을 그대로 카드·상세·확인 창에 보여 주므로, 빈 문자열이 내려가면 이름 없는 칸만 남는다.
     *
     * @scenario case=blank_name
     *
     * @effects blank_dependency_name_falls_back_to_identifier
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function enrich_이름이_비면_식별자를_이름으로_씁니다(): void
    {
        $blankString = new Module(['version' => '1.0.0']);
        // DB 에 JSON 빈 문자열("")로 저장된 이름 — 배열 캐스트를 거쳐 다국어 배열이 아닌 빈 문자열로 읽힌다
        $blankString->setRawAttributes(['name' => '""', 'version' => '1.0.0']);
        $blankLocalized = new Module(['name' => ['ko' => '', 'en' => ''], 'version' => '1.0.0']);

        $moduleRepo = $this->createMock(ModuleRepositoryInterface::class);
        $moduleRepo->method('findByIdentifier')->willReturnMap([
            ['acme-blank-string', $blankString],
            ['acme-blank-localized', $blankLocalized],
        ]);
        $moduleRepo->method('findActiveByIdentifier')->willReturn(null);
        $this->app->instance(ModuleRepositoryInterface::class, $moduleRepo);
        $this->app->instance(PluginRepositoryInterface::class, $this->createMock(PluginRepositoryInterface::class));

        $result = DependencyEnricher::enrich([
            'modules' => ['acme-blank-string' => '*', 'acme-blank-localized' => '*'],
        ]);

        $this->assertSame('acme-blank-string', $result[0]['name']);
        $this->assertSame('acme-blank-localized', $result[1]['name']);
    }
}
