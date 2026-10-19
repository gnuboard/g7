<?php

namespace App\Services\Auth;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Support\Facades\Cache;

/**
 * 로그인 인계 — 외부 인증으로 본인이 확인된 회원을 로그인 화면으로 넘기는 일회용 코드
 *
 * 외부 인증 확장(소셜 로그인 등)은 회원을 확정한 뒤 토큰을 직접 만들지 않고 이 서비스로 코드를 받아
 * `/login?login_handoff={code}` 로 보낸다. 로그인 화면이 `POST /api/auth/login/handoff` 로 코드를 교환하면 코어가
 * `AuthService::loginVerifiedUser()` 로 잠금·회원 상태·2단계 인증·비밀번호 변경·휴면 해제를 비밀번호 로그인과 똑같이
 * 처리하고 같은 모양의 응답을 돌려준다 — 화면은 2단계 인증·비밀번호 변경·휴면 안내를 그대로 쓴다.
 *
 * 코드는 32바이트 난수(base64url), 120초, 한 번만 쓸 수 있다. 캐시 키는 코드 원문이 아니라 해시다.
 *
 * @since 7.2.0
 */
class LoginHandoffService
{
    /** 코드 유효 시간(초) */
    public const TTL_SECONDS = 120;

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
     * 본인 확인을 마친 회원의 인계 코드를 발급합니다.
     *
     * @param  User  $user  본인 확인을 마친 회원
     * @param  array{login_method?: string, skip_two_factor?: bool}  $context  `loginVerifiedUser()` 에 넘길 컨텍스트
     *                                                                         (login_method 필수 — 예 `social:kakao`)
     * @param  string  $guard  로그인 경로 — 지금은 `user` 만 교환된다(관리자 경로 인계는 교환 시 거절)
     * @return string 인계 코드 (URL 에 실어도 되는 문자만)
     *
     * @throws \App\Exceptions\Auth\LoginMethodRequiredException 로그인 수단 이름(`login_method`)이 없을 때
     */
    public function issue(User $user, array $context = [], string $guard = 'user'): string
    {
        AuthService::assertLoginMethodGiven($context);

        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->cache->put($this->cacheKey($code), [
            'user_id' => (int) $user->getKey(),
            'guard' => $guard,
            'context' => [
                'login_method' => $context['login_method'],
                'skip_two_factor' => ($context['skip_two_factor'] ?? false) === true,
            ],
            'issued_at' => now()->toIso8601String(),
        ], self::TTL_SECONDS);

        return $code;
    }

    /**
     * 인계 코드를 꺼냅니다 — 꺼내는 순간 지워진다(한 번만 쓸 수 있다).
     *
     * @param  string  $code  인계 코드
     * @return array{user_id: int, guard: string, context: array<string, mixed>, issued_at: string}|null 인계 내용 (없음·만료·재사용이면 null)
     */
    public function consume(string $code): ?array
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
     * 인계 코드를 교환할 회원과 컨텍스트를 돌려줍니다 — 코드는 이 호출로 소비된다.
     *
     * 없음·만료·재사용·다른 경로·회원 삭제는 전부 null 이다(호출자가 구분해 응답하지 않게).
     *
     * @param  string  $code  인계 코드
     * @param  string  $guard  교환하는 로그인 경로
     * @return array{user: User, context: array<string, mixed>}|null 회원과 `loginVerifiedUser()` 컨텍스트
     */
    public function redeem(string $code, string $guard = 'user'): ?array
    {
        $payload = $this->consume($code);

        if ($payload === null || $payload['guard'] !== $guard) {
            return null;
        }

        $user = $this->userRepository->findById((int) $payload['user_id']);

        return $user === null ? null : ['user' => $user, 'context' => (array) ($payload['context'] ?? [])];
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
