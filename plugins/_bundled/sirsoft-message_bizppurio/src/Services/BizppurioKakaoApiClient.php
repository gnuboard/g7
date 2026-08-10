<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

use App\Services\PluginSettingsService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;

/**
 * 비즈뿌리오 카카오 관리 시스템(kapi.ppurio.com) API 클라이언트.
 *
 * 발송 시스템(api.bizppurio.com)과 별개의 시스템으로, 알림톡 템플릿 등록·검수·조회
 * 및 발신프로필 조회를 담당한다. 토큰 인증이 아니라 매 요청 body 에 bizId + apiKey
 * 를 실어 인증하며, 도메인은 검수/운영 구분 없이 단일(kapi.ppurio.com)이다.
 *
 * 모든 요청은 POST + JSON 이며 응답은 `{code, message, data}` 봉투를 따른다.
 * 성공 결과코드는 "200" 이다. 이 클래스는 공통 POST 위임(bizId/apiKey 자동 주입)과
 * Phase 5·6 에서 소비하는 엔드포인트 메서드를 제공한다.
 */
class BizppurioKakaoApiClient
{
    /** 플러그인 식별자 (manifest 와 일치) */
    private const PLUGIN_IDENTIFIER = 'sirsoft-message_bizppurio';

    /** 카카오 관리 시스템 단일 도메인 (검수/운영 구분 없음) */
    private const BASE_URL = 'https://kapi.ppurio.com';

    /** 카카오 관리 API 성공 결과코드 */
    private const SUCCESS_CODE = '200';

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const REQUEST_TIMEOUT_SECONDS = 20;

    /**
     * @param  PluginSettingsService  $pluginSettings  플러그인 환경설정 조회(bizId=bizppurio_id, apiKey)
     */
    public function __construct(
        private readonly PluginSettingsService $pluginSettings,
    ) {}

    /**
     * 발신프로필 목록을 조회합니다. (`/v3/kakao/profile/use`)
     *
     * @return array<string, mixed> 응답 배열 (code/message/data)
     *
     * @throws BizppurioApiException 자격증명 미설정·HTTP 실패·응답 파싱 실패 시
     */
    public function getSenderProfiles(): array
    {
        return $this->post('/v3/kakao/profile/use');
    }

    /**
     * 알림톡 템플릿 목록을 조회합니다. (`/v3/kakao/template/list`)
     *
     * @param  string  $senderKey  발신프로필 키
     * @param  array<string, mixed>  $params  추가 조회 파라미터 (count/page/status 등)
     * @return array<string, mixed> 응답 배열 (code/message/data)
     *
     * @throws BizppurioApiException 자격증명 미설정·HTTP 실패·응답 파싱 실패 시
     */
    public function getTemplateList(string $senderKey, array $params = []): array
    {
        return $this->post('/v3/kakao/template/list', array_merge($params, [
            'senderKey' => $senderKey,
        ]));
    }

    /**
     * 알림톡 템플릿 상세를 조회합니다. (`/v3/kakao/template/detail`)
     *
     * @param  string  $senderKey  발신프로필 키
     * @param  string  $templateCode  템플릿 코드
     * @return array<string, mixed> 응답 배열 (code/message/data)
     *
     * @throws BizppurioApiException 자격증명 미설정·HTTP 실패·응답 파싱 실패 시
     */
    public function getTemplateDetail(string $senderKey, string $templateCode): array
    {
        return $this->post('/v3/kakao/template/detail', [
            'senderKey' => $senderKey,
            'templateCode' => $templateCode,
        ]);
    }

    /**
     * 카카오 관리 API 의 임의 엔드포인트를 호출합니다.
     *
     * Phase 5·6 의 템플릿 CRUD·검수·카테고리 조회 등에서 재사용한다. bizId/apiKey 는
     * 자동으로 주입되므로 도메인 파라미터만 전달한다.
     *
     * @param  string  $path  엔드포인트 경로 (예: '/v3/kakao/template/add')
     * @param  array<string, mixed>  $params  요청 파라미터 (bizId/apiKey 제외)
     * @return array<string, mixed> 응답 배열 (code/message/data)
     *
     * @throws BizppurioApiException 자격증명 미설정·HTTP 실패·응답 파싱 실패 시
     */
    public function request(string $path, array $params = []): array
    {
        return $this->post($path, $params);
    }

    /**
     * 카카오 관리 API 응답이 성공(200)인지 판정합니다.
     *
     * @param  array<string, mixed>  $result  응답 배열
     * @return bool 성공이면 true
     */
    public function isSuccess(array $result): bool
    {
        return (string) ($result['code'] ?? '') === self::SUCCESS_CODE;
    }

    /**
     * bizId/apiKey 를 주입해 카카오 관리 API 를 POST 호출합니다.
     *
     * @param  string  $path  엔드포인트 경로
     * @param  array<string, mixed>  $params  요청 파라미터 (bizId/apiKey 제외)
     * @return array<string, mixed> 응답 배열
     *
     * @throws BizppurioApiException 자격증명 미설정·HTTP 실패·응답 파싱 실패 시
     */
    private function post(string $path, array $params = []): array
    {
        [$bizId, $apiKey] = $this->credentials();

        $response = $this->http()
            ->withHeaders(['Content-Type' => 'application/json; charset=utf-8'])
            ->post(self::BASE_URL.$path, array_merge($params, [
                'bizId' => $bizId,
                'apiKey' => $apiKey,
            ]));

        if ($response->failed()) {
            throw $this->failureException($response);
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
     * HTTP 실패 응답에서 카카오가 준 사유(message)와 결과코드(code)를 추출해 예외를 만듭니다.
     *
     * 카카오 관리 API 는 실패 시에도 `{code, message}` 봉투를 반환하므로, body 를 파싱해
     * 운영자에게 실제 사유(접근 불가 IP·반려 사유·계정 오류 등)를 노출한다. body 파싱이
     * 불가하거나 message 가 비어 있으면 일반 실패 문구로 폴백한다.
     *
     * @param  Response  $response  실패한 HTTP 응답
     * @return BizppurioApiException 카카오 사유·결과코드·HTTP 상태가 담긴 예외
     */
    private function failureException(Response $response): BizppurioApiException
    {
        $body = $response->json();
        $message = is_array($body) ? (string) ($body['message'] ?? '') : '';
        $code = is_array($body) ? (string) ($body['code'] ?? '') : '';

        return new BizppurioApiException(
            $message !== ''
                ? $message
                : __('sirsoft-message_bizppurio::messages.error.kakao_request_failed'),
            resultCode: $code !== '' ? $code : null,
            httpStatus: $response->status(),
        );
    }

    /**
     * 환경설정에서 bizId(=bizppurio_id)와 apiKey 를 조회합니다.
     *
     * @return array{0: string, 1: string} [bizId, apiKey]
     *
     * @throws BizppurioApiException 자격증명 미설정 시
     */
    private function credentials(): array
    {
        $settings = $this->pluginSettings->get(self::PLUGIN_IDENTIFIER) ?? [];
        $bizId = (string) ($settings['bizppurio_id'] ?? '');
        $apiKey = (string) ($settings['api_key'] ?? '');

        if ($bizId === '' || $apiKey === '') {
            throw new BizppurioApiException(
                __('sirsoft-message_bizppurio::messages.error.kakao_credentials_missing'),
            );
        }

        return [$bizId, $apiKey];
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
