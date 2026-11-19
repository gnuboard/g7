<?php

namespace Plugins\Sirsoft\HeroSection\Support\Positions;

/**
 * 배너가 놓이는 자리 — 위치 + 대상. 순서·노출·통계는 이 단위로 센다.
 *
 * 정적 위치는 대상이 null 이다. 「대상 전체」(`allTargets`)는 등록되지 않은 위치의 관리자 목록처럼 대상을 가리지 않고 볼 때만 쓴다 —
 * 순서·노출에는 쓰지 않는다(대상마다 순서가 따로라 섞으면 순서가 꼬인다).
 */
final class Placement
{
    /**
     * Placement 생성자
     *
     * @param  string  $position  위치 키
     * @param  string|null  $targetKey  대상 키 (정적 위치는 null)
     * @param  bool  $anyTarget  대상을 가리지 않는지
     */
    private function __construct(
        public readonly string $position,
        public readonly ?string $targetKey,
        public readonly bool $anyTarget,
    ) {}

    /**
     * 위치 + 대상 한 자리를 만듭니다.
     *
     * @param  string  $position  위치 키
     * @param  string|null  $targetKey  대상 키 (정적 위치는 null, 빈 문자열도 null)
     * @return self 자리
     */
    public static function of(string $position, ?string $targetKey = null): self
    {
        return new self($position, $targetKey === '' ? null : $targetKey, false);
    }

    /**
     * 위치의 모든 대상을 묶은 자리를 만듭니다(관리자 목록 전용).
     *
     * @param  string  $position  위치 키
     * @return self 자리
     */
    public static function allTargets(string $position): self
    {
        return new self($position, null, true);
    }

    /**
     * 같은 자리인지 반환합니다.
     *
     * @param  self  $other  다른 자리
     * @return bool 같으면 true
     */
    public function equals(self $other): bool
    {
        return $this->position === $other->position && $this->targetKey === $other->targetKey && $this->anyTarget === $other->anyTarget;
    }
}
