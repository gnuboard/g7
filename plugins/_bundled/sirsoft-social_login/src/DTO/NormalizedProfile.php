<?php

namespace Plugins\Sirsoft\SocialLogin\DTO;

use Plugins\Sirsoft\SocialLogin\Enums\EmailTrust;

/**
 * 제공자별로 다른 프로필 응답을 한 모양으로 맞춘 결과.
 *
 * `emailVerified` 는 세 값이다 — true(제공자가 검증했다고 알림), false(검증 안 됨), null(제공자가 알려 주지 않음).
 *
 * 마스킹된 이메일(`ka***@kakao.com`)이나 동의하지 않은 이메일은 `email` 을 null 로 둔다.
 *
 * @since 1.0.0
 */
final class NormalizedProfile
{
    /**
     * @param  string  $providerUserId  제공자 사용자 ID (필수, 대소문자 보존)
     * @param  string|null  $email  이메일
     * @param  bool|null  $emailVerified  이메일 검증 표시
     * @param  string|null  $name  이름
     * @param  string|null  $nickname  별명
     * @param  string|null  $avatarUrl  프로필 사진 주소
     * @param  array<string, mixed>  $raw  원본 프로필 (토큰·비밀 제외)
     */
    public function __construct(
        public readonly string $providerUserId,
        public readonly ?string $email = null,
        public readonly ?bool $emailVerified = null,
        public readonly ?string $name = null,
        public readonly ?string $nickname = null,
        public readonly ?string $avatarUrl = null,
        public readonly array $raw = [],
    ) {}

    /**
     * 제공자 설정의 이메일 신뢰 정책에 따라 이 이메일을 검증된 것으로 볼지 판정합니다.
     *
     * - flag: 제공자가 검증했다고 알린 경우(true)만 믿는다 — false·알 수 없음(null)은 믿지 않는다.
     * - trust: 이메일이 있으면 믿는다(제공자가 소유 확인을 한다고 운영자가 판단한 경우).
     * - verify: 늘 믿지 않는다 — 사이트가 메일 인증번호로 다시 확인한다.
     * 이메일이 없으면 어느 정책이든 false.
     *
     * @param  EmailTrust  $trust  제공자 행의 이메일 신뢰 정책
     * @return bool 검증된 이메일로 볼지
     */
    public function isEmailTrusted(EmailTrust $trust): bool
    {
        if ($this->email === null) {
            return false;
        }

        return match ($trust) {
            EmailTrust::Flag => $this->emailVerified === true,
            EmailTrust::Trust => true,
            EmailTrust::Verify => false,
        };
    }

    /**
     * 가입 대기 보관용 배열로 바꿉니다.
     *
     * @return array<string, mixed> 직렬화 값
     */
    public function toArray(): array
    {
        return [
            'provider_user_id' => $this->providerUserId,
            'email' => $this->email,
            'email_verified' => $this->emailVerified,
            'name' => $this->name,
            'nickname' => $this->nickname,
            'avatar_url' => $this->avatarUrl,
            'raw' => $this->raw,
        ];
    }

    /**
     * 보관한 배열로 다시 만듭니다.
     *
     * @param  array<string, mixed>  $data  직렬화 값
     * @return self 프로필
     */
    public static function fromArray(array $data): self
    {
        return new self(
            providerUserId: (string) ($data['provider_user_id'] ?? ''),
            email: self::str($data['email'] ?? null),
            emailVerified: is_bool($data['email_verified'] ?? null) ? $data['email_verified'] : null,
            name: self::str($data['name'] ?? null),
            nickname: self::str($data['nickname'] ?? null),
            avatarUrl: self::str($data['avatar_url'] ?? null),
            raw: is_array($data['raw'] ?? null) ? $data['raw'] : [],
        );
    }

    /**
     * 빈 문자열을 null 로 바꿉니다.
     *
     * @param  mixed  $value  원본 값
     * @return string|null 정리한 문자열
     */
    public static function str(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * 마스킹되었거나 형식이 틀린 이메일을 null 로 거릅니다.
     *
     * @param  mixed  $value  원본 이메일
     * @return string|null 쓸 수 있는 이메일
     */
    public static function email(mixed $value): ?string
    {
        $email = self::str($value);

        if ($email === null || str_contains($email, '*') || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    /**
     * 불리언 또는 문자열 불리언(`"true"`/`"false"`)을 읽습니다.
     *
     * @param  mixed  $value  원본 값
     * @return bool|null 판정 (알 수 없으면 null)
     */
    public static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $lower = strtolower(trim($value));
            if ($lower === 'true') {
                return true;
            }
            if ($lower === 'false') {
                return false;
            }
        }

        return null;
    }
}
