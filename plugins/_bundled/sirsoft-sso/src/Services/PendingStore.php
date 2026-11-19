<?php

namespace Plugins\Sirsoft\Sso\Services;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Services\Policy\PolicyService;

/**
 * 동의 대기(`sso_pending`)와 연결 확인 대기(`sso_link`) 토큰 저장소.
 *
 * 대기 값에는 IdP 가 준 신원(개인정보)이 들어가므로 암호화해 캐시에 둔다. 토큰은 시작 브라우저의 묶음 쿠키에 묶인다 — 쓰는 순간
 * 요청 쿠키를 대조하고, 다르면 그 토큰을 폐기한다. 묶지 않으면 공격자 신원의 연결 확인 주소를 피해자에게 보내 피해자가 자기
 * 비밀번호를 넣게 하는 계정 탈취가 된다.
 *
 * @since 1.0.0
 */
class PendingStore
{
    /** 종류 — 처음 로그인 약관 동의 대기 */
    public const TYPE_CONSENT = 'consent';

    /** 종류 — 기존 회원 연결 확인 대기 */
    public const TYPE_LINK = 'link';

    /** 기본 수명(분) */
    public const DEFAULT_TTL_MINUTES = 10;

    /** 토큰 형식 */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9]{48}$/';

    /**
     * @param  CacheInterface  $cache  플러그인 캐시
     * @param  StateStore  $states  묶음 쿠키 판정
     * @param  PolicyService|null  $policy  사이트 정책 (수명 `pending_ttl_minutes` — 없으면 기본 10분)
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly StateStore $states,
        private readonly ?PolicyService $policy = null,
    ) {}

    /**
     * 새 대기 토큰을 만들고 저장합니다.
     *
     * @param  string  $type  종류 (consent, link)
     * @param  array<string, mixed>  $data  대기 값 (idp_id, guard, redirect, identity, user_id 등)
     * @param  string  $bind  시작 브라우저 묶음값
     * @param  int|null  $ttlMinutes  수명(분, null 이면 정책 「동의·연결 확인 대기 시간」)
     * @return string 토큰
     */
    public function create(string $type, array $data, string $bind, ?int $ttlMinutes = null): string
    {
        $token = Str::random(48);
        $data['type'] = $type;
        $data['bind_hash'] = StateStore::hashBind($bind);
        $data['created_at'] = time();
        $data['ttl'] = max(1, $ttlMinutes ?? $this->policyTtlMinutes()) * 60;

        $this->write($token, $data);

        return $token;
    }

    /**
     * 대기 값을 읽습니다 (지우지 않는다).
     *
     * @param  string  $token  토큰
     * @param  string  $type  기대 종류
     * @param  Request  $request  요청 (묶음 쿠키 대조)
     * @return array<string, mixed> 대기 값
     *
     * @throws SsoException 없거나 만료면 `pending_expired`/`link_expired`, 다른 브라우저면 `bind_mismatch`(토큰 폐기)
     */
    public function get(string $token, string $type, Request $request): array
    {
        $expired = $type === self::TYPE_LINK ? 'link_expired' : 'pending_expired';
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new SsoException($expired, 'token format', 404);
        }

        $payload = $this->cache->get($this->key($token));
        $data = null;
        if (is_string($payload)) {
            try {
                $data = json_decode(Crypt::decryptString($payload), true);
            } catch (\Throwable) {
                $data = null;
            }
        }

        if (! is_array($data) || ($data['type'] ?? null) !== $type) {
            throw new SsoException($expired, 'not found', 404);
        }

        if (! $this->states->matchesBind($data['bind_hash'] ?? null, $request)) {
            $this->forget($token);

            throw new SsoException('bind_mismatch', 'pending bind mismatch', 403);
        }

        return $data;
    }

    /**
     * 대기 값을 조용히 읽습니다 — 없거나 만료됐거나 다른 브라우저면 null 이고, 토큰을 폐기하지 않는다.
     *
     * 대기 흐름 밖의 곁가지 조회(동의 창이 그릴 회원 항목의 초기값)가 쓴다. 곁가지 요청이 묶음 쿠키 없이 왔다고 대기를 폐기하면
     * 본 흐름(동의 완료)이 끊기므로 여기서는 지우지 않는다 — 묶음이 맞지 않으면 값을 주지 않는 것으로 충분하다.
     *
     * @param  string  $token  토큰
     * @param  string  $type  기대 종류
     * @param  Request  $request  요청 (묶음 쿠키 대조)
     * @return array<string, mixed>|null 대기 값
     */
    public function peek(string $token, string $type, Request $request): ?array
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $payload = $this->cache->get($this->key($token));
        if (! is_string($payload)) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($payload), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($data) || ($data['type'] ?? null) !== $type || ! $this->states->matchesBind($data['bind_hash'] ?? null, $request)) {
            return null;
        }

        return $data;
    }

    /**
     * 대기 값을 고쳐 다시 저장합니다 (남은 수명 유지).
     *
     * @param  string  $token  토큰
     * @param  array<string, mixed>  $data  대기 값
     * @return void 반환값 없음
     */
    public function put(string $token, array $data): void
    {
        $this->write($token, $data);
    }

    /**
     * 대기 토큰을 지웁니다.
     *
     * @param  string  $token  토큰
     * @return void 반환값 없음
     */
    public function forget(string $token): void
    {
        $this->cache->forget($this->key($token));
    }

    /**
     * 암호화해 저장합니다.
     *
     * @param  string  $token  토큰
     * @param  array<string, mixed>  $data  대기 값
     * @return void 반환값 없음
     */
    private function write(string $token, array $data): void
    {
        $remaining = (int) ($data['ttl'] ?? self::DEFAULT_TTL_MINUTES * 60) - (time() - (int) ($data['created_at'] ?? time()));
        if ($remaining <= 0) {
            $this->forget($token);

            return;
        }

        $this->cache->put($this->key($token), Crypt::encryptString((string) json_encode($data)), $remaining);
    }

    /**
     * 정책 「동의·연결 확인 대기 시간」(분)을 읽습니다.
     *
     * 관리자 정책 화면에서 저장한 값이 대기 수명이 된다(종전에는 늘 기본 10분). 정책을 읽지 못하면(설치 전·표 없음) 기본값이다.
     *
     * @return int 수명(분)
     */
    private function policyTtlMinutes(): int
    {
        try {
            // 주입이 없으면(직접 만든 저장소·자동 주입이 비는 문맥) 쓸 때 꺼낸다 — 정책 읽기는 PolicyService 한 곳
            return (int) (($this->policy ?? app(PolicyService::class))->get('pending_ttl_minutes', self::DEFAULT_TTL_MINUTES) ?? self::DEFAULT_TTL_MINUTES);
        } catch (\Throwable) {
            return self::DEFAULT_TTL_MINUTES;
        }
    }

    /**
     * 캐시 키.
     *
     * @param  string  $token  토큰
     * @return string 캐시 키
     */
    private function key(string $token): string
    {
        return 'pending:'.hash('sha256', $token);
    }
}
