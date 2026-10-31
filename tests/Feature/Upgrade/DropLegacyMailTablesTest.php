<?php

namespace Tests\Feature\Upgrade;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_1_0\Migrations\DropLegacyMailTables;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 7.1.0 업그레이드 스텝 08 — 옛 메일 표 드랍 테스트 (#699 B1)
 *
 * 7.0.0-beta.2 부터 쓰이지 않는 mail_templates·mail_send_logs 를 안전할 때만 지운다. 옛 표는 7.1.0
 * 신규 설치에 없으므로 테스트가 직접 만들고, DDL 의 암묵 커밋 때문에 tearDown 에서 직접 정리한다.
 */
class DropLegacyMailTablesTest extends TestCase
{
    /** @var int 시작 시 notification_logs 최대 id */
    private int $maxLogId = 0;

    /**
     * 스텝 클래스를 불러오고 시작 상태를 기록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('upgrades/data/7.1.0/migrations/08_DropLegacyMailTables.php');
        $this->maxLogId = (int) DB::table('notification_logs')->max('id');
        $this->dropTables();
    }

    /**
     * 만든 표·로그를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->dropTables();
        DB::table('notification_logs')->where('id', '>', $this->maxLogId)->delete();

        parent::tearDown();
    }

    /**
     * 빈 표는 둘 다 드랍한다.
     *
     * @scenario surface=upgrade
     *
     * @effects upgrade_drops_empty_mail_tables
     *
     * @return void 반환값 없음
     */
    public function test_empty_tables_are_dropped(): void
    {
        $this->createTables();

        $this->runStep();

        $this->assertFalse(Schema::hasTable('mail_templates'));
        $this->assertFalse(Schema::hasTable('mail_send_logs'));
    }

    /**
     * 행이 있는 mail_templates, 이관되지 않은 mail_send_logs 는 보존한다.
     *
     * @scenario surface=upgrade
     *
     * @effects upgrade_keeps_mail_tables_with_rows
     *
     * @return void 반환값 없음
     */
    public function test_tables_with_unmigrated_rows_are_kept(): void
    {
        $this->createTables();
        DB::table('mail_templates')->insert(['type' => 'custom', 'body' => '<p>x</p>', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mail_send_logs')->insert(['recipient_email' => 'a@example.test', 'created_at' => now(), 'updated_at' => now()]);

        $this->runStep();

        $this->assertTrue(Schema::hasTable('mail_templates'));
        $this->assertTrue(Schema::hasTable('mail_send_logs'));
    }

    /**
     * 발송 로그로 이관이 끝난 mail_send_logs 는 드랍한다.
     *
     * @scenario surface=upgrade
     *
     * @effects upgrade_drops_migrated_mail_send_logs
     *
     * @return void 반환값 없음
     */
    public function test_migrated_send_logs_are_dropped(): void
    {
        $this->createTables();
        DB::table('mail_send_logs')->insert(['recipient_email' => 'a@example.test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notification_logs')->insert([
            'channel' => 'mail', 'notification_type' => 'welcome', 'extension_type' => 'core', 'extension_identifier' => 'core',
            'recipient_identifier' => 'a@example.test', 'status' => 'sent', 'source' => 'migrated_from_mail_send_logs',
            'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runStep();

        $this->assertFalse(Schema::hasTable('mail_send_logs'));
    }

    /**
     * 표가 없으면 건너뛴다 — 신규 설치·재실행.
     *
     * @scenario surface=upgrade
     *
     * @effects upgrade_rerun_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_absent_tables_are_skipped(): void
    {
        $this->runStep();

        $this->assertFalse(Schema::hasTable('mail_templates'));
        $this->assertFalse(Schema::hasTable('mail_send_logs'));
    }

    /**
     * 스텝을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new DropLegacyMailTables)->run(new UpgradeContext('7.0.11', '7.1.0', '7.1.0'));
    }

    /**
     * 옛 메일 표를 만듭니다 (판정에 필요한 칸만).
     *
     * @return void 반환값 없음
     */
    private function createTables(): void
    {
        Schema::create('mail_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 100);
            $table->mediumText('body')->nullable();
            $table->timestamps();
        });
        Schema::create('mail_send_logs', function (Blueprint $table) {
            $table->id();
            $table->string('recipient_email')->nullable();
            $table->timestamps();
        });
    }

    /**
     * 옛 메일 표를 지웁니다.
     *
     * @return void 반환값 없음
     */
    private function dropTables(): void
    {
        Schema::dropIfExists('mail_templates');
        Schema::dropIfExists('mail_send_logs');
    }
}
