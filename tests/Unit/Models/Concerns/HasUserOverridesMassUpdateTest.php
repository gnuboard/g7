<?php

namespace Tests\Unit\Models\Concerns;

use App\Models\NotificationDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * mass update 시 HasUserOverrides trait 의 투명 추적 회귀 방지 테스트.
 *
 * `UserOverridesAwareBuilder` 가 `Model::where(...)->update(...)` 를 가로채
 * user_overrides 에 수정 필드를 자동 기록하는지 검증합니다.
 *
 * 요구사항: `use HasUserOverrides;` 선언만으로 모든 수정 경로(인스턴스
 * update/save, mass update) 에서 user_overrides 가 자동 기록되어야 함.
 */
class HasUserOverridesMassUpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 기본 definition 생성 헬퍼.
     */
    private function createDefinition(array $overrides = []): NotificationDefinition
    {
        return NotificationDefinition::create(array_merge([
            'type' => 'mass-update-test',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '원본'],
            'description' => ['ko' => '원본'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ], $overrides));
    }

    public function test_mass_update_records_user_overrides_for_trackable_fields(): void
    {
        $definition = $this->createDefinition();

        // 사용자가 UI 에서 관리자 일괄 저장 시 흔히 발생하는 패턴.
        NotificationDefinition::where('id', $definition->id)->update([
            'name' => ['ko' => '사용자 수정'],
        ]);

        $reloaded = NotificationDefinition::find($definition->id);

        $this->assertSame(['ko' => '사용자 수정'], $reloaded->name);
        $this->assertIsArray($reloaded->user_overrides);
        $this->assertContains('name', $reloaded->user_overrides, 'mass update 시에도 trackable 필드가 user_overrides 에 자동 기록');
    }

    public function test_mass_update_accumulates_overrides_across_multiple_edits(): void
    {
        $definition = $this->createDefinition();

        NotificationDefinition::where('id', $definition->id)->update([
            'name' => ['ko' => '수정1'],
        ]);
        NotificationDefinition::where('id', $definition->id)->update([
            'is_active' => false,
        ]);

        $reloaded = NotificationDefinition::find($definition->id);

        $this->assertContains('name', $reloaded->user_overrides);
        $this->assertContains('is_active', $reloaded->user_overrides, '여러 번의 mass update 가 누적 기록');
    }

    public function test_mass_update_ignores_non_trackable_fields(): void
    {
        $definition = $this->createDefinition();

        // hook_prefix 는 trackableFields 에 없음
        NotificationDefinition::where('id', $definition->id)->update([
            'hook_prefix' => 'changed.prefix',
        ]);

        $reloaded = NotificationDefinition::find($definition->id);

        $this->assertSame('changed.prefix', $reloaded->hook_prefix);
        $this->assertEmpty($reloaded->user_overrides ?? [], 'trackable 외 필드는 user_overrides 기록 안 됨');
    }

    public function test_mass_update_across_multiple_rows_tracks_each_independently(): void
    {
        $a = $this->createDefinition(['type' => 'row-a']);
        $b = $this->createDefinition(['type' => 'row-b']);

        // 2행 동시 mass update
        NotificationDefinition::whereIn('id', [$a->id, $b->id])->update([
            'name' => ['ko' => '일괄수정'],
        ]);

        $reloadedA = NotificationDefinition::find($a->id);
        $reloadedB = NotificationDefinition::find($b->id);

        $this->assertContains('name', $reloadedA->user_overrides ?? []);
        $this->assertContains('name', $reloadedB->user_overrides ?? []);
    }

    public function test_seeder_context_bypasses_tracking_on_mass_update(): void
    {
        $definition = $this->createDefinition();

        app()->instance('user_overrides.seeding', true);
        try {
            NotificationDefinition::where('id', $definition->id)->update([
                'name' => ['ko' => '시더 수정'],
            ]);
        } finally {
            app()->forgetInstance('user_overrides.seeding');
        }

        $reloaded = NotificationDefinition::find($definition->id);

        $this->assertSame(['ko' => '시더 수정'], $reloaded->name);
        $this->assertEmpty(
            $reloaded->user_overrides ?? [],
            '시더 컨텍스트에서는 user_overrides 기록 안 됨 (성능 유지 + syncFromUpgrade 의미 보존)',
        );
    }

    public function test_raw_db_facade_update_bypasses_tracking(): void
    {
        $definition = $this->createDefinition();

        // 실제 대량 처리 성능이 필요한 경우를 위한 escape hatch — DB facade 사용.
        DB::table('notification_definitions')
            ->where('id', $definition->id)
            ->update(['name' => json_encode(['ko' => 'raw 수정'])]);

        $reloaded = NotificationDefinition::find($definition->id);

        $this->assertEmpty(
            $reloaded->user_overrides ?? [],
            'DB facade 직접 호출은 Eloquent Builder 를 거치지 않아 trait 미적용 — escape hatch',
        );
    }
}
