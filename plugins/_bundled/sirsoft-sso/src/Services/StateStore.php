<?php

namespace Plugins\Sirsoft\Sso\Services;

use App\Contracts\Extension\CacheInterface;
use App\Support\SiteUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie as CookieQueue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\Services\Policy\PolicyService;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * IdP 왕복 state, 브라우저 묶음 쿠키, 시작 티켓 저장소.
 *
 * 로그인 CSRF 를 막으려고 모든 1회용 값(state, 시작 티켓, 동의 대기, 연결 확인 대기)을 그것을 만든 브라우저에 묶는다 — 브라우저마다
 * 무작위 묶음값을 쿠키 `g7_sso_bind` 로 두고, 1회용 값에는 그 sha256 만 저장한다. 쓰는 순간 요청 쿠키의 sha256 과 대조한다.
 * 공격자가 만든 흐름에 저장된 해시는 공격자 쿠키의 것이라 피해자 브라우저에서는 맞지 않는다.
 *
 * 세션에 두지 않는다 — 인앱 브라우저, 하위 도메인 이동에서 세션이 끊긴다. 묶음 쿠키는 web 응답이 굽고 api 요청이 읽으므로
 * 암호화 대상에서 빠져 있어야 한다(코어 `encryptCookies(except:)`).
 *
 * @since 1.0.0
 */
class StateStore
{
    /** 묶음 쿠키 이름 */
    public const BIND_COOKIE = 'g7_sso_bind';

    /** 묶음 쿠키 수명(분) */
    public const BIND_COOKIE_MINUTES = 30;

    /** state 기본 수명(분) */
    public const DEFAULT_STATE_TTL_MINUTES = 10;

    /** 시작 티켓 수명(초) */
    public const TICKET_TTL_SECONDS = 60;

    /** 묶음값 형식 — 32바이트 16진 */
    private const BIND_PATTERN = '/^[a-f0-9]{64}$/';

    /** @var string|null 이 요청에서 새로 구운 묶음값 (같은 요청 안에서 두 번 굽지 않게) */
    private ?string $issuedBind = null;

    /**
     * @param  CacheInterface  $cache  플러그인 캐시
     * @param  PolicyService|null  $policy  사이트 정책 (수명 `state_ttl_minutes` — 없으면 기본 10분)
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ?PolicyService $policy = null,
    ) {}

    /**
     * 요청 브라우저의 묶음값을 돌려줍니다 — 없으면 새로 만들어 web 응답 쿠키 대기열에 싣습니다.
     *
     * api 응답은 쿠키 대기열이 붙지 않으므로 api 에서 처음 굽는 호출자는 `bindCookie()` 를 응답에 직접 붙인다.
     *
     * @param  Request  $request  요청
     * @param  bool  $queue  새로 만들 때 쿠키 대기열에 실을지 (web 응답)
     * @return string 묶음값
     */
    public function ensureBind(Request $request, bool $queue = true): string
    {
        $current = $this->currentBind($request);
        if ($current !== null) {
            return $current;
        }

        $this->issuedBind ??= bin2hex(random_bytes(32));
        if ($queue) {
            CookieQueue::queue($this->bindCookie($this->issuedBind, $request));
        }

        return $this->issuedBind;
    }

    /**
     * 이 요청에서 새로 만든 묶음값이 있으면 그 쿠키를 응답에 직접 붙입니다 (요청에 이미 쿠키가 있었으면 아무것도 하지 않는다).
     *
     * 쿠키 대기열에만 기대지 않는다 — 응답을 이 플러그인이 만드는 지점(왕복 시작, 콜백)에서는 응답에 바로 싣는다.
     *
     * @param  Response  $response  응답
     * @param  Request  $request  요청
     * @return Response 같은 응답
     */
    public function attachIssuedBind(Response $response, Request $request): Response
    {
        $cookieValue = $request->cookies->get(self::BIND_COOKIE);
        if ($this->issuedBind !== null && (! is_string($cookieValue) || preg_match(self::BIND_PATTERN, $cookieValue) !== 1)) {
            $response->headers->setCookie($this->bindCookie($this->issuedBind, $request));
        }

        return $response;
    }

    /**
     * 요청 쿠키의 묶음값을 읽습니다 (형식이 맞을 때만). 같은 요청에서 새로 구운 값도 돌려준다.
     *
     * @param  Request  $request  요청
     * @return string|null 묶음값
     */
    public function currentBind(Request $request): ?string
    {
        $value = $request->cookies->get(self::BIND_COOKIE);
        if (is_string($value) && preg_match(self::BIND_PATTERN, $value) === 1) {
            return $value;
        }

        return $this->issuedBind;
    }

    /**
     * 묶음값의 해시를 만듭니다.
     *
     * @param  string  $bind  묶음값
     * @return string sha256
     */
    public static function hashBind(string $bind): string
    {
        return hash('sha256', $bind);
    }

    /**
     * 저장된 묶음 해시가 요청 브라우저의 것인지 대조합니다.
     *
     * @param  string|null  $storedHash  저장된 해시
     * @param  Request  $request  요청
     * @return bool 같은 브라우저면 true
     */
    public function matchesBind(?string $storedHash, Request $request): bool
    {
        $current = $this->currentBind($request);
        if (! is_string($storedHash) || $storedHash === '' || $current === null) {
            return false;
        }

        return hash_equals($storedHash, self::hashBind($current));
    }

    /**
     * 묶음 쿠키를 만듭니다.
     *
     * HTTPS 사이트면 SameSite=None; Secure — SAML ACS 는 IdP 의 교차 사이트 POST 라 Lax 쿠키가 실리지 않는다. HTTP 개발 사이트는
     * 브라우저가 Secure 없는 None 을 버리므로 Lax 로 굽는다(OIDC 의 GET 콜백은 Lax 로도 실린다).
     *
     * @param  string  $bind  묶음값
     * @param  Request|null  $request  요청 (HTTPS 판정)
     * @return Cookie 쿠키
     */
    public function bindCookie(string $bind, ?Request $request = null): Cookie
    {
        $secure = ($request?->isSecure() ?? false) || str_starts_with(SiteUrl::absolute('/'), 'https://');

        return Cookie::create(
            name: self::BIND_COOKIE,
            value: $bind,
            expire: time() + self::BIND_COOKIE_MINUTES * 60,
            path: '/',
            domain: null,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: $secure ? Cookie::SAMESITE_NONE : Cookie::SAMESITE_LAX,
        );
    }

    /**
     * 새 state 를 만들고 저장합니다.
     *
     * @param  array{intent: string, guard: string, redirect: string|null, idp_id: int, idp_key: string, link_user_id?: int|null, challenge_id?: string|null}  $start  시작 값
     * @param  string  $bind  시작 브라우저 묶음값
     * @param  int|null  $ttlMinutes  수명(분, null 이면 정책 「로그인 요청 유효 시간」)
     * @return LoginIntent 저장한 시작 정보
     */
    public function create(array $start, string $bind, ?int $ttlMinutes = null): LoginIntent
    {
        $intent = new LoginIntent(
            intent: $start['intent'],
            guard: $start['guard'],
            redirect: SafeRedirect::path($start['redirect'] ?? null),
            idpId: $start['idp_id'],
            idpKey: $start['idp_key'],
            stateId: Str::random(43),
            nonce: Str::random(43),
            codeVerifier: Str::random(64),
            bindHash: self::hashBind($bind),
            linkUserId: $start['link_user_id'] ?? null,
            challengeId: $start['challenge_id'] ?? null,
            createdAt: time(),
        );

        $this->store($intent, $ttlMinutes);

        return $intent;
    }

    /**
     * 시작 정보를 다시 저장합니다 (드라이버가 `extra` 를 더했을 때). 수명은 처음 만든 시각 기준으로 남은 만큼이다.
     *
     * @param  LoginIntent  $intent  시작 정보
     * @param  int|null  $ttlMinutes  처음 수명(분, null 이면 정책 「로그인 요청 유효 시간」)
     * @return void 반환값 없음
     */
    public function update(LoginIntent $intent, ?int $ttlMinutes = null): void
    {
        $this->store($intent, $ttlMinutes);
    }

    /**
     * state 로 시작 정보를 꺼내고 바로 지웁니다 (1회용).
     *
     * 읽기와 지우기 사이에 같은 콜백이 한 번 더 들어오면 둘 다 통과하므로 잠금 안에서 꺼낸다. 잠금을 못 잡으면 꺼내지 못한 것으로 본다.
     *
     * @param  string  $state  state
     * @return LoginIntent|null 시작 정보, 없거나 만료면 null
     */
    public function pull(string $state): ?LoginIntent
    {
        if ($state === '' || strlen($state) > 128) {
            return null;
        }

        $data = $this->pullEncrypted($this->stateKey($state));
        if ($data === null || ! hash_equals((string) ($data['state'] ?? ''), $state)) {
            return null;
        }

        return LoginIntent::fromArray($data);
    }

    /**
     * 로그인 상태가 필요한 의도(link, reauth, test)의 1회용 시작 티켓을 발급합니다.
     *
     * 브라우저 이동에는 Bearer 토큰이 실리지 않아 web 시작 경로가 회원을 알 수 없다 — 회원을 아는 API 가 티켓을 발급한다.
     *
     * @param  array{user_id: int, intent: string, idp_id: int, guard?: string, redirect?: string|null, challenge_id?: string|null}  $payload  티켓 내용
     * @param  string  $bind  발급 브라우저 묶음값
     * @return string 티켓
     */
    public function issueTicket(array $payload, string $bind): string
    {
        $ticket = Str::random(48);
        $payload['bind_hash'] = self::hashBind($bind);
        $payload['redirect'] = SafeRedirect::path($payload['redirect'] ?? null);

        $this->cache->put($this->ticketKey($ticket), Crypt::encryptString((string) json_encode($payload)), self::TICKET_TTL_SECONDS);

        return $ticket;
    }

    /**
     * 시작 티켓을 꺼내고 바로 지웁니다 — 다른 브라우저면 null(티켓도 소멸).
     *
     * @param  string  $ticket  티켓
     * @param  Request  $request  요청
     * @return array<string, mixed>|null 티켓 내용
     */
    public function pullTicket(string $ticket, Request $request): ?array
    {
        if (preg_match('/^[A-Za-z0-9]{48}$/', $ticket) !== 1) {
            return null;
        }

        $data = $this->pullEncrypted($this->ticketKey($ticket));
        if ($data === null || ! $this->matchesBind($data['bind_hash'] ?? null, $request)) {
            return null;
        }

        return $data;
    }

    /**
     * 시작 정보를 암호화해 저장합니다.
     *
     * @param  LoginIntent  $intent  시작 정보
     * @param  int|null  $ttlMinutes  수명(분)
     * @return void 반환값 없음
     */
    private function store(LoginIntent $intent, ?int $ttlMinutes): void
    {
        $ttl = max(1, $ttlMinutes ?? $this->policyTtlMinutes()) * 60;
        $remaining = $intent->createdAt > 0 ? $ttl - (time() - $intent->createdAt) : $ttl;

        $this->cache->put(
            $this->stateKey($intent->stateId),
            Crypt::encryptString((string) json_encode($intent->toArray())),
            max(1, $remaining),
        );
    }

    /**
     * 잠금 안에서 암호화된 값을 꺼내고 지운 뒤 풉니다.
     *
     * @param  string  $key  캐시 키
     * @return array<string, mixed>|null 값
     */
    private function pullEncrypted(string $key): ?array
    {
        $payload = Cache::lock('sirsoft-sso:lock:'.$key, 5)->get(function () use ($key) {
            $value = $this->cache->get($key);
            $this->cache->forget($key);

            return $value;
        });

        if (! is_string($payload)) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($payload), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * 정책 「로그인 요청 유효 시간」(분)을 읽습니다.
     *
     * 관리자 정책 화면에서 저장한 값이 state 수명이 된다(종전에는 늘 기본 10분). 정책을 읽지 못하면(설치 전·표 없음) 기본값이다.
     *
     * @return int 수명(분)
     */
    private function policyTtlMinutes(): int
    {
        try {
            // 주입이 없으면(직접 만든 저장소·자동 주입이 비는 문맥) 쓸 때 꺼낸다 — 정책 읽기는 PolicyService 한 곳
            return (int) (($this->policy ?? app(PolicyService::class))->get('state_ttl_minutes', self::DEFAULT_STATE_TTL_MINUTES) ?? self::DEFAULT_STATE_TTL_MINUTES);
        } catch (\Throwable) {
            return self::DEFAULT_STATE_TTL_MINUTES;
        }
    }

    /**
     * state 캐시 키.
     *
     * @param  string  $state  state
     * @return string 캐시 키
     */
    private function stateKey(string $state): string
    {
        return 'state:'.hash('sha256', $state);
    }

    /**
     * 시작 티켓 캐시 키.
     *
     * @param  string  $ticket  티켓
     * @return string 캐시 키
     */
    private function ticketKey(string $ticket): string
    {
        return 'ticket:'.hash('sha256', $ticket);
    }
}
