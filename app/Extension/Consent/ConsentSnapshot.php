<?php

namespace App\Extension\Consent;

/**
 * 동의 스냅샷
 *
 * 한 주체의 동의 상태를 담습니다. `status` 는 세 값입니다.
 *
 * - `ready`: 제공자가 답했다. `categories` 가 그 답이다.
 * - `unknown`: 아직 모르거나 판단할 수 없다(조회 실패, 응답 형식 오류, 제공자 모호, 고른 제공자 없음).
 *   소비자는 동의하지 않은 것으로 다룬다(fail-closed).
 * - `none`: 사이트에 동의 관리 제공자가 없다. 동의 확인 없이 무엇을 할지는 소비자의 정책이다.
 *
 * `necessary` 카테고리는 생성 시 언제나 true 로 둡니다 — 없으면 사이트가 서지 못하는 저장이라
 * 동의 대상이 아닙니다.
 *
 * 내보내기(`toArray`)에는 회원 번호·게스트 식별자·제공자 내부 값이 실리지 않습니다.
 *
 * @since 7.1.0
 */
final class ConsentSnapshot
{
    /** 공개 계약 버전 */
    public const CONTRACT_VERSION = 1;

    /** 상태 — 제공자가 답함 */
    public const STATUS_READY = 'ready';

    /** 상태 — 모름 (fail-closed) */
    public const STATUS_UNKNOWN = 'unknown';

    /** 상태 — 동의 관리 제공자 없음 */
    public const STATUS_NONE = 'none';

    /** 표준 카테고리 어휘 */
    public const STANDARD_CATEGORIES = ['necessary', 'functional', 'analytics', 'marketing'];

    /**
     * 동의 스냅샷을 생성합니다. 정적 생성자(`ready`·`unknown`·`none`)를 쓰세요.
     *
     * @param  string  $status  상태 (`ready`·`unknown`·`none`)
     * @param  array<string, bool>  $categories  카테고리 → 동의 여부
     * @param  bool  $needsRenewal  정책이 바뀌어 다시 동의를 받아야 하는지
     * @param  string|null  $policyVersion  현재 동의 정책 버전
     * @param  string|null  $providerId  답한 제공자 식별자 (내보내지 않음)
     */
    private function __construct(
        public readonly string $status,
        public readonly array $categories,
        public readonly bool $needsRenewal,
        public readonly ?string $policyVersion,
        public readonly ?string $providerId,
    ) {}

    /**
     * 제공자가 답한 스냅샷을 만듭니다.
     *
     * 표준 카테고리 중 답이 없는 것은 false 로 채우고, `necessary` 는 true 로 둡니다. 키가 문자열이
     * 아니거나 값이 bool 로 바뀌지 않는 항목은 버립니다.
     *
     * @param  array<string, mixed>  $categories  카테고리 → 동의 여부
     * @param  bool  $needsRenewal  재동의 필요 여부
     * @param  string|null  $policyVersion  정책 버전
     * @param  string|null  $providerId  답한 제공자 식별자
     * @return self 스냅샷
     */
    public static function ready(array $categories, bool $needsRenewal = false, ?string $policyVersion = null, ?string $providerId = null): self
    {
        return new self(self::STATUS_READY, self::normalizeCategories($categories), $needsRenewal, $policyVersion, $providerId);
    }

    /**
     * 판단할 수 없는 스냅샷을 만듭니다.
     *
     * @return self 스냅샷
     */
    public static function unknown(): self
    {
        return new self(self::STATUS_UNKNOWN, self::normalizeCategories([]), false, null, null);
    }

    /**
     * 동의 관리 제공자가 없는 스냅샷을 만듭니다.
     *
     * @return self 스냅샷
     */
    public static function none(): self
    {
        return new self(self::STATUS_NONE, self::normalizeCategories([]), false, null, null);
    }

    /**
     * 카테고리 하나의 동의 여부를 반환합니다. `ready` 가 아니면 `necessary` 외에는 false 입니다.
     *
     * @param  string  $category  카테고리 키
     * @return bool 동의 여부
     */
    public function allows(string $category): bool
    {
        if ($category === 'necessary') {
            return true;
        }

        return $this->status === self::STATUS_READY && ($this->categories[$category] ?? false) === true;
    }

    /**
     * 분석 수집에 동의했는지 반환합니다 (`ready` 이고 재동의 대상이 아니며 analytics 동의).
     *
     * @return bool 분석 동의 여부
     */
    public function permitsAnalytics(): bool
    {
        return $this->status === self::STATUS_READY && ! $this->needsRenewal && $this->allows('analytics');
    }

    /**
     * 식별자(IP·방문자 쿠키)로 추적해도 되는지 반환합니다 (분석 동의 + 기능 동의).
     *
     * 기능 동의가 없으면 동의 관리 확장이 방문자 쿠키를 지우므로, 분석만 동의한 방문자를 쿠키 방식으로
     * 세면 비콘마다 새 방문이 됩니다.
     *
     * @return bool 추적 허용 여부
     */
    public function permitsTracking(): bool
    {
        return $this->permitsAnalytics() && $this->allows('functional');
    }

    /**
     * 공개 응답 모양(snake_case)으로 내보냅니다.
     *
     * @return array{contract_version: int, status: string, categories: array<string, bool>, needs_renewal: bool, policy_version: string|null}
     *                                                                                                                                         공개 스냅샷
     */
    public function toArray(): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $this->status,
            'categories' => $this->categories,
            'needs_renewal' => $this->needsRenewal,
            'policy_version' => $this->policyVersion,
        ];
    }

    /**
     * 카테고리 맵을 정규화합니다 (표준 어휘 채움 + necessary 강제 true).
     *
     * @param  array<mixed, mixed>  $categories  원본 맵
     * @return array<string, bool> 정규화한 맵
     */
    private static function normalizeCategories(array $categories): array
    {
        $normalized = array_fill_keys(self::STANDARD_CATEGORIES, false);

        foreach ($categories as $key => $value) {
            if (! is_string($key) || $key === '' || ! (is_bool($value) || $value === 0 || $value === 1)) {
                continue;
            }

            $normalized[$key] = (bool) $value;
        }

        $normalized['necessary'] = true;

        return $normalized;
    }
}
