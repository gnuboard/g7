<?php

namespace App\Services;

use App\Contracts\Repositories\MailSendLogRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\MailSendStatus;
use App\Extension\HookManager;
use App\Models\MailSendLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 메일 발송 이력 서비스
 */
class MailSendLogService
{
    /**
     * MailSendLogService 생성자.
     *
     * @param  MailSendLogRepositoryInterface  $repository  메일 발송 이력 리포지토리
     */
    public function __construct(
        private MailSendLogRepositoryInterface $repository
    ) {}

    public function logSent(
        string $recipientEmail,
        ?string $recipientName = null,
        ?string $subject = null,
        ?string $body = null,
        ?string $templateType = null,
        ExtensionOwnerType $extensionType = ExtensionOwnerType::Core,
        string $extensionIdentifier = 'core',
        ?string $source = null,
        ?string $senderEmail = null,
        ?string $senderName = null,
    ): MailSendLog {
        // Before 훅
        HookManager::doAction('core.mail_send_log.before_log_sent', $recipientEmail, $templateType, $extensionIdentifier);

        $log = $this->repository->create([
            'sender_email' => $senderEmail,
            'sender_name' => $senderName,
            'recipient_email' => $recipientEmail,
            'recipient_name' => $recipientName,
            'subject' => $subject,
            'body' => $body,
            'template_type' => $templateType,
            'extension_type' => $extensionType,
            'extension_identifier' => $extensionIdentifier,
            'source' => $source,
            'status' => MailSendStatus::Sent->value,
            'sent_at' => now(),
        ]);

        // After 훅
        HookManager::doAction('core.mail_send_log.after_log_sent', $log);

        return $log;
    }

    public function logFailed(
        string $recipientEmail,
        ?string $recipientName = null,
        ?string $subject = null,
        ?string $body = null,
        ?string $templateType = null,
        ExtensionOwnerType $extensionType = ExtensionOwnerType::Core,
        string $extensionIdentifier = 'core',
        ?string $source = null,
        ?string $errorMessage = null,
        ?string $senderEmail = null,
        ?string $senderName = null,
    ): MailSendLog {
        // Before 훅
        HookManager::doAction('core.mail_send_log.before_log_failed', $recipientEmail, $templateType, $errorMessage);

        $log = $this->repository->create([
            'sender_email' => $senderEmail,
            'sender_name' => $senderName,
            'recipient_email' => $recipientEmail,
            'recipient_name' => $recipientName,
            'subject' => $subject,
            'body' => $body,
            'template_type' => $templateType,
            'extension_type' => $extensionType,
            'extension_identifier' => $extensionIdentifier,
            'source' => $source,
            'status' => MailSendStatus::Failed->value,
            'error_message' => $errorMessage,
            'sent_at' => now(),
        ]);

        // After 훅
        HookManager::doAction('core.mail_send_log.after_log_failed', $log);

        return $log;
    }

    public function logSkipped(
        string $recipientEmail,
        ?string $recipientName = null,
        ?string $templateType = null,
        ExtensionOwnerType $extensionType = ExtensionOwnerType::Core,
        string $extensionIdentifier = 'core',
        ?string $source = null,
        ?string $errorMessage = null,
        ?string $senderEmail = null,
        ?string $senderName = null,
    ): MailSendLog {
        // Before 훅
        HookManager::doAction('core.mail_send_log.before_log_skipped', $recipientEmail, $templateType, $errorMessage);

        $log = $this->repository->create([
            'sender_email' => $senderEmail,
            'sender_name' => $senderName,
            'recipient_email' => $recipientEmail,
            'recipient_name' => $recipientName,
            'subject' => null,
            'template_type' => $templateType,
            'extension_type' => $extensionType,
            'extension_identifier' => $extensionIdentifier,
            'source' => $source,
            'status' => MailSendStatus::Skipped->value,
            'error_message' => $errorMessage,
            'sent_at' => now(),
        ]);

        // After 훅
        HookManager::doAction('core.mail_send_log.after_log_skipped', $log);

        return $log;
    }

    /**
     * 발송 이력 목록을 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지 당 항목 수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function getLogs(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->getPaginated($filters, $perPage);
    }

    /**
     * 발송 통계를 조회합니다.
     *
     * @return array{total: int, sent: int, failed: int, today: int} 통계 정보
     */
    public function getStatistics(): array
    {
        return $this->repository->getStatistics();
    }

    /**
     * 발송 이력을 삭제합니다.
     *
     * @param int $id 삭제할 발송 이력 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool
    {
        HookManager::doAction('core.mail_send_log.before_delete', $id);

        $result = $this->repository->delete($id);

        HookManager::doAction('core.mail_send_log.after_delete', $id);

        return $result;
    }

    /**
     * 여러 발송 이력을 일괄 삭제합니다.
     *
     * @param array<int> $ids 삭제할 발송 이력 ID 목록
     * @return int 삭제된 건수
     */
    public function deleteMany(array $ids): int
    {
        HookManager::doAction('core.mail_send_log.before_delete_many', $ids);

        $count = $this->repository->deleteMany($ids);

        HookManager::doAction('core.mail_send_log.after_delete_many', $ids, $count);

        return $count;
    }
}
