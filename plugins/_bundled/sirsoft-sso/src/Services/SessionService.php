<?php

namespace Plugins\Sirsoft\Sso\Services;

use App\Contracts\Extension\CacheInterface;
use App\Models\User;
use App\Support\SiteUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Models\SsoSession;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoSessionRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * SSO 세션과 로그아웃 — G7 토큰과 IdP 세션을 잇고, 로그아웃 범위(G7 만 / G7 → IdP / 양방향)를 처리합니다.
 *
 *  - 로그인 확정 때 `sso_sessions` 행을 만들고 그 id 를 인계 문맥 `external_ref` 로 싣는다 → 로그인 완료 훅이 토큰 id 를 채운다.
 *  - 세션 수명 상한은 IdP 값과 사이트 값 중 작은 값을 인계 문맥 `max_session_minutes` 로 싣는다(코어가 토큰 절대 만료로 쓴다).
 *  - 로그아웃: 코어가 토큰을 지우기 전에 부르는 필터에서 그 토큰의 세션을 찾아, IdP 로그아웃이 필요하면 1회용 티켓 주소를 돌려준다.
 *    주소에 세션 id 를 싣지 않는다(순번만 알면 남의 IdP 로그아웃을 일으킬 수 있다).
 *  - IdP 발 로그아웃(OIDC back-channel, SAML SLS): 세션의 토큰을 지우고 세션을 끝낸다.
 *
 * @since 1.0.0
 */
class SessionService
{
    /** 인계 문맥 `external_ref` 접두 */
    public const EXTERNAL_REF_PREFIX = 'sso_session:';

    /** 로그아웃 티켓 수명(초) */
    public const LOGOUT_TICKET_TTL = 60;

    /** 로그아웃 복귀 때 갈 로그인 화면을 기억하는 세션 키 */
    public const RETURN_GUARD_SESSION_KEY = 'sirsoft-sso.logout_return_guard';

    /** @var array<int, string> IdP 로그아웃으로 보내는 로그아웃 범위 */
    public const RP_LOGOUT_MODES = ['rp', 'both'];

    /** @var int|null 이번 요청에서 로그아웃 필터가 찾은 세션 ID (로그아웃 완료 훅에서 끝낸다) */
    private ?int $loggingOutSessionId = null;

    /**
     * @param  SsoSessionRepositoryInterface  $sessions  세션 저장소
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  CacheInterface  $cache  플러그인 캐시 (로그아웃 티켓)
     */
    public function __construct(
        private readonly SsoSessionRepositoryInterface $sessions,
        private readonly IdentityProviderRepositoryInterface $idps,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 인계 문맥에 세션 참조와 세션 수명 상한을 싣습니다 (필터 `sirsoft-sso.handoff.filter_context`).
     *
     * @param  array<string, mixed>  $context  인계 문맥
     * @param  User  $user  회원
     * @param  SsoAccount  $account  연결
     * @param  ExternalIdentity  $identity  신원
     * @param  IdentityProvider  $idp  IdP
     * @return array<string, mixed> 문맥
     */
    public function handoffContext(array $context, User $user, SsoAccount $account, ExternalIdentity $identity, IdentityProvider $idp): array
    {
        $session = $this->sessions->create([
            'idp_id' => (int) $idp->getKey(),
            'user_id' => (int) $user->getKey(),
            'external_session' => $identity->sessionRef !== null ? mb_substr($identity->sessionRef, 0, 255) : null,
            'name_id' => mb_substr($identity->nameId ?? $identity->subject, 0, 255),
        ]);

        $context['external_ref'] = self::EXTERNAL_REF_PREFIX.$session->getKey();

        $cap = $this->sessionCap($idp);
        if ($cap !== null) {
            $context['max_session_minutes'] = $cap;
        }

        return $context;
    }

    /**
     * 세션 수명 상한(분)을 정합니다 — IdP 값과 사이트 값 중 켜진 것의 작은 값, 둘 다 끄면 null.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return int|null 상한(분)
     */
    public function sessionCap(IdentityProvider $idp): ?int
    {
        $candidates = array_filter([
            (int) ($idp->max_session_minutes ?? 0),
            (int) $this->sitePolicy('session_max_minutes', 0),
        ], static fn (int $minutes) => $minutes > 0);

        return $candidates === [] ? null : min(10080, min($candidates));
    }

    /**
     * 로그인 완료 훅에서 세션에 토큰 id 를 붙입니다 (액션 `core.auth.after_login`).
     *
     * @param  User  $user  회원
     * @param  array<string, mixed>  $context  로그인 문맥 (`login_method`, `token_id`, `external_ref`)
     * @return void
     */
    public function onAfterLogin(User $user, array $context): void
    {
        $method = $context['login_method'] ?? null;
        $ref = $context['external_ref'] ?? null;
        $tokenId = (int) ($context['token_id'] ?? 0);

        if (! is_string($method) || ! str_starts_with($method, 'sso:') || ! is_string($ref) || $tokenId <= 0) {
            return;
        }

        $sessionId = self::sessionIdFromRef($ref);
        if ($sessionId !== null) {
            $this->sessions->attachToken($sessionId, (int) $user->getKey(), $tokenId);
        }
    }

    /**
     * 로그아웃 뒤 갈 곳을 정합니다 (필터 `core.auth.logout_redirect_url` — 코어가 토큰을 지우기 전에 부른다).
     *
     * 지금 토큰의 세션이 IdP 로그아웃 범위(`rp`, `both`)이고 드라이버가 SLO 를 지원하면 1회용 로그아웃 티켓 주소를 돌려준다.
     * 다른 확장이 이미 주소를 정했으면 그것을 따른다(세션 종료는 그대로 한다).
     *
     * @param  mixed  $url  앞선 필터 결과
     * @param  User  $user  회원
     * @param  array{guard?: string, token_id?: int|null}  $meta  로그아웃 경로와 토큰 id
     * @return mixed 같은 출처 경로 또는 앞선 값
     */
    public function logoutRedirect(mixed $url, User $user, array $meta = []): mixed
    {
        $tokenId = (int) ($meta['token_id'] ?? 0);
        if ($tokenId <= 0) {
            return $url;
        }

        $session = $this->sessions->findOpenByToken($tokenId);
        if ($session === null || (int) $session->user_id !== (int) $user->getKey()) {
            return $url;
        }

        $this->loggingOutSessionId = (int) $session->getKey();

        if (is_string($url) && $url !== '') {
            return $url;
        }

        $idp = $session->idp;
        if (! $idp instanceof IdentityProvider || ! in_array($idp->logout_mode, self::RP_LOGOUT_MODES, true) || ! $this->supportsSlo($idp)) {
            return $url;
        }

        $ticket = Str::random(40);
        $this->cache->put(self::ticketKey($ticket), [
            'session_id' => (int) $session->getKey(),
            'idp_id' => (int) $idp->getKey(),
            'guard' => ($meta['guard'] ?? 'user') === 'admin' ? 'admin' : 'user',
        ], self::LOGOUT_TICKET_TTL);

        return '/plugins/sirsoft-sso/logout/'.$idp->key.'?'.http_build_query(['ticket' => $ticket]);
    }

    /**
     * 로그아웃이 끝나면 필터가 찾은 세션을 끝냅니다 (액션 `core.auth.logout`).
     *
     * @param  User  $user  회원
     * @return void
     */
    public function onLogout(User $user): void
    {
        if ($this->loggingOutSessionId === null) {
            return;
        }

        $this->sessions->end([$this->loggingOutSessionId], SsoSession::END_LOGOUT);
        $this->loggingOutSessionId = null;
    }

    /**
     * 1회용 티켓으로 IdP 로그아웃을 시작합니다 — `GET /plugins/sirsoft-sso/logout/{idp}?ticket=`.
     *
     * 티켓이 없거나 이미 썼거나 다른 IdP 의 것이면 IdP 로 보내지 않고 로그인 화면으로 간다.
     *
     * @param  string  $idpKey  경로의 IdP 키
     * @param  Request  $request  요청
     * @return Response IdP 로그아웃 302 또는 로그인 화면
     */
    public function beginIdpLogout(string $idpKey, Request $request): Response
    {
        $ticket = $request->query('ticket');
        $payload = is_string($ticket) && preg_match('/^[A-Za-z0-9]{40}$/', $ticket) === 1 ? $this->pullTicket($ticket) : null;
        $guard = ($payload['guard'] ?? 'user') === 'admin' ? 'admin' : 'user';

        $session = $payload !== null ? $this->sessions->findById((int) $payload['session_id']) : null;
        $idp = $session?->idp;
        if ($session === null || ! $idp instanceof IdentityProvider || $idp->key !== $idpKey || (int) $idp->getKey() !== (int) $payload['idp_id']) {
            return redirect()->to(self::loginUrl($guard));
        }

        if ($request->hasSession()) {
            $request->session()->put(self::RETURN_GUARD_SESSION_KEY, $guard);
        }

        try {
            $response = $idp->driver()->beginLogout($idp, $session);
        } catch (SsoException $e) {
            report($e);
            $response = null;
        }

        return $response ?? redirect()->to(self::loginUrl($guard));
    }

    /**
     * IdP 로그아웃에서 돌아온 브라우저를 로그인 화면으로 보냅니다 — `GET /plugins/sirsoft-sso/logout/return`.
     *
     * @param  Request  $request  요청
     * @return RedirectResponse 로그인 화면 (관리자 로그아웃이었으면 관리자 로그인 화면)
     */
    public function logoutReturn(Request $request): RedirectResponse
    {
        $guard = $request->hasSession() ? $request->session()->pull(self::RETURN_GUARD_SESSION_KEY, 'user') : 'user';

        return redirect()->to(self::loginUrl($guard === 'admin' ? 'admin' : 'user'));
    }

    /**
     * OIDC back-channel 로그아웃을 받습니다 — `POST /plugins/sirsoft-sso/oidc/{idp}/backchannel-logout`.
     *
     * 로그아웃 범위가 양방향(`both`)인 IdP 만 받는다. 검증 실패는 400(규격 — 본문에 사유를 싣지 않는다).
     *
     * @param  string  $idpKey  경로의 IdP 키
     * @param  Request  $request  IdP 요청
     * @return Response 200 또는 400
     */
    public function backchannel(string $idpKey, Request $request): Response
    {
        $headers = ['Cache-Control' => 'no-store'];
        $idp = $this->idps->findByKey($idpKey);
        if ($idp === null || ! $idp->enabled || $idp->logout_mode !== 'both') {
            return response()->json(['error' => 'invalid_request'], 400, $headers);
        }

        try {
            $result = $idp->driver()->handleLogoutRequest($idp, $request);
        } catch (SsoException $e) {
            return response()->json(['error' => 'invalid_request'], 400, $headers);
        }

        $this->endFromIdp($result->sessions, SsoSession::END_IDP_LOGOUT);

        return $result->response;
    }

    /**
     * IdP 발 로그아웃으로 고른 세션을 끝냅니다 — 토큰을 지우고 `ended_at` 을 남긴다 (OIDC back-channel, SAML SLS 공용).
     *
     * 토큰 id 가 없는 세션(2단계 인증 중 이탈 등)은 그 회원의 그 IdP 열린 세션을 모두 끝낸다 — 다른 IdP 세션은 건드리지 않는다.
     *
     * @param  array<int, SsoSession>  $sessions  끝낼 세션
     * @param  string  $reason  종료 사유
     * @return int 끝낸 세션 수
     */
    public function endFromIdp(array $sessions, string $reason): int
    {
        $ended = 0;

        foreach ($sessions as $session) {
            if (! $session instanceof SsoSession || $session->ended_at !== null) {
                continue;
            }

            $targets = $session->token_id !== null
                ? [$session]
                : $this->sessions->openForUserIdp((int) $session->idp_id, (int) $session->user_id)->all();

            $ended += $this->endSessions($targets, (int) $session->user_id, $reason);
        }

        return $ended;
    }

    /**
     * 회원의 그 IdP 열린 세션을 끝내고 그 토큰을 지웁니다 (퇴사 — 겸직이라 회원을 차단하지 않을 때도 그 IdP 로 받은 로그인은 끊는다).
     *
     * @param  int  $idpId  IdP ID
     * @param  int  $userId  회원 ID
     * @param  string  $reason  종료 사유
     * @return int 끝낸 세션 수
     */
    public function endForUserIdp(int $idpId, int $userId, string $reason): int
    {
        return $this->endSessions($this->sessions->openForUserIdp($idpId, $userId)->all(), $userId, $reason);
    }

    /**
     * 인계 문맥의 세션 참조에서 세션 ID 를 꺼냅니다.
     *
     * @param  string  $ref  `sso_session:{id}`
     * @return int|null 세션 ID
     */
    public static function sessionIdFromRef(string $ref): ?int
    {
        if (preg_match('/^'.preg_quote(self::EXTERNAL_REF_PREFIX, '/').'([1-9][0-9]{0,18})$/', $ref, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * 세션들의 토큰을 지우고 세션을 끝냅니다.
     *
     * @param  array<int, SsoSession>  $sessions  세션
     * @param  int  $userId  회원 ID
     * @param  string  $reason  종료 사유
     * @return int 끝낸 세션 수
     */
    private function endSessions(array $sessions, int $userId, string $reason): int
    {
        if ($sessions === []) {
            return 0;
        }

        $this->sessions->deleteTokens(array_map(static fn (SsoSession $s) => (int) $s->token_id, $sessions), $userId);

        return $this->sessions->end(array_map(static fn (SsoSession $s) => (int) $s->getKey(), $sessions), $reason);
    }

    /**
     * 드라이버가 SP 발 로그아웃을 지원하는지 확인합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return bool 지원하면 true
     */
    private function supportsSlo(IdentityProvider $idp): bool
    {
        try {
            return $idp->driver()->capabilities()->slo;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 로그아웃 티켓을 꺼냅니다 (1회용 — 꺼내면 지운다).
     *
     * @param  string  $ticket  티켓
     * @return array{session_id: int, idp_id: int, guard: string}|null 내용
     */
    private function pullTicket(string $ticket): ?array
    {
        $key = self::ticketKey($ticket);
        $payload = $this->cache->get($key);
        $this->cache->forget($key);

        return is_array($payload) && isset($payload['session_id'], $payload['idp_id']) ? $payload : null;
    }

    /**
     * 사이트 정책 값을 읽습니다 (정책 서비스가 없으면 기본값).
     *
     * @param  string  $key  정책 키
     * @param  mixed  $default  기본값
     * @return mixed 값
     */
    private function sitePolicy(string $key, mixed $default): mixed
    {
        $service = 'Plugins\\Sirsoft\\Sso\\Services\\Policy\\PolicyService';
        if (! class_exists($service) || ! method_exists($service, 'get')) {
            return $default;
        }

        try {
            return app($service)->get($key, $default);
        } catch (\Throwable $e) {
            report($e);

            return $default;
        }
    }

    /**
     * 로그아웃 티켓 캐시 키.
     *
     * @param  string  $ticket  티켓
     * @return string 키
     */
    private static function ticketKey(string $ticket): string
    {
        return 'logout_ticket:'.hash('sha256', $ticket);
    }

    /**
     * 로그인 화면 절대 주소.
     *
     * @param  string  $guard  user, admin
     * @return string 주소
     */
    private static function loginUrl(string $guard): string
    {
        return SiteUrl::absolute($guard === 'admin' ? '/admin/login' : '/login');
    }
}
