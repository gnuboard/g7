<?php

namespace App\Privacy\ManualRun;

use App\Privacy\Contracts\PrivacyManualRunnerInterface;
use App\Privacy\Contracts\PrivacyManualRunReadableInterface;
use App\Privacy\Contracts\PrivacyManualRunRequiresEnabledInterface;
use App\Services\PasswordExpiryService;

/**
 * 비밀번호 만료 처리 한 단계를 직권 실행 대상으로 감싼 러너
 *
 * 두 단계(사전 안내 · 만료일 처리)가 각각 하나의 「지금 실행」이다. 같은 회원 행의 표지를 다루므로
 * 락은 단계와 무관하게 하나(`password-expiry-run`)를 쓰고, 새벽 배치 `auth:process-password-expiry` 도
 * 같은 락을 잡는다. 휴면·보존기간과 달리 직권 실행도 토글이 켜져 있어야 한다 — 꺼져 있으면 로그인 관문이
 * 변경을 요구하지 않아, 꺼진 채 처리하면 "다음 로그인 때 변경" 안내와 로그아웃만 남는다(#736, 2026-10-03 확정).
 * 접수는 {@see PrivacyManualRunRequiresEnabledInterface} 로 거절되고, 접수 뒤 꺼지면 서비스가 처리하지 않는다.
 * 설정을 보기만 하는 관리자도 마지막 실행 기록을 본다({@see PrivacyManualRunReadableInterface}, 2026-10-04 결정).
 *
 * @since 7.1.0
 */
class PasswordExpiryStageRunner implements PrivacyManualRunnerInterface, PrivacyManualRunReadableInterface, PrivacyManualRunRequiresEnabledInterface
{
    /** 대상 키 접두사 */
    public const PREFIX = 'password_expiry:';

    /** 배치 커맨드와 공유하는 락 키 (두 단계 공통) */
    public const LOCK_KEY = 'password-expiry-run';

    /**
     * @param  string  $stage  단계 식별자 ({@see PasswordExpiryService::STAGES})
     * @param  PasswordExpiryService  $service  비밀번호 만료 정책 서비스
     */
    public function __construct(
        private string $stage,
        private PasswordExpiryService $service,
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
        return __('privacy.manual_run.password_expiry_stages.'.$this->stage);
    }

    /**
     * {@inheritDoc}
     */
    public function permissions(): array
    {
        return PasswordExpiryService::RUN_PERMISSIONS;
    }

    /**
     * {@inheritDoc}
     */
    public function readPermissions(): array
    {
        return PasswordExpiryService::RUN_READ_PERMISSIONS;
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled(): bool
    {
        return $this->service->isEnabled();
    }

    /**
     * {@inheritDoc}
     */
    public function supportsSkipWaiting(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function mode(): ?string
    {
        return 'notify';
    }

    /**
     * {@inheritDoc}
     *
     * 이번 실행에서 처리할 건수다 — min(대상 수, 1회 실행당 상한).
     */
    public function count(bool $skipWaiting = false): int
    {
        return $this->service->runCount($this->stage);
    }

    /**
     * {@inheritDoc}
     */
    public function run(bool $skipWaiting = false): array
    {
        $result = $this->stage === 'notice'
            ? $this->service->notifyUpcoming(false)
            : $this->service->processExpired(false);

        return [
            'count' => $result['count'],
            'mode' => 'notify',
            'remaining' => $result['remaining'],
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
