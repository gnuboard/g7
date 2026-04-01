<?php

namespace Tests\Unit\Repositories;

use App\Enums\LayoutSourceType;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Repositories\LayoutRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LayoutRepositoryCacheTest extends TestCase
{
    use RefreshDatabase;

    private LayoutRepository $repository;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(LayoutRepository::class);
        $this->template = Template::factory()->create();
    }

    #[Test]
    public function get_by_source_identifier_returns_module_layouts(): void
    {
        // Given: 모듈 소스 타입 레이아웃 생성
        $moduleLayout = TemplateLayout::factory()
            ->fromModule('sirsoft-ecommerce')
            ->create([
                'template_id' => $this->template->id,
                'name' => 'sirsoft-ecommerce.admin_products_index',
            ]);

        // 플러그인 소스 타입 레이아웃도 생성 (조회에서 제외되어야 함)
        TemplateLayout::factory()
            ->fromPlugin('sirsoft-tosspayments')
            ->create([
                'template_id' => $this->template->id,
                'name' => 'sirsoft-tosspayments.plugin_settings',
            ]);

        // When: 모듈 소스 타입으로 조회
        $result = $this->repository->getBySourceIdentifier('sirsoft-ecommerce', LayoutSourceType::Module);

        // Then: 모듈 레이아웃만 반환
        $this->assertCount(1, $result);
        $this->assertEquals($moduleLayout->id, $result->first()->id);
        $this->assertEquals(LayoutSourceType::Module, $result->first()->source_type);
    }

    #[Test]
    public function get_by_source_identifier_returns_plugin_layouts(): void
    {
        // Given: 플러그인 소스 타입 레이아웃 생성
        $pluginLayout = TemplateLayout::factory()
            ->fromPlugin('sirsoft-tosspayments')
            ->create([
                'template_id' => $this->template->id,
                'name' => 'sirsoft-tosspayments.plugin_settings',
            ]);

        // 모듈 소스 타입 레이아웃도 생성 (조회에서 제외되어야 함)
        TemplateLayout::factory()
            ->fromModule('sirsoft-ecommerce')
            ->create([
                'template_id' => $this->template->id,
                'name' => 'sirsoft-ecommerce.admin_products_index',
            ]);

        // When: 플러그인 소스 타입으로 조회
        $result = $this->repository->getBySourceIdentifier('sirsoft-tosspayments', LayoutSourceType::Plugin);

        // Then: 플러그인 레이아웃만 반환
        $this->assertCount(1, $result);
        $this->assertEquals($pluginLayout->id, $result->first()->id);
        $this->assertEquals(LayoutSourceType::Plugin, $result->first()->source_type);
    }

    #[Test]
    public function get_by_module_identifier_does_not_find_plugin_layouts(): void
    {
        // Given: 플러그인 소스 타입 레이아웃만 존재
        TemplateLayout::factory()
            ->fromPlugin('sirsoft-tosspayments')
            ->create([
                'template_id' => $this->template->id,
                'name' => 'sirsoft-tosspayments.plugin_settings',
            ]);

        // When: 기존 getByModuleIdentifier로 조회
        $result = $this->repository->getByModuleIdentifier('sirsoft-tosspayments');

        // Then: 플러그인 레이아웃은 찾을 수 없음 (source_type이 Module으로 하드코딩)
        $this->assertCount(0, $result);
    }

    #[Test]
    public function get_by_source_identifier_finds_plugin_layouts_correctly(): void
    {
        // Given: 플러그인 소스 타입 레이아웃만 존재
        TemplateLayout::factory()
            ->fromPlugin('sirsoft-tosspayments')
            ->create([
                'template_id' => $this->template->id,
                'name' => 'sirsoft-tosspayments.plugin_settings',
            ]);

        // When: 새 getBySourceIdentifier로 플러그인 타입 지정하여 조회
        $result = $this->repository->getBySourceIdentifier('sirsoft-tosspayments', LayoutSourceType::Plugin);

        // Then: 플러그인 레이아웃을 올바르게 찾음
        $this->assertCount(1, $result);
        $this->assertEquals('sirsoft-tosspayments.plugin_settings', $result->first()->name);
    }

    #[Test]
    public function plugin_layout_cache_invalidation_clears_layout_service_cache(): void
    {
        // Given: 플러그인 레이아웃이 LayoutService 캐시에 저장되어 있음
        $pluginLayout = TemplateLayout::factory()
            ->fromPlugin('sirsoft-tosspayments')
            ->create([
                'template_id' => $this->template->id,
                'name' => 'sirsoft-tosspayments.plugin_settings',
            ]);

        $cacheKey = "template.{$this->template->id}.layout.sirsoft-tosspayments.plugin_settings";
        Cache::put($cacheKey, ['version' => '1.0.0', 'components' => []], 3600);

        // 캐시가 존재하는지 확인
        $this->assertNotNull(Cache::get($cacheKey));

        // When: getBySourceIdentifier로 플러그인 레이아웃을 찾은 후 캐시 삭제
        $layouts = $this->repository->getBySourceIdentifier('sirsoft-tosspayments', LayoutSourceType::Plugin);

        foreach ($layouts as $layout) {
            Cache::forget("template.{$layout->template_id}.layout.{$layout->name}");
        }

        // Then: 캐시가 삭제됨
        $this->assertNull(Cache::get($cacheKey));
    }

    #[Test]
    public function versioned_public_layout_cache_key_is_cleared(): void
    {
        // Given: PublicLayoutController 형식의 버전 포함 캐시 키가 존재
        $cacheVersion = time();
        Cache::put('extension_cache_version', $cacheVersion);

        $cacheKey = "layout.sirsoft-admin_basic.sirsoft-tosspayments.plugin_settings.v{$cacheVersion}";
        Cache::put($cacheKey, ['version' => '1.0.0', 'components' => []], 3600);

        // 캐시가 존재하는지 확인
        $this->assertNotNull(Cache::get($cacheKey));

        // When: 현재 캐시 버전을 읽어 올바른 캐시 키를 삭제
        $currentVersion = (int) Cache::get('extension_cache_version', 0);
        Cache::forget("layout.sirsoft-admin_basic.sirsoft-tosspayments.plugin_settings.v{$currentVersion}");

        // Then: 버전 포함 캐시가 삭제됨
        $this->assertNull(Cache::get($cacheKey));
    }
}
