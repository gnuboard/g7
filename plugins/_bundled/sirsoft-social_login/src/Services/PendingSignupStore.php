<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * 가입을 마치지 않은 소셜 계정(가입 대기)을 잠시 보관합니다.
 *
 * 콜백에서 연결된 회원이 없으면 프로필·토큰·복귀 주소를 여기에 두고, 가입 화면은 일회용이 아닌 대기 토큰으로 이어 간다.
 * 값은 암호화한 JSON 으로 캐시에 두고, 캐시 키는 토큰 원문이 아니라 해시다(캐시 저장소에 토큰이 남지 않게).
 * 유효 시간은 설정 `pending_signup_ttl_minutes`(기본 30분)이고, 값을 바꿀 때마다 처음 만든 시각 기준으로 남은 시간만 둔다.
 * 같은 제공자 계정(제공자 키 + 제공자 사용자 ID)으로 새 대기를 만들면 앞 대기는 지운다 — 방문 기록에 남은 앞 주소로 가입을 마치지 못하게.
 *
 * @since 1.0.0
 */
class PendingSignupStore
{
    /** 기본 유효 시간(분) */
    public const DEFAULT_TTL_MINUTES = 30;

    /**
     * @param  CacheInterface  $cache  플러그인 캐시
     */
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * 새 가입 대기를 만들고 토큰을 돌려줍니다.
     *
     * @param  array<string, mixed>  $data  보관할 값
     * @return string 대기 토큰
     */
    public function create(array $data): string
    {
        $token = Str::random(48);
        $ttl = max(1, (int) plugin_setting('sirsoft-social_login', 'pending_signup_ttl_minutes', self::DEFAULT_TTL_MINUTES)) * 60;
        $data['created_at'] = time();
        $data['expires_at'] = time() + $ttl;

        $identity = $this->identityKey($data);
        if ($identity !== null) {
            $previous = $this->cache->get($identity);
            if (is_string($previous) && $previous !== '') {
                $this->cache->forget($this->keyFromHash($previous));
            }
        }

        $this->write($token, $data, $ttl);
        if ($identity !== null) {
            $this->cache->put($identity, hash('sha256', $token), $ttl);
        }

        return $token;
    }

    /**
     * 가입 대기를 읽습니다.
     *
     * @param  string  $token  대기 토큰
     * @return array<string, mixed>|null 값, 없거나 만료면 null
     */
    public function get(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9]{48}$/', $token) !== 1) {
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

        if (! is_array($data) || (int) ($data['expires_at'] ?? 0) < time()) {
            return null;
        }

        return $data;
    }

    /**
     * 가입 대기 값을 바꿉니다 (남은 유효 시간은 그대로).
     *
     * @param  string  $token  대기 토큰
     * @param  array<string, mixed>  $data  새 값 (get 으로 읽은 값을 고친 것)
     * @return void 반환값 없음
     */
    public function put(string $token, array $data): void
    {
        $remaining = (int) ($data['expires_at'] ?? 0) - time();
        if ($remaining <= 0) {
            $this->forget($token);

            return;
        }

        $this->write($token, $data, $remaining);
    }

    /**
     * 가입 대기를 지웁니다.
     *
     * @param  string  $token  대기 토큰
     * @return void 반환값 없음
     */
    public function forget(string $token): void
    {
        $this->cache->forget($this->key($token));
    }

    /**
     * 암호화해 저장합니다.
     *
     * @param  string  $token  대기 토큰
     * @param  array<string, mixed>  $data  값
     * @param  int  $ttl  유효 시간(초)
     * @return void 반환값 없음
     */
    private function write(string $token, array $data, int $ttl): void
    {
        $this->cache->put($this->key($token), Crypt::encryptString((string) json_encode($data)), $ttl);
    }

    /**
     * 같은 제공자 계정의 최근 대기를 가리키는 캐시 키를 만듭니다 (제공자 사용자 ID 원문 대신 해시).
     *
     * @param  array<string, mixed>  $data  보관할 값
     * @return string|null 캐시 키, 제공자나 사용자 ID 가 없으면 null
     */
    private function identityKey(array $data): ?string
    {
        $provider = (string) ($data['provider_key'] ?? '');
        $subject = (string) ($data['profile']['provider_user_id'] ?? '');

        return $provider === '' || $subject === '' ? null : 'pending_identity:'.hash('sha256', $provider.':'.$subject);
    }

    /**
     * 캐시 키를 만듭니다 (원문 대신 해시).
     *
     * @param  string  $token  대기 토큰
     * @return string 캐시 키
     */
    private function key(string $token): string
    {
        return $this->keyFromHash(hash('sha256', $token));
    }

    /**
     * 토큰 해시로 캐시 키를 만듭니다 — 계정별 색인은 토큰 원문 대신 해시를 들고 있다.
     *
     * @param  string  $hash  대기 토큰의 sha256 해시
     * @return string 캐시 키
     */
    private function keyFromHash(string $hash): string
    {
        return 'pending:'.$hash;
    }
}
