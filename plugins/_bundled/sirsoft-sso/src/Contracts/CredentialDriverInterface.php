<?php

namespace Plugins\Sirsoft\Sso\Contracts;

use Plugins\Sirsoft\Sso\DTO\DirectoryEntry;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;

/**
 * 아이디와 비밀번호를 디렉터리로 확인하는 드라이버의 추가 계약 (LDAP/Active Directory).
 *
 * 리디렉션 흐름이 아니므로 `capabilities()->credential` 이 참인 드라이버가 구현한다. 코어 로그인 화면에서 들어온 아이디와
 * 비밀번호는 코어 외부 자격 증명 검증자(플러그인이 하나를 등록)가 이 계약으로 넘긴다.
 *
 * @since 1.0.0
 */
interface CredentialDriverInterface
{
    /**
     * 아이디와 비밀번호를 디렉터리로 확인합니다.
     *
     * 빈 비밀번호는 바인드 전에 거절한다(익명 바인드 방지). 검색 결과가 정확히 1건일 때만 진행한다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $identifier  이메일 또는 사내 아이디
     * @param  string  $password  비밀번호
     * @return ExternalIdentity 확인된 신원
     *
     * @throws SsoException `ldap_invalid_credentials`, `ldap_unavailable`
     */
    public function authenticate(IdentityProvider $idp, string $identifier, string $password): ExternalIdentity;

    /**
     * 디렉터리에서 계정을 찾습니다 (재직 여부 조회 — 주기 점검, SAML 보조 조회).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $subject  찾을 값 (연결의 subject 또는 이메일)
     * @return DirectoryEntry|null 계정, 없으면 null
     *
     * @throws SsoException `directory_unavailable`
     */
    public function lookup(IdentityProvider $idp, string $subject): ?DirectoryEntry;
}
