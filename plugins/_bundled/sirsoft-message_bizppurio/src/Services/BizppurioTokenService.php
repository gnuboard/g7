<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

use App\Contracts\Extension\CacheInterface;
use App\Services\PluginSettingsService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;

/**
 * 비즈뿌리오 발송 시스템(api.bizppurio.com) 인증 토큰 서비스.
 *
 * `/v1/token` 을 Basic 인증(계정:암호 Base64)으로 호출해 Bearer 토큰을 발급받고,
 * 확장 도메인 캐시(CacheInterface)에 저장한다. 토큰 유효 시간은 24시간이나, 만료
 * 경계 재발급 리스크를 피하기 위해 23시간 TTL 로 캐시한다(PO 결정, 계획서 §5).
 *
 * 발송 응답이 3002(토큰무효)/3005(인증정보무효)를 반환하면 캐시를 forget 하고
 * 1회 재발급한다(BizppurioApiClient 가 재시도 트리거). CacheInterface 는
 * MessageBizppurioServiceProvider 의 $cacheServices contextual binding 으로 주입된다.
 */
class BizppurioTokenService
{
    /** 플러그인 식별자 (manifest 와 일치) */
    private const PLUGIN_IDENTIFIER = 'sirsoft-message_bizppurio';

    /** 토큰 캐시 키 (확장 도메인 캐시 접두사가 자동 적용됨) */
    private const CACHE_KEY = 'bizppurio:token';

    /** 토큰 캐시 TTL (초) — 23시간. 유효 24h 대비 만료 경계 회피 */
    private const CACHE_TTL_SECONDS = 23 * 3600;

    /** 운영 발송 도메인 */
    private const HOST_LIVE = 'https://api.bizppurio.com';

    /** 검수 발송 도메인 */
    private const HOST_DEV = 'https://dev-api.bizppurio.com';

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const REQUEST_TIMEOUT_SECONDS = 15;

    /**
     * @param  CacheInterface  $cache  확장 도메인 캐시 드라이버(contextual binding 주입)
     * @param  PluginSettingsService  $pluginSettings  플러그인 환경설정 조회
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly PluginSettingsService $pluginSettings,
    ) {}

    /**
     * 캐시된 토큰을 반환하거나, 없으면 새로 발급받아 캐시합니다.
     *
     * @return string Bearer 액세스 토큰
     *
     * @throws BizppurioApiException 토큰 발급 실패 시
     */
    public function getToken(): string
    {
        return $this->cache->remember(
            self::CACHE_KEY,
            fn (): string => $this->issueToken(),
            self::CACHE_TTL_SECONDS,
        );
    }

    /**
     * 캐시된 토큰을 무효화하고 새 토큰을 발급받아 반환합니다.
     *
     * 발송 응답이 3002/3005(토큰·인증 무효)일 때 호출한다.
     *
     * @return string 새로 발급받은 Bearer 액세스 토큰
     *
     * @throws BizppurioApiException 토큰 재발급 실패 시
     */
    public function refreshToken(): string
    {
        $this->cache->forget(self::CACHE_KEY);

        return $this->getToken();
    }

    /**
     * 캐시된 토큰을 제거합니다.
     */
    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * 현재 저장된 자격증명으로 토큰 발급을 즉시 재검증합니다.
     *
     * 캐시를 거치지 않고 매번 `/v1/token` 을 새로 호출한다(관리자가 계정/비밀번호가
     * 유효한지 그 자리에서 확인하려는 목적 — 설정 화면 "연결 확인" 버튼이 소비).
     * 검증에 성공하면 새로 발급된 토큰으로 캐시를 갱신해, 확인 직후의 발송이 이
     * 토큰을 그대로 재사용할 수 있게 한다(불필요한 재발급 방지).
     *
     * @return string 새로 발급받은 Bearer 액세스 토큰
     *
     * @throws BizppurioApiException 자격증명 미설정·HTTP 실패·응답 파싱 실패 시
     */
    public function verifyCredentials(): string
    {
        $token = $this->issueToken();

        $this->cache->put(self::CACHE_KEY, $token, self::CACHE_TTL_SECONDS);

        return $token;
    }

    /**
     * `/v1/token` 을 호출해 새 토큰을 발급받습니다.
     *
     * @return string Bearer 액세스 토큰
     *
     * @throws BizppurioApiException 자격증명 미설정·HTTP 실패·응답 파싱 실패 시
     */
    private function issueToken(): string
    {
        $settings = $this->pluginSettings->get(self::PLUGIN_IDENTIFIER) ?? [];
        $account = (string) ($settings['bizppurio_id'] ?? '');
        $password = (string) ($settings['password'] ?? '');

        if ($account === '' || $password === '') {
            throw new BizppurioApiException(
                __('sirsoft-message_bizppurio::messages.error.credentials_missing'),
            );
        }

        $response = $this->http()
            ->withHeaders([
                'Authorization' => 'Basic '.base64_encode($account.':'.$password),
                'Content-Type' => 'application/json; charset=utf-8',
            ])
            ->post($this->baseUrl($settings).'/v1/token');

        if ($response->failed()) {
            throw new BizppurioApiException(
                $this->describeFailure($response),
                resultCode: (string) ($response->json('code') ?? '') ?: null,
                httpStatus: $response->status(),
            );
        }

        $token = (string) ($response->json('accesstoken') ?? '');

        if ($token === '') {
            throw new BizppurioApiException(
                $this->describeFailure($response),
                resultCode: (string) ($response->json('code') ?? '') ?: null,
                httpStatus: $response->status(),
            );
        }

        return $token;
    }

    /**
     * 토큰 발급 실패 응답에서 비즈뿌리오 원문 사유를 담은 메시지를 만듭니다.
     *
     * 비즈뿌리오는 실패 시 `{"code": "3007", "description": "invalid password in
     * bizppurio"}` 형태로 원인을 내려준다. 원문이 있으면 함께 노출해 운영자가 계정/
     * 비밀번호 오류인지 서버 오류인지 즉시 구분할 수 있게 한다(고정 문구만으로는
     * 원인 추적이 불가능했던 문제 대응).
     *
     * @param  \Illuminate\Http\Client\Response  $response  실패한 HTTP 응답
     * @return string 사용자에게 보여줄 실패 메시지
     */
    private function describeFailure(\Illuminate\Http\Client\Response $response): string
    {
        $description = (string) ($response->json('description') ?? '');

        if ($description === '') {
            return __('sirsoft-message_bizppurio::messages.error.token_issue_failed');
        }

        return __('sirsoft-message_bizppurio::messages.error.token_issue_failed_with_reason', [
            'reason' => $description,
        ]);
    }

    /**
     * 환경(운영/검수)에 맞는 발송 도메인 베이스 URL 을 반환합니다.
     *
     * @param  array<string, mixed>  $settings  플러그인 환경설정
     * @return string 베이스 URL (스킴 포함)
     */
    private function baseUrl(array $settings): string
    {
        // 검수 모드(is_test_mode)가 꺼져 있으면 운영 도메인으로 발송한다. 기본값(미설정)은
        // 안전하게 검수(true)로 간주해 운영 발송이 우발적으로 일어나지 않도록 한다.
        $isTestMode = (bool) ($settings['is_test_mode'] ?? true);

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
