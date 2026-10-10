<?php

namespace Tests\Feature\Extension;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\Helpers\DependencyEnricher;
use App\Http\Resources\ModuleResource;
use App\Models\Module;
use App\Models\Plugin;
use Illuminate\Http\Request;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 선택 연동 선언(`integrations`)의 화면 표시용 보강 (#639 ⑥, 결정 P10)
 *
 * 관리자 확장 상세의 「함께 쓰면 연동되는 확장」 행은 대상의 이름과 설치·활성 여부를 보여 준다. 미설치 대상도 목록에
 * 남겨야 「미설치」 배지로 무엇을 설치하면 연동되는지 알 수 있다. 저장소는 흉내 내 DB 를 건드리지 않는다.
 */
class ExtensionIntegrationsEnrichmentTest extends TestCase
{
    /**
     * 게시판 모듈은 활성, 마케팅 플러그인은 설치만, 나머지는 미설치로 흉내 냅니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $board = new Module(['identifier' => 'sirsoft-board', 'name' => ['ko' => '게시판', 'en' => 'Board'], 'status' => 'active']);
        $marketing = new Plugin(['identifier' => 'sirsoft-marketing', 'name' => ['ko' => '마케팅', 'en' => 'Marketing'], 'status' => 'inactive']);

        $this->mock(ModuleRepositoryInterface::class, function (MockInterface $mock) use ($board): void {
            $mock->shouldReceive('findByIdentifier')->andReturnUsing(fn (string $id) => $id === 'sirsoft-board' ? $board : null);
        });
        $this->mock(PluginRepositoryInterface::class, function (MockInterface $mock) use ($marketing): void {
            $mock->shouldReceive('findByIdentifier')->andReturnUsing(fn (string $id) => $id === 'sirsoft-marketing' ? $marketing : null);
        });
        $this->mock(TemplateRepositoryInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findByIdentifier')->andReturn(null);
        });
    }

    /**
     * 대상마다 이름·유형·설치·활성 여부를 싣고, 미설치 대상은 식별자를 이름으로 둔다.
     *
     * @scenario shape=enriched
     *
     * @effects integrations_are_enriched_for_display
     *
     * @return void 반환값 없음
     */
    public function test_enriches_each_target_with_install_state(): void
    {
        app()->setLocale('ko');

        $this->assertSame([
            ['identifier' => 'sirsoft-board', 'name' => '게시판', 'type' => 'module', 'is_installed' => true, 'is_active' => true],
            ['identifier' => 'sirsoft-ecommerce', 'name' => 'sirsoft-ecommerce', 'type' => 'module', 'is_installed' => false, 'is_active' => false],
            ['identifier' => 'sirsoft-marketing', 'name' => '마케팅', 'type' => 'plugin', 'is_installed' => true, 'is_active' => false],
        ], DependencyEnricher::enrichIntegrations([
            'modules' => ['sirsoft-board', 'sirsoft-ecommerce'],
            'plugins' => ['sirsoft-marketing'],
        ]));

        $this->assertSame([], DependencyEnricher::enrichIntegrations(null));
    }

    /**
     * 확장 목록·상세 응답이 매니저가 보강한 연동 목록을 그대로 싣는다.
     *
     * @scenario shape=enriched
     *
     * @effects extension_resources_expose_integrations
     *
     * @return void 반환값 없음
     */
    public function test_module_resource_exposes_integrations(): void
    {
        $integrations = DependencyEnricher::enrichIntegrations(['modules' => ['sirsoft-board']]);
        $resource = new ModuleResource(['identifier' => 'acme-shop', 'name' => 'Acme', 'integrations' => $integrations]);

        $this->assertSame($integrations, $resource->toArray(Request::create('/'))['integrations']);
        $this->assertSame($integrations, $resource->toDetailArray()['integrations']);
        $this->assertSame([], (new ModuleResource(['identifier' => 'acme-shop', 'name' => 'Acme']))->toArray(Request::create('/'))['integrations']);
    }
}
