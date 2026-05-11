<?php

namespace Tests\Unit\Models\Concerns;

use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HasUserOverrides Trait 의 다국어 JSON 컬럼 sub-key dot-path 추적/보존 검증.
 *
 * 7.0.0-beta.4 신규 정책:
 *   - $translatableTrackableFields 에 정의된 다국어 JSON 컬럼은 sub-key 단위로 추적
 *   - 사용자가 ko 만 수정 → user_overrides=['name.ko']
 *   - 시더 재실행 시 ko 만 보존, 신규 locale 키(en/ja) 는 자동 동기화
 *   - 일반 scalar 필드 (icon, order 등) 는 컬럼 단위 추적 (기존 동작)
 *
 * Menu 모델을 reference 로 사용 — name(translatable JSON) + icon/order/url(scalar) 혼합.
 */
class HasUserOverridesSubKeyTest extends TestCase
{
    use RefreshDatabase;

    private function createMenu(array $attributes = []): Menu
    {
        return Menu::create(array_merge([
            'name' => ['ko' => '초기 메뉴', 'en' => 'Initial Menu'],
            'slug' => 'test-menu-'.uniqid(),
            'url' => '/test',
            'icon' => 'home',
            'order' => 0,
            'is_active' => true,
        ], $attributes));
    }

    /** 시나리오 1: 다국어 컬럼 ko 만 수정 → user_overrides=['name.ko'] */
    public function test_translatable_subkey_ko_only_modification_records_dot_path(): void
    {
        $menu = $this->createMenu();

        $menu->name = ['ko' => '수정된 메뉴', 'en' => 'Initial Menu'];
        $menu->save();

        $this->assertEquals(['name.ko'], $menu->fresh()->user_overrides);
    }

    /** 시나리오 2: ko, en 동시 수정 → user_overrides=['name.ko', 'name.en'] */
    public function test_translatable_subkey_multiple_locales_modification(): void
    {
        $menu = $this->createMenu();

        $menu->name = ['ko' => '수정 ko', 'en' => 'Modified EN'];
        $menu->save();

        $this->assertEqualsCanonicalizing(
            ['name.ko', 'name.en'],
            $menu->fresh()->user_overrides
        );
    }

    /** 시나리오 3: ko 보존 + ja 신규 추가 (시더 재실행 시) — 사용자 ko 보존, ja 자동 동기화 */
    public function test_sync_from_upgrade_preserves_overridden_subkey_only(): void
    {
        $menu = $this->createMenu();
        $menu->name = ['ko' => '사용자 수정 ko', 'en' => 'Initial Menu'];
        $menu->save();

        // 시더가 ja 포함 신규 데이터로 재실행
        $menu->syncFromUpgrade([
            'name' => ['ko' => '시더 ko', 'en' => 'Seeder EN', 'ja' => 'シーダー JA'],
        ]);

        $fresh = $menu->fresh();
        $this->assertEquals('사용자 수정 ko', $fresh->name['ko']);   // ko 보존
        $this->assertEquals('Seeder EN', $fresh->name['en']);       // en 갱신
        $this->assertEquals('シーダー JA', $fresh->name['ja']);     // ja 자동 추가
    }

    /** 시나리오 4: 사용자 미수정 시 시더 재실행이 전체 갱신 */
    public function test_sync_from_upgrade_full_replace_when_no_user_overrides(): void
    {
        $menu = $this->createMenu();

        $menu->syncFromUpgrade([
            'name' => ['ko' => '시더 ko', 'en' => 'Seeder EN', 'ja' => 'シーダー JA'],
        ]);

        $fresh = $menu->fresh();
        $this->assertEquals(['ko' => '시더 ko', 'en' => 'Seeder EN', 'ja' => 'シーダー JA'], $fresh->name);
        $this->assertNull($fresh->user_overrides);
    }

    /** 시나리오 5: legacy 컬럼명 user_overrides=['name'] → 전체 컬럼 보존 (역호환) */
    public function test_sync_from_upgrade_legacy_column_level_override_preserved(): void
    {
        $menu = $this->createMenu();
        // 마이그레이션 전 형식 시뮬레이션
        $menu->update(['user_overrides' => ['name']]);

        $menu->syncFromUpgrade([
            'name' => ['ko' => '시더 ko', 'en' => 'Seeder EN', 'ja' => 'シーダー JA'],
        ]);

        $fresh = $menu->fresh();
        $this->assertEquals(['ko' => '초기 메뉴', 'en' => 'Initial Menu'], $fresh->name);
    }

    /** 시나리오 6: scalar 필드 (icon) 수정 → 컬럼명 단위 기록 (기존 동작) */
    public function test_scalar_trackable_field_records_column_name(): void
    {
        $menu = $this->createMenu();

        $menu->icon = 'changed-icon';
        $menu->save();

        $this->assertEquals(['icon'], $menu->fresh()->user_overrides);
    }

    /** 시나리오 7: scalar + translatable 동시 수정 → 두 형식 혼재 */
    public function test_mixed_scalar_and_translatable_modification(): void
    {
        $menu = $this->createMenu();

        $menu->icon = 'new-icon';
        $menu->name = ['ko' => '수정 ko', 'en' => 'Initial Menu'];
        $menu->save();

        $this->assertEqualsCanonicalizing(
            ['icon', 'name.ko'],
            $menu->fresh()->user_overrides
        );
    }

    /** 시나리오 8: scalar 필드는 syncFromUpgrade 시 user_overrides 등록 시 갱신 SKIP (기존 동작 회귀 없음) */
    public function test_sync_from_upgrade_preserves_scalar_overridden_field(): void
    {
        $menu = $this->createMenu();
        $menu->icon = 'user-icon';
        $menu->save();

        $menu->syncFromUpgrade([
            'icon' => 'seeder-icon',
            'order' => 99,
        ]);

        $fresh = $menu->fresh();
        $this->assertEquals('user-icon', $fresh->icon); // 보존
        $this->assertEquals(99, $fresh->order);          // 갱신
    }

    /** 시나리오 9: 시더가 일부 locale 만 제공 + 사용자 보존 locale 누락 시 사용자 값 유지 */
    public function test_sync_from_upgrade_keeps_user_locale_when_seeder_omits_it(): void
    {
        $menu = $this->createMenu();
        $menu->name = ['ko' => '사용자 ko', 'en' => 'Initial Menu'];
        $menu->save();

        // 시더가 ja 만 제공 (ko 누락)
        $menu->syncFromUpgrade([
            'name' => ['ja' => 'シーダー JA'],
        ]);

        $fresh = $menu->fresh();
        $this->assertEquals('사용자 ko', $fresh->name['ko']);
        $this->assertEquals('シーダー JA', $fresh->name['ja']);
    }

    /** 시나리오 10: mass update 경로에서도 sub-key dot-path 자동 추적 */
    public function test_mass_update_records_subkey_overrides(): void
    {
        $menu = $this->createMenu();

        Menu::where('id', $menu->id)->update([
            'name' => ['ko' => '대량 수정 ko', 'en' => 'Initial Menu'],
        ]);

        $this->assertEquals(['name.ko'], $menu->fresh()->user_overrides);
    }

    /** 시나리오 11: 동일 sub-key 반복 수정 시 user_overrides 중복 없음 */
    public function test_repeated_subkey_modification_no_duplicate(): void
    {
        $menu = $this->createMenu();

        $menu->name = ['ko' => '첫 수정', 'en' => 'Initial Menu'];
        $menu->save();
        $menu->name = ['ko' => '두 번째 수정', 'en' => 'Initial Menu'];
        $menu->save();

        $this->assertEquals(['name.ko'], $menu->fresh()->user_overrides);
    }

    /** 시나리오 12: 다른 locale 추가 수정 시 누적 */
    public function test_subsequent_locale_modification_accumulates(): void
    {
        $menu = $this->createMenu();

        $menu->name = ['ko' => '수정 ko', 'en' => 'Initial Menu'];
        $menu->save();

        $menu->name = ['ko' => '수정 ko', 'en' => 'Modified EN'];
        $menu->save();

        $this->assertEqualsCanonicalizing(
            ['name.ko', 'name.en'],
            $menu->fresh()->user_overrides
        );
    }
}
