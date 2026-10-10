<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Plugins\Sirsoft\Gdpr\Enums\PrivacyNoticeStatus;
use Plugins\Sirsoft\Gdpr\Repositories\GdprPrivacyNoticeRepository;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/** 별도 SQLite 메모리 DB에서 연간 통지 마이그레이션을 검증하는 Installation 스모크입니다. */
#[Group('installation')]
class AnnualNoticeMigrationTest extends PluginTestCase
{
    /**
     * 상위 베이스의 전체 마이그레이션 대신 테스트 메서드가 전용 연결을 생성합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void {}

    /**
     * 설치·재실행·롤백·재설치와 실제 이력 저장소 호출을 개발 DB와 분리해 검증합니다.
     *
     * @scenario flow=installation
     *
     * @effects schema_roundtrip, notice_queued, channel_result_appended
     *
     * @return void 반환값 없음
     */
    public function test_installation_roundtrip_and_repository_on_isolated_sqlite(): void
    {
        $connection = 'gdpr_annual_notice_migration';
        $originalDefault = DB::getDefaultConnection();
        $originalConfig = config('database.connections.'.$connection);

        try {
            config(['database.connections.'.$connection => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
            DB::purge($connection);
            DB::setDefaultConnection($connection);
            Schema::clearResolvedInstance('db.schema');
            $this->assertSame(':memory:', DB::connection()->getDatabaseName());

            Schema::create('users', function (Blueprint $table) {
                $table->id();
            });
            $migration = require dirname(__DIR__, 2).'/database/migrations/2026_09_19_000001_create_gdpr_privacy_notice_logs_table.php';
            $migration->up();

            $this->assertTrue(Schema::hasColumns('gdpr_privacy_notice_logs', [
                'id', 'user_id', 'attempt_id', 'status', 'channels', 'is_test', 'sent_at', 'snapshot', 'created_at',
            ]));
            $this->assertTrue(Schema::hasIndex('gdpr_privacy_notice_logs', 'gdpr_notice_user_date_idx'));
            $this->assertTrue(Schema::hasIndex('gdpr_privacy_notice_logs', 'gdpr_notice_attempt_status_idx'));
            $userId = DB::table('users')->insertGetId([]);
            $attempt = (string) Str::uuid();
            $repository = new GdprPrivacyNoticeRepository;
            $queued = $repository->append([
                'user_id' => $userId,
                'attempt_id' => $attempt,
                'status' => PrivacyNoticeStatus::Queued,
                'channels' => ['database'],
                'is_test' => false,
                'snapshot' => ['locale' => 'ko', 'sections' => []],
            ]);
            $this->assertSame(['database'], $repository->pending($attempt));
            $repository->append([
                'user_id' => $userId,
                'attempt_id' => $attempt,
                'status' => PrivacyNoticeStatus::Sent,
                'channels' => ['database'],
                'is_test' => false,
                'sent_at' => now(),
                'snapshot' => $queued->snapshot,
            ]);
            $this->assertSame([], $repository->pending($attempt));
            $migration->up();
            $this->assertSame(2, DB::table('gdpr_privacy_notice_logs')->count());
            $this->assertSame(PrivacyNoticeStatus::Queued, $queued->fresh()->status);

            DB::table('users')->where('id', $userId)->delete();
            $this->assertSame(2, DB::table('gdpr_privacy_notice_logs')->whereNull('user_id')->count());
            $migration->down();
            $this->assertFalse(Schema::hasTable('gdpr_privacy_notice_logs'));
            $migration->up();
            $this->assertTrue(Schema::hasTable('gdpr_privacy_notice_logs'));
            $this->assertSame(0, DB::table('gdpr_privacy_notice_logs')->count());
        } finally {
            DB::setDefaultConnection($originalDefault);
            Schema::clearResolvedInstance('db.schema');
            DB::purge($connection);
            config(['database.connections.'.$connection => $originalConfig]);
        }

        $this->assertSame($originalDefault, DB::getDefaultConnection());
    }
}
