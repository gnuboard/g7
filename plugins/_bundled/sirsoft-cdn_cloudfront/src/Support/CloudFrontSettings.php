<?php

namespace Plugins\Sirsoft\CdnCloudfront\Support;

/**
 * 플러그인 설정 읽기 — 저장값을 쓸 수 있는 모양으로 다듬어 내준다.
 *
 * 설정은 요청마다 바뀔 수 있으므로(관리자 저장) 값을 들고 있지 않고 매번 읽는다.
 */
class CloudFrontSettings
{
    /** 플러그인 식별자 */
    public const IDENTIFIER = 'sirsoft-cdn_cloudfront';

    /**
     * CDN 주소 (끝 `/` 제거). http·https 이고 호스트가 있는 주소만 받는다 — 그 밖의 값은 없는 것으로 본다.
     *
     * @return string|null CDN 주소
     */
    public function cdnBaseUrl(): ?string
    {
        $url = trim((string) plugin_setting(self::IDENTIFIER, 'cdn_base_url', ''));

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return null;
        }

        return rtrim($url, '/');
    }

    /**
     * CDN 호스트 (소문자). 무효화 대상 URL 을 고를 때 쓴다.
     *
     * @return string|null 호스트
     */
    public function cdnHost(): ?string
    {
        $url = $this->cdnBaseUrl();

        if ($url === null) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * 배포 ID
     *
     * @return string 배포 ID (없으면 빈 문자열)
     */
    public function distributionId(): string
    {
        return trim((string) plugin_setting(self::IDENTIFIER, 'distribution_id', ''));
    }

    /**
     * 무효화용 액세스 키
     *
     * @return string 액세스 키 (없으면 빈 문자열)
     */
    public function accessKey(): string
    {
        return trim((string) plugin_setting(self::IDENTIFIER, 'access_key', ''));
    }

    /**
     * 무효화용 비밀 키
     *
     * @return string 비밀 키 (없으면 빈 문자열)
     */
    public function secretKey(): string
    {
        return trim((string) plugin_setting(self::IDENTIFIER, 'secret_key', ''));
    }

    /**
     * 운영자가 자동 무효화를 켰는지
     *
     * @return bool 켰으면 true
     */
    public function purgeEnabled(): bool
    {
        return filter_var(plugin_setting(self::IDENTIFIER, 'purge_enabled', true), FILTER_VALIDATE_BOOL);
    }

    /**
     * 무효화 요청을 보낼 수 있는 자격(배포 ID·액세스 키·비밀 키)이 모두 있는지
     *
     * S3 자격으로 대신하지 않는다 — 무효화 자격은 항상 따로 받는다. 비어 있으면 무효화를 보내지 않는다.
     *
     * @return bool 모두 있으면 true
     */
    public function hasCredentials(): bool
    {
        return $this->distributionId() !== '' && $this->accessKey() !== '' && $this->secretKey() !== '';
    }

    /**
     * 자동 무효화가 동작할 조건(켬 + 자격 + CDN 주소)이 모두 갖춰졌는지
     *
     * @return bool 갖춰졌으면 true
     */
    public function purgeReady(): bool
    {
        return $this->purgeEnabled() && $this->hasCredentials() && $this->cdnHost() !== null;
    }
}
