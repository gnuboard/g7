<?php

namespace Tests\Feature\Installation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * IDV 마이그레이션 Installation 스모크 테스트.
 *
 * users 테이블의 신규 컬럼 5종과 identity_verification_logs 테이블이
 * 정상 생성되는지 검증합니다. (up→down→up 회귀 방지)
 */
class IdentityVerificationMigrationSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_identity_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'identity_verified_at'));
        $this->assertTrue(Schema::hasColumn('users', 'identity_verified_provider'));
        $this->assertTrue(Schema::hasColumn('users', 'identity_verified_purpose_last'));
        $this->assertTrue(Schema::hasColumn('users', 'identity_hash'));
        $this->assertTrue(Schema::hasColumn('users', 'mobile_verified_at'));
    }

    public function test_identity_verification_logs_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('identity_verification_logs'));

        foreach ([
            'id',
            'provider_id',
            'purpose',
            'channel',
            'user_id',
            'target_hash',
            'status',
            'render_hint',
            'attempts',
            'max_attempts',
            'ip_address',
            'user_agent',
            'origin_type',
            'origin_identifier',
            'origin_policy_key',
            'properties',
            'metadata',
            'verification_token',
            'expires_at',
            'verified_at',
            'consumed_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('identity_verification_logs', $column),
                "identity_verification_logs.{$column} column should exist"
            );
        }
    }
}
