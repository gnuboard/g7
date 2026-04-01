<?php

namespace Database\Seeders\Sample;

use App\Enums\ExtensionOwnerType;
use App\Enums\MailSendStatus;
use App\Models\MailSendLog;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 코어 메일 발송 이력 개발용 시더
 *
 * 코어 메일 템플릿(welcome, reset_password, password_changed)을 기반으로
 * 발송 이력 샘플 데이터를 생성합니다.
 *
 * 개발/테스트 용도이므로 설치 시 자동 실행되지 않습니다.
 * 수동 실행: php artisan db:seed --class=MailSendLogSeeder
 */
class MailSendLogSeeder extends Seeder
{
    use HasSeederCounts;

    /**
     * 코어 메일 템플릿 유형별 제목 패턴
     *
     * @var array<string, array{ko: string, en: string}>
     */
    private array $templateSubjects = [
        'welcome' => [
            'ko' => '[그누보드7] 회원가입을 환영합니다',
            'en' => '[Gnuboard7] Welcome to Our Service',
        ],
        'reset_password' => [
            'ko' => '[그누보드7] 비밀번호 재설정 안내',
            'en' => '[Gnuboard7] Password Reset Request',
        ],
        'password_changed' => [
            'ko' => '[그누보드7] 비밀번호가 변경되었습니다',
            'en' => '[Gnuboard7] Your Password Has Been Changed',
        ],
    ];

    /**
     * 시더 실행
     *
     * @return void
     */
    public function run(): void
    {
        $count = $this->getSeederCount('mail_send_logs', 30);

        $this->command->info('코어 메일 발송 이력 시딩 시작...');

        $templateTypes = array_keys($this->templateSubjects);
        $recipients = $this->getRecipients();

        for ($i = 0; $i < $count; $i++) {
            $templateType = $templateTypes[array_rand($templateTypes)];
            $recipient = $recipients[array_rand($recipients)];
            $status = $this->randomStatus();
            $sentAt = $this->randomSentAt();

            MailSendLog::create([
                'recipient_email' => $recipient['email'],
                'recipient_name' => $recipient['name'],
                'subject' => $this->templateSubjects[$templateType]['ko'],
                'template_type' => $templateType,
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'source' => 'notification',
                'status' => $status->value,
                'error_message' => $status === MailSendStatus::Failed
                    ? $this->randomErrorMessage()
                    : null,
                'sent_at' => $sentAt,
            ]);
        }

        $this->command->info("코어 메일 발송 이력 시딩 완료 ({$count}건)");
    }

    /**
     * 샘플 수신자 목록을 반환합니다.
     *
     * @return array<int, array{email: string, name: string}>
     */
    private function getRecipients(): array
    {
        return [
            ['email' => 'hong@example.com', 'name' => '홍길동'],
            ['email' => 'kim@example.com', 'name' => '김철수'],
            ['email' => 'lee@example.com', 'name' => '이영희'],
            ['email' => 'park@example.com', 'name' => '박민수'],
            ['email' => 'choi@example.com', 'name' => '최지은'],
            ['email' => 'jung@example.com', 'name' => '정수민'],
            ['email' => 'kang@example.com', 'name' => '강하늘'],
            ['email' => 'yoon@example.com', 'name' => '윤서연'],
            ['email' => 'john@example.com', 'name' => 'John Doe'],
            ['email' => 'jane@example.com', 'name' => 'Jane Smith'],
        ];
    }

    /**
     * 가중치 기반 랜덤 상태를 반환합니다.
     *
     * @return MailSendStatus
     */
    private function randomStatus(): MailSendStatus
    {
        $rand = mt_rand(1, 100);

        if ($rand <= 80) {
            return MailSendStatus::Sent;
        } elseif ($rand <= 95) {
            return MailSendStatus::Failed;
        }

        return MailSendStatus::Skipped;
    }

    /**
     * 최근 90일 내 랜덤 발송 시각을 반환합니다.
     *
     * @return Carbon
     */
    private function randomSentAt(): Carbon
    {
        return Carbon::now()->subDays(mt_rand(0, 90))->subHours(mt_rand(0, 23))->subMinutes(mt_rand(0, 59));
    }

    /**
     * 랜덤 에러 메시지를 반환합니다.
     *
     * @return string
     */
    private function randomErrorMessage(): string
    {
        $messages = [
            'Connection timed out after 30 seconds',
            'SMTP server returned 550: Mailbox not found',
            'DNS resolution failed for mail server',
            'Authentication failed: Invalid credentials',
            'Message rejected: Recipient address rejected',
        ];

        return $messages[array_rand($messages)];
    }
}
