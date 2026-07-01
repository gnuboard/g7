<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature\Installation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Sirsoft\Gdpr\Models\GdprPolicyVersion;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * GDPR 정책 버전 마이그레이션 Installation 스모크 테스트.
 *
 * 신규 설치 시점에 다음이 보장되는지 검증:
 *  - gdpr_policy_versions 테이블 + 모든 컬럼/인덱스 존재
 *  - initial 행 (version=1, change_type=initial) 자동 시드
 *  - up→down→up 왕복 안전성 (idempotent)
 *
 * GDPR Art.7(1) 동의 입증 책임 — 신규 설치 직후 회원 동의 발생 시 즉시
 * policy_version="1" 이 기록되어야 하므로 initial 시드 보장이 핵심.
 */
class GdprPolicyVersionMigrationSmokeTest extends PluginTestCase
{
    public function test_gdpr_policy_versions_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('gdpr_policy_versions'));
    }

    public function test_gdpr_policy_versions_columns_exist(): void
    {
        foreach ([
            'id',
            'version',
            'change_type',
            'memo',
            'snapshot',
            'created_by',
            'created_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('gdpr_policy_versions', $column),
                "gdpr_policy_versions.{$column} column should exist"
            );
        }
    }

    public function test_initial_seed_row_is_inserted_with_version_1(): void
    {
        $initial = GdprPolicyVersion::query()->orderBy('version')->first();

        $this->assertNotNull($initial, '마이그레이션이 initial 행을 시드하지 않았습니다');
        $this->assertSame(1, $initial->version);
        $this->assertSame('initial', $initial->change_type->value);
        $this->assertNull($initial->memo);
        $this->assertNull($initial->created_by);
    }

    /**
     * 초기 v1 시드 snapshot 이 plugin.php::getConfigValues() 의 settings 기본값을 포함하는지 검증.
     *
     * GDPR Art.7(1) 입증 책임 — 회원이 v1 에 동의했을 때 그 시점 정책 (cookie_categories /
     * blocked_domains / privacy_policy_slug) 을 추후 입증할 수 있어야 한다. 빈 snapshot 시드는
     * 입증 결함 (DPO 가 분쟁 시 "v1 시점 어떤 정책에 동의했는지" 답변 불가).
     */
    public function test_initial_seed_snapshot_includes_default_settings(): void
    {
        $initial = GdprPolicyVersion::query()->where('version', 1)->first();
        $this->assertNotNull($initial);

        $snapshot = $initial->snapshot;
        $this->assertIsArray($snapshot, 'snapshot 이 비어 있어 settings 기본값을 입증할 수 없음');

        // Phase 1: cookie_categories — 기본 4종 (necessary / functional / analytics / marketing) 시드 확인
        // ICO/CNIL 4분류 체계 부합.
        $this->assertArrayHasKey('cookie_categories', $snapshot);
        $this->assertIsArray($snapshot['cookie_categories']);
        $this->assertCount(4, $snapshot['cookie_categories'], 'Phase 1: 카테고리 4종 시드');
        $categoryKeys = array_column($snapshot['cookie_categories'], 'key');
        $this->assertContains('necessary', $categoryKeys);
        $this->assertContains('functional', $categoryKeys);
        $this->assertContains('analytics', $categoryKeys);
        $this->assertContains('marketing', $categoryKeys);

        // privacy_policy_slug — 기본 'privacy'
        $this->assertArrayHasKey('privacy_policy_slug', $snapshot);
        $this->assertSame('privacy', $snapshot['privacy_policy_slug']);

        // blocked_domains — 카탈로그 기본값 (functional + analytics + marketing 카테고리)
        // Phase 2: functional 카탈로그가 외부 functional 도구 도메인 (Crisp / Intercom / Tawk.to / Weglot / Usercentrics) 으로 채워짐
        $this->assertArrayHasKey('blocked_domains', $snapshot);
        $this->assertIsArray($snapshot['blocked_domains']);
        $this->assertArrayHasKey('functional', $snapshot['blocked_domains']);
        $this->assertNotEmpty($snapshot['blocked_domains']['functional'], 'Phase 2 functional 카탈로그가 비어있음 — DEFAULT_BLOCKED_DOMAINS_CATALOG 시드 확인 필요');
        $this->assertContains('*.crisp.chat', $snapshot['blocked_domains']['functional']);
        $this->assertArrayHasKey('analytics', $snapshot['blocked_domains']);
        $this->assertArrayHasKey('marketing', $snapshot['blocked_domains']);
    }

    public function test_initial_row_is_idempotent_on_migrate_rerun(): void
    {
        // RefreshDatabase 트레이트가 매 테스트마다 migrate:fresh 를 실행하므로
        // 본 테스트가 별도 인스턴스에서 실행될 때도 initial 행 *1개만* 존재해야 함.
        $count = GdprPolicyVersion::query()->where('change_type', 'initial')->count();

        $this->assertSame(1, $count, 'initial 행이 중복 시드되었습니다');
    }

    public function test_version_column_has_unique_constraint(): void
    {
        // version=1 row 가 이미 있으므로 같은 version 으로 INSERT 시도 시 예외
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('gdpr_policy_versions')->insert([
            'version' => 1,
            'change_type' => 'material',
            'memo' => 'duplicate version test',
            'snapshot' => '{}',
            'created_by' => null,
            'created_at' => now(),
        ]);
    }

    public function test_snapshot_column_stores_json(): void
    {
        $row = GdprPolicyVersion::create([
            'version' => 2,
            'change_type' => 'material',
            'memo' => 'snapshot test',
            'snapshot' => [
                'cookie_categories' => [
                    ['key' => 'necessary', 'required' => true],
                    ['key' => 'analytics', 'required' => false],
                ],
                'privacy_policy_slug' => 'privacy-policy',
            ],
        ]);

        $reloaded = GdprPolicyVersion::find($row->id);
        $this->assertSame('privacy-policy', $reloaded->snapshot['privacy_policy_slug']);
        $this->assertCount(2, $reloaded->snapshot['cookie_categories']);
    }
}
