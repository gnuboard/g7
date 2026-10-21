<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;

/**
 * OAuth state 저장소.
 *
 * 시작 정보(복귀 주소, 의도, PKCE verifier, nonce)를 state 키로 암호화해 캐시에 두고, 콜백에서 꺼내자마자 지운다.
 * 꺼낸 뒤 바로 지우므로 같은 콜백 주소를 다시 열어도(인가 코드 재사용) 두 번째는 state 가 없어 실패한다.
 *
 * @since 1.0.0
 */
class StateStore
{
    /** 기본 TTL(분) — 설정 state_ttl_minutes 가 없을 때 */
    public const DEFAULT_TTL_MINUTES = 10;

    /**
     * @param  CacheInterface  $cache  플러그인 캐시
     */
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * 새 시작 정보를 만들고 저장합니다.
     *
     * @param  string  $providerKey  제공자 키
     * @param  string  $intent  의도 — login·link·test·reauth
     * @param  string|null  $redirect  복귀 주소 (같은 출처 상대 경로만, 아니면 `/`)
     * @param  string  $callbackUrl  콜백 주소
     * @param  int|null  $userId  시작한 회원 ID
     * @param  bool  $usePkce  PKCE 사용 여부
     * @param  bool  $useNonce  OIDC nonce 사용 여부
     * @param  int|null  $ttlMinutes  TTL(분)
     * @param  string|null  $challengeId  본인 재확인 의도의 코어 본인인증 요청 ID
     * @param  bool  $reconsent  제공자 동의 화면 재요청 여부
     * @return AuthorizationRequest 저장한 시작 정보
     */
    public function create(
        string $providerKey,
        string $intent,
        ?string $redirect,
        string $callbackUrl,
        ?int $userId = null,
        bool $usePkce = true,
        bool $useNonce = false,
        ?int $ttlMinutes = null,
        ?string $challengeId = null,
        bool $reconsent = false,
    ): AuthorizationRequest {
        $request = new AuthorizationRequest(
            providerKey: $providerKey,
            intent: $intent,
            redirect: self::safeRedirect($redirect),
            state: Str::random(40),
            callbackUrl: $callbackUrl,
            userId: $userId,
            nonce: $useNonce ? Str::random(32) : null,
            codeVerifier: $usePkce ? Str::random(64) : null,
            createdAt: time(),
            challengeId: $challengeId,
            reconsent: $reconsent,
        );

        $ttl = max(1, $ttlMinutes ?? self::DEFAULT_TTL_MINUTES) * 60;
        $this->cache->put($this->key($request->state), Crypt::encryptString(json_encode($request->toArray())), $ttl);

        return $request;
    }

    /**
     * state 로 시작 정보를 꺼내고 바로 지웁니다.
     *
     * @param  string  $state  state
     * @return AuthorizationRequest|null 시작 정보, 없거나 만료면 null
     */
    public function pull(string $state): ?AuthorizationRequest
    {
        if ($state === '' || strlen($state) > 128) {
            return null;
        }

        $key = $this->key($state);

        // 읽기와 지우기 사이에 같은 콜백이 한 번 더 들어오면 둘 다 통과한다 — 잠금 안에서 꺼내고 지운다.
        // 잠금을 못 잡으면(동시 요청) 두 번째 요청은 꺼내지 못한 것으로 본다.
        $payload = Cache::lock('sirsoft-social_login:state-lock:'.hash('sha256', $state), 5)
            ->get(function () use ($key) {
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

        if (! is_array($data) || ! hash_equals((string) ($data['state'] ?? ''), $state)) {
            return null;
        }

        return AuthorizationRequest::fromArray($data);
    }

    /**
     * 복귀 주소를 같은 출처 상대 경로로 제한합니다.
     *
     * `/` 로 시작하고 `//`(프로토콜 상대 주소)·역슬래시·스킴·제어문자가 없는 경로만 받는다. 아니면 `/`.
     *
     * @param  string|null  $redirect  요청한 복귀 주소
     * @return string 안전한 복귀 주소
     */
    public static function safeRedirect(?string $redirect): string
    {
        if (! is_string($redirect) || $redirect === '' || strlen($redirect) > 2000) {
            return '/';
        }

        if ($redirect[0] !== '/' || str_starts_with($redirect, '//') || str_contains($redirect, '\\')) {
            return '/';
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $redirect) === 1 || preg_match('#^/[^/?\#]*:#', $redirect) === 1) {
            return '/';
        }

        return $redirect;
    }

    /**
     * 캐시 키를 만듭니다.
     *
     * @param  string  $state  state
     * @return string 캐시 키
     */
    private function key(string $state): string
    {
        return 'state:'.hash('sha256', $state);
    }
}
