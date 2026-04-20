<?php

namespace Database\Seeders\Sample;

use App\Enums\NotificationLogStatus;
use App\Models\NotificationLog;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 알림 발송 이력 개발용 시더
 *
 * 다양한 채널/타입의 알림 발송 이력 샘플 데이터를 생성합니다.
 * 개발/테스트 용도이므로 설치 시 자동 실행되지 않습니다.
 */
class NotificationLogSeeder extends Seeder
{
    use HasSeederCounts;

    /**
     * 시더 실행.
     *
     * @return void
     */
    public function run(): void
    {
        $count = $this->getSeederCount('notification_logs', 50);

        $this->command->info('알림 발송 이력 시딩 시작...');

        $channels = ['mail', 'database'];
        $types = [
            ['type' => 'welcome', 'ext_type' => 'core', 'ext_id' => 'core'],
            ['type' => 'reset_password', 'ext_type' => 'core', 'ext_id' => 'core'],
            ['type' => 'password_changed', 'ext_type' => 'core', 'ext_id' => 'core'],
            ['type' => 'order_confirmed', 'ext_type' => 'module', 'ext_id' => 'sirsoft-ecommerce'],
            ['type' => 'order_shipped', 'ext_type' => 'module', 'ext_id' => 'sirsoft-ecommerce'],
            ['type' => 'new_comment', 'ext_type' => 'module', 'ext_id' => 'sirsoft-board'],
            ['type' => 'new_post_admin', 'ext_type' => 'module', 'ext_id' => 'sirsoft-board'],
        ];
        $recipients = $this->getRecipients();

        for ($i = 0; $i < $count; $i++) {
            $channel = $channels[array_rand($channels)];
            $typeInfo = $types[array_rand($types)];
            $recipient = $recipients[array_rand($recipients)];
            $status = $this->randomStatus();
            $sentAt = Carbon::now()->subDays(mt_rand(0, 30))->subHours(mt_rand(0, 23))->subMinutes(mt_rand(0, 59));

            NotificationLog::create([
                'channel' => $channel,
                'notification_type' => $typeInfo['type'],
                'extension_type' => $typeInfo['ext_type'],
                'extension_identifier' => $typeInfo['ext_id'],
                'recipient_identifier' => $channel === 'mail' ? $recipient['email'] : (string) ($i + 1),
                'recipient_name' => $recipient['name'],
                'subject' => "[G7] {$typeInfo['type']} 알림",
                'status' => $status->value,
                'error_message' => $status === NotificationLogStatus::Failed ? 'SMTP connection refused' : null,
                'source' => 'notification',
                'sent_at' => $sentAt,
            ]);
        }

        $this->command->info("알림 발송 이력 시딩 완료 ({$count}건)");
    }

    /**
     * 샘플 수신자 목록.
     *
     * @return array
     */
    private function getRecipients(): array
    {
        return [
            ['email' => 'hong@example.com', 'name' => '홍길동'],
            ['email' => 'kim@example.com', 'name' => '김철수'],
            ['email' => 'lee@example.com', 'name' => '이영희'],
            ['email' => 'park@example.com', 'name' => '박민수'],
            ['email' => 'john@example.com', 'name' => 'John Doe'],
            ['email' => 'jane@example.com', 'name' => 'Jane Smith'],
        ];
    }

    /**
     * 가중치 기반 랜덤 상태.
     *
     * @return NotificationLogStatus
     */
    private function randomStatus(): NotificationLogStatus
    {
        $rand = mt_rand(1, 100);

        if ($rand <= 80) {
            return NotificationLogStatus::Sent;
        } elseif ($rand <= 95) {
            return NotificationLogStatus::Failed;
        }

        return NotificationLogStatus::Skipped;
    }
}
