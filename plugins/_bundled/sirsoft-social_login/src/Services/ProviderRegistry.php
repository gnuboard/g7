<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\SocialLogin\Contracts\ProviderDriverInterface;
use Plugins\Sirsoft\SocialLogin\Drivers\FacebookDriver;
use Plugins\Sirsoft\SocialLogin\Drivers\GoogleDriver;
use Plugins\Sirsoft\SocialLogin\Drivers\KakaoDriver;
use Plugins\Sirsoft\SocialLogin\Drivers\NaverDriver;
use Plugins\Sirsoft\SocialLogin\Drivers\OidcDriver;
use Plugins\Sirsoft\SocialLogin\Enums\ProviderType;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\ProviderRepositoryInterface;

/**
 * 제공자 레지스트리.
 *
 * 테이블 행을 `driver` 로 드라이버 인스턴스로 만들고, 필터 훅 `sirsoft-social_login.provider.filter_registered` 로
 * 다른 확장이 제공자를 더하게 한다. 확장이 내장 키(kakao·naver·google·facebook)를 덮어쓰려 하면 경고를 남기고 무시한다 —
 * 운영자가 설정한 내장 제공자가 몰래 다른 구현으로 바뀌면 안 된다. 결과는 요청당 한 번만 만든다.
 *
 * @since 1.0.0
 */
class ProviderRegistry
{
    /** 등록 필터 훅 이름 */
    public const FILTER_REGISTERED = 'sirsoft-social_login.provider.filter_registered';

    /** 내장 제공자 키 */
    public const BUILTIN_KEYS = ['kakao', 'naver', 'google', 'facebook'];

    /** 기본 커스텀 제공자 한도 */
    public const DEFAULT_CUSTOM_LIMIT = 10;

    /** @var array<string, class-string<ProviderDriverInterface>> 드라이버 이름 => 클래스 */
    public const DRIVERS = [
        'kakao' => KakaoDriver::class,
        'naver' => NaverDriver::class,
        'google' => GoogleDriver::class,
        'facebook' => FacebookDriver::class,
        'oidc' => OidcDriver::class,
    ];

    /** @var array<string, ProviderDriverInterface>|null 요청당 캐시 */
    private ?array $resolved = null;

    /**
     * @param  ProviderRepositoryInterface  $providers  제공자 저장소
     */
    public function __construct(private readonly ProviderRepositoryInterface $providers) {}

    /**
     * 등록된 모든 제공자 드라이버를 반환합니다 (활성 여부 무관).
     *
     * @return array<string, ProviderDriverInterface> 키 => 드라이버
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $limit = (int) plugin_setting('sirsoft-social_login', 'custom_provider_limit', self::DEFAULT_CUSTOM_LIMIT);
        $drivers = [];
        $custom = 0;

        foreach ($this->providers->all() as $row) {
            if ($row->type === ProviderType::Custom->value && ++$custom > $limit) {
                continue;
            }

            $driver = $this->make($row);
            if ($driver !== null) {
                $drivers[$row->key] = $driver;
            }
        }

        $filtered = HookManager::applyFilters(self::FILTER_REGISTERED, $drivers);

        return $this->resolved = $this->mergeExtensions($drivers, is_array($filtered) ? $filtered : $drivers);
    }

    /**
     * 키로 드라이버를 찾습니다.
     *
     * @param  string  $key  제공자 키
     * @return ProviderDriverInterface|null 드라이버, 없으면 null
     */
    public function get(string $key): ?ProviderDriverInterface
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * 요청당 캐시를 비웁니다 (설정 저장·모의 토글 직후).
     *
     * @return void
     */
    public function flush(): void
    {
        $this->resolved = null;
    }

    /**
     * 행을 드라이버로 만듭니다.
     *
     * @param  SocialLoginProvider  $row  제공자 행
     * @return ProviderDriverInterface|null 드라이버 (알 수 없는 driver 면 null)
     */
    public function make(SocialLoginProvider $row): ?ProviderDriverInterface
    {
        $class = self::DRIVERS[$row->driver] ?? null;

        return $class === null ? null : app($class)->withConfig($row);
    }

    /**
     * 확장이 돌려준 목록에서 새 키만 받아들입니다.
     *
     * 테이블에서 만든 키를 다른 인스턴스로 바꾸거나 지우는 것도 받지 않는다 — 덮어쓰기는 경고 후 무시.
     *
     * @param  array<string, ProviderDriverInterface>  $base  테이블 기반 목록
     * @param  array<mixed>  $filtered  필터 결과
     * @return array<string, ProviderDriverInterface> 합친 목록
     */
    private function mergeExtensions(array $base, array $filtered): array
    {
        $merged = $base;

        foreach ($filtered as $key => $driver) {
            if (! is_string($key) || ! $driver instanceof ProviderDriverInterface) {
                continue;
            }

            if (array_key_exists($key, $base)) {
                if ($driver !== $base[$key] && in_array($key, self::BUILTIN_KEYS, true)) {
                    Log::warning('[sirsoft-social_login] 확장이 내장 제공자를 덮어쓰려 해서 무시했습니다.', ['key' => $key, 'class' => $driver::class]);
                }

                continue;
            }

            if (preg_match('/^[a-z][a-z0-9_]{1,49}$/', $key) !== 1) {
                continue;
            }

            $merged[$key] = $driver;
        }

        return $merged;
    }
}
