<?php

namespace Plugins\Sirsoft\Sso\Services\Ldap;

use LDAP\Connection;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;

/**
 * PHP `ext-ldap` 디렉터리 연결.
 *
 * LDAP v3, 참조(referral) 따라가기 끔(AD 가 다른 도메인으로 보내는 참조에서 익명 재바인드를 막는다), 연결과 검색에 제한 시간을 둔다.
 * 자격 증명 오류(결과 49)만 false 로 돌려주고 나머지 실패는 `ldap_unavailable` 로 구분한다 — 서버 장애는 G7 로그인 실패 횟수에 세지
 * 않는다.
 *
 * @since 1.0.0
 */
class ExtLdapDirectoryClient implements DirectoryClientInterface
{
    /** LDAP 결과 코드 — 자격 증명 오류 */
    private const INVALID_CREDENTIALS = 49;

    /** LDAP 결과 코드 — 건수 제한 초과(요청한 건수까지는 받았다) */
    private const SIZE_LIMIT_EXCEEDED = 4;

    /** @var Connection|null 연결 */
    private mixed $connection = null;

    /**
     * 서버에 연결합니다.
     *
     * @param  array{host: string, port: int, encryption: string, verify_certificate: bool, ca_bundle: string|null, timeout: int}  $options  연결 옵션
     * @return void 반환값 없음
     *
     * @throws SsoException 연결, TLS 실패면 `ldap_unavailable`
     */
    public function connect(array $options): void
    {
        if (! function_exists('ldap_connect')) {
            throw new SsoException('driver_unavailable', 'ext-ldap missing');
        }

        $scheme = $options['encryption'] === 'ldaps' ? 'ldaps' : 'ldap';
        $timeout = max(1, min(30, (int) $options['timeout']));

        if ($options['encryption'] !== 'none') {
            $this->configureTls($options);
        }

        $connection = @ldap_connect($scheme.'://'.$options['host'].':'.(int) $options['port']);
        if ($connection === false) {
            throw new SsoException('ldap_unavailable', 'connect failed');
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
        ldap_set_option($connection, LDAP_OPT_TIMELIMIT, $timeout);
        $this->connection = $connection;

        if ($options['encryption'] === 'starttls' && ! @ldap_start_tls($connection)) {
            $detail = ldap_error($connection);
            $this->close();

            throw new SsoException('ldap_unavailable', 'starttls failed: '.$detail);
        }
    }

    /**
     * 바인드합니다.
     *
     * @param  string|null  $dn  바인드 DN
     * @param  string|null  $password  비밀번호
     * @return bool 성공이면 true, 자격 증명 오류면 false
     *
     * @throws SsoException 서버 장애면 `ldap_unavailable`
     */
    public function bind(?string $dn, ?string $password): bool
    {
        // php-ext-guard: connection() 은 connect() 가 function_exists('ldap_connect') 로 확인하고 연 연결만 돌려준다
        $connection = $this->connection();
        if (@ldap_bind($connection, $dn, $password)) {
            return true;
        }

        $errno = ldap_errno($connection);
        if ($errno === self::INVALID_CREDENTIALS) {
            return false;
        }

        throw new SsoException('ldap_unavailable', 'bind failed: '.$errno.' '.ldap_err2str($errno));
    }

    /**
     * 하위 트리를 검색합니다.
     *
     * @param  string  $baseDn  기준 DN
     * @param  string  $filter  검색 필터
     * @param  array<int, string>  $attributes  가져올 속성
     * @param  int  $sizeLimit  최대 건수
     * @return array<int, array{dn: string, attributes: array<string, array<int, string>>}> 항목
     *
     * @throws SsoException 서버 장애면 `ldap_unavailable`
     */
    public function search(string $baseDn, string $filter, array $attributes, int $sizeLimit = 0): array
    {
        // php-ext-guard: connection() 은 connect() 가 function_exists('ldap_connect') 로 확인하고 연 연결만 돌려준다
        $connection = $this->connection();
        $result = @ldap_search($connection, $baseDn, $filter, $attributes, 0, $sizeLimit);
        if ($result === false) {
            $errno = ldap_errno($connection);
            if ($errno !== self::SIZE_LIMIT_EXCEEDED) {
                throw new SsoException('ldap_unavailable', 'search failed: '.$errno.' '.ldap_err2str($errno));
            }
        }

        if ($result === false) {
            return [];
        }

        $raw = @ldap_get_entries($connection, $result);
        if (! is_array($raw)) {
            return [];
        }

        $entries = [];
        for ($i = 0; $i < (int) ($raw['count'] ?? 0); $i++) {
            $entry = $raw[$i];
            $values = [];
            for ($j = 0; $j < (int) ($entry['count'] ?? 0); $j++) {
                $name = (string) $entry[$j];
                $list = [];
                for ($k = 0; $k < (int) ($entry[$name]['count'] ?? 0); $k++) {
                    $list[] = (string) $entry[$name][$k];
                }
                $values[mb_strtolower($name)] = $list;
            }
            $entries[] = ['dn' => (string) ($entry['dn'] ?? ''), 'attributes' => $values];
        }

        return $entries;
    }

    /**
     * 연결을 닫습니다.
     *
     * @return void 반환값 없음
     */
    public function close(): void
    {
        if ($this->connection !== null) {
            @ldap_unbind($this->connection);
            $this->connection = null;
        }
    }

    /**
     * 열린 연결.
     *
     * @return Connection 연결
     *
     * @throws SsoException 연결 전이면 `ldap_unavailable`
     */
    private function connection(): mixed
    {
        if ($this->connection === null) {
            throw new SsoException('ldap_unavailable', 'not connected');
        }

        return $this->connection;
    }

    /**
     * TLS 옵션 — 인증서 검증 여부와 CA 묶음(PEM 붙여 넣기).
     *
     * OpenLDAP 클라이언트 라이브러리는 TLS 옵션을 연결 전에 전역으로 읽는다. 연결마다 새 TLS 문맥을 쓰도록 `X_TLS_NEWCTX` 와 함께 둔다.
     *
     * @param  array{verify_certificate: bool, ca_bundle: string|null}  $options  연결 옵션
     * @return void 반환값 없음
     */
    private function configureTls(array $options): void
    {
        @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, $options['verify_certificate'] ? LDAP_OPT_X_TLS_DEMAND : LDAP_OPT_X_TLS_NEVER);

        $bundle = trim((string) ($options['ca_bundle'] ?? ''));
        if ($bundle !== '' && str_contains($bundle, 'BEGIN CERTIFICATE')) {
            $path = storage_path('app/sirsoft-sso/ldap-ca-'.substr(hash('sha256', $bundle), 0, 16).'.pem');
            if (! is_file($path)) {
                @mkdir(dirname($path), 0775, true);
                @file_put_contents($path, $bundle);
            }
            @ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $path);
        }

        if (defined('LDAP_OPT_X_TLS_NEWCTX')) {
            @ldap_set_option(null, LDAP_OPT_X_TLS_NEWCTX, 0);
        }
    }
}
