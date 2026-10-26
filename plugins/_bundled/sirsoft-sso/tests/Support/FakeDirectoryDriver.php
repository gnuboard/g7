<?php

namespace Plugins\Sirsoft\Sso\Tests\Support;

use Illuminate\Http\Request;
use Plugins\Sirsoft\Sso\Contracts\CredentialDriverInterface;
use Plugins\Sirsoft\Sso\DTO\DirectoryEntry;
use Plugins\Sirsoft\Sso\DTO\DriverCapabilities;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\DTO\LogoutResult;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * 시험용 디렉터리 드라이버 — `lookup()` 이 심어 둔 계정을 돌려준다 (주기 점검 시험, LDAP 서버 없이).
 *
 * 값 `null` 은 「검색 결과 없음」, `false` 는 「디렉터리 연결 실패」(SsoException `directory_unavailable`)다.
 *
 * @since 1.0.0
 */
class FakeDirectoryDriver extends FakeProtocolDriver implements CredentialDriverInterface
{
    /** @var array<string, DirectoryEntry|false|null> 찾는 값 → 계정 */
    public array $entries = [];

    /** @var array<int, string> 찾은 값 순서 */
    public array $lookups = [];

    /**
     * 시험용 디렉터리 드라이버를 만듭니다.
     *
     * @param  string  $driverKey  프로토콜 키
     */
    public function __construct(string $driverKey = 'ldap')
    {
        parent::__construct($driverKey);
    }

    /**
     * 기능 표 — 자격 증명 + 주기 점검.
     *
     * @return DriverCapabilities 기능 표
     */
    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(credential: true, groups: true, periodicCheck: true);
    }

    /**
     * 시험에서 쓰지 않습니다.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  string  $identifier  아이디
     * @param  string  $password  비밀번호
     * @return ExternalIdentity 신원
     */
    public function authenticate(IdentityProvider $idp, string $identifier, string $password): ExternalIdentity
    {
        throw new SsoException('ldap_invalid_credentials');
    }

    /**
     * 심어 둔 계정을 돌려줍니다.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  string  $subject  찾는 값
     * @return DirectoryEntry|null 계정
     */
    public function lookup(IdentityProvider $idp, string $subject): ?DirectoryEntry
    {
        $this->lookups[] = $subject;
        $entry = $this->entries[$subject] ?? null;
        if ($entry === false) {
            throw new SsoException('directory_unavailable');
        }

        return $entry;
    }

    /**
     * 시험에서 쓰지 않습니다.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  SsoSession  $session  세션
     * @return null 없음
     */
    public function beginLogout(IdentityProvider $idp, SsoSession $session): ?Response
    {
        return null;
    }

    /**
     * 시험에서 쓰지 않습니다.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  Request  $request  요청
     * @return never 항상 예외
     */
    public function handleLogoutRequest(IdentityProvider $idp, Request $request): LogoutResult
    {
        throw new SsoException('id_token_invalid');
    }
}
