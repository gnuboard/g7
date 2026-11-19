<?php

namespace Plugins\Sirsoft\Sso\Drivers\Saml;

use App\Contracts\Extension\CacheInterface;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\IdPMetadataParser;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;

/**
 * SAML IdP 메타데이터 — 주소에서 받거나 붙여 넣은 XML 을 라이브러리 파서(`IdPMetadataParser`)로 읽습니다.
 *
 * 받은 결과는 하루 캐시한다(매일 갱신 명령이 강제로 다시 받는다). 서명 인증서의 만료일 중 가장 이른 값을 IdP 행
 * `cert_expires_at` 에 남겨 만료 알림과 진단 카드가 쓴다.
 *
 * @since 1.0.0
 */
class SamlMetadata
{
    /** 메타데이터 캐시(초) — 하루 */
    public const CACHE_TTL = 86400;

    /** 메타데이터 크기 상한(바이트) */
    public const MAX_BYTES = 1048576;

    /** 서버 간 요청 제한 시간(초) */
    private const HTTP_TIMEOUT = 10;

    /**
     * @param  CacheInterface  $cache  플러그인 캐시
     */
    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * IdP 메타데이터를 읽어 라이브러리 설정 모양(`idp` 칸)으로 돌려줍니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  bool  $force  캐시를 무시하고 다시 받을지
     * @return array{entityId: string, singleSignOnService: array{url: string, binding: string}, singleLogoutService?: array<string, string>, x509cert?: string, x509certMulti?: array<string, array<int, string>>, nameIdFormat?: string} IdP 설정
     *
     * @throws SsoException 받기, 읽기 실패면 `saml_invalid`
     */
    public function idp(IdentityProvider $idp, bool $force = false): array
    {
        return self::parse($this->rawXml($idp, $force));
    }

    /**
     * 메타데이터 XML 을 읽어 라이브러리 설정 모양(`idp` 칸)으로 돌려줍니다 — 로그인과 저장 검사(`SamlDriver::validateConfig`)가 같이 쓴다.
     *
     * 라이브러리 로더가 DOCTYPE·엔티티가 든 XML 을 거절한다(XXE). 깨진 XML 의 libxml 경고는 로그로 새지 않게 모아서 버린다.
     *
     * @param  string  $xml  메타데이터 XML
     * @return array{entityId: string, singleSignOnService: array{url: string, binding: string}, singleLogoutService?: array<string, string>, x509cert?: string, x509certMulti?: array<string, array<int, string>>, nameIdFormat?: string} IdP 설정
     *
     * @throws SsoException 읽지 못했거나 entityID, SSO 주소, 서명 인증서가 없으면 `saml_invalid`
     */
    public static function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $parsed = IdPMetadataParser::parseXML($xml, null, null, Constants::BINDING_HTTP_REDIRECT, Constants::BINDING_HTTP_REDIRECT);
        } catch (\Throwable $e) {
            throw new SsoException('saml_invalid', 'metadata parse failed', 422, $e);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $info = $parsed['idp'] ?? null;
        if (! is_array($info) || ! is_string($info['entityId'] ?? null) || ! is_string($info['singleSignOnService']['url'] ?? null)) {
            throw new SsoException('saml_invalid', 'metadata missing entityId or SingleSignOnService');
        }

        if (! isset($info['x509cert']) && empty($info['x509certMulti']['signing'] ?? [])) {
            throw new SsoException('saml_invalid', 'metadata missing signing certificate');
        }

        if (is_string($parsed['sp']['NameIDFormat'] ?? null)) {
            $info['nameIdFormat'] = $parsed['sp']['NameIDFormat'];
        }

        return $info;
    }

    /**
     * 서명 인증서 목록을 돌려줍니다.
     *
     * @param  array<string, mixed>  $idpInfo  `idp()` 결과
     * @return array<int, string> 인증서 본문 (머리줄 없는 base64)
     */
    public static function signingCertificates(array $idpInfo): array
    {
        if (is_string($idpInfo['x509cert'] ?? null) && $idpInfo['x509cert'] !== '') {
            return [$idpInfo['x509cert']];
        }

        $multi = $idpInfo['x509certMulti']['signing'] ?? [];

        return is_array($multi) ? array_values(array_filter($multi, 'is_string')) : [];
    }

    /**
     * 인증서들 가운데 가장 이른 만료 시각을 돌려줍니다.
     *
     * @param  array<int, string>  $certificates  인증서 본문
     * @return CarbonImmutable|null 만료 시각, 읽을 수 없으면 null
     */
    public static function earliestExpiry(array $certificates): ?CarbonImmutable
    {
        $earliest = null;
        foreach ($certificates as $certificate) {
            $parsed = @openssl_x509_parse(self::pem($certificate));
            if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
                continue;
            }

            $expires = CarbonImmutable::createFromTimestampUTC((int) $parsed['validTo_time_t']);
            if ($earliest === null || $expires->lessThan($earliest)) {
                $earliest = $expires;
            }
        }

        return $earliest;
    }

    /**
     * 인증서 본문을 PEM 으로 감쌉니다 (이미 PEM 이면 그대로).
     *
     * @param  string  $certificate  인증서
     * @return string PEM
     */
    public static function pem(string $certificate): string
    {
        if (str_contains($certificate, '-----BEGIN CERTIFICATE-----')) {
            return $certificate;
        }

        $body = preg_replace('/\s+/', '', $certificate) ?? '';

        return "-----BEGIN CERTIFICATE-----\n".chunk_split($body, 64, "\n")."-----END CERTIFICATE-----\n";
    }

    /**
     * 캐시를 지웁니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return void 반환값 없음
     */
    public function forget(IdentityProvider $idp): void
    {
        $this->cache->forget($this->cacheKey($idp));
    }

    /**
     * 서버 간 HTTP 클라이언트 — 모의 IdP 만 TLS 검증을 끈다(개발 사이트 자체 서명 인증서).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return PendingRequest 클라이언트
     */
    public function http(IdentityProvider $idp): PendingRequest
    {
        $client = Http::timeout(self::HTTP_TIMEOUT)->accept('application/samlmetadata+xml, application/xml, text/xml');

        return $idp->isMock() ? $client->withoutVerifying() : $client;
    }

    /**
     * 메타데이터 XML 원문을 얻습니다 — 붙여 넣은 XML 이 있으면 그것, 아니면 주소에서 받아 캐시.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  bool  $force  다시 받을지
     * @return string XML
     *
     * @throws SsoException 설정이 없거나 받기 실패면 `saml_invalid`
     */
    private function rawXml(IdentityProvider $idp, bool $force): string
    {
        $pasted = trim((string) $idp->configValue('idp_metadata_xml', ''));
        if ($pasted !== '') {
            if (strlen($pasted) > self::MAX_BYTES) {
                throw new SsoException('saml_invalid', 'metadata too large');
            }

            return $pasted;
        }

        $url = (string) $idp->configValue('idp_metadata_url', '');
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            throw new SsoException('saml_invalid', 'idp metadata not configured');
        }

        $key = $this->cacheKey($idp);
        if (! $force) {
            $cached = $this->cache->get($key);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        try {
            $response = $this->http($idp)->get($url);
        } catch (\Throwable $e) {
            throw new SsoException('saml_invalid', 'metadata fetch failed: '.class_basename($e), 422, $e);
        }

        $body = (string) $response->body();
        if (! $response->successful() || $body === '' || strlen($body) > self::MAX_BYTES) {
            throw new SsoException('saml_invalid', 'metadata fetch status '.$response->status());
        }

        $this->cache->put($key, $body, self::CACHE_TTL);

        return $body;
    }

    /**
     * 캐시 키 — 주소가 바뀌면 다른 키.
     *
     * @param  IdentityProvider  $idp  IdP
     * @return string 키
     */
    private function cacheKey(IdentityProvider $idp): string
    {
        return 'saml:meta:'.$idp->getKey().':'.hash('sha256', (string) $idp->configValue('idp_metadata_url', ''));
    }
}
