<?php

namespace Plugins\Sirsoft\Sso\Services\Ldap;

use Plugins\Sirsoft\Sso\Exceptions\SsoException;

/**
 * LDAP 디렉터리 연결 — 연결, 바인드, 검색만 감싼다.
 *
 * 실제 구현은 `ExtLdapDirectoryClient`(PHP `ext-ldap`), 시험은 가짜 디렉터리가 같은 계약을 구현해 서버 없이 드라이버를 시험한다.
 * 한 인스턴스는 한 연결이다 — 드라이버가 호출마다 새로 만든다(컨테이너 `bind`).
 *
 * @since 1.0.0
 */
interface DirectoryClientInterface
{
    /**
     * 서버에 연결합니다 (StartTLS 면 여기서 TLS 를 연다).
     *
     * @param  array{host: string, port: int, encryption: string, verify_certificate: bool, ca_bundle: string|null, timeout: int}  $options  연결 옵션 (encryption: none, starttls, ldaps)
     * @return void 반환값 없음
     *
     * @throws SsoException 연결, TLS 실패면 `ldap_unavailable`
     */
    public function connect(array $options): void;

    /**
     * 바인드합니다.
     *
     * 자격 증명이 틀리면(LDAP 결과 49) false 를 돌려주고, 서버 장애는 예외로 구분한다. 빈 비밀번호 바인드(익명 바인드)는 호출자가 막는다.
     *
     * @param  string|null  $dn  바인드 DN (null 이면 익명)
     * @param  string|null  $password  비밀번호
     * @return bool 성공이면 true, 자격 증명이 틀리면 false
     *
     * @throws SsoException 서버 장애면 `ldap_unavailable`
     */
    public function bind(?string $dn, ?string $password): bool;

    /**
     * 하위 트리를 검색합니다.
     *
     * @param  string  $baseDn  기준 DN
     * @param  string  $filter  검색 필터 (값은 호출자가 이미 이스케이프했다)
     * @param  array<int, string>  $attributes  가져올 속성
     * @param  int  $sizeLimit  최대 건수 (0 이면 서버 기본)
     * @return array<int, array{dn: string, attributes: array<string, array<int, string>>}> 항목 (속성 이름은 소문자)
     *
     * @throws SsoException 서버 장애면 `ldap_unavailable`
     */
    public function search(string $baseDn, string $filter, array $attributes, int $sizeLimit = 0): array;

    /**
     * 연결을 닫습니다.
     *
     * @return void 반환값 없음
     */
    public function close(): void;
}
