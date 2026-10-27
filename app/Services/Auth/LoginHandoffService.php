<?php

namespace App\Services\Auth;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\Auth\HandoffTicket;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * 로그인 인계 — 외부 인증으로 본인이 확인된 회원을 로그인 화면으로 넘기는 일회용 코드
 *
 * 외부 인증 확장(소셜 로그인 등)은 회원을 확정한 뒤 토큰을 직접 만들지 않고 이 서비스로 코드를 받아
 * `/login?login_handoff={code}`(관리자는 `/admin/login?login_handoff={code}`) 로 보낸다. 로그인 화면이 `POST /api/auth/login/handoff`
 * (관리자 `POST /api/auth/admin/login/handoff`) 로 코드를 교환하면 코어가 `AuthService::loginVerifiedUser()` 로 잠금·회원 상태·
 * 2단계 인증·비밀번호 변경·휴면 해제를 비밀번호 로그인과 똑같이 처리하고 같은 모양의 응답을 돌려준다.
 *
 * 코드는 32바이트 난수(base64url), 120초, 한 번만 쓸 수 있다. 캐시 키는 코드 원문이 아니라 해시다.
 *
 * **브라우저 묶기**(로그인 CSRF 방지): 코드는 발급한 브라우저의 묶음 쿠키(`g7_login_bind`)가 있어야 교환된다. 공격자가 자기 신원으로 받은
 * 코드를 피해자 브라우저에 넣어도 피해자 브라우저에는 그 쿠키가 없다.
 * - 웹 라우트(외부 인증 콜백)에서 발급: `issue()` — 쿠키를 대기열에 넣고 web 응답이 붙인다.
 * - API 응답에서 발급: `issueTicket()` — api 그룹에는 쿠키 대기열 미들웨어가 없으므로 호출자가 `->withCookie($ticket->cookie)`.
 * - 묶지 않은 코드: `issueUnbound()` — 실측 명령(`auth:handoff-issue`)과 시험 전용.
 *
 * @since 7.2.0
 */
class LoginHandoffService
{
    /** 코드 유효 시간(초) */
    public const TTL_SECONDS = 120;

    /** 묶음 쿠키 이름 (`bootstrap/app.php` 의 쿠키 암호화 예외에 있다 — web 이 굽고 api 가 읽는다) */
    public const BIND_COOKIE = 'g7_login_bind';

    /** 묶음 쿠키 경로 — 교환 API 에만 실린다 */
    public const BIND_COOKIE_PATH = '/api/auth';

    /** 캐시 키 접두어 */
    private const CACHE_PREFIX = 'core:login_handoff:';

    /**
     * 로그인 인계 서비스를 생성합니다.
     *
     * @param  UserRepositoryInterface  $userRepository  회원 저장소 (교환 시 회원 재조회)
     * @param  CacheInterface  $cache  코어 캐시 (코드 보관)
     */
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private CacheInterface $cache,
    ) {}

    /**
     * 본인 확인을 마친 회원의 인계 코드를 발급하고 묶음 쿠키를 쿠키 대기열에 넣습니다 (웹 라우트용).
     *
     * @param  User  $user  본인 확인을 마친 회원
     * @param  array{login_method?: string, skip_two_factor?: bool, max_session_minutes?: int, external_ref?: string}  $context
     *                                                                                                                  `loginVerifiedUser()` 에 넘길 컨텍스트 (login_method 필수 — 예 `social:kakao`)
     * @param  string  $guard  로그인 경로 (user | admin) — 교환 경로가 다르면 거절된다
     * @return string 인계 코드 (URL 에 실어도 되는 문자만)
     *
     * @throws \App\Exceptions\Auth\LoginMethodRequiredException 로그인 수단 이름(`login_method`)이 없을 때
     */
    public function issue(User $user, array $context = [], string $guard = 'user'): string
    {
        $ticket = $this->issueTicket($user, $context, $guard);

        Cookie::queue($ticket->cookie);

        return $ticket->code;
    }

    /**
     * 인계 코드와 묶음 쿠키를 함께 돌려줍니다 (API 응답용 — 호출자가 응답에 `->withCookie($ticket->cookie)`).
     *
     * @param  User  $user  본인 확인을 마친 회원
     * @param  array<string, mixed>  $context  `loginVerifiedUser()` 컨텍스트 (login_method 필수)
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return HandoffTicket 코드와 묶음 쿠키
     *
     * @throws \App\Exceptions\Auth\LoginMethodRequiredException 로그인 수단 이름(`login_method`)이 없을 때
     */
    public function issueTicket(User $user, array $context = [], string $guard = 'user'): HandoffTicket
    {
        $bind = $this->randomToken();
        $code = $this->store($user, $context, $guard, hash('sha256', $bind));

        return new HandoffTicket($code, $this->bindCookie($bind));
    }

    /**
     * 브라우저에 묶지 않은 인계 코드를 발급합니다 — 실측 명령(`auth:handoff-issue`)과 시험 전용.
     *
     * 웹으로 닿는 경로에서 부르면 로그인 CSRF 방어가 사라진다. 호출처는 정적 검사로 잠근다.
     *
     * @param  User  $user  회원
     * @param  array<string, mixed>  $context  `loginVerifiedUser()` 컨텍스트 (login_method 필수)
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return string 인계 코드
     *
     * @throws \App\Exceptions\Auth\LoginMethodRequiredException 로그인 수단 이름(`login_method`)이 없을 때
     */
    public function issueUnbound(User $user, array $context = [], string $guard = 'user'): string
    {
        return $this->store($user, $context, $guard, null);
    }

    /**
     * 인계 코드를 교환할 회원과 컨텍스트를 돌려줍니다 — 코드는 이 호출로 소비된다.
     *
     * 없음·만료·재사용·다른 경로·묶음 쿠키 불일치·회원 삭제는 전부 null 이다(호출자가 구분해 응답하지 않게).
     * 쿠키가 다르면 코드도 소비한다 — 남의 코드를 넣어 본 브라우저 쪽에서 다시 시도할 기회를 주지 않는다.
     *
     * @param  string  $code  인계 코드
     * @param  string  $guard  교환하는 로그인 경로
     * @param  Request|null  $request  교환 요청 (null 이면 현재 요청 — 묶음 쿠키를 읽는다)
     * @return array{user: User, context: array<string, mixed>}|null 회원과 `loginVerifiedUser()` 컨텍스트
     */
    public function redeem(string $code, string $guard = 'user', ?Request $request = null): ?array
    {
        $payload = $this->consume($code);

        if ($payload === null || $payload['guard'] !== $guard) {
            return null;
        }

        $bindHash = $payload['bind_hash'] ?? null;

        if (is_string($bindHash)) {
            $cookie = ($request ?? request())->cookie(self::BIND_COOKIE);

            if (! is_string($cookie) || $cookie === '' || ! hash_equals($bindHash, hash('sha256', $cookie))) {
                return null;
            }
        }

        $user = $this->userRepository->findById((int) $payload['user_id']);

        return $user === null ? null : ['user' => $user, 'context' => (array) ($payload['context'] ?? [])];
    }

    /**
     * 코드를 저장합니다.
     *
     * @param  User  $user  회원
     * @param  array<string, mixed>  $context  호출자 컨텍스트
     * @param  string  $guard  로그인 경로
     * @param  string|null  $bindHash  묶음 쿠키 해시 (null 이면 묶지 않음)
     * @return string 인계 코드
     */
    private function store(User $user, array $context, string $guard, ?string $bindHash): string
    {
        AuthService::assertLoginMethodGiven($context);

        $code = $this->randomToken();

        $this->cache->put($this->cacheKey($code), [
            'user_id' => (int) $user->getKey(),
            'guard' => $guard,
            'context' => AuthService::normalizeLoginContext($context),
            'bind_hash' => $bindHash,
            'issued_at' => now()->toIso8601String(),
        ], self::TTL_SECONDS);

        return $code;
    }

    /**
     * 인계 코드를 꺼냅니다 — 꺼내는 순간 지워진다(한 번만 쓸 수 있다).
     *
     * 묶음 검사를 건너뛰지 못하게 공개하지 않는다 — 교환은 `redeem()` 으로만 한다.
     *
     * @param  string  $code  인계 코드
     * @return array{user_id: int, guard: string, context: array<string, mixed>, bind_hash: string|null, issued_at: string}|null 인계 내용
     */
    private function consume(string $code): ?array
    {
        if ($code === '' || strlen($code) > 128) {
            return null;
        }

        $key = $this->cacheKey($code);

        // 같은 코드의 동시 교환을 하나로 줄인다 — 조회와 삭제 사이에 끼어든 두 번째 요청이 같은 코드로 로그인하지 못하게.
        $lock = Cache::lock(self::CACHE_PREFIX.'lock:'.hash('sha256', $code), 5);

        if (! $lock->get()) {
            return null;
        }

        try {
            $payload = $this->cache->get($key);
            $this->cache->forget($key);
        } finally {
            $lock->release();
        }

        if (! is_array($payload) || ! isset($payload['user_id'], $payload['guard'])) {
            return null;
        }

        return $payload;
    }

    /**
     * 묶음 쿠키를 만듭니다 — HttpOnly, SameSite=Lax, 교환 API 경로에만, 코드와 같은 수명.
     *
     * Secure 는 HTTPS 요청이거나 세션 쿠키 설정이 Secure 일 때 붙인다(로컬 HTTP 개발에서도 동작하게).
     *
     * @param  string  $value  묶음 값
     * @return SymfonyCookie 쿠키
     */
    private function bindCookie(string $value): SymfonyCookie
    {
        $secure = request()->isSecure() || (bool) config('session.secure', false);

        return new SymfonyCookie(
            name: self::BIND_COOKIE,
            value: $value,
            expire: time() + self::TTL_SECONDS,
            path: self::BIND_COOKIE_PATH,
            domain: null,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: SymfonyCookie::SAMESITE_LAX,
        );
    }

    /**
     * 32바이트 난수를 URL 안전 문자열로 만듭니다.
     *
     * @return string 난수 문자열 (43자)
     */
    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * 코드의 캐시 키를 만듭니다 (원문 대신 해시).
     *
     * @param  string  $code  인계 코드
     * @return string 캐시 키
     */
    private function cacheKey(string $code): string
    {
        return self::CACHE_PREFIX.hash('sha256', $code);
    }
}
