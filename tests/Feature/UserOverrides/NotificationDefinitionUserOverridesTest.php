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

    public function test_seeder_creates_definitions_with_null_user_overrides(): void
    {
        (new NotificationDefinitionSeeder)->run();

        $welcome = NotificationDefinition::where('type', 'welcome')->first();
        $this->assertNotNull($welcome);
        $this->assertNull($welcome->user_overrides);
    }

    public function test_user_modification_records_name_in_user_overrides(): void
    {
        (new NotificationDefinitionSeeder)->run();
        $definition = NotificationDefinition::where('type', 'welcome')->first();

        $definition->name = ['ko' => '커스텀 회원가입 환영', 'en' => 'Custom Welcome'];
        $definition->save();

        $this->assertEquals(['name'], $definition->fresh()->user_overrides);
    }

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
        $this->assertEquals(['name'], $fresh->user_overrides);
    }

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
