<?php

namespace App\Thumbnails;

use App\Helpers\InlineMediaPolicy;
use App\Thumbnails\DTO\ThumbnailResult;
use App\Thumbnails\DTO\ThumbnailSource;
use App\Thumbnails\Http\ThumbnailFileResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * 이미지 서빙 지점의 썸네일 헬퍼 — 게이트 바로 뒤·원본 응답 바로 앞에서 한 줄로 부릅니다.
 *
 * ```php
 * if ($thumbnail = $this->thumbnails->respond($request, $source)) {
 *     return $thumbnail;
 * }
 * // … 원본 응답
 * return ThumbnailResponder::markBypass($response);
 * ```
 *
 * `size`·`w` 가 없으면 아무 것도 하지 않는다(null). 파라미터가 있는데 썸네일을 낼 수 없으면(꺼짐·엔진 없음·
 * 비래스터·움직이는 이미지(설정이 켜져 있을 때)·미정의 규격·작은 원본·생성 제한·픽셀 초과·오류) null 을 돌려주고 사유를 요청에 남겨,
 * 호출처가 원본 응답에 `X-G7-Thumbnail: bypass:{사유}` 를 붙이게 한다. 게이트는 호출처가 먼저 지났으므로
 * 이 헬퍼는 권한을 다시 보지 않는다.
 */
class ThumbnailResponder
{
    /** 사유를 싣는 요청 속성 */
    public const BYPASS_ATTRIBUTE = 'g7.thumbnail.bypass';

    /** 응답 헤더 */
    public const HEADER = 'X-G7-Thumbnail';

    /** 생성 제한 이름 (서비스 프로바이더가 `RateLimiter::for` 로 정의) */
    public const RATE_LIMITER = 'core.thumbnail.generate';

    /** @var list<string> 원본을 냈지만 그 주소가 원본으로 캐시되면 안 되는 일시적 사유 */
    public const TRANSIENT_BYPASS_REASONS = ['rate-limited', 'error'];

    /** 썸네일을 요청했는데 원본을 낸 응답의 캐시 유지 시간 상한(초) — 1년 */
    public const BYPASS_MAX_AGE_CAP = 31536000;

    /**
     * ThumbnailResponder 생성자
     *
     * @param  ThumbnailPresetRegistry  $registry  규격 레지스트리
     * @param  ThumbnailGenerator  $generator  파생본 준비
     * @param  ThumbnailStore  $store  파생본 저장소
     */
    public function __construct(
        private readonly ThumbnailPresetRegistry $registry,
        private readonly ThumbnailGenerator $generator,
        private readonly ThumbnailStore $store,
    ) {}

    /**
     * 썸네일 응답을 만듭니다 (낼 수 없으면 null — 호출처가 원본을 낸다).
     *
     * @param  Request  $request  요청 (`size`·`w`)
     * @param  ThumbnailSource|null  $source  원본 (null 이면 원본 정보를 만들 수 없는 저장소 — 원본을 낸다)
     * @return Response|null 썸네일 응답(200·304·302) 또는 null
     */
    public function respond(Request $request, ?ThumbnailSource $source): ?Response
    {
        $request->attributes->remove(self::BYPASS_ATTRIBUTE);

        if (! ThumbnailQuery::requested($request)) {
            return null;
        }

        // 준비 단계(캐시·잠금 저장소 장애, 원격 원본 읽기 실패 등)의 예외가 서빙을 500 으로 만들지 않게 —
        // 썸네일은 캐시라 어떤 실패든 원본으로 돌려보낸다.
        try {
            $result = $this->result($request, $source);
        } catch (\Throwable $e) {
            Log::warning('썸네일 준비 실패 — 원본을 냅니다', ['disk' => $source?->disk, 'path' => $source?->path, 'error' => $e->getMessage()]);
            $result = ThumbnailResult::bypass('error');
        }

        if (! $result->served()) {
            $request->attributes->set(self::BYPASS_ATTRIBUTE, $result->reason);

            return null;
        }

        try {
            $response = $this->serve($result, (bool) $source?->gated, $source?->maxAge);
        } catch (\Throwable $e) {
            Log::warning('썸네일 응답 실패 — 원본을 냅니다', ['key' => $result->key, 'error' => $e->getMessage()]);
            $request->attributes->set(self::BYPASS_ATTRIBUTE, 'error');

            return null;
        }

        if ($response === null) {
            $request->attributes->set(self::BYPASS_ATTRIBUTE, 'error');

            return null;
        }

        $response->headers->set(self::HEADER, $result->header());

        return $response;
    }

    /**
     * 직전 `respond()` 가 원본으로 돌려보낸 사유
     *
     * @param  Request|null  $request  요청 (null 이면 현재 요청)
     * @return string|null 사유 (썸네일을 요청하지 않았거나 냈으면 null)
     */
    public static function bypassReason(?Request $request = null): ?string
    {
        $reason = ($request ?? request())->attributes->get(self::BYPASS_ATTRIBUTE);

        return is_string($reason) ? $reason : null;
    }

    /**
     * 원본 응답에 `X-G7-Thumbnail: bypass:{사유}` 를 붙이고 그 `?size=` 주소의 캐시를 맞춥니다 (썸네일을 요청했는데 원본을 낼 때만).
     *
     * - 일시 사유(생성 제한·오류): `no-store`.
     * - 그 밖의 사유(미정의 규격·작은 원본·움직이는 이미지 등): `max-age` 를 썸네일 응답과 같은 값(`cache.layout_ttl`, 원본 통로의
     *   유지 시간과 1년 중 작은 값을 넘지 않음)으로 줄이고 `immutable` 을 `must-revalidate` 로 바꾼다. `Expires` 가 있으면 함께 줄이고,
     *   공유 캐시 유지 시간 `s-maxage` 가 있으면 `max-age` 가 없어도 같은 상한으로 줄인다.
     *   `max-age` 와 `s-maxage` 가 모두 없는 응답(비운영 환경의 `no-cache`)의 유지 시간과 `?size=` 가 없는 원본 응답은 건드리지 않는다.
     *
     * @template T of Response
     *
     * @param  T  $response  원본 응답
     * @param  Request|null  $request  요청 (null 이면 현재 요청)
     * @return T 같은 응답
     */
    public static function markBypass(Response $response, ?Request $request = null): Response
    {
        $reason = self::bypassReason($request);

        if ($reason === null || $response->getStatusCode() >= 400) {
            return $response;
        }

        $response->headers->set(self::HEADER, 'bypass:'.$reason);

        // 생성 제한·일시 오류로 원본을 냈다면 그 `?size=` 주소가 원본으로 캐시되면 안 된다 — 브라우저·앞단 CDN 이
        // 하루 동안 원본을 그 주소의 답으로 쥐고 있게 된다(NAT·CDN 뒤에서는 제한이 쉽게 찬다).
        if (in_array($reason, self::TRANSIENT_BYPASS_REASONS, true)) {
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        // 영구 사유라도 운영자가 규격이나 「움직이는 이미지는 원본 사용」을 바꾸면 같은 `?size=` 주소가 썸네일로 바뀌어야 한다 —
        // 원본 통로의 1년·immutable 을 그대로 두면 브라우저·CDN 이 재확인하지 않아 1년 동안 원본으로 남는다.
        self::alignBypassCache($response);

        return $response;
    }

    /**
     * 우회 원본의 캐시 유지 시간을 썸네일 응답과 같은 값으로 맞춥니다.
     *
     * @param  Response  $response  원본 응답
     * @return void 반환값 없음
     */
    private static function alignBypassCache(Response $response): void
    {
        $headers = $response->headers;
        $immutable = $headers->hasCacheControlDirective('immutable');

        if ($immutable) {
            $headers->removeCacheControlDirective('immutable');
            $headers->addCacheControlDirective('must-revalidate');
        }

        $limit = min((int) g7_core_settings('cache.layout_ttl', 86400), self::BYPASS_MAX_AGE_CAP);

        // 공유 캐시 유지 시간도 같은 값으로 줄인다 — CDN 은 `s-maxage` 를 `max-age` 보다 먼저 따르므로, 이것만 남기면
        // 규격을 바꿔도 그 `?size=` 주소가 앞단에 원래 시간 동안 원본으로 남는다.
        $shared = $headers->getCacheControlDirective('s-maxage');

        if (is_string($shared) && ctype_digit($shared)) {
            $headers->addCacheControlDirective('s-maxage', (string) max(0, min($limit, (int) $shared)));
        }

        $current = $headers->getCacheControlDirective('max-age');

        if (! is_string($current) || ! ctype_digit($current)) {
            return;
        }

        $ttl = max(0, min($limit, (int) $current));
        $headers->addCacheControlDirective('max-age', (string) $ttl);

        if ($headers->has('Expires')) {
            $response->setExpires((new \DateTimeImmutable)->setTimestamp(time() + $ttl));
        }
    }

    /**
     * 파생본을 준비합니다 (생성 제한 포함).
     *
     * @param  Request  $request  요청
     * @param  ThumbnailSource|null  $source  원본
     * @return ThumbnailResult 결과
     */
    private function result(Request $request, ?ThumbnailSource $source): ThumbnailResult
    {
        if (! (bool) config('core.thumbnail.enabled', true)) {
            return ThumbnailResult::bypass('disabled');
        }

        if ($source === null || ! InlineMediaPolicy::isInlineImage($source->mime)) {
            return ThumbnailResult::bypass('not-raster');
        }

        $spec = $this->registry->resolve($request);

        if ($spec === null) {
            return ThumbnailResult::bypass('unknown-preset');
        }

        $existing = $this->generator->prepare($source, $spec, allowGenerate: false);

        if ($existing->served() || $existing->reason !== 'rate-limited') {
            return $existing;
        }

        // 여기부터는 실제 생성 — 미생성 주소를 대량으로 찍어 CPU 를 쓰게 하는 요청을 IP 단위로 막는다.
        if (! $this->allowGeneration($request)) {
            Log::warning('썸네일 생성 제한 초과 — 원본을 냅니다', ['ip' => $request->ip()]);

            return ThumbnailResult::bypass('rate-limited');
        }

        return $this->generator->prepare($source, $spec);
    }

    /**
     * 생성 제한을 한 번 소비합니다.
     *
     * @param  Request  $request  요청
     * @return bool 생성해도 되면 true
     */
    private function allowGeneration(Request $request): bool
    {
        // 한도·기준(IP)은 서비스 프로바이더의 이름 붙은 limiter 가 정한다 — 여기서 같은 값을 다시 계산하지 않는다.
        $resolver = RateLimiter::limiter(self::RATE_LIMITER);
        $limit = $resolver !== null ? $resolver($request) : null;

        if (! $limit instanceof Limit) {
            $limit = Limit::perMinute(max(1, (int) config('core.thumbnail.generate_per_minute', 120)))->by($request->ip() ?? 'unknown');
        }

        $key = self::RATE_LIMITER.':'.$limit->key;

        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            return false;
        }

        RateLimiter::hit($key, $limit->decaySeconds);

        return true;
    }

    /**
     * 준비된 파생본을 응답으로 냅니다 — 비공개 디스크는 스트림, 공개 자산 디스크는 302.
     *
     * @param  ThumbnailResult  $result  준비 결과
     * @param  bool  $gated  권한 원본인지
     * @param  int|null  $maxAge  원본 통로의 캐시 상한(초) — 썸네일이 원본보다 오래 캐시되지 않게
     * @return Response|null 응답
     */
    private function serve(ThumbnailResult $result, bool $gated, ?int $maxAge = null): ?Response
    {
        $disk = (string) $result->disk;
        $key = (string) $result->key;
        $ttl = (int) g7_core_settings('cache.layout_ttl', 86400);

        if ($maxAge !== null) {
            $ttl = max(0, min($ttl, $maxAge));
        }
        $storage = $this->store->storage($disk);

        if (! $gated && $disk !== ThumbnailStore::PRIVATE_DISK && ($url = $storage->url('', $key)) !== null) {
            $redirect = new RedirectResponse($url, 302);
            $redirect->headers->set('Cache-Control', "public, max-age={$ttl}");

            // 스트림 응답과 같은 보안 헤더(nosniff 등)를 단다 — 본문이 없는 302 라도 서빙 응답의 헤더 계약은 하나다.
            // 코어·확장의 썸네일 302 는 모두 이 지점을 지난다.
            return InlineMediaPolicy::harden($redirect, (string) $result->mime);
        }

        $stream = $storage->response('', $key, basename($key), ['Content-Type' => (string) $result->mime]);

        if ($stream === null) {
            return null;
        }

        $modified = (int) $this->store->filesystem($disk)->lastModified($key);

        $response = app(ThumbnailFileResponse::class)->stream(
            $stream,
            $key.'|'.$modified,
            $ttl,
            $modified,
            (string) $result->mime,
            $gated
        );

        return InlineMediaPolicy::harden($response, (string) $result->mime);
    }
}
