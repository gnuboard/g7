<?php

namespace App\Extension\Auth;

use App\Support\SameOriginPath;

/**
 * 로그인 진입 판정 결과 DTO.
 *
 * 필터 `core.auth.login_discovery` 의 반환값이다. 로그인 화면이 이메일 칸을 벗어날 때 「이 식별자는 어떤 수단으로 로그인하는가」를
 * 묻는다. **식별자(도메인)만으로** 답해야 한다 — 회원 존재 여부에 따라 모양이 달라지면 계정 열거가 된다.
 * 응답은 항상 `{method, label, redirect_url}` 이다.
 *
 * @since 7.2.0
 */
final class LoginDiscoveryResult
{
    /** 비밀번호 로그인 */
    public const METHOD_PASSWORD = 'password';

    /** 외부 수단 로그인 */
    public const METHOD_EXTERNAL = 'external';

    /**
     * @param  string  $method  로그인 수단 (`password` | `external`)
     * @param  array<string, string>|null  $label  수단 이름 (로케일 → 이름)
     * @param  string|null  $redirectUrl  외부 수단을 시작하는 같은 출처 경로
     */
    public function __construct(
        public readonly string $method = self::METHOD_PASSWORD,
        public readonly ?array $label = null,
        public readonly ?string $redirectUrl = null,
    ) {}

    /**
     * 비밀번호 로그인 결과를 만듭니다.
     *
     * @return self 비밀번호 결과
     */
    public static function password(): self
    {
        return new self;
    }

    /**
     * 외부 수단 결과를 만듭니다.
     *
     * @param  array<string, string>  $label  수단 이름 (로케일 → 이름)
     * @param  string  $redirectUrl  시작 경로 (같은 출처 경로가 아니면 null 로 남는다)
     * @return self 외부 수단 결과
     */
    public static function external(array $label, string $redirectUrl): self
    {
        return new self(self::METHOD_EXTERNAL, $label, $redirectUrl);
    }

    /**
     * 응답 본문 모양으로 돌려줍니다 — 외부 수단인데 이동 경로가 같은 출처가 아니면 비밀번호로 낮춘다.
     *
     * @return array{method: string, label: array<string, string>|null, redirect_url: string|null} 응답 데이터
     */
    public function toArray(): array
    {
        $redirect = SameOriginPath::normalize($this->redirectUrl);

        if ($this->method !== self::METHOD_EXTERNAL || $redirect === null) {
            return ['method' => self::METHOD_PASSWORD, 'label' => null, 'redirect_url' => null];
        }

        $label = [];

        foreach ((array) $this->label as $locale => $text) {
            if (is_string($locale) && is_string($text) && $text !== '') {
                $label[$locale] = mb_substr($text, 0, 100);
            }
        }

        return ['method' => self::METHOD_EXTERNAL, 'label' => $label === [] ? null : $label, 'redirect_url' => $redirect];
    }
}
