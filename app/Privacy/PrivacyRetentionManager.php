<?php

namespace App\Privacy;

use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\User;
use App\Privacy\Contracts\PrivacyRetentionHandlerInterface;
use App\Privacy\Handlers\WithdrawnUserRetentionHandler;
use Illuminate\Support\Facades\Cache;
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

    /** 처리 락 유지 시간(초) — 한 핸들러의 최대 처리 시간보다 길게 */
    private const RUN_LOCK_SECONDS = 3600;

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

            $id = $this->identify($instance);

            if ($id === null) {
                continue;
            }

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
     * 핸들러 하나를 처리하는 동안 잡는 락의 키를 반환합니다.
     *
     * 새벽 배치({@see process()})와 관리자 직권 실행 큐 작업이 같은 키를 잡아 같은 도메인을
     * 동시에 처리하지 않는다.
     *
     * @param  string  $id  핸들러 식별자
     * @return string 캐시 락 키
     */
    public static function lockKey(string $id): string
    {
        return 'privacy-run:retention:'.$id;
    }

    /**
     * 모든 핸들러의 활성 여부와 대상 건수를 미리 봅니다.
     *
     * **꺼진 핸들러도 건수를 셉니다** — 운영자는 켜기 전에 규모를 확인해야 합니다(되돌릴 수
     * 없는 파기다). "꺼져 있음" 과 "대상 0건" 은 여전히 다른 정보이므로 `enabled` 로 함께
     * 알립니다. `count` 가 `null` 이면 그 핸들러의 미리보기가 실패한 것입니다(로그 참조).
     *
     * @return array<string, array{enabled: bool, count: int|null}> 식별자 => 활성 여부·건수
     */
    public function preview(): array
    {
        $result = [];

        foreach ($this->handlers() as $id => $handler) {
            $enabled = $this->isEnabled($handler);

            try {
                $result[$id] = ['enabled' => $enabled, 'count' => $handler->preview()];
            } catch (Throwable $e) {
                $result[$id] = ['enabled' => $enabled, 'count' => null];
                Log::warning('개인정보 보유기간 미리보기 실패', [
                    'handler' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * 관리자가 이 핸들러의 대상 목록을 볼 수 있는지 판정합니다.
     *
     * 핸들러가 선언한 권한(설정 읽기 + 도메인 읽기)을 **모두** 가져야 한다. 선언이 비었거나
     * 선언 조회가 실패하면 거부한다 — 명단에는 개인정보가 그대로 나오므로 모르면 막는다.
     *
     * @param  PrivacyRetentionHandlerInterface  $handler  핸들러
     * @param  User|null  $user  요청 관리자
     * @return bool 열람 가능 여부
     */
    public function canViewTargets(PrivacyRetentionHandlerInterface $handler, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        try {
            $permissions = array_values(array_filter(
                $handler->targetPermissions(),
                fn ($permission) => is_string($permission) && $permission !== '',
            ));
        } catch (Throwable $e) {
            Log::warning('개인정보 보유기간 핸들러 목록 권한 조회 실패 — 거부', [
                'handler' => $this->identify($handler) ?? get_class($handler),
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $this->canViewTargetList($permissions, $user);
    }

    /**
     * 관리자가 이 핸들러를 직권 실행할 수 있는지 판정합니다.
     *
     * 판정식은 명단 열람과 같다({@see canViewTargetList()}) — 권한을 **모두** 가져야 하고 각
     * 권한의 적용 범위가 전체여야 한다. 직권 실행은 도메인 전체를 처리하므로 범위를 좁혀 위임받은
     * 관리자에게 열면 자기 범위 밖의 행까지 파기하게 된다.
     *
     * @param  PrivacyRetentionHandlerInterface  $handler  핸들러
     * @param  User|null  $user  관리자
     * @return bool 실행 가능 여부 (권한 조회 실패 시 false)
     */
    public function canRun(PrivacyRetentionHandlerInterface $handler, ?User $user): bool
    {
        return $this->canViewTargetList($this->runPermissions($handler), $user);
    }

    /**
     * 핸들러의 직권 실행 권한 목록을 안전하게 조회합니다.
     *
     * @param  PrivacyRetentionHandlerInterface  $handler  핸들러
     * @return array<int, string> 권한 식별자 목록 (조회 실패 시 빈 배열 — 판정이 거부로 떨어진다)
     */
    public function runPermissions(PrivacyRetentionHandlerInterface $handler): array
    {
        try {
            return array_values(array_filter(
                $handler->runPermissions(),
                fn ($permission) => is_string($permission) && $permission !== '',
            ));
        } catch (Throwable $e) {
            Log::warning('개인정보 보유기간 핸들러 실행 권한 조회 실패 — 거부', [
                'handler' => $this->identify($handler) ?? get_class($handler),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * 핸들러 하나를 관리자 직권으로 실행합니다 — 활성 토글을 보지 않습니다.
     *
     * 자동 배치({@see process()})와 달리 예외를 삼키지 않는다 — 직권 실행은 큐 작업 한 건이
     * 한 핸들러이므로, 던져서 작업이 실패 기록을 남기게 하는 편이 운영자에게 정확하다.
     *
     * @param  string  $id  핸들러 식별자
     * @param  bool  $dryRun  true 면 건수만 셉니다
     * @return array{count: int, mode: string} 처리 건수와 방식
     *
     * @throws \InvalidArgumentException 등록되지 않은 식별자
     */
    public function runManually(string $id, bool $dryRun = false): array
    {
        $handler = $this->handler($id);

        if ($handler === null) {
            throw new \InvalidArgumentException(__('privacy.targets.unknown_handler'));
        }

        $outcome = $handler->process($dryRun, true);

        return [
            'count' => (int) ($outcome['count'] ?? 0),
            'mode' => (string) ($outcome['mode'] ?? 'unknown'),
        ];
    }

    /**
     * 핸들러의 표시 이름을 안전하게 반환합니다.
     *
     * @param  PrivacyRetentionHandlerInterface  $handler  핸들러
     * @return string 라벨 (실패 시 식별자)
     */
    public function labelOf(PrivacyRetentionHandlerInterface $handler): string
    {
        return $this->label($handler);
    }

    /**
     * 핸들러의 활성 여부를 안전하게 반환합니다.
     *
     * @param  PrivacyRetentionHandlerInterface  $handler  핸들러
     * @return bool 활성 여부 (판독 실패 시 false)
     */
    public function enabledOf(PrivacyRetentionHandlerInterface $handler): bool
    {
        return $this->isEnabled($handler);
    }

    /**
     * 권한 목록으로 대상 명단 열람 가능 여부를 판정합니다 — 코어 휴면·확장 명단도 이 판정을 씁니다.
     *
     * 권한을 **모두** 가져야 하고, 각 권한의 적용 범위가 **전체**여야 한다. 회원·주문·게시판 목록은
     * 범위(`self`·`role`)를 걸어 본인·같은 역할 것만 보이는데, 명단은 도메인 전체를 모은 것이라
     * 범위를 걸 수 없다 — 범위가 좁은 관리자에게 열면 설정 화면을 거쳐 목록 화면보다 넓은 명단을
     * 보게 된다(2026-09-23 확정 「전체 범위만 명단 허용」). 건수는 이 판정과 무관하게 보인다.
     *
     * @param  array<int, string>  $permissions  필요한 권한 식별자 목록
     * @param  User|null  $user  요청 관리자
     * @return bool 열람 가능 여부
     */
    public function canViewTargetList(array $permissions, ?User $user): bool
    {
        if ($user === null || $permissions === []) {
            return false;
        }

        if (! $user->hasPermissions($permissions, true, PermissionType::Admin)) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($user->getEffectiveScopeForPermission($permission) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * 활성 핸들러를 실행합니다.
     *
     * 한 핸들러가 던져도 나머지는 계속합니다 — 도메인 하나의 실패가 다른 도메인의
     * 법정 파기 의무를 막지 않아야 합니다. 실패는 결과에 `error` 로 남습니다.
     *
     * @param  bool  $dryRun  true 면 건수만 셉니다
     * @param  string|null  $only  지정하면 그 식별자 하나만 실행합니다
     * @return array<string, array{status: string, count: int, mode: string, label: string, error?: string}> 식별자별 실행 결과 (status: processed·disabled·failed·locked — locked 는 직권 실행이 처리 중이라 건너뜀)
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

            // 직권 실행과 같은 락 — 관리자가 화면에서 돌린 작업이 이 핸들러를 처리하는 중이면
            // 건너뛴다. 같은 행을 두 프로세스가 동시에 처리하면 prune 훅이 두 번 나가고 삭제
            // 방식은 서로의 대상을 지운다. 건너뛴 것은 다음 주기가 다시 잡는다.
            $lock = $dryRun ? null : Cache::lock(self::lockKey($id), self::RUN_LOCK_SECONDS);

            if ($lock !== null && ! $lock->get()) {
                $result[$id] = ['status' => 'locked', 'count' => 0, 'mode' => 'none', 'label' => $label];

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
            } finally {
                $lock?->release();
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
                'handler' => $this->identify($handler) ?? get_class($handler),
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
            return $this->identify($handler) ?? get_class($handler);
        }
    }

    /**
     * 핸들러 식별자를 안전하게 조회합니다.
     *
     * 식별자 조회도 확장 코드다 — 생성·활성 판독·라벨 조회를 전부 가드해 두고 이것만
     * 맨몸으로 부르면, 확장 핸들러 하나의 `id()` 가 던지는 순간 수집 자체가 죽어 **코어
     * 회원 파기까지 멈춘다**(이 클래스 docblock 이 금지한 바로 그 상황). 위 두 가드의
     * catch 블록도 여기를 거친다 — catch 안에서 다시 맨몸으로 부르면 가드가 무의미하다.
     *
     * @param  PrivacyRetentionHandlerInterface  $handler  핸들러
     * @return string|null 식별자 (조회 실패 시 null — 기록 후 그 핸들러를 건너뛴다)
     */
    private function identify(PrivacyRetentionHandlerInterface $handler): ?string
    {
        try {
            return $handler->id();
        } catch (Throwable $e) {
            Log::warning('개인정보 보유기간 핸들러 식별자 조회 실패 — 건너뜀', [
                'class' => get_class($handler),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
