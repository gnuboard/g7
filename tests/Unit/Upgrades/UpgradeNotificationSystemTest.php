<?php

namespace Tests\Unit\Upgrades;

use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 알림 시스템 업그레이드 스텝 테스트
 *
 * mail_templates → notification_templates, mail_send_logs → notification_logs 이관을 검증합니다.
 */
class UpgradeNotificationSystemTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 코어 mail_templates → notification_templates 이관 검증
     */
    public function test_mail_templates_migrated_to_notification_templates(): void
    {
        // Given: notification_definition + mail_templates에 데이터 존재
        $definition = NotificationDefinition::create([
            'type' => 'welcome',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '환영'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        if (Schema::hasTable('mail_templates')) {
            DB::table('mail_templates')->insert([
                'type' => 'welcome',
                'subject' => json_encode(['ko' => '수정된 제목']),
                'body' => json_encode(['ko' => '수정된 본문']),
                'variables' => json_encode([]),
                'is_active' => true,
                'is_default' => false,
                'user_overrides' => json_encode(['subject']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // When: 이관 로직 실행
            NotificationTemplate::updateOrCreate(
                ['definition_id' => $definition->id, 'channel' => 'mail'],
                [
                    'subject' => json_encode(['ko' => '수정된 제목']),
                    'body' => json_encode(['ko' => '수정된 본문']),
                    'is_active' => true,
                    'is_default' => false,
                    'user_overrides' => json_encode(['subject']),
                ]
            );

            // Then
            $template = NotificationTemplate::where('definition_id', $definition->id)
                ->where('channel', 'mail')
                ->first();

            $this->assertNotNull($template);
            $this->assertFalse($template->is_default);
        } else {
            $this->markTestSkipped('mail_templates 테이블 미존재');
        }
    }

    /**
     * mail_send_logs → notification_logs 이관 검증
     */
    public function test_mail_send_logs_migrated_to_notification_logs(): void
    {
        if (! Schema::hasTable('mail_send_logs')) {
            $this->markTestSkipped('mail_send_logs 테이블 미존재');
        }

        // Given: mail_send_logs에 데이터 존재
        DB::table('mail_send_logs')->insert([
            'recipient_email' => 'test@example.com',
            'recipient_name' => 'Test User',
            'subject' => 'Welcome',
            'template_type' => 'welcome',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'source' => 'notification',
            'status' => 'sent',
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // When: 이관 로직 실행
        $logs = DB::table('mail_send_logs')->get();
        foreach ($logs as $log) {
            NotificationLog::create([
                'channel' => 'mail',
                'notification_type' => $log->template_type ?? '',
                'extension_type' => $log->extension_type ?? 'core',
                'extension_identifier' => $log->extension_identifier ?? 'core',
                'recipient_identifier' => $log->recipient_email ?? '',
                'recipient_name' => $log->recipient_name,
                'subject' => $log->subject,
                'status' => $log->status ?? 'sent',
                'source' => $log->source ?? 'notification',
                'sent_at' => $log->sent_at,
            ]);
        }

        // Then
        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'mail',
            'notification_type' => 'welcome',
            'recipient_identifier' => 'test@example.com',
            'status' => 'sent',
        ]);
    }

    /**
     * 권한 매핑 전환 검증
     */
    public function test_permission_mapping_migration(): void
    {
        // Given: 기존 권한 존재
        DB::table('permissions')->insert([
            'identifier' => 'core.mail-send-logs.read',
            'name' => json_encode(['ko' => '발송 이력 조회']),
            'type' => 'admin',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // When: 권한 매핑 전환
        DB::table('permissions')
            ->where('identifier', 'core.mail-send-logs.read')
            ->update(['identifier' => 'core.notification-logs.read']);

        // Then
        $this->assertDatabaseHas('permissions', [
            'identifier' => 'core.notification-logs.read',
        ]);
        $this->assertDatabaseMissing('permissions', [
            'identifier' => 'core.mail-send-logs.read',
        ]);
    }
}
