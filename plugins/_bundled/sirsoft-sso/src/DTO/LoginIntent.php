<?php

namespace Plugins\Sirsoft\Sso\DTO;

/**
 * 로그인 왕복의 시작 정보 (state 로 저장되고 콜백에서 한 번 꺼낸다).
 *
 * `bindHash` 는 시작한 브라우저의 묶음 쿠키(`g7_sso_bind`) sha256 이다 — 콜백에서 같은 쿠키를 가진 브라우저인지 대조한다.
 * 드라이버가 따로 보관할 값(SAML 요청 ID 등)은 `extra` 에 담아 `StateStore::update()` 로 다시 저장한다.
 *
 * @since 1.0.0
 */
final class LoginIntent
{
    /** 의도 — 로그인 */
    public const LOGIN = 'login';

    /** 의도 — 로그인한 회원의 계정 연결 */
    public const LINK = 'link';

    /** 의도 — 민감 작업 재확인 */
    public const REAUTH = 'reauth';

    /** 의도 — 관리자 「브라우저로 로그인 테스트」 */
    public const TEST = 'test';

    /** @var array<int, string> 의도 목록 */
    public const INTENTS = [self::LOGIN, self::LINK, self::REAUTH, self::TEST];

    /** @var array<int, string> 로그인 경로 목록 */
    public const GUARDS = ['user', 'admin'];

    /**
     * @param  string  $intent  의도 (login, link, reauth, test)
     * @param  string  $guard  로그인 경로 (user, admin)
     * @param  string  $redirect  로그인 뒤 복귀 경로 (같은 출처 경로)
     * @param  int  $idpId  IdP ID
     * @param  string  $idpKey  IdP 키
     * @param  string  $stateId  state 값
     * @param  string  $nonce  OIDC nonce
     * @param  string  $codeVerifier  PKCE code_verifier
     * @param  string  $bindHash  시작 브라우저 묶음 쿠키 sha256
     * @param  int|null  $linkUserId  link, reauth, test 의 대상 회원 ID (시작 티켓에서 확정)
     * @param  string|null  $challengeId  reauth 의 코어 본인인증 요청 ID
     * @param  int  $createdAt  만든 시각 (유닉스 초)
     * @param  array<string, mixed>  $extra  드라이버 보관 값
     */
    public function __construct(
        public readonly string $intent,
        public readonly string $guard,
        public readonly string $redirect,
        public readonly int $idpId,
        public readonly string $idpKey,
        public readonly string $stateId,
        public readonly string $nonce,
        public readonly string $codeVerifier,
        public readonly string $bindHash,
        public readonly ?int $linkUserId = null,
        public readonly ?string $challengeId = null,
        public readonly int $createdAt = 0,
        public readonly array $extra = [],
    ) {}

    /**
     * 드라이버 보관 값을 더한 복제본을 만듭니다.
     *
     * @param  array<string, mixed>  $extra  더할 값
     * @return self 새 시작 정보
     */
    public function withExtra(array $extra): self
    {
        return new self(
            $this->intent, $this->guard, $this->redirect, $this->idpId, $this->idpKey, $this->stateId, $this->nonce,
            $this->codeVerifier, $this->bindHash, $this->linkUserId, $this->challengeId, $this->createdAt,
            array_merge($this->extra, $extra),
        );
    }

    /**
     * 배열로 바꿉니다 (state 저장).
     *
     * @return array<string, mixed> 저장 모양
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'guard' => $this->guard,
            'redirect' => $this->redirect,
            'idp_id' => $this->idpId,
            'idp_key' => $this->idpKey,
            'state' => $this->stateId,
            'nonce' => $this->nonce,
            'code_verifier' => $this->codeVerifier,
            'bind_hash' => $this->bindHash,
            'link_user_id' => $this->linkUserId,
            'challenge_id' => $this->challengeId,
            'created_at' => $this->createdAt,
            'extra' => $this->extra,
        ];
    }

    /**
     * 저장 모양에서 되살립니다.
     *
     * @param  array<string, mixed>  $data  저장 모양
     * @return self 시작 정보
     */
    public static function fromArray(array $data): self
    {
        return new self(
            intent: (string) ($data['intent'] ?? self::LOGIN),
            guard: (string) ($data['guard'] ?? 'user'),
            redirect: (string) ($data['redirect'] ?? '/'),
            idpId: (int) ($data['idp_id'] ?? 0),
            idpKey: (string) ($data['idp_key'] ?? ''),
            stateId: (string) ($data['state'] ?? ''),
            nonce: (string) ($data['nonce'] ?? ''),
            codeVerifier: (string) ($data['code_verifier'] ?? ''),
            bindHash: (string) ($data['bind_hash'] ?? ''),
            linkUserId: isset($data['link_user_id']) ? (int) $data['link_user_id'] : null,
            challengeId: isset($data['challenge_id']) ? (string) $data['challenge_id'] : null,
            createdAt: (int) ($data['created_at'] ?? 0),
            extra: is_array($data['extra'] ?? null) ? $data['extra'] : [],
        );
    }
}
