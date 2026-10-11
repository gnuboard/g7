<?php

namespace Tests\Feature\Repositories;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Models\Template;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 템플릿을 불러오지 않은 프로세스(콘솔)에서도 템플릿 역의존성을 판정하는지 검증합니다 (#145 F4, D4).
 *
 * 콘솔 부팅은 성능을 위해 템플릿 로딩을 건너뛴다. 그래서 `TemplateManager::getTemplate()` 이 null 을 돌려
 * 역의존성 조회가 빈 결과가 되고, 크론·큐 워커 같은 콘솔 프로세스가 템플릿이 쓰는 모듈을 그대로 자동 비활성화했다
 * (게시판·페이지 모듈이 꺼지고 사용자 홈 503 — #145 패턴). 조회 전에 `ensureLoaded()` 로 템플릿을 불러온다.
 */
class TemplateDependencyConsoleTest extends TestCase
{
    use DatabaseTransactions;

    private bool $loaded = false;

    /**
     * 불러오기 전에는 템플릿 정보를 돌려주지 않는 템플릿 매니저 대역을 컨테이너에 넣고 활성 템플릿 기록을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->loaded = false;
        $manager = Mockery::mock(TemplateManagerInterface::class);
        $manager->shouldReceive('ensureLoaded')->andReturnUsing(function () {
            $this->loaded = true;
        });
        $manager->shouldReceive('getTemplate')->andReturnUsing(fn (string $identifier) => $this->loaded && $identifier === 'test-console-basic'
            ? ['dependencies' => ['modules' => ['test-board' => '>=1.0.0'], 'plugins' => ['test-postcode' => '>=1.0.0']]]
            : null);
        $this->app->instance(TemplateManagerInterface::class, $manager);

        Template::query()->where('status', 'active')->update(['status' => 'inactive']);
        Template::factory()->create(['identifier' => 'test-console-basic', 'type' => 'user', 'status' => 'active']);
    }

    /**
     * 템플릿을 불러오지 않은 상태에서도 모듈 역의존성이 잡힌다.
     *
     * @scenario case=module_dependency
     *
     * @effects console_finds_templates_depending_on_module
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_module_dependency_found_without_loaded_templates(): void
    {
        $dependents = app(TemplateRepositoryInterface::class)->findActiveByModuleDependency('test-board');

        $this->assertSame(['test-console-basic'], $dependents->pluck('identifier')->all());
    }

    /**
     * 템플릿을 불러오지 않은 상태에서도 플러그인 역의존성이 잡힌다.
     *
     * @scenario case=plugin_dependency
     *
     * @effects console_finds_templates_depending_on_plugin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_plugin_dependency_found_without_loaded_templates(): void
    {
        $dependents = app(TemplateRepositoryInterface::class)->findActiveByPluginDependency('test-postcode');

        $this->assertSame(['test-console-basic'], $dependents->pluck('identifier')->all());
    }
}
