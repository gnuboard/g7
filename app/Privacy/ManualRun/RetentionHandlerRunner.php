<?php

namespace App\Privacy\ManualRun;

use App\Privacy\Contracts\PrivacyManualRunnerInterface;
use App\Privacy\Contracts\PrivacyRetentionHandlerInterface;
use App\Privacy\PrivacyRetentionManager;

/**
 * 보유기간 핸들러 하나를 직권 실행 대상으로 감싼 러너
 *
 * 코어가 등록된 보유기간 핸들러마다 하나씩 만든다 — 확장은 핸들러만 등록하면 「지금 실행」이
 * 따라온다. 처리는 핸들러의 `process(force: true)` 이므로 건수·명단·자동 배치와 같은 조건식을 쓴다.
 */
class RetentionHandlerRunner implements PrivacyManualRunnerInterface
{
    /** 대상 키 접두사 */
    public const PREFIX = 'retention:';

    /**
     * dry-run 결과 캐시 (인스턴스 수명 = 요청 하나)
     *
     * @var array{count: int, mode: string}|null
     */
    private ?array $dryRunResult = null;

    /**
     * @param  string  $handlerId  핸들러 식별자
     * @param  PrivacyRetentionHandlerInterface  $handler  감쌀 핸들러
     * @param  PrivacyRetentionManager  $manager  보유기간 수집기 (안전 조회·실행 경로)
     */
    public function __construct(
        private string $handlerId,
        private PrivacyRetentionHandlerInterface $handler,
        private PrivacyRetentionManager $manager,
    ) {}

    /**
     * 핸들러 식별자로 대상 키를 만듭니다.
     *
     * @param  string  $handlerId  핸들러 식별자
     * @return string 대상 키
     */
    public static function keyFor(string $handlerId): string
    {
        return self::PREFIX.$handlerId;
    }

    /**
     * 배치 커맨드와 공유하는 락 키를 만듭니다.
     *
     * @param  string  $handlerId  핸들러 식별자
     * @return string 락 키
     */
    public static function lockKeyFor(string $handlerId): string
    {
        return PrivacyRetentionManager::lockKey($handlerId);
    }

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return self::keyFor($this->handlerId);
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return $this->manager->labelOf($this->handler);
    }

    /**
     * {@inheritDoc}
     */
    public function permissions(): array
    {
        return $this->manager->runPermissions($this->handler);
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled(): bool
    {
        return $this->manager->enabledOf($this->handler);
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
        return $this->dryRun()['mode'];
    }

    /**
     * {@inheritDoc}
     */
    public function count(bool $skipWaiting = false): int
    {
        return $this->dryRun()['count'];
    }

    /**
     * 직권 dry-run 결과 — 건수와 방식을 한 번의 조회로 얻는다(확인 창이 둘 다 쓴다).
     *
     * @return array{count: int, mode: string} dry-run 결과
     */
    private function dryRun(): array
    {
        return $this->dryRunResult ??= $this->manager->runManually($this->handlerId, true);
    }

    /**
     * {@inheritDoc}
     */
    public function run(bool $skipWaiting = false): array
    {
        return $this->manager->runManually($this->handlerId);
    }

    /**
     * {@inheritDoc}
     */
    public function lockKey(): ?string
    {
        return self::lockKeyFor($this->handlerId);
    }
}
