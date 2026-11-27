<?php

namespace Tests\Feature\UserOverrides;

use App\Models\NotificationDefinition;
use Database\Seeders\NotificationDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NotificationDefinition user_overrides 회귀 테스트.
 *
 * NotificationDefinitionSeeder 가 syncOrCreateFromUpgrade 패턴을 사용하여
 * 사용자가 수정한 trackable 필드(name, is_active)를 보존하는지 검증합니다.
 *
 * 시나리오:
 *  1. 시더 첫 실행 → NotificationDefinition 생성
 *  2. 사용자가 name 을 수정 → user_overrides 자동 기록
 *  3. 시더 재실행 → name 보존 (시더 정의값 무시), 다른 필드는 갱신
 */
class NotificationDefinitionUserOverridesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 시더 첫 실행이 알림 정의를 만들고, 만든 직후에는 user_overrides 가 비어 있는지 확인한다.
     *
     * @scenario write_path=sync_or_create_missing
     *
     * @effects sync_or_create_creates_missing_record_without_overrides
     *
     * @return void 반환값 없음
     */
    public function test_seeder_creates_definitions_with_null_user_overrides(): void
    {
        (new NotificationDefinitionSeeder)->run();

        $welcome = NotificationDefinition::where('type', 'welcome')->first();
        $this->assertNotNull($welcome);
        $this->assertNull($welcome->user_overrides);
    }

    /**
     * 사용자가 name 을 고치면 user_overrides 에 로케일별 경로(name.ko·name.en)로 기록되는지 확인한다.
     *
     * @scenario write_path=user_update
     *
     * @effects user_edit_records_trackable_fields_per_locale
     *
     * @return void 반환값 없음
     */
    public function test_user_modification_records_name_in_user_overrides(): void
    {
        (new NotificationDefinitionSeeder)->run();
        $definition = NotificationDefinition::where('type', 'welcome')->first();

        $definition->name = ['ko' => '커스텀 회원가입 환영', 'en' => 'Custom Welcome'];
        $definition->save();

        // `name` 은 다국어 JSON 컬럼이라 **sub-key dot-path 단위**로 기록된다
        // (`NotificationDefinition::$translatableTrackableFields`, HasUserOverrides @since 7.0.0-beta.4).
        // 이 인프라를 넣으면서 기록 단위가 `name` → `name.{locale}` 로 바뀌었는데,
        // 이 단언은 그 전 시점 그대로였다.
        $this->assertEqualsCanonicalizing(['name.ko', 'name.en'], $definition->fresh()->user_overrides);
    }

    /**
     * 시더를 다시 돌려도 사용자가 고친 name 과 그 user_overrides 기록이 그대로 남는지 확인한다.
     *
     * @scenario write_path=sync_or_create_existing
     *
     * @effects upgrade_sync_skips_user_overridden_fields, sync_or_create_preserves_existing_user_overrides
     *
     * @return void 반환값 없음
     */
    public function test_seeder_re_run_preserves_user_modified_name(): void
    {
        (new NotificationDefinitionSeeder)->run();
        $definition = NotificationDefinition::where('type', 'welcome')->first();
        $definition->name = ['ko' => '커스텀 회원가입 환영', 'en' => 'Custom Welcome'];
        $definition->save();

        // 시더 재실행 — syncOrCreateFromUpgrade 가 trackable 필드는 보존해야 함
        (new NotificationDefinitionSeeder)->run();

        $fresh = $definition->fresh();
        $this->assertEquals(['ko' => '커스텀 회원가입 환영', 'en' => 'Custom Welcome'], $fresh->name);
        $this->assertEqualsCanonicalizing(['name.ko', 'name.en'], $fresh->user_overrides);
    }

    /**
     * 추적 대상이 아닌 hooks 는 고쳐도 기록되지 않고, 시더를 다시 돌리면 정의값으로 갱신되는지 확인한다.
     *
     * @scenario write_path=sync_or_create_existing
     *
     * @effects non_trackable_edit_not_recorded, upgrade_sync_always_updates_non_trackable_fields
     *
     * @return void 반환값 없음
     */
    public function test_seeder_re_run_updates_non_trackable_fields(): void
    {
        (new NotificationDefinitionSeeder)->run();
        $definition = NotificationDefinition::where('type', 'welcome')->first();

        // hooks 는 trackable 외 필드 — 시더 재실행 시 항상 갱신되어야 함
        $definition->hooks = ['legacy.hook'];
        // hooks 수정은 user_overrides 에 기록되지 않아야 함
        $definition->save();
        $this->assertNull($definition->fresh()->user_overrides);

        (new NotificationDefinitionSeeder)->run();

        // 시더 정의값으로 갱신
        $this->assertEquals(['core.auth.after_register'], $definition->fresh()->hooks);
    }
}
