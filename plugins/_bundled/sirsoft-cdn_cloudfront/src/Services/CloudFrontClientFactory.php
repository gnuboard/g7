<?php

namespace Plugins\Sirsoft\CdnCloudfront\Services;

use Aws\CloudFront\CloudFrontClient;

/**
 * CloudFront API 클라이언트 생성기
 *
 * SDK 는 코어 vendor 의 `aws/aws-sdk-php` 를 쓴다(이 플러그인은 따로 require 하지 않는다).
 * CloudFront 는 전역 서비스라 지역은 us-east-1 로 고정이다.
 *
 * SDK 자체 재시도는 끈다 — 실패는 이 플러그인 대기열이 지수 대기로 다시 보내므로, SDK 가 응답 뒤
 * (`terminating`) 단계에서 몇 번씩 더 기다리며 요청을 붙잡지 않게 한다.
 */
class CloudFrontClientFactory
{
    /** API 버전 */
    public const API_VERSION = '2020-05-31';

    /** CloudFront 서명 지역 (전역 서비스) */
    public const REGION = 'us-east-1';

    /**
     * 시험이 가짜 처리기를 넣을 수 있게 HTTP 처리기를 주입받습니다.
     *
     * @param  callable|null  $handler  HTTP 처리기 (null 이면 SDK 기본 — 시험에서는 Aws\MockHandler)
     */
    public function __construct(private $handler = null) {}

    /**
     * 클라이언트를 만듭니다.
     *
     * @param  string  $accessKey  액세스 키
     * @param  string  $secretKey  비밀 키
     * @return CloudFrontClient 클라이언트
     *
     * @throws \InvalidArgumentException SDK 가 클라이언트 설정을 거절한 경우
     */
    public function make(string $accessKey, string $secretKey): CloudFrontClient
    {
        $config = [
            'version' => self::API_VERSION,
            'region' => self::REGION,
            'credentials' => ['key' => $accessKey, 'secret' => $secretKey],
            'retries' => 0,
            'http' => ['connect_timeout' => 5, 'timeout' => 15],
        ];

        if ($this->handler !== null) {
            $config['handler'] = $this->handler;
        }

        return new CloudFrontClient($config);
    }
}
