<?php

namespace Plugins\Sirsoft\SocialLogin\DTO;

use Carbon\CarbonImmutable;

/**
 * 제공자 토큰 응답의 정규화 결과.
 *
 * @since 1.0.0
 */
final class TokenSet
{
    /**
     * @param  string  $accessToken  접근 토큰
     * @param  string|null  $refreshToken  갱신 토큰 (응답에 있을 때만)
     * @param  CarbonImmutable|null  $expiresAt  만료 시각
     * @param  string|null  $idToken  OIDC id_token
     * @param  array<string, mixed>  $raw  원본 응답 (저장하지 않는다)
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken = null,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?string $idToken = null,
        public readonly array $raw = [],
    ) {}

    /**
     * 가입 대기 보관용 배열로 바꿉니다 (원본 응답은 담지 않는다).
     *
     * @return array<string, mixed> 직렬화 값
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt?->getTimestamp(),
        ];
    }

    /**
     * 보관한 배열로 다시 만듭니다.
     *
     * @param  array<string, mixed>  $data  직렬화 값
     * @return self 토큰
     */
    public static function fromArray(array $data): self
    {
        $expires = $data['expires_at'] ?? null;

        return new self(
            accessToken: (string) ($data['access_token'] ?? ''),
            refreshToken: is_string($data['refresh_token'] ?? null) && $data['refresh_token'] !== '' ? $data['refresh_token'] : null,
            expiresAt: is_numeric($expires) ? CarbonImmutable::createFromTimestamp((int) $expires) : null,
        );
    }

    /**
     * 토큰 응답 본문을 정규화합니다.
     *
     * `expires_in` 은 숫자 또는 숫자 문자열(네이버)로 온다. 갱신 토큰·id_token 은 있을 때만 채운다.
     *
     * @param  array<string, mixed>  $body  토큰 응답 본문
     * @return self 정규화 결과
     */
    public static function fromResponse(array $body): self
    {
        $expiresIn = $body['expires_in'] ?? null;
        $expiresAt = is_numeric($expiresIn) && (int) $expiresIn > 0
            ? CarbonImmutable::now()->addSeconds((int) $expiresIn)
            : null;

        $refresh = $body['refresh_token'] ?? null;
        $idToken = $body['id_token'] ?? null;

        return new self(
            accessToken: (string) ($body['access_token'] ?? ''),
            refreshToken: is_string($refresh) && $refresh !== '' ? $refresh : null,
            expiresAt: $expiresAt,
            idToken: is_string($idToken) && $idToken !== '' ? $idToken : null,
            raw: $body,
        );
    }
}
