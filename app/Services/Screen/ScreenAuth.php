<?php

namespace App\Services\Screen;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;
use App\Services\Auth\TokenExpirySlider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 개발자 화면(custom 예약 자리)의 로그인 회원 판정 — 서버 세션 + 그 로그인 때 발급된 토큰의 생존.
 *
 * G7 화면의 로그인은 API 가 Bearer 토큰을 발급하고, 같은 요청에서 서버 세션(웹 가드)에도 로그인을 남긴다.
 * 이때 발급한 토큰 ID 를 세션 `g7.screen_token_id` 에 묶어 둔다(「짝」). 개발자 화면은 웹 세션 회원이 있고
 * 그 짝 토큰이 아직 살아 있을(존재·미만료) 때만 로그인으로 본다 — 로그아웃·강제 로그아웃·비밀번호 변경 등
 * 토큰을 회수하는 어떤 경로든 곧 「세션 로그인 무효」가 된다. 판정이 통과하면 토큰 만료도 API 요청과 같은 규칙으로
 * 연장한다({@see TokenExpirySlider}).
 *
 * 한계: 7.2.0 이전에 로그인해 짝이 없는 세션은 로그인으로 보지 않는다(다시 로그인하면 짝이 생긴다).
 * 회원 상태(정지 등) 판정은 하지 않는다 — 내부 API 의 상태 미들웨어가 맡는다.
 *
 * @since 7.2.0
 */
class ScreenAuth
{
    /** 세션에 저장하는 짝 토큰 ID 키 */
    public const SESSION_KEY = 'g7.screen_token_id';

    /** 요청 단위 판정 결과 메모 키 */
    private const MEMO_KEY = 'g7.screen_auth';

    /**
     * 웹 세션에 로그인을 남기고 방금 발급한 토큰을 짝으로 묶습니다 (세션이 시작된 요청에서만).
     *
     * @param  User  $user  로그인 회원
     * @param  PersonalAccessToken  $token  방금 발급한 토큰
     * @return void 반환값 없음
     */
    public static function pair(User $user, PersonalAccessToken $token): void
    {
        $request = request();
        if (! $request->hasSession() || ! $request->session()->isStarted()) {
            return;
        }

        if (Auth::guard('web')->id() !== $user->getAuthIdentifier()) {
            Auth::guard('web')->login($user);
        }
        $request->session()->put(self::SESSION_KEY, $token->getKey());
        $request->attributes->remove(self::MEMO_KEY);
    }

    /**
     * 웹 세션 회원이 같을 때만 짝 토큰을 새 토큰으로 바꿉니다 (토큰 갱신 API).
     *
     * @param  User  $user  토큰을 갱신한 회원
     * @param  PersonalAccessToken  $token  새 토큰
     * @return void 반환값 없음
     */
    public static function repair(User $user, PersonalAccessToken $token): void
    {
        $request = request();
        if (! $request->hasSession() || ! $request->session()->isStarted()) {
            return;
        }
        if (Auth::guard('web')->id() !== $user->getAuthIdentifier()) {
            return;
        }

        $request->session()->put(self::SESSION_KEY, $token->getKey());
        $request->attributes->remove(self::MEMO_KEY);
    }

    /**
     * 개발자 화면의 로그인 회원을 돌려줍니다.
     *
     * 웹 세션 회원이 있고 짝 토큰이 그 회원의 것이며 존재·미만료일 때만 회원이다. 아니면 웹 가드 로그아웃 +
     * 짝 표식을 지우고 null. 판정이 통과하면 토큰 만료를 슬라이딩 연장한다.
     *
     * @return User|null 로그인 회원, 아니면 null
     */
    public static function user(): ?User
    {
        $resolved = self::resolve();

        return $resolved['user'];
    }

    /**
     * 로그인 회원의 짝 토큰 모델을 돌려줍니다 (내부 API 도구가 그 회원 자격으로 API 를 부를 때 쓴다).
     *
     * @return PersonalAccessToken|null 짝 토큰, 비회원이면 null
     */
    public static function token(): ?PersonalAccessToken
    {
        return self::resolve()['token'];
    }

    /**
     * 로그인 여부.
     *
     * @return bool 로그인이면 true
     */
    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * 로그아웃합니다 — 짝 토큰 삭제 + 웹 가드 로그아웃 + 세션 무효화 (로그아웃 API 와 같은 결과).
     *
     * @return void 반환값 없음
     */
    public static function logout(): void
    {
        $request = request();
        $token = self::resolve()['token'];
        if ($token !== null) {
            app(UserRepositoryInterface::class)->deleteAccessToken($token);
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        $request->attributes->remove(self::MEMO_KEY);
    }

    /**
     * 요청 단위로 한 번 판정합니다.
     *
     * @return array{user: User|null, token: PersonalAccessToken|null} 판정 결과
     */
    private static function resolve(): array
    {
        $request = request();
        $memo = $request->attributes->get(self::MEMO_KEY);
        if (is_array($memo)) {
            return $memo;
        }

        $result = self::evaluate($request);
        $request->attributes->set(self::MEMO_KEY, $result);

        return $result;
    }

    /**
     * 세션 회원과 짝 토큰을 대조합니다.
     *
     * @param  Request  $request  현재 요청
     * @return array{user: User|null, token: PersonalAccessToken|null} 판정 결과
     */
    private static function evaluate(Request $request): array
    {
        $none = ['user' => null, 'token' => null];
        if (! $request->hasSession()) {
            return $none;
        }

        $guard = Auth::guard('web');
        $user = $guard->user();
        if (! $user instanceof User) {
            // 회원이 삭제돼 세션의 로그인을 되살릴 수 없으면 남은 로그인 표식·짝 표식을 지운다(로그아웃할 회원이 없다)
            if ($request->session()->has(self::SESSION_KEY) && method_exists($guard, 'getName')) {
                $request->session()->forget([$guard->getName(), self::SESSION_KEY]);
            }

            return $none;
        }

        $tokenId = $request->session()->get(self::SESSION_KEY);
        $token = $tokenId !== null ? app(UserRepositoryInterface::class)->findAccessTokenFor($user, $tokenId) : null;

        $alive = $token instanceof PersonalAccessToken
            && ($token->expires_at === null || $token->expires_at->isFuture());

        if (! $alive) {
            // 짝 토큰이 회수·만료되면 웹 세션 로그인도 정식 로그아웃으로 끝낸다(Logout 이벤트 포함
            // 결정). 세션 자체는 무효화하지 않는다 — 같은 세션의 CSRF 토큰·다른 값은 그대로 둔다.
            Auth::guard('web')->logout();
            $request->session()->forget(self::SESSION_KEY);

            return $none;
        }

        app(TokenExpirySlider::class)->slide($token, $request);

        return ['user' => $user, 'token' => $token];
    }
}
