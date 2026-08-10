<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

use App\Contracts\Extension\CacheInterface;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;

/**
 * 카카오 승인 템플릿 내용(본문·버튼·요소)을 발송용으로 조회·캐시하는 서비스 (B안 5-1).
 *
 * 발송 시 알림톡 payload 는 카카오에 등록된 승인 템플릿의 실제 내용(templateContent·
 * buttons·quickReplies·templateTitle·templateHeader·templateItem·templateItemHighlight·
 * templateRepresentLink)으로 채워야 한다(비즈뿌리오 발송 API 가 완성본을 요구 — templatecode
 * 는 식별용일 뿐 내용을 대신 채워주지 않는다). 이 서비스는 그 원천을 카카오 상세조회
 * (AlimtalkTemplateService::detail)로 가져오되, template_code 단위로 캐시해 rate limit
 * (1000회/합산)을 억제한다.
 *
 * 캐시 정책:
 *  - TTL 은 환경설정 template_cache_ttl(기본 3600초=1시간). 만료되면 다음 조회에서 자동
 *    재조회되므로 카카오 템플릿 변경이 사람 개입 없이 최대 TTL 만큼 지연돼 반영된다.
 *  - TTL=0 이면 캐시를 우회하고 매 조회마다 실시간 조회한다(항상 최신 — 소량 발송·최신성
 *    우선 사이트용).
 *  - 관리자가 카카오에서 템플릿을 방금 바꿔 즉시 반영이 필요하면 clear() 로 수동 무효화한다.
 *
 * 조회 실패(고아 template_code·카카오 장애·rate limit)는 예외를 삼키고 null 을 반환한다.
 * 호출측(AlimtalkChannelDriver)이 null 을 받으면 알림톡 발송을 skip 하고 로그만 남긴다
 * (발송이 접수되지 않으므로 SMS 대체발송은 트리거되지 않는다).
 */
class KakaoTemplateContentResolver
{
    /** 플러그인 식별자 (manifest 와 일치) */
    private const PLUGIN_IDENTIFIER = 'sirsoft-message_bizppurio';

    /** 템플릿 내용 캐시 키 접두사 (확장 도메인 캐시 접두사가 추가로 적용됨) */
    private const CACHE_KEY_PREFIX = 'bizppurio:tpl_content:';

    /** 템플릿 내용 캐시 시간 기본값(분) — 1시간 */
    private const DEFAULT_CACHE_MINUTES = 60;

    /** rate limit(429/5002) 시 재시도 횟수 — 조회 폭주 완화(1회만, 무한 방지) */
    private const RATE_LIMIT_RETRIES = 1;

    /** rate limit 재시도 전 대기(마이크로초) — 0.2초 */
    private const RATE_LIMIT_RETRY_WAIT_US = 200000;

    /**
     * @param  AlimtalkTemplateService  $templates  카카오 상세조회 위임
     * @param  CacheInterface  $cache  확장 도메인 캐시(contextual binding 주입)
     * @param  PluginSettingsService  $pluginSettings  캐시 TTL 설정 조회
     */
    public function __construct(
        private readonly AlimtalkTemplateService $templates,
        private readonly CacheInterface $cache,
        private readonly PluginSettingsService $pluginSettings,
    ) {}

    /**
     * 카카오 승인 템플릿의 발송용 내용을 조회합니다.
     *
     * TTL>0 이면 캐시 우선(없으면 조회 후 캐시), TTL=0 이면 캐시 우회(매번 조회).
     * 조회 실패는 null 을 반환한다.
     *
     * @param  string  $templateCode  카카오 템플릿 코드
     * @return array<string, mixed>|null 카카오 상세(templateContent/buttons/…) 또는 실패 시 null
     */
    public function resolve(string $templateCode): ?array
    {
        $ttl = $this->cacheTtl();

        // TTL=0 → 캐시 우회, 매번 실시간 조회.
        if ($ttl <= 0) {
            return $this->fetch($templateCode);
        }

        $cacheKey = self::CACHE_KEY_PREFIX.$templateCode;

        // 캐시 히트면 그대로 반환. 미스면 조회해 캐시.
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $content = $this->fetch($templateCode);

        // 조회 실패(null)는 캐시하지 않는다 — 다음 발송에서 재시도 가능하도록.
        if ($content !== null) {
            $this->cache->put($cacheKey, $content, $ttl);
        }

        return $content;
    }

    /**
     * 특정 템플릿의 캐시를 무효화합니다 (관리자 수동 초기화).
     *
     * @param  string  $templateCode  카카오 템플릿 코드
     */
    public function clear(string $templateCode): void
    {
        $this->cache->forget(self::CACHE_KEY_PREFIX.$templateCode);
    }

    /**
     * 여러 템플릿의 캐시를 한 번에 무효화합니다 (관리자 전체 초기화).
     *
     * 캐시 키가 template_code 단위이므로, 연결된 모든 알림톡 템플릿 코드를 받아 각각 비운다.
     * 다음 발송에서 최신 내용으로 재조회된다.
     *
     * @param  array<int, string>  $templateCodes  카카오 템플릿 코드 목록
     * @return int 비운 캐시 키 수(중복 제거 후)
     */
    public function clearMany(array $templateCodes): int
    {
        $codes = array_values(array_unique(array_filter($templateCodes, static fn ($c) => $c !== '')));

        foreach ($codes as $code) {
            $this->clear((string) $code);
        }

        return count($codes);
    }

    /**
     * 카카오 상세조회를 수행합니다. 실패는 null(예외 삼킴 + 로그).
     *
     * rate limit(HTTP 429 또는 결과코드 5002)은 조회 폭주 상황이므로 짧게 대기 후 1회
     * 재시도한다(무한 방지). 재시도해도 rate limit 이면 null 을 반환해 호출측이 발송을 skip
     * 하게 한다. 그 외 실패(고아 template_code·자격증명 오류 등)는 재시도 없이 null.
     *
     * @param  string  $templateCode  카카오 템플릿 코드
     * @return array<string, mixed>|null 상세 또는 실패 시 null
     */
    private function fetch(string $templateCode): ?array
    {
        for ($attempt = 0; $attempt <= self::RATE_LIMIT_RETRIES; $attempt++) {
            try {
                return $this->templates->detail($templateCode);
            } catch (BizppurioApiException $e) {
                // rate limit 이고 재시도 여유가 남았으면 짧게 대기 후 재시도.
                if ($this->isRateLimited($e) && $attempt < self::RATE_LIMIT_RETRIES) {
                    usleep(self::RATE_LIMIT_RETRY_WAIT_US);

                    continue;
                }

                Log::warning('비즈뿌리오 알림톡 템플릿 내용 조회 실패 — 발송 skip 후보', [
                    'template_code' => $templateCode,
                    'result_code' => $e->getResultCode(),
                    'http_status' => $e->getHttpStatus(),
                    'message' => $e->getMessage(),
                ]);

                return null;
            }
        }

        return null;
    }

    /**
     * 예외가 rate limit(HTTP 429 또는 결과코드 5002)인지 판정합니다.
     *
     * @param  BizppurioApiException  $e  조회 실패 예외
     * @return bool rate limit 이면 true
     */
    private function isRateLimited(BizppurioApiException $e): bool
    {
        return $e->getHttpStatus() === 429 || $e->getResultCode() === '5002';
    }

    /**
     * 캐시 TTL(초)을 환경설정에서 조회합니다 (기본 60분, 0=캐시 끔).
     *
     * 관리자 화면·저장은 "분" 단위(template_cache_minutes)로 관리하고, 캐시 드라이버에는
     * 초 단위가 필요하므로 60 을 곱해 반환한다. 0 이면 캐시를 끄므로 0 그대로 반환한다.
     *
     * @return int TTL(초)
     */
    private function cacheTtl(): int
    {
        $minutes = (int) $this->pluginSettings->get(
            self::PLUGIN_IDENTIFIER,
            'template_cache_minutes',
            self::DEFAULT_CACHE_MINUTES,
        );

        return max(0, $minutes) * 60;
    }
}
