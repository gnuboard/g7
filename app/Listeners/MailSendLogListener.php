<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Enums\ExtensionOwnerType;
use App\Services\MailSendLogService;
use Illuminate\Support\Facades\Log;

/**
 * 메일 발송 훅을 구독하여 발송 이력을 기록합니다.
 *
 * core.mail.after_send, core.mail.send_failed, core.mail.send_skipped 훅을 구독합니다.
 */
class MailSendLogListener implements HookListenerInterface
{
    /**
     * MailSendLogListener 생성자.
     *
     * @param MailSendLogService $mailSendLogService 메일 발송 이력 서비스
     */
    public function __construct(
        private MailSendLogService $mailSendLogService
    ) {}

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.mail.after_send' => ['method' => 'handleAfterSend', 'priority' => 10],
            'core.mail.send_failed' => ['method' => 'handleSendFailed', 'priority' => 10],
            'core.mail.send_skipped' => ['method' => 'handleSendSkipped', 'priority' => 10],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param mixed ...$args 훅에서 전달된 인수들
     */
    public function handle(...$args): void
    {
        // 기본 핸들러는 사용하지 않음 (각 훅별 핸들러 사용)
    }

    /**
     * 메일 발송 성공 이력을 기록합니다.
     *
     * @param array $data 발송 데이터
     * @return void
     */
    public function handleAfterSend(array $data): void
    {
        try {
            $this->mailSendLogService->logSent(
                recipientEmail: $data['recipientEmail'] ?? '',
                recipientName: $data['recipientName'] ?? null,
                subject: $data['subject'] ?? null,
                body: $data['body'] ?? null,
                templateType: $data['templateType'] ?? null,
                extensionType: ExtensionOwnerType::tryFrom($data['extensionType'] ?? 'core') ?? ExtensionOwnerType::Core,
                extensionIdentifier: $data['extensionIdentifier'] ?? 'core',
                source: $data['source'] ?? null,
                senderEmail: $data['senderEmail'] ?? null,
                senderName: $data['senderName'] ?? null,
            );
        } catch (\Exception $e) {
            Log::warning('메일 발송 이력 기록 실패', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 메일 발송 실패 이력을 기록합니다.
     *
     * @param array $data 발송 데이터
     * @return void
     */
    public function handleSendFailed(array $data): void
    {
        try {
            $this->mailSendLogService->logFailed(
                recipientEmail: $data['recipientEmail'] ?? '',
                recipientName: $data['recipientName'] ?? null,
                subject: $data['subject'] ?? null,
                body: $data['body'] ?? null,
                templateType: $data['templateType'] ?? null,
                extensionType: ExtensionOwnerType::tryFrom($data['extensionType'] ?? 'core') ?? ExtensionOwnerType::Core,
                extensionIdentifier: $data['extensionIdentifier'] ?? 'core',
                source: $data['source'] ?? null,
                errorMessage: $data['errorMessage'] ?? null,
                senderEmail: $data['senderEmail'] ?? null,
                senderName: $data['senderName'] ?? null,
            );
        } catch (\Exception $e) {
            Log::warning('메일 발송 실패 이력 기록 실패', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 메일 발송 건너뜀 이력을 기록합니다.
     *
     * @param array $data 발송 데이터
     * @return void
     */
    public function handleSendSkipped(array $data): void
    {
        try {
            $this->mailSendLogService->logSkipped(
                recipientEmail: $data['recipientEmail'] ?? '',
                recipientName: $data['recipientName'] ?? null,
                templateType: $data['templateType'] ?? null,
                extensionType: ExtensionOwnerType::tryFrom($data['extensionType'] ?? 'core') ?? ExtensionOwnerType::Core,
                extensionIdentifier: $data['extensionIdentifier'] ?? 'core',
                source: $data['source'] ?? null,
                errorMessage: $data['errorMessage'] ?? null,
                senderEmail: $data['senderEmail'] ?? null,
                senderName: $data['senderName'] ?? null,
            );
        } catch (\Exception $e) {
            Log::warning('메일 발송 건너뜀 이력 기록 실패', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
