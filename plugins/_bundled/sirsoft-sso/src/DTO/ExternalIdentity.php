<?php

namespace Plugins\Sirsoft\Sso\DTO;

/**
 * IdP 가 확인해 준 신원 (서명, state, 재생 검증을 마친 값).
 *
 * 연결 키는 `subject` 다 — 이메일은 키가 아니다(소유 도메인 자동 연결만 예외). 토큰, 비밀값, 어설션 XML, id_token 원문은
 * 담지 않는다 — 이 객체는 훅 인자로 다른 확장에 넘어간다.
 *
 * @since 1.0.0
 */
final class ExternalIdentity
{
    /**
     * @param  string  $subject  IdP 안의 고유 식별자
     * @param  string|null  $subjectFormat  식별자 형식 (SAML NameID Format)
     * @param  string|null  $email  이메일
     * @param  bool|null  $emailVerified  이메일 검증 표시 (null 은 IdP 가 알려 주지 않음)
     * @param  string|null  $displayName  표시 이름
     * @param  array<string, string|array<int, string>>  $attributes  그 밖의 속성
     * @param  array<int, string>  $groups  그룹 이름
     * @param  bool|null  $mfa  IdP 가 다중 인증을 거쳤다고 알려 줌 (null 은 알 수 없음)
     * @param  string|null  $sessionRef  IdP 세션 참조 (OIDC sid, SAML SessionIndex)
     * @param  string|null  $nameId  SAML NameID 원문 (로그아웃 요청용)
     */
    public function __construct(
        public readonly string $subject,
        public readonly ?string $subjectFormat = null,
        public readonly ?string $email = null,
        public readonly ?bool $emailVerified = null,
        public readonly ?string $displayName = null,
        public readonly array $attributes = [],
        public readonly array $groups = [],
        public readonly ?bool $mfa = null,
        public readonly ?string $sessionRef = null,
        public readonly ?string $nameId = null,
    ) {}

    /**
     * 이메일 도메인을 소문자로 반환합니다.
     *
     * @return string|null 도메인, 이메일이 없으면 null
     */
    public function emailDomain(): ?string
    {
        if ($this->email === null || ! str_contains($this->email, '@')) {
            return null;
        }

        return mb_strtolower(substr($this->email, strrpos($this->email, '@') + 1));
    }

    /**
     * 배열로 바꿉니다 (대기 저장 — 암호화해서 보관한다).
     *
     * @return array<string, mixed> 저장 모양
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'subject_format' => $this->subjectFormat,
            'email' => $this->email,
            'email_verified' => $this->emailVerified,
            'display_name' => $this->displayName,
            'attributes' => $this->attributes,
            'groups' => $this->groups,
            'mfa' => $this->mfa,
            'session_ref' => $this->sessionRef,
            'name_id' => $this->nameId,
        ];
    }

    /**
     * 저장 모양에서 되살립니다.
     *
     * @param  array<string, mixed>  $data  저장 모양
     * @return self 신원
     */
    public static function fromArray(array $data): self
    {
        return new self(
            subject: (string) ($data['subject'] ?? ''),
            subjectFormat: isset($data['subject_format']) ? (string) $data['subject_format'] : null,
            email: isset($data['email']) ? (string) $data['email'] : null,
            emailVerified: isset($data['email_verified']) ? (bool) $data['email_verified'] : null,
            displayName: isset($data['display_name']) ? (string) $data['display_name'] : null,
            attributes: is_array($data['attributes'] ?? null) ? $data['attributes'] : [],
            groups: is_array($data['groups'] ?? null) ? array_values(array_map('strval', $data['groups'])) : [],
            mfa: isset($data['mfa']) ? (bool) $data['mfa'] : null,
            sessionRef: isset($data['session_ref']) ? (string) $data['session_ref'] : null,
            nameId: isset($data['name_id']) ? (string) $data['name_id'] : null,
        );
    }
}
