<?php

namespace Tests\Feature\Extension;

use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Extension\Helpers\ExtensionRoleSyncHelper;
use App\Extension\Helpers\NotificationSyncHelper;
use App\Models\Menu;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Services\MenuService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 확장 동기화가 운영자 수정을 되돌리는 결함군 회귀 테스트 (#727 D5-①·③·⑤, W10·W11·W12).
 *
 * 결함(수정 전):
 *  - 역할 이름·설명: 기록은 `name.ko`(다국어 dot-path)인데 판정은 `name` 정확 일치라 업데이트마다 되돌아갔다.
 *  - 확장 역할·메뉴의 `is_active`, 메뉴 `parent_id` 는 추적하지 않아 끄거나 옮긴 것이 되살아났다.
 *  - 메뉴 드래그 한 번에 모든 메뉴 순서가 「수정됨」 으로 기록돼 새 버전의 순서 변경이 영영 안 들어왔다.
 *  - 알림 템플릿 동기화가 「기본」 표시를 늘 true 로 되돌려 수정본인데 복원 버튼이 숨었다.
 */
class ExtensionSyncUserOverridesTest extends TestCase
{
    use DatabaseTransactions;

    /** 실행 고유 접미어 (기존 데이터와 공존) */
    private string $suffix;

    /**
     * 실행 고유 접미어를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->suffix = substr(md5((string) microtime(true)), 0, 8);
    }

    /**
     * 확장 역할 하나를 만듭니다.
     *
     * @return Role 역할
     */
    private function extensionRole(): Role
    {
        return app(ExtensionRoleSyncHelper::class)->syncRole(
            "probe-{$this->suffix}.manager",
            ['ko' => '관리 역할', 'en' => 'Manager role'],
            ['ko' => '쇼핑몰 관리자', 'en' => 'Shop manager'],
            ExtensionOwnerType::Module,
            "probe-{$this->suffix}",
            ['is_active' => true]
        );
    }

    /**
     * 역할 이름 ko 만 고치면 ko 는 보존되고 en 은 새 선언값으로 갱신된다 (D5-①).
     *
     * @scenario surface=role
     *
     * @effects role_locale_edit_survives_sync
     *
     * @return void 반환값 없음
     */
    public function test_role_name_locale_edit_survives_sync(): void
    {
        $role = $this->extensionRole();
        $role->update(['name' => ['ko' => '쇼핑몰 관리자(실측)', 'en' => 'Shop manager']]);
        $this->assertSame(['name.ko'], $role->fresh()->user_overrides);

        $synced = app(ExtensionRoleSyncHelper::class)->syncRole(
            $role->identifier,
            ['ko' => '관리 역할', 'en' => 'Manager role'],
            ['ko' => '쇼핑몰 관리자 v2', 'en' => 'Shop manager v2'],
            ExtensionOwnerType::Module,
            "probe-{$this->suffix}",
            ['is_active' => true]
        );

        $this->assertSame('쇼핑몰 관리자(실측)', $synced->name['ko'], '운영자가 고친 ko 가 되돌아갔다');
        $this->assertSame('Shop manager v2', $synced->name['en'], '운영자가 안 고친 en 은 새 선언값이어야 한다');
    }

    /**
     * 운영자가 끈 확장 역할은 동기화가 다시 켜지 않는다 (W10).
     *
     * @scenario surface=role
     *
     * @effects disabled_extension_role_stays_disabled
     *
     * @return void 반환값 없음
     */
    public function test_disabled_extension_role_stays_disabled_after_sync(): void
    {
        $role = $this->extensionRole();
        $role->update(['is_active' => false]);

        $synced = app(ExtensionRoleSyncHelper::class)->syncRole(
            $role->identifier,
            ['ko' => '관리 역할', 'en' => 'Manager role'],
            ['ko' => '쇼핑몰 관리자', 'en' => 'Shop manager'],
            ExtensionOwnerType::Module,
            "probe-{$this->suffix}",
            ['is_active' => true]
        );

        $this->assertFalse((bool) $synced->is_active, '운영자가 끈 역할이 다시 켜졌다');
    }

    /**
     * 합의 해제 — 운영자 값과 새 선언값이 같아지면 표식을 푼다.
     *
     * @scenario surface=role
     *
     * @effects override_released_when_declaration_catches_up
     *
     * @return void 반환값 없음
     */
    public function test_override_is_released_when_declaration_catches_up(): void
    {
        $role = $this->extensionRole();
        $role->update(['name' => ['ko' => '쇼핑몰 운영자', 'en' => 'Shop manager']]);

        $synced = app(ExtensionRoleSyncHelper::class)->syncRole(
            $role->identifier,
            ['ko' => '관리 역할', 'en' => 'Manager role'],
            ['ko' => '쇼핑몰 운영자', 'en' => 'Shop manager'],
            ExtensionOwnerType::Module,
            "probe-{$this->suffix}",
            ['is_active' => true]
        );

        $this->assertEmpty($synced->user_overrides ?? [], '운영자 값과 선언이 같아졌는데 표식이 남았다');
    }

    /**
     * 확장 메뉴 한 개를 만듭니다.
     *
     * @param  string  $slug  슬러그
     * @param  int|null  $parentId  부모 ID
     * @return Menu 메뉴
     */
    private function extensionMenu(string $slug, ?int $parentId = null): Menu
    {
        return app(ExtensionMenuSyncHelper::class)->syncMenu(
            "{$slug}-{$this->suffix}",
            ExtensionOwnerType::Module,
            "probe-{$this->suffix}",
            ['name' => ['ko' => $slug, 'en' => $slug], 'url' => "/admin/{$slug}", 'icon' => 'fas fa-circle', 'order' => 1, 'is_active' => true],
            $parentId
        );
    }

    /**
     * 목록 토글로 끈 메뉴와 편집 패널로 옮긴 메뉴는 동기화 뒤에도 그대로다 (D5-③).
     *
     * @scenario surface=menu
     *
     * @effects disabled_or_moved_menu_survives_sync
     *
     * @return void 반환값 없음
     */
    public function test_disabled_or_moved_menu_survives_sync(): void
    {
        $parentA = $this->extensionMenu('parent-a');
        $parentB = $this->extensionMenu('parent-b');
        $toggled = $this->extensionMenu('toggled', $parentA->id);
        $moved = $this->extensionMenu('moved', $parentA->id);

        app(MenuService::class)->toggleMenuStatus($toggled);
        $moved->update(['parent_id' => $parentB->id]);

        $helper = app(ExtensionMenuSyncHelper::class);
        $helper->syncMenu("toggled-{$this->suffix}", ExtensionOwnerType::Module, "probe-{$this->suffix}", ['name' => ['ko' => 'toggled', 'en' => 'toggled'], 'url' => '/admin/toggled', 'icon' => 'fas fa-circle', 'order' => 1, 'is_active' => true], $parentA->id);
        $helper->syncMenu("moved-{$this->suffix}", ExtensionOwnerType::Module, "probe-{$this->suffix}", ['name' => ['ko' => 'moved', 'en' => 'moved'], 'url' => '/admin/moved', 'icon' => 'fas fa-circle', 'order' => 1, 'is_active' => true], $parentA->id);

        $this->assertFalse((bool) $toggled->fresh()->is_active, '끈 메뉴가 되살아났다');
        $this->assertSame($parentB->id, (int) $moved->fresh()->parent_id, '옮긴 메뉴가 원위치로 돌아갔다');
    }

    /**
     * 드래그로 순서를 저장해도 실제로 순서가 바뀐 메뉴만 「수정됨」 이다 (W11).
     *
     * @scenario surface=menu
     *
     * @effects menu_order_save_marks_only_moved_menus
     *
     * @return void 반환값 없음
     */
    public function test_menu_order_save_records_only_really_moved_menus(): void
    {
        $a = $this->extensionMenu('ord-a');
        $b = $this->extensionMenu('ord-b');
        $c = $this->extensionMenu('ord-c');
        Menu::whereKey($a->id)->toBase()->update(['order' => 1]);
        Menu::whereKey($b->id)->toBase()->update(['order' => 2]);
        Menu::whereKey($c->id)->toBase()->update(['order' => 3]);
        $repository = app(MenuRepositoryInterface::class);

        // 같은 순서로 저장(드래그했다 제자리) — 아무 메뉴도 표식되지 않는다
        $repository->updateOrder([$a->id, $b->id, $c->id]);
        foreach ([$a, $b, $c] as $menu) {
            $this->assertNotContains('order', $menu->fresh()->user_overrides ?? []);
        }

        // b·c 만 맞바꿈 — 실제로 바뀐 두 메뉴만 표식되고 a 는 그대로
        $repository->updateOrder([$a->id, $c->id, $b->id]);
        $this->assertNotContains('order', $a->fresh()->user_overrides ?? []);
        $this->assertContains('order', $b->fresh()->user_overrides ?? []);
        $this->assertContains('order', $c->fresh()->user_overrides ?? []);
    }

    /**
     * 알림 템플릿 동기화는 수정본의 「기본」 표시를 되살리지 않고, 정의도 소속 템플릿이 수정됐으면 「기본」 이 아니다 (W12).
     *
     * @scenario surface=notification_template
     *
     * @effects notification_sync_keeps_customized_state
     *
     * @return void 반환값 없음
     */
    public function test_notification_sync_keeps_customized_state(): void
    {
        $helper = app(NotificationSyncHelper::class);
        $definition = $helper->syncDefinition([
            'type' => "probe_{$this->suffix}", 'hook_prefix' => 'core.probe', 'extension_type' => 'core', 'extension_identifier' => 'core',
            'name' => ['ko' => '실측', 'en' => 'Probe'], 'channels' => ['mail'], 'hooks' => ['core.probe.after'],
        ]);
        $template = $helper->syncTemplate($definition->id, ['channel' => 'mail', 'subject' => ['ko' => '제목'], 'body' => ['ko' => '본문']]);
        $template->update(['body' => ['ko' => '운영자 본문'], 'is_default' => false]);
        $definition->update(['is_default' => false]);

        $helper->syncDefinition([
            'type' => "probe_{$this->suffix}", 'hook_prefix' => 'core.probe', 'extension_type' => 'core', 'extension_identifier' => 'core',
            'name' => ['ko' => '실측', 'en' => 'Probe'], 'channels' => ['mail'], 'hooks' => ['core.probe.after'],
        ]);
        $helper->syncTemplate($definition->id, ['channel' => 'mail', 'subject' => ['ko' => '제목'], 'body' => ['ko' => '본문 v2']]);

        $this->assertFalse((bool) NotificationTemplate::find($template->id)->is_default, '수정본의 「기본」 표시가 되살아났다');
        $this->assertFalse((bool) NotificationDefinition::find($definition->id)->is_default);
        $this->assertSame('운영자 본문', NotificationTemplate::find($template->id)->body['ko']);
    }
}
