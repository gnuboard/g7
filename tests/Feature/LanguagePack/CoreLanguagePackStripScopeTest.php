<?php

namespace Tests\Feature\LanguagePack;

use App\Contracts\Repositories\LanguagePackTranslationRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Models\Menu;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 코어 언어팩을 끌 때 걷는 번역 범위 — 코어 팩이 넣은 행(코어 소유)만 걷는다.
 *
 * 종전에는 코어 범위 팩을 끄면 메뉴·권한·역할·알림 정의/템플릿 **전 행**에서 그 로케일 키를 지웠다. 켜기는 코어 seed 만
 * 코어 소유 행에 다시 넣으므로, 같은 로케일의 활성 확장 팩(게시판 ja 등)이 넣은 확장 메뉴·권한 이름이 지워진 채 남아
 * 일본어 관리자 사이드바가 영어(Board Management 등)로 보였다. 걷는 범위를 넣는 범위(`scopeOwnership` — 코어|NULL)와 맞춘다.
 *
 * @scenario-manifest tests/scenarios/core-language-pack-strip-scope.yaml
 */
class CoreLanguagePackStripScopeTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 코어 범위 ja 팩 행을 만듭니다.
     *
     * @return LanguagePack 비활성 코어 ja 팩
     */
    private function corePack(): LanguagePack
    {
        return LanguagePack::query()->create([
            'identifier' => 'test-core-ja-stripscope',
            'vendor' => 'test',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Inactive->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);
    }

    /**
     * 코어·확장 소유 행을 ja 이름과 함께 만들고 코어 팩을 끕니다.
     *
     * @return array<string, \Illuminate\Database\Eloquent\Model> 이름별 행
     */
    private function seedRowsAndStripCorePack(): array
    {
        $name = ['ko' => '이름', 'en' => 'Name', 'ja' => '名前'];
        $ext = ['extension_type' => ExtensionOwnerType::Module->value, 'extension_identifier' => 'stripscope-board'];
        $core = ['extension_type' => ExtensionOwnerType::Core->value, 'extension_identifier' => 'core'];

        $rows = [
            'coreMenu' => Menu::query()->create(['name' => $name, 'slug' => 'stripscope-core-menu', 'url' => '/admin/x', 'order' => 1] + $core),
            'extMenu' => Menu::query()->create(['name' => $name, 'slug' => 'stripscope-board-menu', 'url' => '/admin/y', 'order' => 2] + $ext),
            'corePermission' => Permission::factory()->create(['identifier' => 'stripscope.core', 'name' => $name, 'description' => $name] + $core),
            'extPermission' => Permission::factory()->create(['identifier' => 'stripscope-board.read', 'name' => $name, 'description' => $name] + $ext),
            'extRole' => Role::factory()->create(['identifier' => 'stripscope-board-manager', 'name' => $name, 'description' => $name] + $ext),
            'extDefinition' => NotificationDefinition::factory()->create(['type' => 'stripscope_board_new', 'name' => $name, 'description' => $name] + $ext),
        ];
        $rows['extTemplate'] = NotificationTemplate::query()->create([
            'definition_id' => $rows['extDefinition']->id, 'channel' => 'mail', 'subject' => $name, 'body' => $name,
            'is_active' => true, 'is_default' => true,
        ]);

        app(LanguagePackTranslationRepositoryInterface::class)->stripLocaleFromPack($this->corePack());

        return $rows;
    }

    /**
     * 코어 팩을 끄면 코어 소유 메뉴·권한의 ja 는 걷힌다(종전과 같음).
     *
     * @scenario owner=core
     *
     * @effects core_pack_strip_removes_core_owned_locale
     *
     * @return void 반환값 없음
     */
    public function test_core_pack_strip_removes_core_owned_locale(): void
    {
        $rows = $this->seedRowsAndStripCorePack();

        $this->assertArrayNotHasKey('ja', $rows['coreMenu']->fresh()->name);
        $this->assertArrayNotHasKey('ja', $rows['corePermission']->fresh()->name);
    }

    /**
     * 코어 팩을 꺼도 확장 소유 메뉴·권한·역할·알림 정의/템플릿의 ja(확장 팩이 넣은 것)는 남는다.
     *
     * @scenario owner=extension
     *
     * @effects core_pack_strip_keeps_extension_owned_locale
     *
     * @return void 반환값 없음
     */
    public function test_core_pack_strip_keeps_extension_owned_locale(): void
    {
        $rows = $this->seedRowsAndStripCorePack();

        $this->assertSame('名前', $rows['extMenu']->fresh()->name['ja'] ?? null, '코어 팩을 껐는데 확장 메뉴의 ja(확장 팩이 넣은 것)까지 지웠다');
        $this->assertSame('名前', $rows['extPermission']->fresh()->name['ja'] ?? null, '확장 권한의 ja 를 지웠다');
        $this->assertSame('名前', $rows['extRole']->fresh()->name['ja'] ?? null, '확장 역할의 ja 를 지웠다');
        $this->assertSame('名前', $rows['extDefinition']->fresh()->name['ja'] ?? null, '확장 알림 정의의 ja 를 지웠다');
        $this->assertSame('名前', $rows['extTemplate']->fresh()->subject['ja'] ?? null, '확장 알림 템플릿의 ja 를 지웠다');
    }
}
