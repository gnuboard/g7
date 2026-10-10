<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Extension\PluginManager;
use App\Models\LanguagePack;
use App\Providers\LanguagePackServiceProvider;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePack\LanguagePackSeedInjector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 플러그인 관리자 메뉴 이름에 플러그인 언어팩 번역을 넣는다 (7.1.0 출시 점검 U5-1).
 *
 * 플러그인 메뉴 동기화는 `plugin.{id}.admin_menus.translations` 필터를 발화하지만 언어팩 쪽 결선이 `module.{id}` 에만
 * 있었고, 주입기는 모듈 범위 팩만 골랐다. 또 플러그인을 다시 켜면 플러그인 자신의 activate() 가 필터 없이 메뉴를
 * 동기화해, 업데이트·재활성화 뒤 일본어 메뉴 이름이 ko/en 으로 돌아갔다(대상: 첨부 이미지 관리·GDPR 메뉴).
 */
class PluginMenuTranslationFilterTest extends TestCase
{
    use DatabaseTransactions;

    private const TARGET = 'acme-menu-plugin';

    private string $packRoot;

    /**
     * 플러그인 범위 일본어 팩과 menus seed 를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->packRoot = base_path('lang-packs/g7-plugin-'.self::TARGET.'-ja');
        File::ensureDirectoryExists($this->packRoot.'/seed');
        File::put($this->packRoot.'/seed/menus.json', json_encode(['acme-menu' => ['name' => 'アクメメニュー']]));

        LanguagePack::query()->create([
            'identifier' => 'g7-plugin-'.self::TARGET.'-ja',
            'vendor' => 'g7',
            'scope' => LanguagePackScope::Plugin->value,
            'target_identifier' => self::TARGET,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'bundled',
        ]);
        $this->app->make(LanguagePackRegistry::class)->invalidate();
    }

    /**
     * 임시 팩 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->packRoot);

        parent::tearDown();
    }

    /**
     * 주입기는 플러그인 범위 팩의 menus seed 로 플러그인 메뉴 이름에 ja 를 넣고, 모듈 범위로 물으면 넣지 않는다.
     *
     * @scenario scope=plugin, path=menu_sync_filter
     *
     * @effects plugin_menu_names_receive_plugin_pack_translation
     *
     * @return void 반환값 없음
     */
    public function test_injector_merges_plugin_pack_menu_seed(): void
    {
        $menus = [['slug' => 'acme-menu', 'name' => ['ko' => '아크메', 'en' => 'Acme']]];
        $injector = $this->app->make(LanguagePackSeedInjector::class);

        $plugin = $injector->injectExtensionMenus($menus, self::TARGET, LanguagePackScope::Plugin->value);
        $this->assertSame('アクメメニュー', $plugin[0]['name']['ja']);
        $this->assertSame('아크메', $plugin[0]['name']['ko']);

        $module = $injector->injectExtensionMenus($menus, self::TARGET);
        $this->assertArrayNotHasKey('ja', $module[0]['name'], '모듈 범위로 물으면 플러그인 팩을 쓰지 않는다');
    }

    /**
     * 언어팩 결선은 팩의 범위로 필터 이름을 만든다 — 플러그인 팩은 `plugin.{id}.admin_menus.translations` 에 붙는다.
     *
     * @scenario scope=plugin, path=provider_wiring
     *
     * @effects plugin_menu_filter_is_wired_by_pack_scope
     *
     * @return void 반환값 없음
     */
    public function test_provider_wires_menu_filter_by_pack_scope(): void
    {
        $source = $this->methodSource(LanguagePackServiceProvider::class, 'registerExtensionSeedFilters');

        $this->assertStringContainsString('"{$scopeStr}.{$target}.admin_menus.translations"', $source, '메뉴 필터가 모듈 범위에만 결선돼 있다');
        $this->assertStringNotContainsString('"module.{$target}.admin_menus.translations"', $source);
    }

    /**
     * 플러그인 활성화는 플러그인 자신의 activate() 뒤에 코어의 필터 경유 메뉴 동기화를 한 번 더 돈다.
     *
     * @scenario scope=plugin, path=activate
     *
     * @effects plugin_activation_syncs_menus_through_translation_filter
     *
     * @return void 반환값 없음
     */
    public function test_plugin_activation_syncs_menus_through_filter(): void
    {
        $source = $this->methodSource(PluginManager::class, 'activatePlugin');

        $activateAt = strpos($source, '$plugin->activate()');
        $menusAt = strpos($source, '$this->createPluginMenus($plugin)');

        $this->assertNotFalse($activateAt);
        $this->assertNotFalse($menusAt, '플러그인 활성화가 필터 경유 메뉴 동기화를 하지 않는다 — 재활성화 때 언어팩 메뉴 이름이 사라진다');
        $this->assertGreaterThan($activateAt, $menusAt, '플러그인 activate() 의 필터 없는 동기화 뒤에 돌아야 번역이 남는다');
    }

    /**
     * 메서드 소스를 돌려줍니다.
     *
     * @param  class-string  $class  클래스
     * @param  string  $method  메서드 이름
     * @return string 메서드 소스
     */
    private function methodSource(string $class, string $method): string
    {
        $ref = new ReflectionMethod($class, $method);
        $lines = file($ref->getFileName());

        return implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    }
}
