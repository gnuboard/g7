<?php

namespace App\Services;

use App\Contracts\Notifications\ChannelReadinessCheckerInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 알림 채널 발송 준비 상태 검증 서비스
 *
 * 각 채널의 설정 완료 여부를 사전 검증합니다.
 * 코어 채널(mail, database)의 체크 로직을 내장하고,
 * 플러그인은 core.notification.channel_readiness 필터 훅으로 확장할 수 있습니다.
 */
class ChannelReadinessService implements ChannelReadinessCheckerInterface
{
    /**
     * 요청 내 캐시 (동일 요청에서 반복 호출 방지).
     *
     * @var array<string, array{ready: bool, reason: string|null}>|null
     */
    private ?array $cache = null;

    /**
     * @param ConfigRepositoryInterface $configRepository
     */
    public function __construct(
        private readonly ConfigRepositoryInterface $configRepository,
    ) {}

    /**
     * 채널이 발송 준비 상태인지 확인합니다.
     *
     * @param string $channelId 채널 식별자
     * @return bool
     */
    public function isReady(string $channelId): bool
    {
        return $this->check($channelId)['ready'];
    }

    /**
     * 채널별 준비 상태와 사유를 반환합니다.
     *
     * @param string $channelId 채널 식별자
     * @return array{ready: bool, reason: string|null}
     */
    public function check(string $channelId): array
    {
        if ($this->cache !== null && isset($this->cache[$channelId])) {
            return $this->cache[$channelId];
        }

        try {
            $result = $this->performCheck($channelId);
        } catch (\Throwable $e) {
            // 안전성: 체크 자체가 실패하면 ready=true (발송 시도 허용)
            Log::warning('ChannelReadinessService: 체크 실패, 발송 허용', [
                'channel' => $channelId,
                'error' => $e->getMessage(),
            ]);
            $result = ['ready' => true, 'reason' => null];
        }

        $this->cache ??= [];
        $this->cache[$channelId] = $result;

        return $result;
    }

    /**
     * 여러 채널의 준비 상태를 일괄 반환합니다.
     *
     * @param array<string> $channelIds 채널 ID 배열
     * @return array<string, array{ready: bool, reason: string|null}>
     */
    public function checkAll(array $channelIds): array
    {
        $results = [];
        foreach ($channelIds as $id) {
            $results[$id] = $this->check($id);
        }

        return $results;
    }

    /**
     * 채널별 체크 로직을 실행합니다.
     *
     * 코어 채널은 내부 메서드로, 플러그인 채널은 훅으로 처리합니다.
     *
     * @param string $channelId 채널 식별자
     * @return array{ready: bool, reason: string|null}
     */
    private function performCheck(string $channelId): array
    {
        // 코어 채널 체크
        $result = match ($channelId) {
            'mail' => $this->checkMail(),
            'database' => $this->checkDatabase(),
            default => ['ready' => true, 'reason' => null],
        };

        // 플러그인 확장: 커스텀 채널 체커 적용
        return HookManager::applyFilters(
            'core.notification.channel_readiness',
            $result,
            $channelId
        );
    }

    /**
     * 메일 채널 준비 상태를 검증합니다.
     *
     * @return array{ready: bool, reason: string|null}
     */
    private function checkMail(): array
    {
        $mailSettings = $this->configRepository->getCategory('mail');
        $mailer = $mailSettings['mailer'] ?? 'smtp';

        // from_address는 모든 mailer에 공통 필수
        $fromAddress = $mailSettings['from_address'] ?? '';
        if (empty($fromAddress) || $fromAddress === 'noreply@example.com') {
            return ['ready' => false, 'reason' => 'notification.readiness.mail_from_address_not_configured'];
        }

        return match ($mailer) {
            'smtp' => $this->checkSmtp($mailSettings),
            'mailgun' => $this->checkMailgun($mailSettings),
            'ses' => $this->checkSes($mailSettings),
            'log', 'array' => ['ready' => true, 'reason' => null],
            default => ['ready' => true, 'reason' => null],
        };
    }

    /**
     * SMTP mailer 필수 설정을 검증합니다.
     *
     * @param array $settings 메일 설정
     * @return array{ready: bool, reason: string|null}
     */
    private function checkSmtp(array $settings): array
    {
        if (empty($settings['host'])) {
            return ['ready' => false, 'reason' => 'notification.readiness.mail_smtp_host_empty'];
        }
        if (empty($settings['port'])) {
            return ['ready' => false, 'reason' => 'notification.readiness.mail_smtp_port_empty'];
        }

        return ['ready' => true, 'reason' => null];
    }

    /**
     * Mailgun mailer 필수 설정을 검증합니다.
     *
     * @param array $settings 메일 설정
     * @return array{ready: bool, reason: string|null}
     */
    private function checkMailgun(array $settings): array
    {
        if (empty($settings['mailgun_domain'])) {
            return ['ready' => false, 'reason' => 'notification.readiness.mail_mailgun_domain_empty'];
        }
        if (empty($settings['mailgun_secret'])) {
            return ['ready' => false, 'reason' => 'notification.readiness.mail_mailgun_secret_empty'];
        }

        return ['ready' => true, 'reason' => null];
    }

    /**
     * SES mailer 필수 설정을 검증합니다.
     *
     * @param array $settings 메일 설정
     * @return array{ready: bool, reason: string|null}
     */
    private function checkSes(array $settings): array
    {
        if (empty($settings['ses_key'])) {
            return ['ready' => false, 'reason' => 'notification.readiness.mail_ses_key_empty'];
        }
        if (empty($settings['ses_secret'])) {
            return ['ready' => false, 'reason' => 'notification.readiness.mail_ses_secret_empty'];
        }

        return ['ready' => true, 'reason' => null];
    }

    /**
     * database 채널 준비 상태를 검증합니다.
     *
     * @return array{ready: bool, reason: string|null}
     */
    private function checkDatabase(): array
    {
        try {
            if (! Schema::hasTable('notifications')) {
                return ['ready' => false, 'reason' => 'notification.readiness.database_table_missing'];
            }
        } catch (\Throwable $e) {
            // DB 접속 실패: 안전하게 ready=true (발송 시도 허용)
            return ['ready' => true, 'reason' => null];
        }

        return ['ready' => true, 'reason' => null];
    }
}
