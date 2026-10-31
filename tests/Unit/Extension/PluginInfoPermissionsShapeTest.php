<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\PluginInterface;
use App\Extension\AbstractPlugin;
use App\Extension\PluginManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 권한 계층을 선언한 플러그인 스텁.
 *
 * 실제 플러그인 디렉토리를 만들지 않고 식별자만 고정하려고 `getPluginPath()` 를 덮어쓴다
 * (AbstractPlugin 은 경로 basename 으로 식별자를 정한다).
 */
class PermissionShapeProbePlugin extends AbstractPlugin
{
    /**
     * 돌려줄 권한 선언을 받아 둔다.
     *
     * @param  array<string, mixed>  $permissions  getPermissions() 가 돌려줄 선언
     */
    public function __construct(private array $permissions = []) {}

    /**
     * 식별자를 고정하는 가상 경로를 돌려준다(디렉토리는 만들지 않는다).
     *
     * @return string 식별자를 결정하는 가상 경로
     */
    protected function getPluginPath(): string
    {
        return base_path('plugins/sirsoft-permission_shape_probe');
    }

    /**
     * 생성자로 받은 권한 계층 선언을 그대로 돌려준다.
     *
     * @return array<string, mixed> 권한 계층 선언
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }
}

/**
 * 번들·대기 폴백 경로를 재현하는 관리자 — 클래스 임시 로드 결과만 스텁으로 바꾼다.
 */
class PermissionShapeFallbackPluginManager extends PluginManager
{
    /** @var PluginInterface|null 임시 로드가 돌려줄 인스턴스(null 이면 로드 실패 갈래) */
    public ?PluginInterface $probe = null;

    /**
     * 클래스 임시 로드 대신 스텁을 돌려준다.
     *
     * @param  string  $pluginName  플러그인 식별자
     * @param  bool  $isPending  대기 디렉토리 여부
     * @return PluginInterface|null 스텁 인스턴스
     */
    protected function tryLoadPluginInstance(string $pluginName, bool $isPending): ?PluginInterface
    {
        return $this->probe;
    }
}

/**
 * 플러그인 상세 정보의 권한이 모듈과 같은 분류 배열인지 검증 (회귀)
 *
 * 플러그인도 모듈처럼 `getPermissions()` 로 `{name, description, categories}` 계층을 선언한다.
 * 모듈 정보는 `categories` 만 꺼내 분류 배열로 내보내는데(ModuleManager::getModuleInfo), 플러그인
 * 정보는 계층 객체를 그대로 내보냈다. 관리자 플러그인 상세 창은 배열을 기대하므로
 * (`permissions.length`) 권한을 가진 플러그인(GDPR·환율·TOTP 등)의 권한 구획이 「권한 없음」도
 * 목록도 아닌 빈칸으로 남았다. API 문서도 `permissions` 를 배열로 적는다.
 *
 * 정보는 세 갈래에서 만들어진다 — 활성 인스턴스, 번들·대기 클래스 임시 로드, 그 로드가 실패했을 때의
 * 메타데이터 폴백. 세 갈래 모두 같은 모양이어야 한다.
 */
class PluginInfoPermissionsShapeTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, array<string, mixed>> 선언에 담을 분류 배열 */
    private const CATEGORIES = [[
        'identifier' => 'privacy',
        'name' => ['ko' => '개인정보 보호', 'en' => 'Privacy'],
        'description' => ['ko' => '개인정보 권한', 'en' => 'Privacy permissions'],
        'permissions' => [
            ['action' => 'view', 'name' => ['ko' => '개인정보 조회', 'en' => 'View Privacy'], 'type' => 'admin'],
        ],
    ]];

    /**
     * 분류 배열을 감싼 권한 계층 선언.
     *
     * @return array<string, mixed> {name, description, categories} 계층
     */
    private function hierarchy(): array
    {
        return [
            'name' => ['ko' => '프로브', 'en' => 'Probe'],
            'description' => ['ko' => '프로브 권한', 'en' => 'Probe permissions'],
            'categories' => self::CATEGORIES,
        ];
    }

    /**
     * 활성 레지스트리에 스텁을 잠시 넣고 상세 정보를 읽는다.
     *
     * @param  PermissionShapeProbePlugin  $plugin  스텁
     * @return array<string, mixed> 상세 정보
     */
    private function activeInfoOf(PermissionShapeProbePlugin $plugin): array
    {
        $manager = app(PluginManager::class);
        $registry = new ReflectionProperty(PluginManager::class, 'plugins');
        $registry->setAccessible(true);
        $before = $registry->getValue($manager);
        $registry->setValue($manager, [...$before, $plugin->getIdentifier() => $plugin]);

        try {
            return $manager->getPluginInfo($plugin->getIdentifier());
        } finally {
            $registry->setValue($manager, $before);
        }
    }

    /**
     * 번들 메타데이터만 있는 플러그인으로 상세 정보를 읽는다.
     *
     * @param  PluginInterface|null  $probe  임시 로드 결과(null 이면 메타데이터 폴백)
     * @param  array<string, mixed>  $metadata  번들 plugin.json 메타데이터
     * @return array<string, mixed> 상세 정보
     */
    private function bundledInfoOf(?PluginInterface $probe, array $metadata): array
    {
        $manager = app(PermissionShapeFallbackPluginManager::class);
        $manager->probe = $probe;
        $bundled = new ReflectionProperty(PluginManager::class, 'bundledPlugins');
        $bundled->setAccessible(true);
        $bundled->setValue($manager, ['sirsoft-permission_shape_probe' => $metadata]);

        return $manager->getPluginInfo('sirsoft-permission_shape_probe');
    }

    /**
     * 활성 플러그인이 선언한 권한 계층은 분류 배열로 나온다.
     *
     * @scenario screen=plugins
     *
     * @effects plugin_info_permissions_are_category_list
     *
     * @return void 단언만 수행한다
     */
    public function test_active_plugin_exposes_declared_hierarchy_as_category_list(): void
    {
        $info = $this->activeInfoOf(new PermissionShapeProbePlugin($this->hierarchy()));

        $this->assertSame(self::CATEGORIES, $info['permissions']);
    }

    /**
     * 권한을 선언하지 않은 활성 플러그인은 빈 배열이다.
     *
     * @scenario screen=plugins
     *
     * @effects plugin_info_permissions_are_category_list
     *
     * @return void 단언만 수행한다
     */
    public function test_active_plugin_without_permissions_exposes_empty_list(): void
    {
        $info = $this->activeInfoOf(new PermissionShapeProbePlugin([]));

        $this->assertSame([], $info['permissions']);
    }

    /**
     * 번들 플러그인을 임시 로드한 상세 정보도 분류 배열이다.
     *
     * @scenario screen=plugins
     *
     * @effects plugin_info_permissions_are_category_list
     *
     * @return void 단언만 수행한다
     */
    public function test_bundled_plugin_loaded_for_detail_exposes_category_list(): void
    {
        $info = $this->bundledInfoOf(new PermissionShapeProbePlugin($this->hierarchy()), [
            'identifier' => 'sirsoft-permission_shape_probe',
            'version' => '1.0.0',
        ]);

        $this->assertSame(self::CATEGORIES, $info['permissions']);
    }

    /**
     * 임시 로드가 실패해 메타데이터로 만든 상세 정보도 분류 배열이다.
     *
     * @scenario screen=plugins
     *
     * @effects plugin_info_permissions_are_category_list
     *
     * @return void 단언만 수행한다
     */
    public function test_bundled_metadata_fallback_exposes_category_list(): void
    {
        $info = $this->bundledInfoOf(null, [
            'identifier' => 'sirsoft-permission_shape_probe',
            'version' => '1.0.0',
            'permissions' => $this->hierarchy(),
        ]);

        $this->assertSame(self::CATEGORIES, $info['permissions']);
    }
}
