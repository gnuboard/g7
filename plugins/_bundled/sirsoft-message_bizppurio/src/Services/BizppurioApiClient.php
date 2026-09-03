<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

use App\Services\PluginSettingsService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;

/**
 * 비즈뿌리오 발송 시스템(api.bizppurio.com) API 클라이언트.
 *
 * `/v3/message` 를 Bearer 토큰 인증으로 호출해 SMS/LMS/알림톡을 발송한다.
 * 완성된 payload(MessagePayloadBuilder 조립)를 받아 전송하며, 응답 결과코드가
 * 3002(토큰무효)/3005(인증정보무효)면 토큰을 재발급(BizppurioTokenService)한 뒤
 * 1회 재시도한다. environment 설정에 따라 운영/검수 도메인을 분기한다(카카오 관리
 * 시스템 kapi 와 달리 발송 시스템만 도메인 분기 대상).
 */
class BizppurioApiClient
{
    /** 플러그인 식별자 (manifest 와 일치) */
    private const PLUGIN_IDENTIFIER = 'sirsoft-message_bizppurio';

    /** 운영 발송 도메인 */
    private const HOST_LIVE = 'https://api.bizppurio.com';

    /** 검수 발송 도메인 */
    private const HOST_DEV = 'https://dev-api.bizppurio.com';

    /** 토큰·인증 무효 결과코드 — forget 후 재발급 트리거 */
    private const AUTH_INVALID_CODES = ['3002', '3005'];

    /** 발송 응답 성공 결과코드 */
    private const SUCCESS_CODE = '1000';

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const REQUEST_TIMEOUT_SECONDS = 20;

    /**
     * @param  BizppurioTokenService  $tokenService  발송 토큰 발급·캐시
     * @param  PluginSettingsService  $pluginSettings  플러그인 환경설정 조회
     */
    public function __construct(
        private readonly BizppurioTokenService $tokenService,
        private readonly PluginSettingsService $pluginSettings,
    ) {}

    /**
     * 완성된 발송 payload 를 `/v3/message` 로 전송합니다.
     *
     * 토큰·인증 무효(3002/3005) 응답 시 토큰을 재발급한 뒤 1회 재시도합니다.
     *
     * @param  array<string, mixed>  $payload  MessagePayloadBuilder 가 조립한 발송 본문
     * @return array<string, mixed> 응답 배열 (code/description/messagekey/refkey)
     *
     * @throws BizppurioApiException HTTP 실패·응답 파싱 실패 시
     */
    public function sendMessage(array $payload): array
    {
        $result = $this->postMessage($payload, $this->tokenService->getToken());

        // 토큰·인증 무효 → 재발급 후 1회 재시도
        if (in_array((string) ($result['code'] ?? ''), self::AUTH_INVALID_CODES, true)) {
            $result = $this->postMessage($payload, $this->tokenService->refreshToken());
        }

        return $result;
    }

    /**
     * 발송 응답 결과코드가 성공(1000)인지 판정합니다.
     *
     * @param  array<string, mixed>  $result  sendMessage 응답
     * @return bool 성공이면 true
     */
    public function isSuccess(array $result): bool
    {
        return (string) ($result['code'] ?? '') === self::SUCCESS_CODE;
    }

    /**
     * 단일 발송 요청을 수행합니다.
     *
     * @param  array<string, mixed>  $payload  발송 본문
     * @param  string  $token  Bearer 액세스 토큰
     * @return array<string, mixed> 응답 배열
     *
     * @throws BizppurioApiException HTTP 실패·응답 파싱 실패 시
     */
    private function postMessage(array $payload, string $token): array
    {
        $response = $this->http()
            ->withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json; charset=utf-8',
            ])
            ->post($this->baseUrl().'/v3/message', $payload);

        if ($response->failed()) {
            // 비즈뿌리오는 실패 응답에도 code·description 을 담아준다(명세). 이를 추출해
            // 예외에 실어, 발송 이력·로그에 "왜 실패했는지"(결과코드+사유)가 남도록 한다.
            // body 가 없거나 파싱 불가하면 HTTP 상태만 보존한다.
            $body = $response->json();
            $code = is_array($body) ? (string) ($body['code'] ?? '') : '';
            $description = is_array($body) ? (string) ($body['description'] ?? '') : '';

            throw new BizppurioApiException(
                $description !== ''
                    ? $description
                    : __('sirsoft-message_bizppurio::messages.error.send_failed'),
                resultCode: $code !== '' ? $code : null,
                httpStatus: $response->status(),
            );
        }

        $result = $response->json();

        if (! is_array($result)) {
            throw new BizppurioApiException(
                __('sirsoft-message_bizppurio::messages.error.invalid_response'),
                httpStatus: $response->status(),
            );
        }

        return $result;
    }

    /**
     * 환경(운영/검수)에 맞는 발송 도메인 베이스 URL 을 반환합니다.
     *
     * @return string 베이스 URL (스킴 포함)
     */
    private function baseUrl(): string
    {
        // 검수 모드(is_test_mode)가 꺼져 있으면 운영 도메인으로 발송한다. 기본값(미설정)은
        // 안전하게 검수(true)로 간주해 운영 발송이 우발적으로 일어나지 않도록 한다.
        $isTestMode = (bool) $this->pluginSettings->get(self::PLUGIN_IDENTIFIER, 'is_test_mode', true);

        return $isTestMode ? self::HOST_DEV : self::HOST_LIVE;
    }

    /**
     * 공통 타임아웃이 적용된 HTTP 클라이언트를 반환합니다.
     */
    private function http(): PendingRequest
    {
        return Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS);
    }
}
