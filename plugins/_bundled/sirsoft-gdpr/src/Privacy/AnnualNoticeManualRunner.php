<?php

namespace Plugins\Sirsoft\Gdpr\Privacy;

use App\Privacy\Contracts\PrivacyManualRunnerInterface;
use Plugins\Sirsoft\Gdpr\Services\GdprAnnualNoticeService;

/**
 * 개인정보 연간 통지를 관리자 직권으로 발송하는 코어 직권 실행 대상
 *
 * 플러그인 설정 화면의 「지금 실행」이 코어 직권 실행 API 를 거쳐 큐에서 이 러너를 부른다.
 * 발송 규칙은 정기 실행과 같다 — 실행당 상한(`batch_size`)과 「이번 주기에 이미 받은 회원 제외」를
 * 지키고, 연간 통지 토글만 보지 않는다(2026-09-25 확정). 남은 인원은 결과에 함께 적어 운영자가
 * 다시 실행할지 판단하게 한다.
 *
 * 통지 접수는 회원 행 잠금으로 직렬화되어 정기 실행과 겹쳐도 같은 회원에게 두 번 나가지 않으므로
 * 공유 락을 두지 않는다({@see lockKey()}).
 */
class AnnualNoticeManualRunner implements PrivacyManualRunnerInterface
{
    /** 대상 키 */
    public const KEY = 'sirsoft-gdpr:annual_notice';

    /**
     * @param  GdprAnnualNoticeService  $notices  연간 통지 서비스
     */
    public function __construct(private readonly GdprAnnualNoticeService $notices) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return __('sirsoft-gdpr::messages.annual_notice.manual_run_label');
    }

    /**
     * {@inheritDoc}
     *
     * 개인정보 설정 수정 권한 — 이 플러그인에서 설정 수정과 통지 운영이 같은 권한이다
     * (시험 발송·정책 버전 발행과 같은 결).
     */
    public function permissions(): array
    {
        return ['sirsoft-gdpr.privacy.update'];
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled(): bool
    {
        return $this->notices->isEnabled();
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
     * 이번 주기 대상 전원이다 — 한 번 실행에 접수되는 수는 `batch_size` 로 잘린다.
     */
    public function count(bool $skipWaiting = false): int
    {
        return $this->notices->dueCount();
    }

    /**
     * {@inheritDoc}
     */
    public function run(bool $skipWaiting = false): array
    {
        $count = $this->notices->run(force: true);

        return [
            'count' => $count,
            'mode' => 'notify',
            'remaining' => $this->notices->dueCount(),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function lockKey(): ?string
    {
        return null;
    }
}
