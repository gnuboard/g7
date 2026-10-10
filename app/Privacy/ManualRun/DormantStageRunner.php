<?php

namespace App\Privacy\ManualRun;

use App\Privacy\Contracts\PrivacyManualRunnerInterface;
use App\Privacy\DormantAccountService;

/**
 * 휴면 생명주기 한 단계를 직권 실행 대상으로 감싼 러너
 *
 * 네 단계(사전 안내 · 휴면 전환 · 탈퇴 예고 · 탈퇴)가 각각 하나의 「지금 실행」이다. 네 단계는
 * 같은 회원 행을 차례로 옮기므로 락은 단계와 무관하게 하나(`privacy-run:dormant`)를 쓰고,
 * 새벽 배치 `privacy:process-dormant` 도 같은 락을 잡는다.
 */
class DormantStageRunner implements PrivacyManualRunnerInterface
{
    /** 대상 키 접두사 */
    public const PREFIX = 'dormant:';

    /** 배치 커맨드와 공유하는 락 키 (네 단계 공통) */
    public const LOCK_KEY = 'privacy-run:dormant';

    /**
     * @param  string  $stage  단계 식별자 ({@see DormantAccountService::STAGES})
     * @param  DormantAccountService  $service  휴면 생명주기 서비스
     */
    public function __construct(
        private string $stage,
        private DormantAccountService $service,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return self::PREFIX.$this->stage;
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return __('privacy.manual_run.dormant_stages.'.$this->stage);
    }

    /**
     * {@inheritDoc}
     */
    public function permissions(): array
    {
        return DormantAccountService::RUN_PERMISSIONS;
    }

    /**
     * {@inheritDoc}
     *
     * 탈퇴 예고·탈퇴 단계는 자동 탈퇴 토글까지 켜져 있어야 자동 실행된다.
     */
    public function isEnabled(): bool
    {
        if (! $this->service->isEnabled()) {
            return false;
        }

        return in_array($this->stage, ['withdraw_notice', 'withdraw'], true)
            ? $this->service->isWithdrawEnabled()
            : true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsSkipWaiting(): bool
    {
        return $this->service->supportsSkipWaiting($this->stage);
    }

    /**
     * {@inheritDoc}
     */
    public function mode(): ?string
    {
        return $this->stage;
    }

    /**
     * {@inheritDoc}
     */
    public function count(bool $skipWaiting = false): int
    {
        return $this->service->runStage($this->stage, true, $skipWaiting);
    }

    /**
     * {@inheritDoc}
     */
    public function run(bool $skipWaiting = false): array
    {
        return [
            'count' => $this->service->runStage($this->stage, false, $skipWaiting),
            'mode' => $this->stage,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function lockKey(): ?string
    {
        return self::LOCK_KEY;
    }
}
