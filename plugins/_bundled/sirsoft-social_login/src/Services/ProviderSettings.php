<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use Plugins\Sirsoft\SocialLogin\Contracts\ProviderDriverInterface;
use Plugins\Sirsoft\SocialLogin\Drivers\AbstractOAuth2Driver;
use Plugins\Sirsoft\SocialLogin\Enums\EmailMissingPolicy;
use Plugins\Sirsoft\SocialLogin\Enums\EmailTrust;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\ProviderRepositoryInterface;

/**
 * 제공자별 정책 값(가입 허용·이메일 미제공·이메일 신뢰·표시 이름·버튼 모양)을 한 곳에서 읽습니다.
 *
 * 테이블 행이 있으면 행을 따르고, 행이 없는 제공자(모의 제공자처럼 다른 확장이 등록한 것)는 드라이버가 들고 온 설정을,
 * 그마저 없으면 안전한 기본값(가입 허용, 이메일 직접 입력, 검증 표시만 믿음)을 쓴다. 비밀값은 다루지 않는다.
 *
 * @since 1.0.0
 */
class ProviderSettings
{
    /** 내장 버튼 모양이 있는 드라이버 — 그 밖은 `custom` */
    private const BRANDS = ['kakao', 'naver', 'google', 'facebook'];

    /**
     * @param  ProviderRegistry  $registry  제공자 레지스트리
     * @param  ProviderRepositoryInterface  $providers  제공자 저장소
     */
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ProviderRepositoryInterface $providers,
    ) {}

    /**
     * 제공자가 켜져 있는지 확인합니다.
     *
     * 테이블 행이 있으면 그 `enabled`, 없으면 레지스트리에 있는 것만으로 켜진 것으로 본다(등록한 확장이 켜고 끈다).
     *
     * @param  string  $key  제공자 키
     * @return bool 켜져 있으면 true
     */
    public function isEnabled(string $key): bool
    {
        if ($this->registry->get($key) === null) {
            return false;
        }

        $row = $this->providers->findByKey($key);

        return $row === null ? true : $row->enabled;
    }

    /**
     * 신규 가입 허용 여부를 반환합니다.
     *
     * @param  string  $key  제공자 키
     * @return bool 허용이면 true
     */
    public function signupAllowed(string $key): bool
    {
        return (bool) $this->value($key, 'signup_allowed', true);
    }

    /**
     * 이메일 미제공 정책을 반환합니다.
     *
     * @param  string  $key  제공자 키
     * @return EmailMissingPolicy 정책 (모르는 값이면 직접 입력)
     */
    public function emailMissingPolicy(string $key): EmailMissingPolicy
    {
        return EmailMissingPolicy::tryFrom((string) $this->value($key, 'email_missing_policy', EmailMissingPolicy::Ask->value))
            ?? EmailMissingPolicy::Ask;
    }

    /**
     * 이메일 신뢰 정책을 반환합니다.
     *
     * @param  string  $key  제공자 키
     * @return EmailTrust 정책 (모르는 값이면 검증 표시만 믿음)
     */
    public function emailTrust(string $key): EmailTrust
    {
        return EmailTrust::tryFrom((string) $this->value($key, 'email_trust', EmailTrust::Flag->value)) ?? EmailTrust::Flag;
    }

    /**
     * 화면에 보일 제공자 이름을 반환합니다.
     *
     * @param  string  $key  제공자 키
     * @param  string|null  $locale  로케일
     * @return string 표시 이름
     */
    public function label(string $key, ?string $locale = null): string
    {
        return $this->registry->get($key)?->label($locale) ?? $key;
    }

    /**
     * 버튼 모양 계열을 반환합니다 (kakao·naver·google·facebook·custom). 모의 제공자는 원 제공자 계열을 따른다.
     *
     * @param  string  $key  제공자 키
     * @return string 계열
     */
    public function brand(string $key): string
    {
        $driver = $this->registry->get($key);
        $name = $driver instanceof AbstractOAuth2Driver ? $driver->driverName() : null;

        return in_array($name, self::BRANDS, true) ? $name : 'custom';
    }

    /**
     * 커스텀 버튼 색을 반환합니다 (`{bg, fg, border}` — 없으면 null).
     *
     * @param  string  $key  제공자 키
     * @return array<string, string>|null 색
     */
    public function buttonStyle(string $key): ?array
    {
        $style = $this->value($key, 'button_style');
        if (! is_array($style)) {
            return null;
        }

        $clean = [];
        foreach (['bg', 'fg', 'border'] as $part) {
            if (isset($style[$part]) && is_string($style[$part]) && preg_match('/^#[0-9A-Fa-f]{3,8}$/', $style[$part]) === 1) {
                $clean[$part] = $style[$part];
            }
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * 켜진 제공자를 표시 순서대로 반환합니다.
     *
     * 테이블 행은 `sort_order` 순, 테이블에 없는 제공자(확장 등록)는 그 뒤에 등록 순서대로 둔다.
     *
     * @return array<int, string> 제공자 키 목록
     */
    public function enabledKeys(): array
    {
        $rows = $this->providers->all()->keyBy('key');
        $keys = [];

        foreach ($rows as $key => $row) {
            if ($row->enabled && $this->registry->get((string) $key) !== null) {
                $keys[] = (string) $key;
            }
        }

        foreach (array_keys($this->registry->all()) as $key) {
            if (! $rows->has($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * 설정 값 하나를 읽습니다 — 행 → 드라이버 설정 → 기본값 순.
     *
     * @param  string  $key  제공자 키
     * @param  string  $name  설정 이름
     * @param  mixed  $default  기본값
     * @return mixed 값
     */
    private function value(string $key, string $name, mixed $default = null): mixed
    {
        $row = $this->providers->findByKey($key);
        if ($row instanceof SocialLoginProvider) {
            return $row->getAttribute($name) ?? $default;
        }

        $driver = $this->registry->get($key);

        return $driver instanceof AbstractOAuth2Driver ? $driver->setting($name, $default) : $default;
    }

    /**
     * 드라이버를 반환합니다 (호출자 편의).
     *
     * @param  string  $key  제공자 키
     * @return ProviderDriverInterface|null 드라이버
     */
    public function driver(string $key): ?ProviderDriverInterface
    {
        return $this->registry->get($key);
    }
}
