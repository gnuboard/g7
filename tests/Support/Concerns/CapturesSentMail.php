<?php

namespace Tests\Support\Concerns;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Services\NotificationChannelService;
use App\Services\NotificationDefinitionService;
use Database\Seeders\NotificationDefinitionSeeder;
use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mime\Email;

/**
 * 알림 파이프라인을 끝까지 태운 실제 발송 메일을 수집하는 테스트 도구.
 *
 * `Mail::fake()` 는 Mailable 만 기록하고, 알림 메일 채널이 보내는 `DbTemplateMail` 은
 * 가짜 메일러의 `send()` 가 조용히 버린다. `Notification::fake()` 는 디스패처(`NotificationDispatcher`)
 * 자체를 건너뛰어 채널 토글·준비 상태·발송 로그·발송 결과 보고서를 하나도 검증하지 못한다.
 * 그래서 메일러를 array 전송으로 바꿔 실제 경로를 그대로 태우고, 전송 계층이 받은 메시지를 읽는다.
 *
 * 개발 PC 의 실제 메일 설정(storage/app/settings/mail.json)이 부팅 시 config 로 새어 들어오므로
 * (mem: core-settings-boot-config-leaks-into-unit-tests) 메일러를 명시적으로 덮어쓴다.
 */
trait CapturesSentMail
{
    /**
     * 메일 채널을 발송 가능 상태로 만들고 메일러를 array 전송으로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function useArrayMailer(): void
    {
        app(ConfigRepositoryInterface::class)->saveCategory('mail', [
            'mailer' => 'array',
            'from_address' => 'test@g7.test',
            'from_name' => 'G7 Test',
        ]);

        config([
            'mail.default' => 'array',
            'mail.from.address' => 'test@g7.test',
            'mail.from.name' => 'G7 Test',
        ]);

        app('mail.manager')->forgetMailers();
        $this->setCoreMailChannelActive(true);
    }

    /**
     * 본인인증 메일을 보낼 수 있는 사이트 상태를 만듭니다 (메일러는 바꾸지 않는다).
     *
     * 본인인증 메일은 7.1.0 부터 알림 정의(category=identity)·메일 채널 준비 상태·채널 토글을 거친다.
     * RefreshDatabase 직후에는 정의가 없고 테스트 메일 설정은 준비 미완료라, 이 셋을 갖추지 않으면
     * 게이트 동작이 아니라 「코드 발송 실패」를 측정하게 된다. `Mail::fake()` 와 함께 써도 된다.
     *
     * @return void 반환값 없음
     */
    protected function prepareIdentityNotifications(): void
    {
        $this->seed(NotificationDefinitionSeeder::class);
        app(NotificationDefinitionService::class)->invalidateAllCache();
        $this->enableMailChannelReadiness();
        $this->setCoreMailChannelActive(true);
    }

    /**
     * 코어 「알림 채널 관리」의 메일 토글을 바꿉니다.
     *
     * @param  bool  $active  메일 채널 활성 여부
     * @return void 반환값 없음
     */
    protected function setCoreMailChannelActive(bool $active): void
    {
        app(ConfigRepositoryInterface::class)->saveCategory('notifications', [
            'channels' => [
                ['id' => 'mail', 'is_active' => $active, 'sort_order' => 1],
                ['id' => 'database', 'is_active' => true, 'sort_order' => 2],
            ],
        ]);
        config(['g7_settings.core.notifications.channels' => [
            ['id' => 'mail', 'is_active' => $active, 'sort_order' => 1],
            ['id' => 'database', 'is_active' => true, 'sort_order' => 2],
        ]]);

        app(NotificationChannelService::class)->clearChannelEnabledCache();
    }

    /**
     * array 전송이 받은 메시지를 요약해 반환합니다.
     *
     * @return array<int, array{to: string, subject: string, html: string}> 수신 순서대로의 메시지
     */
    protected function sentMails(): array
    {
        $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();

        if (! $transport instanceof ArrayTransport) {
            return [];
        }

        $mails = [];
        foreach ($transport->messages() as $sent) {
            $email = $sent->getOriginalMessage();
            if (! $email instanceof Email) {
                continue;
            }

            $mails[] = [
                'to' => implode(',', array_map(static fn ($a) => $a->getAddress(), $email->getTo())),
                'subject' => (string) $email->getSubject(),
                'html' => (string) $email->getHtmlBody(),
            ];
        }

        return $mails;
    }
}
