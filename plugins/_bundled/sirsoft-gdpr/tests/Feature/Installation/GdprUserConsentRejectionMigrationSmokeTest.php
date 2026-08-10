<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature\Installation;

use Illuminate\Support\Facades\Schema;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * GDPR 거부(rejection) 컬럼 추가 마이그레이션 Installation 스모크 테스트.
 *
 * 이슈 #430 — 쿠키 거부 기능. gdpr_user_consents 테이블에
 * is_rejected / rejected_at 컬럼이 추가되고, up→down→up 왕복이 안전한지 검증.
 *
 * 기존 001 마이그레이션(drop→create)은 수정 금지 대상이므로 add-column 방식의
 * 별도 마이그레이션으로 컬럼을 추가한다. hasColumn 가드로 왕복 안전.
 */
class GdprUserConsentRejectionMigrationSmokeTest extends PluginTestCase
{
    /**
     * @scenario entry=reject, subject=member, category=optional
     * @effects rejection_columns_exist
     */
    public function test_rejection_columns_exist(): void
    {
        foreach (['is_rejected', 'rejected_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('gdpr_user_consents', $column),
                "gdpr_user_consents.{$column} column should exist"
            );
        }
    }

    /**
     * @scenario entry=reject, subject=member, category=optional
     * @effects is_rejected_defaults_to_false
     */
    public function test_is_rejected_defaults_to_false(): void
    {
        $user = \App\Models\User::factory()->create();

        $consent = \Plugins\Sirsoft\Gdpr\Models\GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'consent_category' => 'cookie',
            'is_consented' => false,
        ]);

        $this->assertFalse($consent->fresh()->is_rejected, 'is_rejected 기본값은 false 여야 함');
        $this->assertNull($consent->fresh()->rejected_at, 'rejected_at 기본값은 null 이어야 함');
    }

    /**
     * @scenario entry=reject, subject=member, category=optional
     * @effects rejection_migration_is_reversible
     */
    public function test_rejection_migration_is_reversible(): void
    {
        $migration = require base_path(
            'plugins/_bundled/sirsoft-gdpr/database/migrations/2026_07_14_000001_add_rejection_to_gdpr_user_consents.php'
        );

        // down: 컬럼 제거
        $migration->down();

        $this->assertFalse(
            Schema::hasColumn('gdpr_user_consents', 'is_rejected'),
            'down 후 is_rejected 컬럼이 제거되어야 함'
        );
        $this->assertFalse(
            Schema::hasColumn('gdpr_user_consents', 'rejected_at'),
            'down 후 rejected_at 컬럼이 제거되어야 함'
        );

        // up: 컬럼 재추가 (왕복 안전성 — hasColumn 가드로 중복 추가 방지)
        $migration->up();

        $this->assertTrue(
            Schema::hasColumn('gdpr_user_consents', 'is_rejected'),
            'up 재실행 후 is_rejected 컬럼이 복원되어야 함'
        );
        $this->assertTrue(
            Schema::hasColumn('gdpr_user_consents', 'rejected_at'),
            'up 재실행 후 rejected_at 컬럼이 복원되어야 함'
        );

        // up 재호출 시 hasColumn 가드로 중복 추가 예외가 발생하지 않아야 함 (idempotent)
        $migration->up();
        $this->assertTrue(Schema::hasColumn('gdpr_user_consents', 'is_rejected'));
    }
}
