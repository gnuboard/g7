<?php

namespace App\Privacy\ManualRun;

use App\Extension\HookManager;
use App\Models\User;
use App\Privacy\Contracts\PrivacyManualRunnerInterface;
use App\Privacy\Contracts\PrivacyManualRunReadableInterface;
use App\Privacy\DormantAccountService;
use App\Privacy\PrivacyRetentionManager;
use App\Services\PasswordExpiryService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 개인정보 조치 직권 실행 대상 수집기
 *
 * 코어 기본 대상(보유기간 핸들러마다 하나 + 휴면 4단계 + 비밀번호 만료 2단계)과 확장이
 * `core.privacy.manual_run_targets` 필터로 더한 대상을 키로 모은다. 접수 API·큐 작업·상태
 * 조회가 모두 이 수집기에서 대상을 찾는다 — 큐 작업은 대상을 직렬화하지 않고 실행 시점에
 * 키로 다시 찾으므로, 그사이 확장이 비활성화되면 실행하지 않고 실패로 기록된다.
 *
 * 등록 규칙은 보유기간 수집기({@see PrivacyRetentionManager})와 같다 — 계약을 구현하지 않았거나
 * 생성·키 조회에 실패한 등록은 기록 후 건너뛰고, 같은 키가 둘이면 먼저 등록된 쪽만 남긴다
 * (확장이 코어 대상을 가로채지 못하게).
 */
class PrivacyManualRunRegistry
{
    /** 확장이 직권 실행 대상을 등록하는 필터 훅 */
    public const TARGETS_FILTER = 'core.privacy.manual_run_targets';

    /**
     * 해석된 대상 캐시 (요청 수명)
     *
     * @var array<string, PrivacyManualRunnerInterface>|null
     */
    private ?array $resolved = null;

    /**
     * @param  PrivacyRetentionManager  $retentionManager  보유기간 핸들러 수집기
     * @param  DormantAccountService  $dormantAccountService  휴면 생명주기 서비스
     * @param  PasswordExpiryService  $passwordExpiryService  비밀번호 만료 정책 서비스
     */
    public function __construct(
        private PrivacyRetentionManager $retentionManager,
        private DormantAccountService $dormantAccountService,
        private PasswordExpiryService $passwordExpiryService,
    ) {}

    /**
     * 모든 직권 실행 대상을 키 순서(등록 순)로 반환합니다.
     *
     * @return array<string, PrivacyManualRunnerInterface> 대상 키 => 러너
     */
    public function runners(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $runners = [];

        foreach ($this->retentionManager->handlers() as $id => $handler) {
            $runner = new RetentionHandlerRunner($id, $handler, $this->retentionManager);
            $runners[$runner->key()] = $runner;
        }

        foreach (DormantAccountService::STAGES as $stage) {
            $runner = new DormantStageRunner($stage, $this->dormantAccountService);
            $runners[$runner->key()] = $runner;
        }

        // 비밀번호 만료 2단계 (#736) — 보안 탭의 「지금 실행」. 개인정보 조치와 같은 접수·진행 장치를 쓴다.
        foreach (PasswordExpiryService::STAGES as $stage) {
            $runner = new PasswordExpiryStageRunner($stage, $this->passwordExpiryService);
            $runners[$runner->key()] = $runner;
        }

        foreach ($this->registeredExtensions() as $class) {
            $runner = $this->instantiate($class);

            if ($runner === null) {
                continue;
            }

            $key = $this->identify($runner);

            if ($key === null || $key === '' || isset($runners[$key])) {
                Log::warning('개인정보 직권 실행 대상 키가 비었거나 중복되어 건너뜀', [
                    'class' => is_string($class) ? $class : get_debug_type($class),
                    'key' => $key,
                ]);

                continue;
            }

            $runners[$key] = $runner;
        }

        return $this->resolved = $runners;
    }

    /**
     * 키로 대상을 찾습니다.
     *
     * @param  string  $key  대상 키
     * @return PrivacyManualRunnerInterface|null 없으면 null
     */
    public function runner(string $key): ?PrivacyManualRunnerInterface
    {
        return $this->runners()[$key] ?? null;
    }

    /**
     * 관리자가 이 대상을 직권 실행할 수 있는지 판정합니다.
     *
     * 명단 열람과 같은 판정식 — 권한을 모두 가져야 하고 각 권한의 적용 범위가 전체여야 한다
     * ({@see PrivacyRetentionManager::canViewTargetList()}). 권한 조회가 던지면 거부한다.
     *
     * @param  PrivacyManualRunnerInterface  $runner  대상
     * @param  User|null  $user  관리자
     * @return bool 실행 가능 여부
     */
    public function canRun(PrivacyManualRunnerInterface $runner, ?User $user): bool
    {
        try {
            $permissions = array_values(array_filter(
                $runner->permissions(),
                fn ($permission) => is_string($permission) && $permission !== '',
            ));
        } catch (Throwable $e) {
            Log::warning('개인정보 직권 실행 권한 조회 실패 — 거부', [
                'key' => $this->identify($runner) ?? get_class($runner),
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $this->retentionManager->canViewTargetList($permissions, $user);
    }

    /**
     * 관리자가 이 대상의 실행 기록(접수자 이름 포함)을 볼 수 있는지 판정합니다.
     *
     * 실행할 수 있으면 볼 수 있다. 그 밖에는 {@see PrivacyManualRunReadableInterface} 를 단 대상만 열람 권한으로
     * 판정한다(판정식은 실행과 같다 — 모두 가져야 하고 범위가 전체). 권한 조회가 던지면 거부한다.
     *
     * @param  PrivacyManualRunnerInterface  $runner  대상
     * @param  User|null  $user  관리자
     * @return bool 실행 기록 열람 가능 여부
     */
    public function canViewRun(PrivacyManualRunnerInterface $runner, ?User $user): bool
    {
        if ($this->canRun($runner, $user)) {
            return true;
        }

        if (! $runner instanceof PrivacyManualRunReadableInterface) {
            return false;
        }

        try {
            $permissions = array_values(array_filter(
                $runner->readPermissions(),
                fn ($permission) => is_string($permission) && $permission !== '',
            ));
        } catch (Throwable $e) {
            Log::warning('개인정보 직권 실행 기록 열람 권한 조회 실패 — 거부', [
                'key' => $this->identify($runner) ?? get_class($runner),
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $this->retentionManager->canViewTargetList($permissions, $user);
    }

    /**
     * 확장이 필터로 등록한 대상 클래스 목록.
     *
     * @return array<int, mixed> 클래스명 또는 인스턴스 목록
     */
    private function registeredExtensions(): array
    {
        $runners = HookManager::applyFilters(self::TARGETS_FILTER, []);

        return is_array($runners) ? array_values($runners) : [];
    }

    /**
     * 클래스명 또는 인스턴스를 계약 구현 인스턴스로 해석합니다.
     *
     * @param  mixed  $class  클래스명 또는 인스턴스
     * @return PrivacyManualRunnerInterface|null 해석 실패 시 null
     */
    private function instantiate(mixed $class): ?PrivacyManualRunnerInterface
    {
        if ($class instanceof PrivacyManualRunnerInterface) {
            return $class;
        }

        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            Log::warning('개인정보 직권 실행 대상 클래스를 찾을 수 없음', [
                'given' => is_string($class) ? $class : get_debug_type($class),
            ]);

            return null;
        }

        try {
            $instance = app($class);
        } catch (Throwable $e) {
            Log::warning('개인정보 직권 실행 대상 생성 실패', [
                'class' => $class,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $instance instanceof PrivacyManualRunnerInterface) {
            Log::warning('개인정보 직권 실행 대상이 계약을 구현하지 않음', ['class' => $class]);

            return null;
        }

        return $instance;
    }

    /**
     * 대상 키를 안전하게 조회합니다 — 확장 코드이므로 던지면 기록 후 null.
     *
     * @param  PrivacyManualRunnerInterface  $runner  대상
     * @return string|null 대상 키
     */
    private function identify(PrivacyManualRunnerInterface $runner): ?string
    {
        try {
            return $runner->key();
        } catch (Throwable $e) {
            Log::warning('개인정보 직권 실행 대상 키 조회 실패 — 건너뜀', [
                'class' => get_class($runner),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
