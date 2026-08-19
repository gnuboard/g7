<?php

namespace Tests\Unit\Extension;

use App\Enums\ExtensionOwnerType;
use App\Extension\AbstractPlugin;
use App\Extension\PluginManager;
use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 메뉴 동기화 검증용 플러그인 스텁.
 *
 * 실제 플러그인 디렉토리를 만들지 않고 식별자만 고정하기 위해 `getPluginPath()` 를 덮어쓴다
 * (AbstractPlugin 은 경로 basename 으로 식별자를 정한다).
 */
class MenuSyncProbePlugin extends AbstractPlugin
{
    /**
     * @param  array<int, array<string, mixed>>  $menus  선언할 관리자 메뉴
     */
    public function __construct(private array $menus = []) {}

    /**
     * @return string 식별자를 결정하는 가상 경로
     */
    protected function getPluginPath(): string
    {
        return base_path('plugins/sirsoft-menu_sync_probe');
    }

    /**
     * @return array<int, array<string, mixed>> 관리자 메뉴 선언
     */
    public function getAdminMenus(): array
    {
        return $this->menus;
    }
}

/**
 * 플러그인 관리자 메뉴가 선언형 산출물 동기화로 만들어지는지 검증 (회귀)
 *
 * 메뉴 동기화가 활성화(activate) 시점에만 일어나면, 이미 활성 상태인 사이트가 플러그인을
 * 업데이트해 새 화면을 받아도 메뉴가 생기지 않는다. 그 화면은 주소를 직접 입력해야만 닿을 수
 * 있어 운영자에게는 없는 기능이 된다(브라우저 실측 — 업데이트 반영 후 메뉴 0건).
 *
 * 설치·업데이트가 공통으로 지나는 syncDeclarativeArtifacts 에서 메뉴를 동기화하는지 본다.
 * 파일시스템(활성 디렉토리)은 건드리지 않고 DB 만 확인한다.
 */
class PluginMenuSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 파일 무접촉으로 메뉴 동기화 메서드만 호출합니다.
     *
     * @param  object  $plugin  대상 플러그인 스텁
     */
    private function syncMenus(object $plugin): void
    {
        $method = new ReflectionMethod(PluginManager::class, 'createPluginMenus');
        $method->setAccessible(true);
        $method->invoke(app(PluginManager::class), $plugin);
    }

    /**
     * getAdminMenus 를 선언한 플러그인 스텁을 만듭니다.
     *
     * @param  array<int, array<string, mixed>>  $menus  선언할 메뉴 목록
     */
    private function makePlugin(array $menus): object
    {
        return new MenuSyncProbePlugin($menus);
    }

    /**
     * PluginManager 메서드 본문이 호출하는 `$this->...()` 이름을 원문에서 수집합니다.
     *
     * 단계 목록을 테스트에 손으로 적어두면 새 산출물 종류가 늘었을 때 그 사실이 검사에
     * 반영되지 않으므로, 두 경로 모두 소스에서 도출해 비교한다.
     *
     * @param  string  $method  대상 메서드명
     * @return array<int, string> 호출된 메서드명 목록 (중복 제거)
     */
    private function selfCallsIn(string $method): array
    {
        $reflection = new ReflectionMethod(PluginManager::class, $method);

        $source = implode('', array_slice(
            file($reflection->getFileName()),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * 설치 경로가 선언형 산출물 동기화 단계를 빠짐없이 지나는지 (회귀)
     *
     * 메뉴 동기화를 syncDeclarativeArtifacts 에만 넣으면 그 경로를 지나는 것은 업데이트뿐이라,
     * 신규 설치는 플러그인이 activate() 를 직접 구현한 경우에만 메뉴가 생긴다. AbstractPlugin
     * 의 기본 activate() 는 `return true` 뿐이므로, getAdminMenus() 만 선언한 플러그인은
     * 설치·활성화를 마쳐도 메뉴가 없고 예외도 경고도 남지 않는다. 모듈은 installModule() 이
     * createModuleMenus() 를 직접 호출해 이 구멍이 없다.
     *
     * 단계 목록은 syncDeclarativeArtifacts 원문에서 도출하므로, 앞으로 선언형 산출물이
     * 추가될 때 설치 경로에 반영하지 않으면 그 사실이 여기서 드러난다.
     *
     * @effects plugin_install_path_syncs_declared_menus
     */
    public function test_install_path_covers_every_declarative_sync_step(): void
    {
        // stale 정리는 기존 레코드를 대상으로 하므로 신규 설치 경로에는 대상이 없다.
        $required = array_diff($this->selfCallsIn('syncDeclarativeArtifacts'), ['cleanupStalePluginEntries']);

        $missing = array_values(array_diff($required, $this->selfCallsIn('installPlugin')));

        $this->assertSame(
            [],
            $missing,
            'installPlugin() 이 선언형 산출물 동기화 단계를 건너뛰었다: '.implode(', ', $missing),
        );
    }

    /**
     * @scenario declaration=declared, run=first_run
     *
     * @effects plugin_admin_menu_synced_on_declarative_sync
     */
    public function test_declared_admin_menu_is_created(): void
    {
        $this->syncMenus($this->makePlugin([[
            'name' => ['ko' => '검수 메뉴', 'en' => 'Probe Menu'],
            'slug' => 'menu-sync-probe',
            'url' => '/admin/plugins/sirsoft-menu_sync_probe/probe',
            'icon' => 'fas fa-vial',
            'order' => 50,
        ]]));

        $menu = Menu::where('slug', 'menu-sync-probe')->first();

        $this->assertNotNull($menu, '플러그인이 선언한 관리자 메뉴가 생성되어야 한다.');
        $this->assertSame(ExtensionOwnerType::Plugin->value, $menu->extension_type instanceof ExtensionOwnerType
            ? $menu->extension_type->value
            : $menu->extension_type);
        $this->assertSame('sirsoft-menu_sync_probe', $menu->extension_identifier);
        $this->assertSame('/admin/plugins/sirsoft-menu_sync_probe/probe', $menu->url);
    }

    /**
     * 재호출은 행을 중복 생성하지 않는다 (업데이트마다 실행되므로 멱등이어야 한다).
     *
     * @scenario declaration=declared, run=repeated_run
     *
     * @effects plugin_admin_menu_sync_is_idempotent
     */
    public function test_repeated_sync_does_not_duplicate_menu(): void
    {
        $plugin = $this->makePlugin([[
            'name' => ['ko' => '검수 메뉴', 'en' => 'Probe Menu'],
            'slug' => 'menu-sync-probe',
            'url' => '/admin/plugins/sirsoft-menu_sync_probe/probe',
            'order' => 50,
        ]]);

        $this->syncMenus($plugin);
        $this->syncMenus($plugin);

        $this->assertSame(1, Menu::where('slug', 'menu-sync-probe')->count());
    }

    /**
     * 메뉴를 선언하지 않은 플러그인은 아무것도 만들지 않는다.
     *
     * @scenario declaration=not_declared, run=first_run
     *
     * @effects plugin_without_menu_declaration_creates_nothing
     */
    public function test_plugin_without_menus_creates_nothing(): void
    {
        $before = Menu::count();

        $this->syncMenus($this->makePlugin([]));

        $this->assertSame($before, Menu::count());
    }
}
