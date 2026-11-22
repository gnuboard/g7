<?php

namespace Tests\Unit\Models;

use App\Enums\ExtensionOwnerType;
use App\Models\Menu;
use App\Services\MenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 메뉴 아이콘 정규형과 사용자 수정 표식(user_overrides) 테스트 (#381).
 *
 * 레거시 문자열과 구조형이 같은 바이트로 저장되므로 같은 아이콘을 다시 저장해도 「수정됨」이 생기지 않고,
 * 실제로 바꾸면 생기는지 — 모델 저장·mass update·업그레이드 계획·서비스 수정 네 경로에서 검증한다.
 */
class MenuIconUserOverridesTest extends TestCase
{
    use RefreshDatabase;

    /** @var string 테스트용 currentColor SVG */
    private const SAFE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2 2 22h20z"/></svg>';

    /**
     * 코어 소유 메뉴를 만듭니다 (사용자 수정 표식 없음).
     *
     * @param  mixed  $icon  아이콘 입력
     * @return Menu 메뉴
     */
    private function coreMenu(mixed $icon): Menu
    {
        return Menu::factory()->create([
            'icon' => $icon,
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
        ])->fresh();
    }

    /**
     * 뮤테이터는 모든 입력을 정규형으로 저장한다 — 공백 정규화·구조형 JSON·해석 불가 배열은 null.
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_mutator_stores_canonical_form(): void
    {
        $this->assertSame('fas fa-users', $this->coreMenu(' fas  fa-users ')->getRawOriginal('icon'));
        $this->assertSame('fas fa-users', $this->coreMenu(['type' => 'class', 'value' => 'fas fa-users'])->getRawOriginal('icon'));
        $this->assertSame(
            '{"path":"assets/menu.png","source":"package","type":"image"}',
            $this->coreMenu(['source' => 'package', 'type' => 'image', 'path' => 'assets/menu.png', 'url' => '/x'])->getRawOriginal('icon')
        );
        $this->assertSame(
            ['type' => 'svg', 'value' => self::SAFE_SVG],
            json_decode((string) $this->coreMenu(['type' => 'svg', 'value' => self::SAFE_SVG])->getRawOriginal('icon'), true)
        );
        $this->assertNull($this->coreMenu(['type' => 'unknown'])->getRawOriginal('icon'));

        $menu = $this->coreMenu('fas fa-users');
        $this->assertSame('fas fa-users', $menu->iconSpec()?->value);
    }

    /**
     * 같은 아이콘을 구조형으로 다시 저장하면 dirty 가 아니고 사용자 수정 표식이 생기지 않는다.
     *
     * @effects same_value_not_marked
     *
     * @return void 반환값 없음
     */
    public function test_resaving_same_icon_as_structured_is_not_marked(): void
    {
        $menu = $this->coreMenu('fas fa-users');

        $menu->icon = ['type' => 'class', 'value' => ' fas   fa-users '];
        $this->assertFalse($menu->isDirty('icon'));
        $menu->save();

        $this->assertNotContains('icon', $menu->fresh()->user_overrides ?? []);
    }

    /**
     * mass update 로 같은 뜻의 값을 써도 표식이 생기지 않고, 다른 값을 쓰면 생긴다.
     *
     * @effects same_value_not_marked, change_marked
     *
     * @return void 반환값 없음
     */
    public function test_mass_update_marks_only_real_icon_change(): void
    {
        $menu = $this->coreMenu('fas fa-users');

        Menu::where('id', $menu->id)->update(['icon' => ' fas  fa-users ']);
        $this->assertNotContains('icon', $menu->fresh()->user_overrides ?? []);

        Menu::where('id', $menu->id)->update(['icon' => 'fas fa-star']);
        $this->assertContains('icon', $menu->fresh()->user_overrides ?? []);
    }

    /**
     * 모델 저장으로 아이콘을 실제로 바꾸면(class → svg) 표식이 생긴다.
     *
     * @effects change_marked
     *
     * @return void 반환값 없음
     */
    public function test_real_icon_change_is_marked(): void
    {
        $menu = $this->coreMenu('fas fa-users');

        $menu->update(['icon' => ['type' => 'svg', 'value' => self::SAFE_SVG]]);

        $this->assertContains('icon', $menu->fresh()->user_overrides ?? []);
    }

    /**
     * 업그레이드 계획 — 운영자 값과 새 선언값이 같은 뜻이 되면(표기만 다름) icon 표식을 푼다.
     *
     * @effects sync_respects_override, same_value_not_marked
     *
     * @return void 반환값 없음
     */
    public function test_plan_upgrade_releases_mark_when_values_converge(): void
    {
        $menu = $this->coreMenu('fas fa-star');
        $menu->forceFill(['user_overrides' => ['icon']])->saveQuietly();
        $menu = $menu->fresh();

        $converged = $menu->planUpgrade(['icon' => ['type' => 'class', 'value' => ' fas  fa-star ']]);
        $this->assertArrayNotHasKey('icon', $converged);
        $this->assertArrayHasKey('user_overrides', $converged);
        $this->assertNull($converged['user_overrides']);

        $diverged = $menu->planUpgrade(['icon' => 'fas fa-heart']);
        $this->assertSame([], $diverged, '운영자 표식이 있는 icon 은 다른 선언값으로 덮지 않는다');
    }

    /**
     * 비교값 정규화는 icon 만 한다 — name·url 은 원값 그대로 비교한다.
     *
     * @effects same_value_not_marked
     *
     * @return void 반환값 없음
     */
    public function test_comparable_value_normalizes_only_icon(): void
    {
        $menu = new Menu;

        $this->assertSame('fas fa-x', $menu->comparableTrackableValue('icon', ' fas  fa-x '));
        $this->assertSame('fas fa-x', $menu->comparableTrackableValue('icon', ['type' => 'class', 'value' => 'fas fa-x']));
        $this->assertSame(' 이름 ', $menu->comparableTrackableValue('name', ' 이름 '));
        $this->assertSame(['ko' => ' a '], $menu->comparableTrackableValue('name', ['ko' => ' a ']));
        $this->assertSame(' /a ', $menu->comparableTrackableValue('url', ' /a '));
    }

    /**
     * 서비스 수정에 icon_spec 만 보내도 icon 수정 표식이 남는다 — 변환이 before_update 훅보다 앞선다.
     *
     * @scenario icon_type=class, icon_type=svg, entry=api_update
     *
     * @effects change_marked, same_value_not_marked
     *
     * @return void 반환값 없음
     */
    public function test_service_update_with_icon_spec_only_marks_icon(): void
    {
        $menu = $this->coreMenu('fas fa-users');
        $service = app(MenuService::class);

        $service->updateMenu($menu->fresh(), ['icon_spec' => ['type' => 'class', 'value' => 'fas  fa-users']]);
        $this->assertNotContains('icon', $menu->fresh()->user_overrides ?? []);

        $service->updateMenu($menu->fresh(), ['icon_spec' => ['type' => 'svg', 'value' => self::SAFE_SVG]]);
        $fresh = $menu->fresh();
        $this->assertContains('icon', $fresh->user_overrides ?? []);
        $this->assertSame('svg', $fresh->iconSpec()?->type);
    }

    /**
     * 레거시 icon 문자열로 수정하면 같은 뜻은 표식 없이 정규형으로 남고, 다른 클래스는 표식된다.
     *
     * @scenario icon_type=legacy_string, entry=api_update
     *
     * @effects canonical_storage, same_value_not_marked, change_marked
     *
     * @return void 반환값 없음
     */
    public function test_service_update_with_legacy_icon_string(): void
    {
        $menu = $this->coreMenu('fas fa-users');
        $service = app(MenuService::class);

        $service->updateMenu($menu->fresh(), ['icon' => ' fas  fa-users ']);
        $fresh = $menu->fresh();
        $this->assertSame('fas fa-users', $fresh->getRawOriginal('icon'));
        $this->assertNotContains('icon', $fresh->user_overrides ?? []);

        $service->updateMenu($fresh, ['icon' => 'fas fa-star']);
        $this->assertContains('icon', $menu->fresh()->user_overrides ?? []);
        $this->assertSame('fas fa-star', $menu->fresh()->getRawOriginal('icon'));
    }
}
