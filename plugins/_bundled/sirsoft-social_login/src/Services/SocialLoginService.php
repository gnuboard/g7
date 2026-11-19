<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Extension\Auth\HandoffTicket;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\Auth\LoginHandoffService;
use App\Support\SiteUrl;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptOutcome;
use Plugins\Sirsoft\SocialLogin\Exceptions\SocialFlowException;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;

/**
 * 본인이 확인된 회원을 코어 로그인 인계로 넘깁니다.
 *
 * 이 플러그인이 하는 일은 「관리자 소셜 로그인 차단」 판정과 인계 코드 발급까지다. 잠금·회원 상태·2단계 인증·비밀번호
 * 변경·휴면 해제는 로그인 화면이 코드를 교환할 때 코어(`AuthService::loginVerifiedUser`)가 비밀번호 로그인과 똑같이
 * 처리하므로 여기서 다시 검사하지 않는다. 토큰을 직접 만들지 않는다.
 *
 * @since 1.0.0
 */
class SocialLoginService
{
    /** 소셜 로그인 인계 직후 훅 (인자: User, SocialAccount — 토큰·원본 프로필 없음) */
    public const ACTION_AFTER_SOCIAL_LOGIN = 'sirsoft-social_login.auth.after_social_login';

    /**
     * @param  LoginHandoffService  $handoff  코어 로그인 인계
     * @param  AccountCredentialWriter  $writer  연결 행 갱신
     * @param  AttemptRecorder  $attempts  시도 기록
     */
    public function __construct(
        private readonly LoginHandoffService $handoff,
        private readonly AccountCredentialWriter $writer,
        private readonly AttemptRecorder $attempts,
    ) {}

    /**
     * 관리자 소셜 로그인 차단 규칙을 적용합니다.
     *
     * 관리자 판정은 코어 관리자 로그인이 쓰는 `User::isAdmin()` 을 그대로 쓴다(판정식을 따로 두지 않는다).
     *
     * @param  User  $user  회원
     * @return void
     *
     * @throws SocialFlowException 관리자이고 설정이 막고 있으면 `admin_blocked`(403)
     */
    public function assertMayLogin(User $user): void
    {
        $allowAdmin = (bool) plugin_setting('sirsoft-social_login', 'allow_admin_social_login', false);

        if (! $allowAdmin && $user->isAdmin()) {
            throw new SocialFlowException('admin_blocked', 403);
        }
    }

    /**
     * 인계 코드를 발급합니다.
     *
     * 사이트 설정에서 「소셜 로그인에도 2단계 인증」을 껐으면 인계 컨텍스트에 `skip_two_factor` 를 실어 코어가 2단계
     * 인증 단계만 건너뛰게 한다(잠금·상태·비밀번호 변경·휴면은 그대로). 가입 직후 로그인도 코어 일반 가입과 같게
     * 2단계 인증 없이 들어가므로 호출자가 `$skipTwoFactor` 를 켠다.
     *
     * @param  User  $user  회원
     * @param  string  $providerKey  제공자 키
     * @param  bool  $skipTwoFactor  2단계 인증을 건너뛸지 (가입 직후)
     * @return string 인계 코드
     */
    public function issueHandoff(User $user, string $providerKey, bool $skipTwoFactor = false): string
    {
        return $this->handoff->issue($user, $this->handoffContext($providerKey, $skipTwoFactor));
    }

    /**
     * API 응답용 인계 코드와 묶음 쿠키를 발급합니다.
     *
     * 코어는 인계 코드를 발급한 브라우저에 묶는다(쿠키). 웹 콜백은 `issueHandoff()` 가 쿠키를 응답 대기열에 넣지만, API 응답
     * (가입 완료·기존 계정 연결)에는 쿠키 대기열이 붙지 않으므로 호출자가 응답에 `->withCookie($ticket->cookie)` 로 직접 싣는다.
     *
     * @param  User  $user  회원
     * @param  string  $providerKey  제공자 키
     * @param  bool  $skipTwoFactor  2단계 인증을 건너뛸지 (가입 직후)
     * @return HandoffTicket 인계 코드와 묶음 쿠키
     */
    public function issueHandoffTicket(User $user, string $providerKey, bool $skipTwoFactor = false): HandoffTicket
    {
        return $this->handoff->issueTicket($user, $this->handoffContext($providerKey, $skipTwoFactor));
    }

    /**
     * 인계 컨텍스트를 만듭니다.
     *
     * @param  string  $providerKey  제공자 키
     * @param  bool  $skipTwoFactor  2단계 인증을 건너뛸지
     * @return array{login_method: string, skip_two_factor: bool} 컨텍스트
     */
    private function handoffContext(string $providerKey, bool $skipTwoFactor): array
    {
        $twoFactorEnabled = (bool) plugin_setting('sirsoft-social_login', 'two_factor_enabled', true);

        return [
            'login_method' => 'social:'.$providerKey,
            'skip_two_factor' => $skipTwoFactor || ! $twoFactorEnabled,
        ];
    }

    /**
     * 연결된 회원을 로그인 인계로 넘기고 로그인 화면 주소를 돌려줍니다.
     *
     * @param  SocialAccount  $account  연결
     * @param  NormalizedProfile  $profile  이번 왕복의 프로필
     * @param  TokenSet|null  $tokens  이번 왕복의 토큰
     * @param  string  $redirect  로그인 뒤 갈 주소
     * @return string 이동할 주소
     */
    public function loginLinked(SocialAccount $account, NormalizedProfile $profile, ?TokenSet $tokens, string $redirect): string
    {
        $user = $account->user;
        $providerKey = $account->provider_key;

        if (! $user instanceof User) {
            $this->attempts->record($providerKey, AttemptIntent::Login->value, AttemptOutcome::Failed, null, 'account_missing');

            return OAuthFlowService::loginErrorUrl('account_missing', $redirect);
        }

        try {
            $this->assertMayLogin($user);
        } catch (SocialFlowException $e) {
            $this->attempts->record($providerKey, AttemptIntent::Login->value, AttemptOutcome::Denied, (int) $user->getKey(), $e->errorCode);

            return OAuthFlowService::loginErrorUrl($e->errorCode, $redirect);
        }

        $code = $this->issueHandoff($user, $providerKey);

        $this->writer->refresh($account, $profile, $tokens, loggedIn: true);

        HookManager::doAction(self::ACTION_AFTER_SOCIAL_LOGIN, $user, $account);

        $this->attempts->record($providerKey, AttemptIntent::Login->value, AttemptOutcome::Handoff, (int) $user->getKey());

        return self::handoffUrl($code, $redirect);
    }

    /**
     * 로그인 화면의 인계 주소를 만듭니다.
     *
     * @param  string  $code  인계 코드
     * @param  string|null  $redirect  로그인 뒤 갈 주소 (같은 출처 상대 경로만)
     * @return string 로그인 화면 절대 주소
     */
    public static function handoffUrl(string $code, ?string $redirect = null): string
    {
        return SiteUrl::absolute(self::handoffPath($code, $redirect));
    }

    /**
     * 로그인 화면의 인계 경로를 만듭니다 (API 응답용 상대 경로 — 화면이 같은 출처로 이동한다).
     *
     * @param  string  $code  인계 코드
     * @param  string|null  $redirect  로그인 뒤 갈 주소
     * @return string 상대 경로
     */
    public static function handoffPath(string $code, ?string $redirect = null): string
    {
        $query = ['login_handoff' => $code];
        $safe = StateStore::safeRedirect($redirect);
        if ($safe !== '/') {
            $query['redirect'] = $safe;
        }

        return '/login?'.http_build_query($query);
    }
}
