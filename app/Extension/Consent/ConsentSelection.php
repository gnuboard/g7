<?php

namespace App\Extension\Consent;

use App\Contracts\Extension\ConsentProviderInterface;

/**
 * 동의 제공자 선택 상태
 *
 * 운영자 선택값(`privacy.consent_provider`)과 등록된 제공자로 정해집니다.
 *
 * | state | 뜻 | 스냅샷 |
 * | --- | --- | --- |
 * | `none` | 동의 관리 없음 — 제공자 0개(`no_provider`) 또는 고른 제공자가 수집을 꺼 둠(`provider_unavailable`) | none |
 * | `auto` | 선택 비어 있음 + 활성 제공자 1개 | 그 제공자 |
 * | `selected` | 고른 제공자가 등록돼 있고 수집 중 | 그 제공자 |
 * | `ambiguous` | 선택 비어 있음 + 활성 제공자 2개 이상 | unknown |
 * | `missing` | 고른 제공자가 등록돼 있지 않음(비활성·삭제) | unknown |
 *
 * @since 7.1.0
 */
final class ConsentSelection
{
    /** 동의 관리 없음 */
    public const STATE_NONE = 'none';

    /** 자동 선택 */
    public const STATE_AUTO = 'auto';

    /** 운영자 선택 */
    public const STATE_SELECTED = 'selected';

    /** 후보가 여럿이라 고를 수 없음 */
    public const STATE_AMBIGUOUS = 'ambiguous';

    /** 고른 제공자가 사라짐 */
    public const STATE_MISSING = 'missing';

    /** none 사유 — 등록된(수집 중인) 제공자가 없음 */
    public const REASON_NO_PROVIDER = 'no_provider';

    /** none 사유 — 고른 제공자가 수집을 꺼 둠 */
    public const REASON_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /**
     * 선택 상태를 생성합니다.
     *
     * @param  string  $state  선택 상태
     * @param  ConsentProviderInterface|null  $provider  답할 제공자 (auto·selected 일 때만)
     * @param  array<int, ConsentProviderInterface>  $candidates  자동 선택 후보(수집 중인 제공자)
     * @param  string|null  $reason  none 의 사유 (`no_provider`·`provider_unavailable`)
     */
    public function __construct(
        public readonly string $state,
        public readonly ?ConsentProviderInterface $provider,
        public readonly array $candidates,
        public readonly ?string $reason = null,
    ) {}

    /**
     * 답할 제공자가 정해졌는지 반환합니다.
     *
     * @return bool auto·selected 이면 true
     */
    public function hasProvider(): bool
    {
        return $this->provider !== null;
    }

    /**
     * 사이트에 동의 관리 제공자가 있다고 볼지 반환합니다 (none 이 아니면 true).
     *
     * ambiguous·missing 도 「있다」로 봅니다 — 운영자 확인이 필요한 상태라 동의 없이 수집하면 안 됩니다.
     *
     * @return bool 동의 관리 대상 여부
     */
    public function governsConsent(): bool
    {
        return $this->state !== self::STATE_NONE;
    }

    /**
     * 화면용 요약을 내보냅니다.
     *
     * @return array{state: string, reason: string|null, provider_id: string|null, provider_label: string|null}
     *                                                                                                          선택 상태 요약
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'reason' => $this->reason,
            'provider_id' => $this->provider?->getId(),
            'provider_label' => $this->provider?->getLabel(),
        ];
    }
}
