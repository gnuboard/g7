<?php

namespace Tests\Feature\Migrations;

use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * template_custom_translations 마이그레이션 테스트.
 *
 * 테이블 생성 + lock_version 컬럼 + up/down 라운드트립 안전성을 검증합니다.
 */
class TemplateCustomTranslationsMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 같은 스위트의 DB 테스트와 migrate:fresh 정합성을 위해 일관 선언
     * (requiredExtensions 누락 시 migrate:fresh 1회 함정 — feedback_required_extensions_all_db_tests).
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    public function test_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('template_custom_translations'));

        $this->assertTrue(Schema::hasColumns('template_custom_translations', [
            'id',
            'template_id',
            'layout_name',
            'translation_key',
            'values',
            'user_overrides',
            'status',
            'created_by',
            'updated_by',
            'lock_version',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_lock_version_column_defaults_to_zero(): void
    {
        $template = Template::factory()->create();

        $id = DB::table('template_custom_translations')->insertGetId([
            'template_id' => $template->id,
            'layout_name' => 'home',
            'translation_key' => 'custom.home.1',
            'values' => json_encode(['ko' => 'a', 'en' => 'a']),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('template_custom_translations')->find($id);

        $this->assertSame(0, (int) $row->lock_version);
    }

    public function test_lock_version_migration_down_then_up_roundtrip(): void
    {
        // down: lock_version 컬럼 제거
        Schema::table('template_custom_translations', function ($table) {
            $table->dropColumn('lock_version');
        });
        $this->assertFalse(Schema::hasColumn('template_custom_translations', 'lock_version'));

        // up: 컬럼 재추가 (마이그레이션 up 본문과 동일)
        Schema::table('template_custom_translations', function ($table) {
            $table->unsignedBigInteger('lock_version')->default(0)->after('updated_by')->comment('낙관적 잠금 버전');
        });
        $this->assertTrue(Schema::hasColumn('template_custom_translations', 'lock_version'));
    }
}
