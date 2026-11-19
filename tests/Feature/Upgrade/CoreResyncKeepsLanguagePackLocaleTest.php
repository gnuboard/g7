<?php

namespace Tests\Feature\Upgrade;

use App\Enums\ExtensionOwnerType;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Services\CoreUpdateService;
use App\Services\LanguagePack\LanguagePackRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 코어 선언 재동기화가 활성 코어 언어팩의 번역(ja)을 지우지 않는다 (1차 캠페인 E17).
 *
 * `core:resync-declarations`·코어 업데이트의 재동기화(`syncCoreMenus()`·`syncCoreRolesAndPermissions()`)는 `config('core.*')` 를
 * 필터 없이 읽어 name 을 ko·en 묶음으로 통째로 덮었다 — 언어팩 판이 함께 오르지 않는 코어만 패치(언어팩 재활성화 없음)나 단독
 * 재동기화에서 코어 메뉴·권한·역할의 ja 가 지워졌다(실측 메뉴 63→36, 권한 278→177). 시더와 같은 필터(`core.*.config`)를 거쳐
 * 활성 팩 번역을 함께 넣는다. 운영자가 고친 메뉴·역할 이름(user_overrides)은 종전처럼 덮지 않는다.
 *
 * @scenario-manifest tests/scenarios/core-resync-keeps-language-pack-locale.yaml
 */
class CoreResyncKeepsLanguagePackLocaleTest extends TestCase
{
    use DatabaseTransactions;

    /** @var string 시험용 코어 ja 팩 디렉터리 */
    private string $packDir;

    /**
     * 활성 코어 ja 팩(번들 seed 사본)을 둡니다 — 판은 바꾸지 않는다(코어만 패치 상황).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $identifier = 'test-core-ja-resync';
        $this->packDir = base_path('lang-packs/'.$identifier);
        File::ensureDirectoryExists($this->packDir.'/seed');
        foreach (['menus', 'permissions', 'roles'] as $entity) {
            File::copy(base_path("lang-packs/_bundled/g7-core-ja/seed/{$entity}.json"), $this->packDir."/seed/{$entity}.json");
        }

        LanguagePack::query()->where('scope', LanguagePackScope::Core->value)->where('locale', 'ja')->delete();
        LanguagePack::query()->create([
            'identifier' => $identifier,
            'vendor' => 'test',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);
        $this->app->make(LanguagePackRegistry::class)->invalidate();
    }

    /**
     * 시험용 팩 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->packDir);
        parent::tearDown();
    }

    /**
     * 코어 메뉴·권한·역할을 재동기화해도 활성 코어 ja 팩의 이름이 남는다(언어팩 판 불변·재활성화 없음).
     *
     * @scenario path=core_resync, override=none
     *
     * @effects core_resync_keeps_active_pack_locale
     *
     * @return void 반환값 없음
     */
    public function test_core_resync_keeps_active_pack_locale(): void
    {
        $service = $this->app->make(CoreUpdateService::class);
        $service->syncCoreRolesAndPermissions();
        $service->syncCoreMenus();

        $menu = Menu::query()->where('slug', 'admin-dashboard')->where('extension_type', ExtensionOwnerType::Core->value)->first();
        $permission = Permission::query()->where('identifier', 'core.users.read')->first();
        $role = Role::query()->where('identifier', 'admin')->first();

        $this->assertSame('ダッシュボード', $menu?->name['ja'] ?? null, '코어 재동기화가 코어 메뉴의 ja(활성 언어팩)를 지웠다');
        $this->assertSame('ユーザー閲覧', $permission?->name['ja'] ?? null, '코어 재동기화가 코어 권한의 ja 를 지웠다');
        $this->assertSame('管理者', $role?->name['ja'] ?? null, '코어 재동기화가 코어 역할의 ja 를 지웠다');
        $this->assertNotEmpty($menu->name['ko'] ?? null, 'ko 기본값은 선언대로 남는다');

        $child = Menu::query()->where('slug', 'admin-analytics-overview')->where('extension_type', ExtensionOwnerType::Core->value)->first();
        $this->assertSame('概要', $child?->name['ja'] ?? null, '코어 재동기화가 하위 메뉴(children)의 ja 를 지웠다');
    }

    /**
     * 운영자가 고친 메뉴 이름(user_overrides)은 재동기화가 팩 번역으로 덮지 않는다.
     *
     * @scenario path=core_resync, override=operator_name
     *
     * @effects core_resync_preserves_operator_name
     *
     * @return void 반환값 없음
     */
    public function test_core_resync_preserves_operator_renamed_menu(): void
    {
        $service = $this->app->make(CoreUpdateService::class);
        $service->syncCoreMenus();

        $menu = Menu::query()->where('slug', 'admin-settings')->where('extension_type', ExtensionOwnerType::Core->value)->firstOrFail();
        $custom = ['ko' => '운영자 설정', 'en' => 'Ops Settings', 'ja' => '運営者設定'];
        Menu::whereKey($menu->id)->toBase()->update(['name' => json_encode($custom, JSON_UNESCAPED_UNICODE), 'user_overrides' => json_encode(['name.ja', 'name.ko'])]);

        $service->syncCoreMenus();

        $this->assertSame($custom, $menu->fresh()->name, '운영자가 고친 메뉴 이름을 재동기화가 덮었다');
    }
}
