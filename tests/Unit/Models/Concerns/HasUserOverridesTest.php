<?php

namespace Tests\Unit\Models\Concerns;

use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HasUserOverrides Trait 단위 테스트.
 *
 * NotificationTemplate 을 reference 모델로 사용하여 trait 의 8개 시나리오를 검증합니다.
 *
 * 검증 시나리오:
 *  1. 신규 생성 시 user_overrides 가 null 이고 자동 기록 안 됨
 *  2. 사용자 수정(일반 update) → trackable 필드가 user_overrides 에 자동 기록
 *  3. trackable 외 필드 수정 → 기록 안 됨
 *  4. syncFromUpgrade() 호출 시 user_overrides 에 기록된 필드는 갱신 스킵
 *  5. syncFromUpgrade() 호출 시 trackable 외 필드는 항상 갱신
 *  6. syncFromUpgrade() 후 user_overrides 자체는 변경 안 됨 (자동 기록 비활성)
 *  7. createFromUpgrade() 로 생성 시 자동 기록 비활성
 *  8. 동일 필드를 두 번 수정 시 user_overrides 에 중복 기록 안 됨
 */
class HasUserOverridesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 외래키 충족용 알림 정의 생성 후 NotificationTemplate 을 생성합니다.
     */
    private function createTemplate(array $attributes = []): NotificationTemplate
    {
        $definition = NotificationDefinition::factory()->create();

        return NotificationTemplate::create(array_merge([
            'definition_id' => $definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '초기 제목', 'en' => 'Initial Subject'],
            'body' => ['ko' => '초기 본문', 'en' => 'Initial Body'],
            'is_active' => true,
            'is_default' => true,
        ], $attributes));
    }

    /**
     * 시나리오 1: 신규 생성 시 user_overrides 가 null.
     */
    public function test_new_template_has_null_user_overrides(): void
    {
        $template = $this->createTemplate();

        $this->assertNull($template->fresh()->user_overrides);
    }

    /**
     * 시나리오 2: 사용자 수정 시 trackable 필드 자동 기록.
     */
    public function test_user_update_records_trackable_fields(): void
    {
        $template = $this->createTemplate();
        $template->subject = ['ko' => '수정된 제목', 'en' => 'Modified'];
        $template->save();

        $this->assertEquals(['subject'], $template->fresh()->user_overrides);
    }

    /**
     * 시나리오 3: trackable 외 필드 수정 → 기록 안 됨.
     */
    public function test_non_trackable_field_update_is_not_recorded(): void
    {
        $template = $this->createTemplate();
        // updated_by 는 trackableFields 에 없는 필드
        $template->updated_by = null;
        $template->is_default = false; // is_default 도 trackableFields 에 없음
        $template->save();

        $this->assertNull($template->fresh()->user_overrides);
    }

    /**
     * 시나리오 4: syncFromUpgrade 호출 시 user_overrides 에 기록된 필드는 갱신 스킵.
     */
    public function test_sync_from_upgrade_preserves_overridden_fields(): void
    {
        $template = $this->createTemplate();
        $template->subject = ['ko' => '사용자 수정 제목', 'en' => 'User Modified'];
        $template->save();

        // 시더가 다시 실행되는 상황 시뮬레이션
        $template->syncFromUpgrade([
            'subject' => ['ko' => '시더 제목', 'en' => 'Seeder Subject'],
            'body' => ['ko' => '시더 본문', 'en' => 'Seeder Body'],
        ]);

        $fresh = $template->fresh();
        $this->assertEquals(['ko' => '사용자 수정 제목', 'en' => 'User Modified'], $fresh->subject);
        $this->assertEquals(['ko' => '시더 본문', 'en' => 'Seeder Body'], $fresh->body);
    }

    /**
     * 시나리오 5: syncFromUpgrade 호출 시 trackable 외 필드는 항상 갱신.
     */
    public function test_sync_from_upgrade_always_updates_non_trackable_fields(): void
    {
        $template = $this->createTemplate(['is_default' => true]);

        $template->syncFromUpgrade([
            'is_default' => false,
        ]);

        $this->assertFalse($template->fresh()->is_default);
    }

    /**
     * 시나리오 6: syncFromUpgrade 후 user_overrides 자체는 변경 안 됨 (자동 기록 비활성).
     */
    public function test_sync_from_upgrade_does_not_record_overrides(): void
    {
        $template = $this->createTemplate();

        $template->syncFromUpgrade([
            'subject' => ['ko' => '시더 제목', 'en' => 'Seeder Subject'],
            'body' => ['ko' => '시더 본문', 'en' => 'Seeder Body'],
        ]);

        $this->assertNull($template->fresh()->user_overrides);
    }

    /**
     * 시나리오 7: createFromUpgrade 로 생성 시 자동 기록 비활성.
     */
    public function test_create_from_upgrade_does_not_record_overrides(): void
    {
        $definition = NotificationDefinition::factory()->create();

        $template = NotificationTemplate::createFromUpgrade([
            'definition_id' => $definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '시더 제목', 'en' => 'Seeder'],
            'body' => ['ko' => '시더 본문', 'en' => 'Seeder Body'],
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->assertNull($template->fresh()->user_overrides);
    }

    /**
     * 시나리오 8: 동일 필드를 두 번 수정 시 user_overrides 에 중복 기록 안 됨.
     */
    public function test_repeated_user_update_does_not_duplicate_overrides(): void
    {
        $template = $this->createTemplate();
        $template->subject = ['ko' => '첫 수정', 'en' => 'First'];
        $template->save();

        $template->subject = ['ko' => '두 번째 수정', 'en' => 'Second'];
        $template->save();

        $this->assertEquals(['subject'], $template->fresh()->user_overrides);
    }

    /**
     * 추가: syncOrCreateFromUpgrade — 존재하지 않으면 생성.
     */
    public function test_sync_or_create_from_upgrade_creates_when_missing(): void
    {
        $definition = NotificationDefinition::factory()->create();

        $template = NotificationTemplate::syncOrCreateFromUpgrade(
            ['definition_id' => $definition->id, 'channel' => 'mail'],
            [
                'subject' => ['ko' => '시더 제목', 'en' => 'Seeder'],
                'body' => ['ko' => '시더 본문', 'en' => 'Seeder Body'],
                'is_active' => true,
                'is_default' => true,
            ]
        );

        $this->assertNotNull($template->id);
        $this->assertNull($template->fresh()->user_overrides);
    }

    /**
     * 추가: syncOrCreateFromUpgrade — 존재하면 syncFromUpgrade 호출.
     */
    public function test_sync_or_create_from_upgrade_preserves_existing_overrides(): void
    {
        $template = $this->createTemplate();
        $template->subject = ['ko' => '사용자 수정', 'en' => 'User'];
        $template->save();

        NotificationTemplate::syncOrCreateFromUpgrade(
            ['definition_id' => $template->definition_id, 'channel' => 'mail'],
            [
                'subject' => ['ko' => '시더 덮어쓰기', 'en' => 'Seeder Override'],
                'body' => ['ko' => '시더 본문', 'en' => 'Seeder Body'],
            ]
        );

        $fresh = $template->fresh();
        $this->assertEquals(['ko' => '사용자 수정', 'en' => 'User'], $fresh->subject);
        $this->assertEquals(['ko' => '시더 본문', 'en' => 'Seeder Body'], $fresh->body);
    }
}
