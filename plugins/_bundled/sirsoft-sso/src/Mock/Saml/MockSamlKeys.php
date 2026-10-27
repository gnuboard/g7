<?php

namespace Plugins\Sirsoft\Sso\Mock\Saml;

use App\Contracts\Extension\CacheInterface;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlDriver;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlMetadata;
use Plugins\Sirsoft\Sso\Drivers\Saml\X509KeyPair;
use Plugins\Sirsoft\Sso\Mock\MockGate;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;

/**
 * 모의 SAML IdP 의 서명 키와 인증서.
 *
 * 처음 쓰일 때 만들어 모의 활성 최대 시간 동안만 캐시에 둔다. 저장소에 고정 키를 두지 않는다 — 고정 키는 누구나 알 수 있어, 모의
 * 메타데이터를 실수로 실제 IdP 설정에 넣으면 위조 응답을 만들 수 있다.
 *
 * - `primary` — 응답 서명 키 (메타데이터에 늘 실린다)
 * - `other` — 메타데이터에 없는 키 (`wrong_key` 변종)
 * - `rotated` — 교체 예정 키 (메타데이터 `cert_rotated` 변종이 주 키와 함께 싣는다)
 * - `expiring` — 7일 뒤 만료되는 키 (메타데이터 `cert_expiring_7d` 변종이 주 키와 함께 싣는다 — 만료 알림 실측)
 *
 * @since 1.0.0
 */
class MockSamlKeys
{
    /** @var array<string, int> 키 이름 → 유효 일수 */
    private const KEYS = ['primary' => 365, 'other' => 365, 'rotated' => 730, 'expiring' => 7];

    /**
     * @param  CacheInterface  $cache  플러그인 캐시
     */
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * 키 쌍을 꺼내거나 새로 만듭니다.
     *
     * @param  string  $name  primary, other, rotated, expiring
     * @return array{certificate: string, private_key: string} 인증서와 개인키 PEM
     */
    public function pair(string $name = 'primary'): array
    {
        $name = array_key_exists($name, self::KEYS) ? $name : 'primary';
        $cacheKey = 'mock_saml_key_'.$name;

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && is_string($cached['certificate'] ?? null) && is_string($cached['private_key'] ?? null)) {
            return $cached;
        }

        $generated = X509KeyPair::generate('G7 Mock SAML IdP '.$name, self::KEYS[$name]);
        $pair = ['certificate' => $generated->certificate, 'private_key' => $generated->privateKey];
        $this->cache->put($cacheKey, $pair, MockGate::MAX_MINUTES * 60);
        $this->forgetMockMetadata();

        return $pair;
    }

    /**
     * 모의 메타데이터 주소를 쓰는 SAML IdP 의 SP 메타데이터 캐시를 비웁니다 — 새 키가 생기면 실린 인증서가 바뀐다.
     *
     * 키는 모의 활성 최대 시간만, SP 메타데이터는 하루 캐시된다. 비우지 않으면 오래 켜 둔 사이트에서 새 키로 서명한 응답을 낡은
     * 인증서로 검증해 모의 SAML 로그인이 `saml_invalid` 가 된다. 모의 주소가 아닌(운영) IdP 캐시는 건드리지 않는다.
     *
     * @return void 반환값 없음
     */
    private function forgetMockMetadata(): void
    {
        $prefix = MockSamlController::entityId().'/metadata';
        $metadata = app(SamlMetadata::class);
        foreach (app(IdentityProviderRepositoryInterface::class)->enabled() as $idp) {
            if ($idp->protocol === SamlDriver::KEY && str_starts_with((string) $idp->configValue('idp_metadata_url', ''), $prefix)) {
                $metadata->forget($idp);
            }
        }
    }

    /**
     * 만들어 둔 키 쌍을 넣습니다 — 시험이 프로세스 안에서 같은 키를 다시 쓰게 해 RSA 생성 부하를 줄인다.
     *
     * @param  string  $name  primary, other, rotated, expiring
     * @param  array{certificate: string, private_key: string}  $pair  인증서와 개인키 PEM
     * @return void 반환값 없음
     */
    public function put(string $name, array $pair): void
    {
        if (array_key_exists($name, self::KEYS)) {
            $this->cache->put('mock_saml_key_'.$name, $pair, MockGate::MAX_MINUTES * 60);
        }
    }

    /**
     * 키를 모두 지웁니다.
     *
     * @return void 반환값 없음
     */
    public function forget(): void
    {
        foreach (array_keys(self::KEYS) as $name) {
            $this->cache->forget('mock_saml_key_'.$name);
        }
    }
}
