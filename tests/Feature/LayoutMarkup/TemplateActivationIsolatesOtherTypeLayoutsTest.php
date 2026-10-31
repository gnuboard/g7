<?php

namespace Tests\Feature\LayoutMarkup;

use App\Contracts\Extension\ModuleInterface;
use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 템플릿 활성화는 그 템플릿 종류의 모듈 화면만 처리하고, 한 모듈의 실패는 그 모듈만 건너뛴다 (#725 W8 · 결정 D6).
 *
 * 활성화는 활성 모듈 화면을 새 템플릿에 등록하는데, 종전에는 사용자 템플릿을 켤 때도 모듈의 **관리자** 화면까지
 * 컴파일·검증했고 한 파일의 실패가 예외로 번져 활성화 트랜잭션 전체가 되돌아갔다 — 무관한 모듈의 관리자 화면 하나가
 * 깨지면 사용자 템플릿을 켤 수 없었다.
 *
 *  ⓐ 사용자 템플릿 활성화는 모듈 `layouts/admin` 을 컴파일하지 않는다 — 깨져 있어도 활성화가 서고 사용자 화면은 등록된다.
 *  ⓑ 사용자 화면이 깨진 모듈은 그 모듈만 ERROR 1줄 후 건너뛰고, 다른 모듈 화면과 활성화는 계속된다.
 *
 * 모듈 레이아웃 경로는 `modules/{id}/resources/layouts` 고정이라 실행 고유 이름의 임시 모듈 디렉토리를 만들고 지운다.
 */
class TemplateActivationIsolatesOtherTypeLayoutsTest extends TestCase
{
    use DatabaseTransactions;

    private const TEMPLATE = 'sirsoft-basic';

    /** @var array<int, string> 만든 임시 모듈 식별자 */
    private array $modules = [];

    private Template $template;

    /**
     * 비활성 사용자 템플릿 행을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $existing = Template::query()->where('identifier', self::TEMPLATE)->first();
        $this->template = $existing ?? Template::factory()->create(['identifier' => self::TEMPLATE, 'type' => 'user']);
        $this->template->update(['status' => ExtensionStatus::Inactive->value]);
    }

    /**
     * 임시 모듈 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->modules as $identifier) {
            File::deleteDirectory(base_path("modules/{$identifier}"));
        }

        parent::tearDown();
    }

    /**
     * ⓐ 관리자 화면이 깨진 모듈이 있어도 사용자 템플릿은 켜지고, 그 모듈의 사용자 화면은 등록된다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects template_activation_compiles_only_its_type_and_isolates_module_failure
     *
     * @return void 반환값 없음
     */
    public function test_user_activation_ignores_broken_admin_layout_of_a_module(): void
    {
        $module = $this->makeModule('probe-iso-admin', userBroken: false, adminBroken: true);
        $this->bindModules([$module]);

        Log::spy();

        $result = $this->activate();

        $this->assertTrue($result['success'], '무관한 관리자 화면 하나 때문에 사용자 템플릿 활성화가 막혔다');
        $this->assertSame([$module.'.probe_user_page'], $this->moduleLayoutNames($module), '그 모듈의 사용자 화면은 등록돼야 한다');
        Log::shouldNotHaveReceived('error', [Mockery::on(fn ($message): bool => is_string($message) && str_contains($message, 'admin_broken')), Mockery::any()]);
    }

    /**
     * ⓑ 사용자 화면이 깨진 모듈은 그 모듈만 ERROR 1줄로 건너뛰고, 다른 모듈의 사용자 화면과 활성화는 계속된다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects template_activation_compiles_only_its_type_and_isolates_module_failure
     *
     * @return void 반환값 없음
     */
    public function test_module_failure_is_isolated_during_activation(): void
    {
        $broken = $this->makeModule('probe-iso-broken', userBroken: true, adminBroken: false);
        $healthy = $this->makeModule('probe-iso-healthy', userBroken: false, adminBroken: false);
        $this->bindModules([$broken, $healthy]);

        Log::spy();

        $result = $this->activate();

        $this->assertTrue($result['success'], '한 모듈의 사용자 화면 실패가 활성화 전체를 되돌렸다');
        $this->assertSame([$healthy.'.probe_user_page'], $this->moduleLayoutNames($healthy));
        $this->assertSame([], $this->moduleLayoutNames($broken));
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, '템플릿 활성화 중 모듈 레이아웃 등록 실패')
                && ($context['module'] ?? null) === $broken
                && ($context['template'] ?? null) === self::TEMPLATE)
            ->once();
    }

    /**
     * 사용자·관리자 화면을 하나씩 가진 임시 모듈 디렉토리를 만듭니다.
     *
     * @param  string  $prefix  식별자 접두사
     * @param  bool  $userBroken  사용자 화면을 깨뜨릴지
     * @param  bool  $adminBroken  관리자 화면을 깨뜨릴지
     * @return string 모듈 식별자
     */
    private function makeModule(string $prefix, bool $userBroken, bool $adminBroken): string
    {
        $identifier = $prefix.'-'.substr(md5(uniqid('', true)), 0, 8);
        $this->modules[] = $identifier;
        $layouts = base_path("modules/{$identifier}/resources/layouts");
        File::ensureDirectoryExists($layouts.'/user');
        File::ensureDirectoryExists($layouts.'/admin');

        File::put($layouts.'/user/probe_user_page.g7.html', $userBroken
            ? "<layout name=\"probe_user_page\" version=\"1.0.0\">\n  <div id=\"root\">\n"
            : "<layout name=\"probe_user_page\" version=\"1.0.0\">\n  <div id=\"root\"/>\n</layout>\n");
        File::put($layouts.'/admin/admin_broken_page.g7.html', $adminBroken
            ? "<layout name=\"admin_broken_page\" version=\"1.0.0\">\n  <div id=\"root\">\n"
            : "<layout name=\"admin_broken_page\" version=\"1.0.0\">\n  <div id=\"root\"/>\n</layout>\n");

        return $identifier;
    }

    /**
     * 임시 모듈들을 활성 모듈로 보는 모듈 매니저(부분 대역)와 빈 플러그인 매니저를 바인딩합니다.
     *
     * @param  array<int, string>  $identifiers  모듈 식별자 목록
     * @return void 반환값 없음
     */
    private function bindModules(array $identifiers): void
    {
        $modules = [];
        foreach ($identifiers as $identifier) {
            $module = $this->createMock(ModuleInterface::class);
            $module->method('getIdentifier')->willReturn($identifier);
            $module->method('getExtensionsPath')->willReturn(base_path("modules/{$identifier}/resources/extensions"));
            $module->method('getLayoutExtensions')->willReturn([]);
            $modules[$identifier] = $module;
        }

        /** @var ModuleManager&MockInterface $moduleManager */
        $moduleManager = Mockery::mock(ModuleManager::class, $this->constructorArgs(ModuleManager::class))->makePartial();
        $moduleManager->shouldReceive('getActiveModules')->andReturn($modules);
        $moduleManager->shouldReceive('getModule')->andReturnUsing(fn (string $id) => $modules[$id] ?? null);
        $this->app->instance(ModuleManager::class, $moduleManager);

        $pluginManager = $this->createMock(PluginManager::class);
        $pluginManager->method('getActivePlugins')->willReturn([]);
        $pluginManager->method('registerLayoutsForAllActivePlugins')->willReturn(0);
        $this->app->instance(PluginManager::class, $pluginManager);
    }

    /**
     * 자기 레이아웃 갱신·캐시 굽기만 대역으로 바꾼 템플릿 매니저로 사용자 템플릿을 켭니다.
     *
     * @return array<string, mixed> 활성화 결과
     */
    private function activate(): array
    {
        /** @var TemplateManager&MockInterface $manager */
        $manager = Mockery::mock(TemplateManager::class, $this->constructorArgs(TemplateManager::class))->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive('refreshTemplateLayouts')->andReturn(['success' => true, 'layout_sync' => [], 'layout_warnings' => []]);
        $manager->shouldReceive('warmTemplateCache')->andReturnNull();
        $manager->loadTemplates();

        return $manager->activateTemplate(self::TEMPLATE, true);
    }

    /**
     * 클래스 생성자 인자를 컨테이너로 만듭니다.
     *
     * @param  class-string  $class  클래스
     * @return array<int, mixed> 생성자 인자
     */
    private function constructorArgs(string $class): array
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();

        return array_map(
            fn (\ReflectionParameter $parameter) => $this->app->make($parameter->getType()->getName()),
            $constructor?->getParameters() ?? []
        );
    }

    /**
     * 이 템플릿에 등록된 모듈 화면 이름 목록을 얻습니다.
     *
     * @param  string  $module  모듈 식별자
     * @return array<int, string> 레이아웃 이름 목록
     */
    private function moduleLayoutNames(string $module): array
    {
        return TemplateLayout::query()
            ->where('template_id', $this->template->id)
            ->where('name', 'like', $module.'.%')
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
