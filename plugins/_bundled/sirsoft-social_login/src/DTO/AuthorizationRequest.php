<?php

namespace Plugins\Sirsoft\SocialLogin\DTO;

/**
 * OAuth 왕복 1회의 시작 정보.
 *
 * 시작 때 만들어 state 키로 캐시에 암호화해 두고, 콜백에서 꺼내자마자 지운다(1회성).
 *
 * @since 1.0.0
 */
final class AuthorizationRequest
{
    /**
     * @param  string  $providerKey  제공자 키
     * @param  string  $intent  의도 — login·link·test·reauth
     * @param  string  $redirect  완료 뒤 돌아갈 같은 출처 상대 경로
     * @param  string  $state  CSRF 방지 state
     * @param  string  $callbackUrl  제공자에 등록한 콜백 주소
     * @param  int|null  $userId  연결·테스트를 시작한 회원 ID
     * @param  string|null  $nonce  OIDC nonce
     * @param  string|null  $codeVerifier  PKCE code_verifier
     * @param  int  $createdAt  생성 시각(유닉스 초)
     * @param  string|null  $challengeId  본인 재확인(reauth) 의도의 코어 본인인증 요청 ID
     * @param  bool  $reconsent  제공자에 동의 화면을 다시 띄워 달라고 요청할지 (이메일 제공을 거절한 회원의 재동의)
     */
    public function __construct(
        public readonly string $providerKey,
        public readonly string $intent,
        public readonly string $redirect,
        public readonly string $state,
        public readonly string $callbackUrl,
        public readonly ?int $userId = null,
        public readonly ?string $nonce = null,
        public readonly ?string $codeVerifier = null,
        public readonly int $createdAt = 0,
        public readonly ?string $challengeId = null,
        public readonly bool $reconsent = false,
    ) {}

    /**
     * 캐시 저장용 배열로 바꿉니다.
     *
     * @return array<string, mixed> 직렬화 값
     */
    public function toArray(): array
    {
        return [
            'provider_key' => $this->providerKey,
            'intent' => $this->intent,
            'redirect' => $this->redirect,
            'state' => $this->state,
            'callback_url' => $this->callbackUrl,
            'user_id' => $this->userId,
            'nonce' => $this->nonce,
            'code_verifier' => $this->codeVerifier,
            'created_at' => $this->createdAt,
            'challenge_id' => $this->challengeId,
            'reconsent' => $this->reconsent,
        ];
    }

    /**
     * 캐시에서 꺼낸 배열로 다시 만듭니다.
     *
     * @param  array<string, mixed>  $data  직렬화 값
     * @return self 복원한 요청
     */
    public static function fromArray(array $data): self
    {
        return new self(
            providerKey: (string) ($data['provider_key'] ?? ''),
            intent: (string) ($data['intent'] ?? 'login'),
            redirect: (string) ($data['redirect'] ?? '/'),
            state: (string) ($data['state'] ?? ''),
            callbackUrl: (string) ($data['callback_url'] ?? ''),
            userId: isset($data['user_id']) ? (int) $data['user_id'] : null,
            nonce: $data['nonce'] ?? null,
            codeVerifier: $data['code_verifier'] ?? null,
            createdAt: (int) ($data['created_at'] ?? 0),
            challengeId: isset($data['challenge_id']) && is_string($data['challenge_id']) ? $data['challenge_id'] : null,
            reconsent: ($data['reconsent'] ?? false) === true,
        );
    }
}
