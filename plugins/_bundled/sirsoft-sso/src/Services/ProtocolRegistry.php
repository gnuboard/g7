<?php

namespace Plugins\Sirsoft\Sso\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Sso\Contracts\ProtocolDriverInterface;
use Plugins\Sirsoft\Sso\Drivers\Oidc\OidcDriver;

/**
 * 프로토콜 드라이버 레지스트리.
 *
 * 내장 드라이버(oidc, saml, ldap) + 필터 `sirsoft-sso.protocol.filter_registered` 로 다른 확장이 더한 드라이버. 내장 키를 덮어쓰려는
 * 등록과 계약을 구현하지 않은 값은 경고 로그 뒤 무시한다. 요청 단위 싱글턴이라 목록은 한 요청에서 한 번만 만든다.
 *
 * @since 1.0.0
 */
class ProtocolRegistry
{
    /** 드라이버 목록 필터 훅 */
    public const FILTER_REGISTERED = 'sirsoft-sso.protocol.filter_registered';

    /**
     * 내장 드라이버 클래스 — 키 → 클래스. 클래스가 아직 없으면(설치 단계별 배포) 건너뛴다.
     *
     * @var array<string, class-string>
     */
    public const BUILTIN = [
        'oidc' => OidcDriver::class,
        'saml' => 'Plugins\\Sirsoft\\Sso\\Drivers\\Saml\\SamlDriver',
        'ldap' => 'Plugins\\Sirsoft\\Sso\\Drivers\\Ldap\\LdapDriver',
    ];

    /** @var array<string, ProtocolDriverInterface>|null 만든 목록 */
    private ?array $drivers = null;

    /**
     * 모든 드라이버를 반환합니다.
     *
     * @return array<string, ProtocolDriverInterface> 키 → 드라이버
     */
    public function all(): array
    {
        if ($this->drivers !== null) {
            return $this->drivers;
        }

        $builtin = [];
        foreach (self::BUILTIN as $key => $class) {
            if (class_exists($class)) {
                $builtin[$key] = app($class);
            }
        }

        $filtered = HookManager::applyFilters(self::FILTER_REGISTERED, $builtin);
        $drivers = $builtin;

        foreach (is_array($filtered) ? $filtered : [] as $key => $driver) {
            if (! $driver instanceof ProtocolDriverInterface) {
                Log::warning('[sirsoft-sso] 계약을 구현하지 않은 프로토콜 드라이버를 무시합니다.', ['key' => (string) $key]);

                continue;
            }

            $driverKey = $driver->key();
            if (isset($builtin[$driverKey])) {
                if ($builtin[$driverKey] !== $driver) {
                    Log::warning('[sirsoft-sso] 내장 프로토콜 키는 덮어쓸 수 없습니다.', ['key' => $driverKey]);
                }

                continue;
            }

            if (preg_match('/^[a-z][a-z0-9_]{1,9}$/', $driverKey) !== 1) {
                Log::warning('[sirsoft-sso] 프로토콜 키 형식이 맞지 않아 무시합니다.', ['key' => $driverKey]);

                continue;
            }

            $drivers[$driverKey] = $driver;
        }

        return $this->drivers = $drivers;
    }

    /**
     * 키로 드라이버를 찾습니다.
     *
     * @param  string  $key  프로토콜 키
     * @return ProtocolDriverInterface|null 드라이버
     */
    public function get(string $key): ?ProtocolDriverInterface
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * 만든 목록을 비웁니다 (시험, 필터 등록 직후).
     *
     * @return void 반환값 없음
     */
    public function flush(): void
    {
        $this->drivers = null;
    }
}
