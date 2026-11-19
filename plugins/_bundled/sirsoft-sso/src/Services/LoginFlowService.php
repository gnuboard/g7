<?php

namespace Plugins\Sirsoft\Sso\Services;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Auth\HandoffTicket;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\Auth\LoginHandoffService;
use App\Services\IdentityVerificationService;
use App\Support\SiteUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\DTO\ResolveResult;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Identity\SsoReauthIdentityProvider;
use Plugins\Sirsoft\Sso\Mock\MockGate;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Models\SsoLoginAttempt;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\LoginAttemptRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoAccountRepositoryInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * SSO 로그인 왕복 — 시작(IdP 로 보내기), 콜백(검증 → 회원 결정 → 인계), 시작 티켓, 관리자 테스트 결과.
 *
 * 이 서비스는 토큰을 만들지 않는다. 회원이 정해지면 코어 `LoginHandoffService` 로 인계 코드를 발급하고 로그인 화면으로 보낸다 —
 * 잠금, 회원 상태, 2단계 인증, 비밀번호 변경, 휴면 해제는 화면이 코드를 교환할 때 코어가 비밀번호 로그인과 같게 처리한다.
 *
 * 프로토콜별 콜백(OIDC 콜백, SAML ACS)은 드라이버의 `completeLogin()` 결과를 `complete()` 로 넘긴다.
 *
 * @since 1.0.0
 */
class LoginFlowService
{
    /** 신원 필터 */
    public const FILTER_IDENTITY = 'sirsoft-sso.identity.filter_resolved';

    /** 인계 문맥 필터 */
    public const FILTER_HANDOFF_CONTEXT = 'sirsoft-sso.handoff.filter_context';

    /** 회원 결정 직후 훅 */
    public const ACTION_AFTER_RESOLVE = 'sirsoft-sso.account.after_resolve';

    /** SSO 로그인 인계 직후 훅 */
    public const ACTION_AFTER_SSO_LOGIN = 'sirsoft-sso.auth.after_sso_login';

    /** SSO 로그인 실패 직후 훅 — 인자 (?IdentityProvider $idp, string $errorCode, string $intent, string $guard, string $outcome) */
    public const ACTION_LOGIN_FAILED = 'sirsoft-sso.auth.login_failed';

    /** 실패로 보는 시도 결과 */
    private const FAILURE_OUTCOMES = [SsoLoginAttempt::OUTCOME_FAILED, SsoLoginAttempt::OUTCOME_DENIED];

    /** 관리자 테스트 결과 보관(초) — 결과 주소가 곧 열람 권한이라 짧게 둔다 */
    public const TEST_RESULT_TTL = 60;

    /**
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  SsoAccountRepositoryInterface  $accounts  연결 저장소
     * @param  LoginAttemptRepositoryInterface  $attempts  시도 이력
     * @param  StateStore  $states  state, 묶음 쿠키, 시작 티켓
     * @param  AccountResolver  $resolver  회원 결정
     * @param  LoginHandoffService  $handoff  코어 로그인 인계
     * @param  MockGate  $mockGate  모의 IdP 게이트
     * @param  CacheInterface  $cache  플러그인 캐시 (테스트 결과)
     */
    public function __construct(
        private readonly IdentityProviderRepositoryInterface $idps,
        private readonly SsoAccountRepositoryInterface $accounts,
        private readonly LoginAttemptRepositoryInterface $attempts,
        private readonly StateStore $states,
        private readonly AccountResolver $resolver,
        private readonly LoginHandoffService $handoff,
        private readonly MockGate $mockGate,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 왕복을 시작합니다 — `GET /plugins/sirsoft-sso/login/{idp}`.
     *
     * `intent=login` 만 쿼리로 받는다. link, reauth, test 는 회원을 아는 API 가 발급한 1회용 시작 티켓(`?ticket=`)만 받는다 — 브라우저
     * 이동에는 Bearer 토큰이 실리지 않아 이 경로가 회원을 알 수 없다.
     *
     * @param  string  $idpKey  IdP 키
     * @param  Request  $request  요청
     * @return Response IdP 로 가는 응답 또는 오류 화면으로 이동
     */
    public function start(string $idpKey, Request $request): Response
    {
        $guard = in_array($request->query('guard'), LoginIntent::GUARDS, true) ? (string) $request->query('guard') : 'user';
        $redirect = SafeRedirect::path(is_string($request->query('redirect')) ? $request->query('redirect') : null);
        $ticket = $request->query('ticket');
        $idp = $this->idps->findByKey($idpKey);

        $start = ['intent' => LoginIntent::LOGIN, 'guard' => $guard, 'redirect' => $redirect, 'link_user_id' => null, 'challenge_id' => null];

        if (is_string($ticket) && $ticket !== '') {
            $payload = $this->states->pullTicket($ticket, $request);
            if ($payload === null || $idp === null || (int) ($payload['idp_id'] ?? 0) !== (int) $idp->getKey()) {
                $this->record($idp, LoginIntent::LINK, $guard, SsoLoginAttempt::OUTCOME_DENIED, null, 'ticket_invalid', $request);

                return redirect()->to(self::errorUrl('ticket_invalid', $guard, $redirect));
            }

            $start = [
                'intent' => (string) $payload['intent'],
                'guard' => in_array($payload['guard'] ?? null, LoginIntent::GUARDS, true) ? (string) $payload['guard'] : 'user',
                'redirect' => (string) ($payload['redirect'] ?? '/'),
                'link_user_id' => (int) $payload['user_id'],
                'challenge_id' => isset($payload['challenge_id']) ? (string) $payload['challenge_id'] : null,
            ];
        } elseif (is_string($request->query('intent')) && $request->query('intent') !== LoginIntent::LOGIN) {
            return redirect()->to(self::errorUrl('ticket_required', $guard, $redirect));
        }

        try {
            $idp = $this->assertUsable($idp, $start['intent']);
            $intent = $this->states->create([
                'intent' => $start['intent'],
                'guard' => $start['guard'],
                'redirect' => $start['redirect'],
                'idp_id' => (int) $idp->getKey(),
                'idp_key' => $idp->key,
                'link_user_id' => $start['link_user_id'],
                'challenge_id' => $start['challenge_id'],
            ], $this->states->ensureBind($request));

            return $this->states->attachIssuedBind($idp->driver()->beginLogin($idp, $intent), $request);
        } catch (SsoException $e) {
            $this->record($idp, $start['intent'], $start['guard'], SsoLoginAttempt::OUTCOME_FAILED, $start['link_user_id'], $e->errorCode, $request);
            if ($idp !== null) {
                $this->idps->recordOutcome($idp, $e->errorCode);
            }

            return redirect()->to($this->failureUrl($start['intent'], $start['guard'], $start['redirect'], $e->errorCode));
        }
    }

    /**
     * state 를 쓰는 콜백을 처리합니다 (OIDC 콜백 — SAML ACS 도 state 를 찾은 뒤 같은 길).
     *
     * @param  string  $idpKey  경로의 IdP 키
     * @param  Request  $request  콜백 요청
     * @param  string  $stateParam  state 가 실린 요청 칸 이름 (OIDC `state`, SAML `RelayState`)
     * @return RedirectResponse 다음 화면
     */
    public function callback(string $idpKey, Request $request, string $stateParam = 'state'): RedirectResponse
    {
        $state = $request->input($stateParam);
        $intent = is_string($state) ? $this->states->pull($state) : null;
        $idp = $this->idps->findByKey($idpKey);

        if ($intent === null || $idp === null || $intent->idpKey !== $idpKey || $intent->idpId !== (int) $idp->getKey()) {
            $this->record($idp, LoginIntent::LOGIN, 'user', SsoLoginAttempt::OUTCOME_FAILED, null, 'state_expired', $request);

            return redirect()->to(self::errorUrl('state_expired', $intent->guard ?? 'user'));
        }

        if (! $this->states->matchesBind($intent->bindHash, $request)) {
            $this->record($idp, $intent->intent, $intent->guard, SsoLoginAttempt::OUTCOME_FAILED, $intent->linkUserId, 'bind_mismatch', $request);

            return redirect()->to($this->failureUrl($intent->intent, $intent->guard, $intent->redirect, 'bind_mismatch'));
        }

        try {
            $identity = $idp->driver()->completeLogin($idp, $request, $intent);
        } catch (SsoException $e) {
            return $this->fail($idp, $intent, $e->errorCode, $request, responseSummary: $e->responseSummary);
        } catch (\Throwable $e) {
            report($e);

            return $this->fail($idp, $intent, 'token_error', $request);
        }

        return $this->complete($idp, $identity, $intent, $request);
    }

    /**
     * 검증된 신원으로 왕복을 마칩니다 — 의도별 분기와 인계.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  ExternalIdentity  $identity  드라이버가 검증한 신원
     * @param  LoginIntent  $intent  시작 정보
     * @param  Request  $request  요청
     * @return RedirectResponse 다음 화면
     */
    public function complete(IdentityProvider $idp, ExternalIdentity $identity, LoginIntent $intent, Request $request): RedirectResponse
    {
        $filtered = HookManager::applyFilters(self::FILTER_IDENTITY, $identity, $idp);
        if ($filtered instanceof ExternalIdentity && $filtered->subject !== '') {
            $identity = $filtered;
        }

        $this->idps->recordOutcome($idp, null);
        $this->regenerateSession($request);

        if ($intent->intent === LoginIntent::TEST) {
            $this->record($idp, $intent->intent, $intent->guard, SsoLoginAttempt::OUTCOME_TEST, $intent->linkUserId, null, $request);

            return redirect()->to($this->storeTestResult($idp, true, $identity, null));
        }

        try {
            $result = $this->resolver->resolve($idp, $identity, $intent, $request);
        } catch (SsoException $e) {
            return $this->fail($idp, $intent, $e->errorCode, $request, SsoLoginAttempt::OUTCOME_DENIED);
        } catch (\Throwable $e) {
            report($e);

            return $this->fail($idp, $intent, 'token_error', $request);
        }

        return $this->dispatch($idp, $identity, $intent, $result, $request);
    }

    /**
     * 회원 결정 결과를 다음 화면으로 바꿉니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  ExternalIdentity  $identity  신원
     * @param  LoginIntent  $intent  시작 정보
     * @param  ResolveResult  $result  결정 결과
     * @param  Request  $request  요청
     * @return RedirectResponse 다음 화면
     */
    private function dispatch(IdentityProvider $idp, ExternalIdentity $identity, LoginIntent $intent, ResolveResult $result, Request $request): RedirectResponse
    {
        $userId = $result->user !== null ? (int) $result->user->getKey() : $intent->linkUserId;

        switch ($result->outcome) {
            case ResolveResult::PENDING_CONSENT:
                $this->record($idp, $intent->intent, $intent->guard, SsoLoginAttempt::OUTCOME_PENDING, null, null, $request);

                return redirect()->to(self::screenUrl($intent->guard, ['sso_pending' => (string) $result->pendingToken], $intent->redirect));

            case ResolveResult::PENDING_LINK:
                $this->record($idp, $intent->intent, $intent->guard, SsoLoginAttempt::OUTCOME_LINK_PENDING, null, null, $request);

                return redirect()->to(self::screenUrl($intent->guard, ['sso_link' => (string) $result->pendingToken], $intent->redirect));

            case ResolveResult::LINKED:
                $this->record($idp, $intent->intent, $intent->guard, SsoLoginAttempt::OUTCOME_LINKED, $userId, null, $request);

                return redirect()->to(SiteUrl::absolute(self::withQuery($intent->redirect, ['sso_linked' => $idp->key])));

            case ResolveResult::REAUTH:
                return $this->completeReauth($idp, $intent, $result, $request);

            case ResolveResult::REJECTED:
                return $this->fail($idp, $intent, (string) $result->errorCode, $request, SsoLoginAttempt::OUTCOME_DENIED);
        }

        /** @var User $user */
        $user = $result->user;
        /** @var SsoAccount $account */
        $account = $result->account;

        $code = $this->issueHandoff($user, $account, $identity, $idp, $intent->guard, $result->outcome)->code ?? '';
        $this->record($idp, $intent->intent, $intent->guard, SsoLoginAttempt::OUTCOME_HANDOFF, $userId, null, $request);

        return redirect()->to(self::screenUrl($intent->guard, ['login_handoff' => $code], $intent->redirect));
    }

    /**
     * 확정된 회원의 인계 코드를 발급합니다.
     *
     * web 응답(콜백)이면 코어가 묶음 쿠키를 쿠키 대기열로 붙인다. api 응답(동의 완료, 연결 확인 완료)은 쿠키 대기열이 없으므로
     * `$forApi = true` 로 티켓(코드 + 쿠키)을 받아 호출자가 응답에 붙인다.
     *
     * @param  User  $user  회원
     * @param  SsoAccount  $account  연결
     * @param  ExternalIdentity  $identity  신원
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $guard  로그인 경로
     * @param  string  $outcome  결정 결과 종류 (훅 인자)
     * @param  bool  $forApi  api 응답용 티켓을 받을지
     * @return object{code: string, cookie: Cookie|null} 인계 코드와 (api 면) 묶음 쿠키
     */
    public function issueHandoff(User $user, SsoAccount $account, ExternalIdentity $identity, IdentityProvider $idp, string $guard, string $outcome, bool $forApi = false): object
    {
        $this->accounts->update($account, [
            'last_login_at' => now(),
            'email' => $identity->email !== null ? mb_substr($identity->email, 0, 255) : $account->email, // audit:allow resource-member-email-ungated 연결 행 저장값(응답 아님)
            'display_name' => $identity->displayName ?? $account->display_name,
        ]);

        HookManager::doAction(self::ACTION_AFTER_RESOLVE, $user, $account, $identity, $outcome);

        $context = $this->handoffContext($user, $account, $identity, $idp);

        if ($forApi) {
            /** @var HandoffTicket $ticket */
            $ticket = $this->handoff->issueTicket($user, $context, $guard);
            $issued = (object) ['code' => $ticket->code, 'cookie' => $ticket->cookie];
        } else {
            $issued = (object) ['code' => $this->handoff->issue($user, $context, $guard), 'cookie' => null];
        }

        HookManager::doAction(self::ACTION_AFTER_SSO_LOGIN, $user, $account);

        return $issued;
    }

    /**
     * 코어 로그인 인계 문맥을 만듭니다 — 다른 영역이 필터로 세션 상한, 외부 세션 참조, 2단계 인증 생략을 더한다.
     *
     * @param  User  $user  회원
     * @param  SsoAccount  $account  연결
     * @param  ExternalIdentity  $identity  신원
     * @param  IdentityProvider  $idp  IdP
     * @return array<string, mixed> 문맥
     */
    public function handoffContext(User $user, SsoAccount $account, ExternalIdentity $identity, IdentityProvider $idp): array
    {
        $context = HookManager::applyFilters(self::FILTER_HANDOFF_CONTEXT, [
            'login_method' => 'sso:'.$idp->key,
        ], $user, $account, $identity, $idp);

        $context = is_array($context) ? $context : [];
        $context['login_method'] = 'sso:'.$idp->key;

        return $context;
    }

    /**
     * 로그인 상태가 필요한 의도의 시작 주소를 만듭니다 (마이페이지 연결, 재확인, 관리자 브라우저 테스트).
     *
     * 요청 브라우저에 묶음 쿠키가 없으면 새로 만들어 쿠키 대기열에 싣는다 — 플러그인 api 라우트와 코어 본인인증 요청 라우트에는
     * `Plugin::getMiddleware()` 가 쿠키 대기열 미들웨어를 붙여 둔다.
     *
     * @param  User  $user  회원 (API 가 인증으로 확정)
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $intent  link, reauth, test
     * @param  Request  $request  요청
     * @param  string|null  $redirect  왕복 뒤 복귀 경로
     * @param  string|null  $challengeId  reauth 의 코어 본인인증 요청 ID
     * @return array{start_url: string} 시작 주소 (같은 출처 경로)
     */
    public function issueStartUrl(User $user, IdentityProvider $idp, string $intent, Request $request, ?string $redirect = null, ?string $challengeId = null): array
    {
        $bind = $this->states->ensureBind($request);

        $ticket = $this->states->issueTicket([
            'user_id' => (int) $user->getKey(),
            'intent' => in_array($intent, [LoginIntent::LINK, LoginIntent::REAUTH, LoginIntent::TEST], true) ? $intent : LoginIntent::LINK,
            'idp_id' => (int) $idp->getKey(),
            'guard' => $intent === LoginIntent::TEST ? 'admin' : 'user',
            'redirect' => $redirect,
            'challenge_id' => $challengeId,
        ], $bind);

        return [
            'start_url' => '/plugins/sirsoft-sso/login/'.$idp->key.'?'.http_build_query(['ticket' => $ticket]),
        ];
    }

    /**
     * 로그인 화면 버튼용 공개 IdP 목록을 만듭니다 — 키, 라벨, 버튼 색, 시작 주소만 (설정, 비밀값 없음).
     *
     * 모의 IdP(키 접두 `mock_`)는 모의 게이트가 켜져 있을 때만 보인다. 서버에서 쓸 수 없는 드라이버의 IdP 와 리디렉션 로그인이 아닌
     * IdP(LDAP — 로그인 칸으로 들어온다)는 뺀다.
     *
     * @param  string  $guard  로그인 경로 (user, admin)
     * @param  string|null  $redirect  로그인 뒤 복귀 경로
     * @return array<int, array{key: string, label: string, initial: string, button_style: array<string, string>|null, start_url: string}> 목록
     */
    public function publicIdps(string $guard, ?string $redirect = null): array
    {
        $guard = in_array($guard, LoginIntent::GUARDS, true) ? $guard : 'user';
        $safe = SafeRedirect::path($redirect);
        $mockEnabled = $this->mockGate->enabled();
        $items = [];

        foreach ($this->idps->visibleEnabled() as $idp) {
            if ((! $mockEnabled && $idp->isMock()) || ! $this->redirectCapable($idp)) {
                continue;
            }

            $query = ['guard' => $guard];
            if ($safe !== '/') {
                $query['redirect'] = $safe;
            }

            $label = $idp->localizedLabel();
            $items[] = [
                'key' => $idp->key,
                'label' => $label,
                'initial' => mb_substr($label, 0, 1),
                'button_style' => is_array($idp->button_style) ? $idp->button_style : null,
                'start_url' => '/plugins/sirsoft-sso/login/'.$idp->key.'?'.http_build_query($query),
            ];
        }

        return $items;
    }

    /**
     * 리디렉션 로그인을 쓸 수 있는 IdP 인지 확인합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return bool 버튼으로 시작할 수 있으면 true
     */
    private function redirectCapable(IdentityProvider $idp): bool
    {
        try {
            $driver = $idp->driver();

            return $driver->isAvailable() && $driver->capabilities()->redirect;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 일회용 테스트 결과를 꺼냅니다 (한 번 보면 지워짐).
     *
     * @param  string  $code  결과 코드
     * @return array<string, mixed>|null 결과
     */
    public function pullTestResult(string $code): ?array
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $code) !== 1) {
            return null;
        }

        $key = 'test_result:'.hash('sha256', $code);
        $result = $this->cache->get($key);
        $this->cache->forget($key);

        return is_array($result) ? $result : null;
    }

    /**
     * 로그인 화면 오류 주소를 만듭니다 (`?sso_error=` — 주입된 토스트가 문구를 보인다).
     *
     * @param  string  $code  오류 코드
     * @param  string  $guard  로그인 경로
     * @param  string|null  $redirect  로그인 뒤 복귀 경로
     * @return string 절대 주소
     */
    public static function errorUrl(string $code, string $guard = 'user', ?string $redirect = null): string
    {
        return self::screenUrl($guard, ['sso_error' => $code], $redirect);
    }

    /**
     * 로그인 화면 주소를 만듭니다 (사용자 `/login`, 관리자 `/admin/login`).
     *
     * @param  string  $guard  로그인 경로
     * @param  array<string, string>  $query  쿼리
     * @param  string|null  $redirect  로그인 뒤 복귀 경로 (`/` 면 생략)
     * @return string 절대 주소
     */
    public static function screenUrl(string $guard, array $query, ?string $redirect = null): string
    {
        $safe = SafeRedirect::path($redirect);
        if ($safe !== '/') {
            $query['redirect'] = $safe;
        }

        return SiteUrl::absolute(($guard === 'admin' ? '/admin/login' : '/login').'?'.http_build_query($query));
    }

    /**
     * 경로에 쿼리를 더합니다.
     *
     * @param  string  $path  경로
     * @param  array<string, string>  $query  쿼리
     * @return string 경로
     */
    public static function withQuery(string $path, array $query): string
    {
        return $path.(str_contains($path, '?') ? '&' : '?').http_build_query($query);
    }

    /**
     * IdP 와 드라이버를 쓸 수 있는지 확인합니다.
     *
     * 관리자 테스트는 꺼진 IdP 도 시작한다(켜기 전에 확인하는 용도). 모의 IdP 는 게이트가 켜져 있을 때만.
     *
     * @param  IdentityProvider|null  $idp  IdP
     * @param  string  $intent  의도
     * @return IdentityProvider IdP
     *
     * @throws SsoException `idp_disabled`, `driver_unavailable`
     */
    private function assertUsable(?IdentityProvider $idp, string $intent): IdentityProvider
    {
        if ($idp === null || ($intent !== LoginIntent::TEST && ! $idp->enabled)) {
            throw new SsoException('idp_disabled');
        }

        if ($idp->isMock() && ! $this->mockGate->enabled()) {
            throw new SsoException('idp_disabled', 'mock gate off');
        }

        $driver = $idp->driver();
        if (! $driver->isAvailable() || ! $driver->capabilities()->redirect) {
            throw new SsoException('driver_unavailable');
        }

        return $idp;
    }

    /**
     * 재확인 성공을 코어 본인인증 요청에 반영하고 출발 화면으로 돌려보냅니다.
     *
     * 왕복 증명을 남긴 뒤 코어 확인(`IdentityVerificationService::verify`)까지 여기서 마치고, 발급된 `verification_token` 을 복귀 주소에
     * 붙인다 — 화면은 다른 외부 수단과 같이 `query.verification_token` 으로 원래 저장을 다시 보낸다. 토큰은 1회 소비, 짧은
     * 수명, 본인 것만 통과하는 코어 관문이 검증한다. 복귀 경로에 있던 화면 쿼리(`reauth=app_key` 등)는 그대로 둔다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  LoginIntent  $intent  시작 정보
     * @param  ResolveResult  $result  결정 결과
     * @param  Request  $request  요청
     * @return RedirectResponse 출발 화면
     */
    private function completeReauth(IdentityProvider $idp, LoginIntent $intent, ResolveResult $result, Request $request): RedirectResponse
    {
        $user = $result->user;
        $verified = $user !== null && $intent->challengeId !== null && class_exists(SsoReauthIdentityProvider::class)
            && app(SsoReauthIdentityProvider::class)->markVerified($intent->challengeId, $user, $idp);

        if (! $verified) {
            return $this->fail($idp, $intent, 'ticket_invalid', $request, SsoLoginAttempt::OUTCOME_DENIED);
        }

        $verification = app(IdentityVerificationService::class)->verify((string) $intent->challengeId, []);
        $token = $verification->claims['verification_token'] ?? null;
        if (! $verification->success || ! is_string($token) || $token === '') {
            return $this->fail($idp, $intent, 'ticket_invalid', $request, SsoLoginAttempt::OUTCOME_DENIED);
        }

        $this->record($idp, $intent->intent, $intent->guard, SsoLoginAttempt::OUTCOME_REAUTH, (int) $user->getKey(), null, $request);

        return redirect()->to(SiteUrl::absolute(self::withQuery($intent->redirect, ['verification_token' => $token])));
    }

    /**
     * 실패를 기록하고 의도에 맞는 화면으로 보냅니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  LoginIntent  $intent  시작 정보
     * @param  string  $errorCode  오류 코드
     * @param  Request  $request  요청
     * @param  string  $outcome  기록할 결과 (실패/거절)
     * @param  array<string, mixed>|null  $responseSummary  실패한 IdP 응답 요약(진단 화면용, 원문 없음)
     * @return RedirectResponse 화면
     */
    private function fail(IdentityProvider $idp, LoginIntent $intent, string $errorCode, Request $request, string $outcome = SsoLoginAttempt::OUTCOME_FAILED, ?array $responseSummary = null): RedirectResponse
    {
        $this->record($idp, $intent->intent, $intent->guard, $outcome, $intent->linkUserId, $errorCode, $request, $responseSummary);
        if ($outcome === SsoLoginAttempt::OUTCOME_FAILED) {
            $this->idps->recordOutcome($idp, $errorCode);
        }

        if ($intent->intent === LoginIntent::TEST) {
            return redirect()->to($this->storeTestResult($idp, false, null, $errorCode));
        }

        return redirect()->to($this->failureUrl($intent->intent, $intent->guard, $intent->redirect, $errorCode));
    }

    /**
     * 실패 때 갈 주소 — 로그인 의도는 로그인 화면, 로그인한 회원이 시작한 의도(연결, 재확인)는 출발 화면.
     *
     * @param  string  $intent  의도
     * @param  string  $guard  로그인 경로
     * @param  string  $redirect  출발/복귀 경로
     * @param  string  $errorCode  오류 코드
     * @return string 절대 주소
     */
    private function failureUrl(string $intent, string $guard, string $redirect, string $errorCode): string
    {
        if (in_array($intent, [LoginIntent::LINK, LoginIntent::REAUTH], true)) {
            return SiteUrl::absolute(self::withQuery($redirect, ['sso_error' => $errorCode]));
        }

        if ($intent === LoginIntent::TEST) {
            return $this->storeTestResult(null, false, null, $errorCode);
        }

        return self::errorUrl($errorCode, $guard, $redirect);
    }

    /**
     * 관리자 테스트 결과를 일회용 코드로 저장하고 결과 화면 주소를 반환합니다 — 토큰, 원문은 싣지 않고 값은 가린다.
     *
     * @param  IdentityProvider|null  $idp  IdP
     * @param  bool  $success  성공 여부
     * @param  ExternalIdentity|null  $identity  신원
     * @param  string|null  $errorCode  오류 코드
     * @return string 결과 화면 절대 주소
     */
    private function storeTestResult(?IdentityProvider $idp, bool $success, ?ExternalIdentity $identity, ?string $errorCode): string
    {
        $code = Str::random(40);

        $this->cache->put('test_result:'.hash('sha256', $code), [
            'idp_key' => $idp?->key,
            'idp_label' => $idp?->localizedLabel(),
            'success' => $success,
            'error_code' => $errorCode,
            'subject' => $identity !== null ? self::mask($identity->subject) : null,
            'email' => $identity?->email !== null ? self::maskEmail($identity->email) : null, // audit:allow resource-member-email-ungated 가린 값만 — 관리자 테스트 결과 화면(60초 1회)
            'email_verified' => $identity?->emailVerified,
            'display_name' => $identity?->displayName,
            'groups' => $identity?->groups ?? [],
            'attribute_keys' => $identity !== null ? array_keys($identity->attributes) : [],
            'mfa' => $identity?->mfa,
            'tested_at' => now()->toIso8601String(),
        ], self::TEST_RESULT_TTL);

        return SiteUrl::absolute('/plugins/sirsoft-sso/test-result/'.$code);
    }

    /**
     * 시도를 기록합니다.
     *
     * 실패(`failed`)·거절(`denied`) 기록 직후에는 `sirsoft-sso.auth.login_failed` 를 발행한다 — 실패 지점이 여럿이라 기록하는 이 한 곳에서
     * 낸다. 인자에는 토큰, 어설션 원문, subject 를 싣지 않는다. IdP 자체 장애만 세려면 구독자가 `$outcome === 'failed'` 로 거른다.
     *
     * @param  IdentityProvider|null  $idp  IdP
     * @param  string  $intent  의도
     * @param  string  $guard  로그인 경로
     * @param  string  $outcome  결과
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $reason  사유 코드
     * @param  Request  $request  요청
     * @param  array<string, mixed>|null  $responseSummary  실패한 IdP 응답 요약(진단 화면용, 원문 없음)
     * @return void 반환값 없음
     */
    private function record(?IdentityProvider $idp, string $intent, string $guard, string $outcome, ?int $userId, ?string $reason, Request $request, ?array $responseSummary = null): void
    {
        $this->attempts->record([
            'response_summary' => $responseSummary,
            'idp_id' => $idp !== null ? (int) $idp->getKey() : null,
            'user_id' => $userId,
            'intent' => in_array($intent, LoginIntent::INTENTS, true) ? $intent : LoginIntent::LOGIN,
            'guard' => in_array($guard, LoginIntent::GUARDS, true) ? $guard : 'user',
            'outcome' => $outcome,
            'reason_code' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        if (in_array($outcome, self::FAILURE_OUTCOMES, true)) {
            HookManager::doAction(self::ACTION_LOGIN_FAILED, $idp, (string) $reason, $intent, $guard, $outcome);
        }
    }

    /**
     * 세션이 있으면 ID 를 새로 만듭니다 (세션 고정 방어).
     *
     * @param  Request  $request  요청
     * @return void 반환값 없음
     */
    private function regenerateSession(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->regenerate(true);
        }
    }

    /**
     * 식별자를 앞뒤만 보이게 가립니다.
     *
     * @param  string  $value  값
     * @return string 가린 값
     */
    public static function mask(string $value): string
    {
        $length = mb_strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return mb_substr($value, 0, 2).str_repeat('*', $length - 4).mb_substr($value, -2);
    }

    /**
     * 이메일의 로컬 부분을 가립니다 (예: `h***@corp.com`).
     *
     * @param  string  $email  이메일
     * @return string 가린 이메일
     */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('*', max(3, mb_strlen($local) - 1)).'@'.$domain;
    }
}
