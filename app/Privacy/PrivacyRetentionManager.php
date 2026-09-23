<?php

namespace App\Privacy;

use App\Extension\HookManager;
use App\Privacy\Contracts\PrivacyRetentionHandlerInterface;
use App\Privacy\Handlers\WithdrawnUserRetentionHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 개인정보 보유기간 처리 진입점
 *
 * 등록된 {@see PrivacyRetentionHandlerInterface} 를 모아 실행합니다. 코어는 어떤 도메인이
 * 무엇을 지우는지 알지 못하며, 각 핸들러의 활성 여부와 건수만 보고 취합합니다.
 *
 * ## 등록
 *
 * 코어 기본은 탈퇴 회원 잔여 정보 핸들러 하나이고, 확장은 필터 훅으로 더합니다.
 *
 * ```php
 * HookManager::addFilter('core.privacy.retention_handlers', function (array $handlers) {
 *     $handlers[] = OrderRetentionHandler::class;
 *
 *     return $handlers;
 * });
 * ```
 *
 * 계약을 구현하지 않은 클래스나 생성이 실패한 클래스는 **기록 후 건너뜁니다** — 확장 하나가
 * 잘못 등록했다고 다른 도메인의 법정 파기 의무가 함께 멈추면 안 되기 때문입니다.
 * 반대로 조용히 버리지도 않습니다: 건너뛴 사실이 로그에 남지 않으면 운영자는 그 도메인이
 * 처리되고 있다고 믿게 됩니다.
 */
class PrivacyRetentionManager
{
    /** 확장이 보유기간 핸들러를 등록하는 필터 훅 */
    public const HANDLERS_FILTER = 'core.privacy.retention_handlers';

    /**
     * 해석된 핸들러 캐시 (요청 수명)
     *
     * @var array<string, PrivacyRetentionHandlerInterface>|null
     */
    private ?array $resolved = null;

    /**
     * 코어 기본 핸들러 클래스 목록을 반환합니다.
     *
     * @return array<int, class-string> 핸들러 클래스 목록
     */
    public function coreHandlers(): array
    {
        return [
            WithdrawnUserRetentionHandler::class,
        ];
    }

    /**
     * 등록된 핸들러 클래스 목록(코어 + 확장)을 반환합니다.
     *
     * @return array<int, mixed> 핸들러 클래스명 또는 인스턴스 목록
     */
    public function registered(): array
    {
        $handlers = HookManager::applyFilters(self::HANDLERS_FILTER, $this->coreHandlers());

        return is_array($handlers) ? array_values($handlers) : $this->coreHandlers();
    }

    /**
     * 계약을 만족하는 핸들러 인스턴스 목록을 반환합니다.
     *
     * 같은 `id()` 를 쓰는 핸들러가 둘이면 **먼저 등록된 쪽만** 남깁니다 — 뒤엣것을 덮어쓰면
     * 확장이 코어 회원 파기를 가로챌 수 있고, 둘 다 실행하면 같은 행을 두 번 처리합니다.
     *
     * @return array<string, PrivacyRetentionHandlerInterface> 식별자 => 핸들러
     */
    public function handlers(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $handlers = [];

        foreach ($this->registered() as $class) {
            $instance = $this->instantiate($class);

            if ($instance === null) {
                continue;
            }

            $id = $instance->id();

            if ($id === '' || isset($handlers[$id])) {
                Log::warning('개인정보 보유기간 핸들러 식별자가 비었거나 중복되어 건너뜀', [
                    'class' => is_string($class) ? $class : get_debug_type($class),
                    'id' => $id,
                ]);

                continue;
            }

            $handlers[$id] = $instance;
        }

        return $this->resolved = $handlers;
    }

    /**
     * 식별자로 핸들러 하나를 찾습니다.
     *
     * @param  string  $id  핸들러 식별자
     * @return PrivacyRetentionHandlerInterface|null 없으면 null
     */
    public function handler(string $id): ?PrivacyRetentionHandlerInterface
    {
        return $this->handlers()[$id] ?? null;
    }

    /**
     * 활성 핸들러의 대상 건수를 미리 봅니다.
     *
     * 꺼진 핸들러는 건수 대신 `null` 로 담습니다 — "꺼져 있음" 과 "대상 0건" 은
     * 운영자에게 전혀 다른 정보입니다.
     *
     * @return array<string, int|null> 식별자 => 건수(꺼져 있으면 null)
     */
    public function preview(): array
    {
        $result = [];

        foreach ($this->handlers() as $id => $handler) {
            if (! $this->isEnabled($handler)) {
                $result[$id] = null;

                continue;
            }

            try {
                $result[$id] = $handler->preview();
            } catch (Throwable $e) {
                $result[$id] = null;
                Log::warning('개인정보 보유기간 미리보기 실패', [
                    'handler' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * 활성 핸들러를 실행합니다.
     *
     * 한 핸들러가 던져도 나머지는 계속합니다 — 도메인 하나의 실패가 다른 도메인의
     * 법정 파기 의무를 막지 않아야 합니다. 실패는 결과에 `error` 로 남습니다.
     *
     * @param  bool  $dryRun  true 면 건수만 셉니다
     * @param  string|null  $only  지정하면 그 식별자 하나만 실행합니다
     * @return array<string, array{status: string, count: int, mode: string, label: string, error?: string}> 식별자별 실행 결과
     */
    public function process(bool $dryRun = false, ?string $only = null): array
    {
        $handlers = $this->handlers();

        if ($only !== null) {
            $handlers = isset($handlers[$only]) ? [$only => $handlers[$only]] : [];
        }

        $result = [];

        foreach ($handlers as $id => $handler) {
            $label = $this->label($handler);

            if (! $this->isEnabled($handler)) {
                $result[$id] = ['status' => 'disabled', 'count' => 0, 'mode' => 'none', 'label' => $label];

                continue;
            }

            try {
                $outcome = $handler->process($dryRun);
                $result[$id] = [
                    'status' => 'processed',
                    'count' => (int) ($outcome['count'] ?? 0),
                    'mode' => (string) ($outcome['mode'] ?? 'unknown'),
                    'label' => $label,
                ];
            } catch (Throwable $e) {
                $result[$id] = [
                    'status' => 'failed',
                    'count' => 0,
                    'mode' => 'none',
                    'label' => $label,
                    'error' => $e->getMessage(),
                ];
                Log::error('개인정보 보유기간 처리 실패', [
                    'handler' => $id,
                    'dry_run' => $dryRun,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * 클래스명 또는 인스턴스를 계약 구현 인스턴스로 해석합니다.
     *
     * @param  mixed  $class  클래스명 또는 인스턴스
     * @return PrivacyRetentionHandlerInterface|null 해석 실패 시 null
     */
    private function instantiate(mixed $class): ?PrivacyRetentionHandlerInterface
    {
        if ($class instanceof PrivacyRetentionHandlerInterface) {
            return $class;
        }

        if (! is_string($class) || $class === '') {
            Log::warning('개인정보 보유기간 핸들러 등록값이 클래스명이 아님', [
                'given' => get_debug_type($class),
            ]);

            return null;
        }

        if (! class_exists($class)) {
            Log::warning('개인정보 보유기간 핸들러 클래스를 찾을 수 없음', ['class' => $class]);

            return null;
        }

        try {
            $instance = app($class);
        } catch (Throwable $e) {
            Log::warning('개인정보 보유기간 핸들러 생성 실패', [
                'class' => $class,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $instance instanceof PrivacyRetentionHandlerInterface) {
            Log::warning('개인정보 보유기간 핸들러가 계약을 구현하지 않음', ['class' => $class]);

            return null;
        }

        return $instance;
    }

    /**
     * 핸들러의 활성 여부를 안전하게 조회합니다.
     *
     * @param  PrivacyRetentionHandlerInterface  $handler  핸들러
     * @return bool 활성 여부 (판독 실패 시 false)
     */
    private function isEnabled(PrivacyRetentionHandlerInterface $handler): bool
    {
        try {
            return $handler->isEnabled();
        } catch (Throwable $e) {
            Log::warning('개인정보 보유기간 핸들러 활성 판독 실패 — 꺼진 것으로 처리', [
                'handler' => $handler->id(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 핸들러 라벨을 안전하게 조회합니다.
     *
     * @param  PrivacyRetentionHandlerInterface  $handler  핸들러
     * @return string 라벨 (실패 시 식별자)
     */
    private function label(PrivacyRetentionHandlerInterface $handler): string
    {
        try {
            return $handler->label();
        } catch (Throwable) {
            return $handler->id();
        }
    }
}
