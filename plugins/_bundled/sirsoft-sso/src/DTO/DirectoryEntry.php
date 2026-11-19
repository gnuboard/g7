<?php

namespace Plugins\Sirsoft\Sso\DTO;

/**
 * 디렉터리(LDAP/Active Directory)에서 찾은 계정 1건.
 *
 * @since 1.0.0
 */
final class DirectoryEntry
{
    /**
     * @param  string  $subject  연결 키로 쓰는 고유 값 (예: AD objectGUID 문자열, OpenLDAP entryUUID)
     * @param  bool  $active  재직(활성) 여부 — AD ACCOUNTDISABLE 비트, 설정한 비활성 속성으로 판정
     * @param  string|null  $email  이메일
     * @param  string|null  $displayName  표시 이름
     * @param  array<string, string|array<int, string>>  $attributes  그 밖의 속성
     * @param  array<int, string>  $groups  그룹 이름
     */
    public function __construct(
        public readonly string $subject,
        public readonly bool $active,
        public readonly ?string $email = null,
        public readonly ?string $displayName = null,
        public readonly array $attributes = [],
        public readonly array $groups = [],
    ) {}
}
