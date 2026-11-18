<?php

namespace App\CoreUpdate;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\UnknownEmailLockService;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * 관리자 화면 코어 업데이트가 사이트를 점검 모드로 둔 동안, 우회 쿠키가 없는 관리자가 이어 갈 수 있게 하는 통로.
 *
 * 웹 코어 업데이트는 화면(`advance` 호출)이 진행을 몬다. 진행 중(되돌리기 포함)에 그 브라우저를 닫으면 남은 조각을 부를 주체가
 * 없고, 다른 브라우저·기기에는 점검 우회 쿠키가 없어 관리자 로그인 화면부터 503 이다 — 진행·되돌리기 면제 경로
 * (`MaintenanceModePage::MAINTENANCE_EXEMPT_URIS`)는 로그인 토큰이 있어야 해서 새 기기에서는 쓸 수 없다. 그러면 운영자가 FTP 로
 * 점검 파일을 지우기 전까지 사이트가 점검 모드에 남는다.
 *
 * 이 통로는 점검 화면의 관리자 확인 칸에서 이메일·비밀번호를 받아 **코어 업데이트 권한(`core.system.update`)을 가진 관리자**면
 * 점검 우회 쿠키만 발급한다(진행 화면이 이미 발급하는 것과 같은 쿠키). 로그인 세션·토큰은 만들지 않는다 — 관리자는 그 뒤 평소
 * 로그인(2단계 인증 포함)을 거쳐 업데이트 탭을 열고, 탭이 진행을 이어 간다. 통로는 점검 모드이면서 웹 코어 업데이트가
 * 진행 중·되돌리는 중·되돌리기 실패(복구 화면)일 때만 열린다. 시도는 IP 마다 10분에 5회로 제한하고, 실패 사유는 구분하지 않는다.
 * 틀린 비밀번호는 일반 로그인 실패와 같은 계수기(`core.auth.login_failed` → 로그인 실패 리스너)에 세고, 잠긴 계정(가입되지
 * 않은 주소의 잠금 흉내 포함)은 비밀번호가 맞아도 통과하지 못한다 — 이 칸이 잠금을 피해 가는 비밀번호 대입 통로가 되지 않게 한다.
 */
class CoreUpdateMaintenanceAccess
{
    /** 이 통로를 여는 실행 상태 — 진행 중·되돌리는 중·되돌리기 실패(점검 모드를 켠 채 복구 안내) */
    public const RECOVERABLE_STATUSES = [
        CoreUpdateRunState::STATUS_RUNNING,
        CoreUpdateRunState::STATUS_ROLLING_BACK,
        CoreUpdateRunState::STATUS_ROLLBACK_FAILED,
    ];

    /** 통로 주소 (점검 미들웨어가 이 주소의 POST 를 직접 처리하고, 점검 화면 확인 칸이 이리로 보낸다) */
    public const PATH = 'core-update/maintenance-access';

    /** 시도 상한 (IP 마다) */
    public const MAX_ATTEMPTS = 5;

    /** 시도 제한 창(초) */
    public const DECAY_SECONDS = 600;

    /** 결과 — 우회 쿠키 발급 */
    public const RESULT_GRANTED = 'granted';

    /** 결과 — 확인 실패(이메일·비밀번호·권한·회원 상태를 구분하지 않는다) */
    public const RESULT_FAILED = 'failed';

    /** 결과 — 시도 제한 */
    public const RESULT_THROTTLED = 'throttled';

    /** 결과 — 통로가 닫혀 있음(점검 아님·진행 중인 웹 업데이트 없음) */
    public const RESULT_UNAVAILABLE = 'unavailable';

    /**
     * @param  UserRepositoryInterface  $users  회원 저장소
     * @param  UnknownEmailLockService  $unknownEmailLock  가입되지 않은 주소의 잠금 흉내 (일반 로그인과 공유)
     * @param  CoreUpdateRunState|null  $runState  웹 실행 상태 (null 이면 기본 경로)
     */
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly UnknownEmailLockService $unknownEmailLock,
        private readonly ?CoreUpdateRunState $runState = null,
    ) {}

    /**
     * 통로가 열려 있는지 — 점검 모드이고 웹 코어 업데이트가 이어 가거나 복구할 상태인지.
     *
     * 점검 미들웨어(전역 맨 앞, DB 전)가 부르므로 실행 상태 파일만 읽는다.
     *
     * @return bool 열려 있으면 true
     */
    public function available(): bool
    {
        if (! app()->isDownForMaintenance()) {
            return false;
        }

        try {
            $status = ($this->runState ?? new CoreUpdateRunState)->status();
        } catch (\Throwable) {
            return false;
        }

        return in_array($status, self::RECOVERABLE_STATUSES, true);
    }

    /**
     * 관리자 확인을 시도합니다.
     *
     * @param  string  $email  이메일
     * @param  string  $password  비밀번호
     * @param  string  $ip  요청 IP (시도 제한 키)
     * @param  string|null  $userAgent  요청 User-Agent (로그인 실패 기록용 — 전역 요청에 기대지 않고 호출부가 넘긴다)
     * @return array{result: string, cookie: Cookie|null} 결과와 (발급했으면) 우회 쿠키
     */
    public function attempt(string $email, string $password, string $ip, ?string $userAgent = null): array
    {
        if (! $this->available()) {
            return ['result' => self::RESULT_UNAVAILABLE, 'cookie' => null];
        }

        $key = 'core-update-maintenance-access:'.sha1($ip);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return ['result' => self::RESULT_THROTTLED, 'cookie' => null];
        }

        $secret = $this->bypassSecret();
        $user = $email !== '' && $password !== '' ? $this->users->findByEmail($email) : null;

        // 일반 로그인과 같은 잠금 — 잠긴 계정(가입되지 않은 주소의 잠금 흉내 포함)은 비밀번호가 맞아도 통과하지 못하고,
        // 잠긴 동안의 시도는 세지 않는다(재잠금 시각을 늘리지 않게 — `AuthService::login()` 의 사전 잠금 판정과 같다).
        // 응답은 다른 실패와 같은 한 문장이라 잠금 여부도 드러나지 않는다.
        if ($email !== '' && $this->isLocked($user, $email)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return ['result' => self::RESULT_FAILED, 'cookie' => null];
        }

        $passwordMatches = $user !== null
            && is_string($user->password) && $user->password !== ''
            && Hash::check($password, $user->password);

        if (! $passwordMatches && $email !== '') {
            // 틀린 비밀번호(가입되지 않은 주소 포함)는 일반 로그인 실패와 같은 계수기에 센다 — 같은 훅을 그 요청 안에서(sync)
            // 받는 로그인 실패 리스너가 원자적 증가·임계 잠금·잠금 훅까지 맡는다(동시 요청도 정확히 센다).
            HookManager::doAction('core.auth.login_failed', $email, [
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'attempted_at' => now(),
                'source' => 'core_update_maintenance_access',
            ]);
        }

        $allowed = $passwordMatches
            && UserStatus::allowsLogin($user->status)
            && $user->hasPermission('core.system.update');

        if (! $allowed || $secret === null) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return ['result' => self::RESULT_FAILED, 'cookie' => null];
        }

        RateLimiter::clear($key);

        return ['result' => self::RESULT_GRANTED, 'cookie' => MaintenanceModeBypassCookie::create($secret)];
    }

    /**
     * 일반 로그인과 같은 기준으로 잠긴 계정인지 판정합니다 (보안 환경설정의 로그인 시도 제한이 꺼져 있으면 잠금 없음).
     *
     * @param  User|null  $user  이메일로 찾은 회원 (없으면 가입되지 않은 주소)
     * @param  string  $email  입력한 이메일
     * @return bool 잠겨 있으면 true
     */
    private function isLocked(?User $user, string $email): bool
    {
        if ($user === null) {
            return $this->unknownEmailLock->lockState($email) !== null;
        }

        return (bool) g7_core_settings('security.login_attempt_enabled', true) && $this->users->isLocked($user);
    }

    /**
     * 점검 모드 비밀값을 읽습니다 (발급한 쿠키를 점검 미들웨어가 같은 값으로 검증한다).
     *
     * @return string|null 비밀값 (없으면 null — 그때는 쿠키를 줄 수 없다)
     */
    private function bypassSecret(): ?string
    {
        try {
            $data = app()->maintenanceMode()->data();
        } catch (\Throwable) {
            return null;
        }

        $secret = is_array($data) ? ($data['secret'] ?? null) : null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
