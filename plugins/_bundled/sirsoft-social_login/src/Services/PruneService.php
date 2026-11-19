<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use Plugins\Sirsoft\SocialLogin\Mock\MockGate;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\AttemptRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\SocialAccountRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\WebhookEventRepositoryInterface;

/**
 * 보존 기간 정리 — 원본 프로필 사본 · 시도 기록 · 웹훅 수신 이력 · 만료된 모의 제공자 표지.
 *
 * 보존 기간은 설정(`attempt_log_retention_days`·`webhook_event_retention_days`, 원본 프로필은 연결 행마다 저장 때 정한 기한)을
 * 따른다. 기록 표 삭제는 500 행씩 끊어 지운다(큰 표에서 잠금을 오래 잡지 않게).
 *
 * @since 1.0.0
 */
class PruneService
{
    /**
     * @param  SocialAccountRepositoryInterface  $accounts  연결 저장소
     * @param  AttemptRepositoryInterface  $attempts  시도 저장소
     * @param  WebhookEventRepositoryInterface  $webhooks  웹훅 저장소
     * @param  MockGate  $mock  모의 게이트
     */
    public function __construct(
        private readonly SocialAccountRepositoryInterface $accounts,
        private readonly AttemptRepositoryInterface $attempts,
        private readonly WebhookEventRepositoryInterface $webhooks,
        private readonly MockGate $mock,
    ) {}

    /**
     * 정리를 실행합니다.
     *
     * @param  bool  $dryRun  true 면 세기만
     * @return array<string, int> 대상별 수 — profile_raw·attempts·webhook_events·mock_flag
     */
    public function run(bool $dryRun = false): array
    {
        $attemptDays = max(7, (int) plugin_setting('sirsoft-social_login', 'attempt_log_retention_days', 90));
        $webhookDays = max(7, (int) plugin_setting('sirsoft-social_login', 'webhook_event_retention_days', 90));

        return [
            'profile_raw' => $this->accounts->clearExpiredProfileRaw($dryRun),
            'attempts' => $this->attempts->pruneBefore(now()->subDays($attemptDays), $dryRun),
            'webhook_events' => $this->webhooks->pruneBefore(now()->subDays($webhookDays), $dryRun),
            'mock_flag' => $this->mock->pruneExpired($dryRun) ? 1 : 0,
        ];
    }
}
