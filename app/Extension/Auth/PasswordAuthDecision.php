<?php

namespace App\Extension\Auth;

use App\Support\SameOriginPath;

/**
 * 비밀번호 인증 정책 판정 결과 DTO.
 *
 * 필터 `core.auth.password_auth_decision` 의 반환값이다. 확장(예: 회사 계정 로그인 강제)이 「이 회원·이 식별자는 비밀번호로
 * 인증하지 못한다」고 답할 때 쓴다. 코어는 결과를 받아 비밀번호 로그인·재설정·설정·관리자 지정·로그인 중 변경을 막는다.
 *
 * - `basis = identifier` — 식별자(이메일 도메인 등)만으로 내린 판정. 로그인 시도 **전**에 묻는다.
 * - `basis = user` — 회원(역할·연결 등)을 보고 내린 판정. 비밀번호가 맞은 **뒤**에 묻는다(계정 열거 방지).
 *
 * @since 7.2.0
 */
final class PasswordAuthDecision
{
    /** 식별자 기준 판정 */
    public const BASIS_IDENTIFIER = 'identifier';

    /** 회원 기준 판정 */
    public const BASIS_USER = 'user';

    /**
     * @param  bool  $allowed  비밀번호 인증 허용 여부
     * @param  string|null  $reasonKey  거절 사유 문구 키 (null 이면 코어 `auth.password_auth_disabled`)
     * @param  array<string, string>|null  $methodLabel  안내할 로그인 수단 이름 (로케일 → 이름)
     * @param  string|null  $redirectUrl  그 수단으로 로그인을 시작하는 같은 출처 경로 (아니면 null 로 정규화)
     * @param  string  $basis  판정 기준 (`identifier` | `user`)
     * @param  bool  $requireTwoFactor  허용하되 사이트 2단계 인증 설정과 무관하게 2단계 인증을 요구 (비상 계정 등)
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $reasonKey = null,
        public readonly ?array $methodLabel = null,
        public readonly ?string $redirectUrl = null,
        public readonly string $basis = self::BASIS_IDENTIFIER,
        public readonly bool $requireTwoFactor = false,
    ) {}

    /**
     * 허용 결과를 만듭니다.
     *
     * @param  bool  $requireTwoFactor  2단계 인증을 반드시 거치게 할지
     * @return self 허용 결과
     */
    public static function allow(bool $requireTwoFactor = false): self
    {
        return new self(allowed: true, requireTwoFactor: $requireTwoFactor);
    }

    /**
     * 거절 결과를 만듭니다.
     *
     * @param  string  $basis  판정 기준 (`identifier` | `user`)
     * @param  array<string, string>|null  $methodLabel  안내할 로그인 수단 이름 (로케일 → 이름)
     * @param  string|null  $redirectUrl  그 수단의 시작 경로 (같은 출처 경로만 남는다)
     * @param  string|null  $reasonKey  거절 사유 문구 키
     * @return self 거절 결과
     */
    public static function deny(
        string $basis = self::BASIS_IDENTIFIER,
        ?array $methodLabel = null,
        ?string $redirectUrl = null,
        ?string $reasonKey = null,
    ): self {
        return new self(
            allowed: false,
            reasonKey: $reasonKey,
            methodLabel: self::normalizeLabel($methodLabel),
            redirectUrl: SameOriginPath::normalize($redirectUrl),
            basis: $basis === self::BASIS_USER ? self::BASIS_USER : self::BASIS_IDENTIFIER,
        );
    }

    /**
     * 응답 `data` 에 실을 값을 돌려줍니다 (회원 정보 없음 — 수단 이름과 이동 경로만).
     *
     * @return array{method_label: array<string, string>|null, redirect_url: string|null} 응답 데이터
     */
    public function toResponseData(): array
    {
        return [
            'method_label' => $this->methodLabel,
            'redirect_url' => SameOriginPath::normalize($this->redirectUrl),
        ];
    }

    /**
     * 수단 이름을 로케일 → 문자열 맵으로 정리합니다.
     *
     * @param  array<mixed>|null  $label  확장이 준 이름
     * @return array<string, string>|null 정리한 이름 (비면 null)
     */
    private static function normalizeLabel(?array $label): ?array
    {
        if ($label === null) {
            return null;
        }

        $clean = [];

        foreach ($label as $locale => $text) {
            if (is_string($locale) && is_string($text) && $text !== '') {
                $clean[$locale] = mb_substr($text, 0, 100);
            }
        }

        return $clean === [] ? null : $clean;
    }
}
