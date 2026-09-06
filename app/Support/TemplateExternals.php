<?php

namespace App\Support;

use App\Rules\SafeLayoutExpressions;
use Illuminate\Support\Facades\Log;

class TemplateExternals
{
    /**
     * same-origin 자산 항목에서 무시하는 키.
     *
     * `preconnect`·`dns-prefetch` 는 다른 origin 과의 커넥션을 미리 여는 힌트이고,
     * `crossorigin` 은 교차 출처 요청의 자격 증명 모드를 정하는 속성이다. 자기 서버의
     * 자산에는 둘 다 의미가 없다 — 남겨두면 브라우저가 자기 origin 에 preconnect 를
     * 걸고, 폰트 CSS 에 불필요한 CORS 모드가 붙는다.
     */
    private const EXTERNAL_ONLY_KEYS = ['preconnect', 'crossorigin'];

    /**
     * 자체 제공(same-origin)이 불가능한 타입.
     *
     * 리소스 힌트는 정의상 다른 origin 을 향한다.
     */
    private const EXTERNAL_ONLY_TYPES = ['preconnect', 'dns-prefetch'];

    private const TYPES = [
        'style',
        'webfont',
        'script',
        'preconnect',
        'dns-prefetch',
        'preload',
        'modulepreload',
    ];

    private const SCRIPT_POSITIONS = [
        'head',
        'before-core',
        'before-template',
        'body-end',
    ];

    private const REFERRER_POLICIES = [
        'no-referrer',
        'no-referrer-when-downgrade',
        'origin',
        'origin-when-cross-origin',
        'same-origin',
        'strict-origin',
        'strict-origin-when-cross-origin',
        'unsafe-url',
    ];

    /**
     * externals 선언을 정규화합니다.
     *
     * 항목은 두 형태 중 하나로 자산을 가리킨다:
     *  - `asset`: 템플릿이 **자체 제공**하는 파일의 `dist/` 이하 경로. `AssetUrl::templateAsset()`
     *    이 자산 URL 이중 모드·정적 게시를 반영한 URL 을 만든다. 자체 제공이 원칙이므로 이쪽이 기본.
     *  - `url`: 절대 URL. `https://` 외부 URL 또는 `/` 로 시작하는 same-origin 경로.
     *
     * @param  array<int, mixed>  $externals  template.json 의 externals 배열
     * @param  string|null  $templateIdentifier  `asset` 해석에 필요한 템플릿 식별자
     * @param  int|string|null  $version  캐시 무효화 버전
     * @return array<int, array<string, mixed>>
     */
    public static function normalize(array $externals, ?string $templateIdentifier = null, int|string|null $version = null): array
    {
        $normalized = [];
        $seen = [];

        foreach ($externals as $external) {
            if (! is_array($external)) {
                continue;
            }

            $item = self::normalizeItem($external, $templateIdentifier, $version);

            if ($item === null) {
                continue;
            }

            $key = $item['type'].'|'.$item['url'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $externals
     * @return array<int, array<string, mixed>>
     */
    public static function resourceHints(array $externals): array
    {
        $hints = [];
        $seen = [];

        foreach ($externals as $external) {
            if (($external['type'] ?? null) === 'preconnect') {
                self::appendHint($hints, $seen, 'preconnect', $external['url'], $external['crossorigin'] ?? null);
            }

            if (($external['type'] ?? null) === 'dns-prefetch') {
                self::appendHint($hints, $seen, 'dns-prefetch', $external['url'], null);
            }

            if (! empty($external['preconnect'])) {
                self::appendHint($hints, $seen, 'preconnect', $external['preconnect'], $external['crossorigin'] ?? null);
            }
        }

        return $hints;
    }

    /**
     * @param  array<int, array<string, mixed>>  $externals
     * @return array<int, array<string, mixed>>
     */
    public static function headLinks(array $externals): array
    {
        return array_values(array_filter(
            $externals,
            fn (array $external): bool => in_array($external['type'], ['style', 'webfont', 'preload', 'modulepreload'], true)
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $externals
     * @return array<int, array<string, mixed>>
     */
    public static function scriptsForPosition(array $externals, string $position): array
    {
        return array_values(array_filter(
            $externals,
            fn (array $external): bool => ($external['type'] ?? null) === 'script'
                && ($external['position'] ?? 'before-template') === $position
        ));
    }

    /**
     * @param  array<string, mixed>  $external
     * @return array<string, string|bool>
     */
    public static function linkAttributes(array $external): array
    {
        $attributes = [
            'rel' => self::linkRel($external['type']),
            'href' => $external['url'],
        ];

        self::appendCommonAttributes($attributes, $external);

        if (in_array($external['type'], ['style', 'webfont'], true)) {
            self::appendIfString($attributes, 'media', $external['media'] ?? null);
            $attributes['onerror'] = self::failureHandlerScript($external);
        }

        if ($external['type'] === 'preload') {
            self::appendIfString($attributes, 'as', $external['as'] ?? null);
        }

        if (in_array($external['type'], ['preload', 'modulepreload'], true)) {
            self::appendIfString($attributes, 'type', $external['mimeType'] ?? null);
            self::appendIfString($attributes, 'fetchpriority', $external['fetchpriority'] ?? null);
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $external
     * @return array<string, string|bool>
     */
    public static function scriptAttributes(array $external): array
    {
        $attributes = [
            'src' => $external['url'],
        ];

        self::appendCommonAttributes($attributes, $external);

        if (($external['async'] ?? false) === true) {
            $attributes['async'] = true;
        }

        if (($external['defer'] ?? false) === true) {
            $attributes['defer'] = true;
        }

        $attributes['onerror'] = self::failureHandlerScript($external);

        return $attributes;
    }

    /**
     * 자산 로드 실패를 알리는 인라인 핸들러를 만듭니다.
     *
     * 이 태그들은 서버가 HTML 에 직접 심으므로, 브라우저가 자산에 도달하지 못해도
     * 자바스크립트 쪽에는 아무 신호가 오지 않는다. 실패는 "아이콘이 안 보인다" ·
     * "글꼴이 다르다" 로만 나타나고 자체 서버 로그에도 흔적이 남지 않아 운영자가
     * 원인을 특정할 수 없다. `onerror` 가 그 유일한 신호다.
     *
     * 부팅 초기에는 엔진이 아직 없을 수 있으므로 핸들러는 `template-externals-head`
     * 가 먼저 심어 두는 부트스트랩(대기열)을 부른다.
     *
     * @param  array<string, mixed>  $external  정규화된 externals 항목
     * @return string onerror 속성값
     */
    private static function failureHandlerScript(array $external): string
    {
        $label = self::failureLabel($external);

        return "window.__g7ExternalAssetFailed&&window.__g7ExternalAssetFailed(this,'".$label."')";
    }

    /**
     * 안내 배너에 표시할 짧은 항목명을 만듭니다.
     *
     * 선언된 `id` 가 있으면 그것을, 없으면 URL 의 파일명을 쓴다 — 운영자가 어느 자산이
     * 실패했는지 알아볼 수 있어야 한다.
     *
     * @param  array<string, mixed>  $external  정규화된 externals 항목
     * @return string 항목명 (작은따옴표 안전)
     */
    private static function failureLabel(array $external): string
    {
        $id = $external['id'] ?? null;

        if (is_string($id) && $id !== '') {
            return $id;
        }

        $path = parse_url((string) ($external['url'] ?? ''), PHP_URL_PATH) ?: '';
        $basename = basename($path);

        if ($basename === '') {
            return 'asset';
        }

        return preg_replace('/[^A-Za-z0-9._-]/', '', $basename) ?: 'asset';
    }

    /**
     * @param  array<string, string|bool>  $attributes
     */
    public static function renderAttributes(array $attributes): string
    {
        $html = '';

        foreach ($attributes as $name => $value) {
            if ($value === true) {
                $html .= ' '.e($name);

                continue;
            }

            if (is_string($value) && $value !== '') {
                $html .= ' '.e($name).'="'.e($value).'"';
            }
        }

        return $html;
    }

    /**
     * externals 항목 1건을 정규화합니다.
     *
     * @param  array<string, mixed>  $external  원본 항목
     * @param  string|null  $templateIdentifier  `asset` 해석용 템플릿 식별자
     * @param  int|string|null  $version  캐시 무효화 버전
     * @return array<string, mixed>|null 정규화 결과, 유효하지 않으면 null
     */
    private static function normalizeItem(array $external, ?string $templateIdentifier = null, int|string|null $version = null): ?array
    {
        $type = $external['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            Log::warning('Template external skipped because type is invalid.', [
                'type' => is_scalar($type) ? $type : gettype($type),
            ]);

            return null;
        }

        $resolved = self::resolveUrl($external, $type, $templateIdentifier, $version);

        if ($resolved === null) {
            return null;
        }

        [$url, $isSameOrigin] = $resolved;

        // same-origin 항목에서는 외부 전용 키를 떨어뜨린다 (선언에 남아 있어도 무시)
        if ($isSameOrigin) {
            foreach (self::EXTERNAL_ONLY_KEYS as $key) {
                unset($external[$key]);
            }
        }

        if ($type === 'preload' && empty($external['as'])) {
            Log::warning('Template external preload skipped because as is missing.', ['url' => $url]);

            return null;
        }

        $item = [
            'type' => $type,
            'url' => $url,
        ];

        self::appendId($item, $external['id'] ?? null);
        self::appendCrossorigin($item, $external['crossorigin'] ?? null);
        self::appendIfAllowed($item, 'integrity', $external['integrity'] ?? null, ['style', 'webfont', 'script', 'preload', 'modulepreload'], $type);
        self::appendIfAllowed($item, 'media', $external['media'] ?? null, ['style', 'webfont'], $type);
        self::appendIfAllowed($item, 'as', $external['as'] ?? null, ['preload'], $type);
        self::appendIfAllowed($item, 'mimeType', $external['mimeType'] ?? null, ['preload', 'modulepreload'], $type);

        if (isset($external['referrerpolicy'])
            && is_string($external['referrerpolicy'])
            && in_array($external['referrerpolicy'], self::REFERRER_POLICIES, true)
            && in_array($type, ['style', 'webfont', 'script', 'preload', 'modulepreload'], true)
        ) {
            $item['referrerpolicy'] = $external['referrerpolicy'];
        }

        if (isset($external['fetchpriority'])
            && is_string($external['fetchpriority'])
            && in_array($external['fetchpriority'], ['high', 'low', 'auto'], true)
            && in_array($type, ['preload', 'modulepreload'], true)
        ) {
            $item['fetchpriority'] = $external['fetchpriority'];
        }

        if (isset($external['preconnect'])
            && is_string($external['preconnect'])
            && self::isHttpsUrl($external['preconnect'])
            && in_array($type, ['style', 'webfont', 'script', 'preload', 'modulepreload'], true)
        ) {
            $item['preconnect'] = $external['preconnect'];
        }

        if ($type === 'script') {
            if (($external['async'] ?? false) === true && ($external['defer'] ?? false) === true) {
                Log::warning('Template external script skipped because async and defer are both true.', ['url' => $url]);

                return null;
            }

            if (isset($external['position']) && ! in_array($external['position'], self::SCRIPT_POSITIONS, true)) {
                Log::warning('Template external script skipped because position is invalid.', ['url' => $url]);

                return null;
            }

            $item['position'] = $external['position'] ?? 'before-template';
            $item['async'] = ($external['async'] ?? false) === true;
            $item['defer'] = ($external['defer'] ?? false) === true;
        }

        return $item;
    }

    /**
     * 항목이 가리키는 자산 URL 을 해석합니다.
     *
     * 종전에는 `https://` 로 시작하지 않는 항목을 **로그 없이 버렸다**. 그래서 자체 제공
     * 경로를 적으면 자산이 조용히 사라지고, 선언은 파일에 남아 있어 원인을 찾을 수 없었다.
     *
     * @param  array<string, mixed>  $external  원본 항목
     * @param  string  $type  항목 타입
     * @param  string|null  $templateIdentifier  `asset` 해석용 템플릿 식별자
     * @param  int|string|null  $version  캐시 무효화 버전
     * @return array{0: string, 1: bool}|null [URL, same-origin 여부], 유효하지 않으면 null
     */
    private static function resolveUrl(array $external, string $type, ?string $templateIdentifier, int|string|null $version): ?array
    {
        $asset = $external['asset'] ?? null;

        if (is_string($asset) && $asset !== '') {
            if (in_array($type, self::EXTERNAL_ONLY_TYPES, true)) {
                Log::warning('Template external skipped because asset is not applicable to this type.', [
                    'type' => $type,
                    'asset' => $asset,
                ]);

                return null;
            }

            if ($templateIdentifier === null || $templateIdentifier === '') {
                Log::warning('Template external skipped because template identifier is unknown.', [
                    'asset' => $asset,
                ]);

                return null;
            }

            if (! self::isSafeAssetPath($asset)) {
                Log::warning('Template external skipped because asset path is unsafe.', [
                    'asset' => $asset,
                ]);

                return null;
            }

            if (isset($external['url'])) {
                Log::warning('Template external url is ignored because asset takes precedence.', [
                    'asset' => $asset,
                    'url' => is_scalar($external['url']) ? $external['url'] : gettype($external['url']),
                ]);
            }

            return [AssetUrl::templateAsset($templateIdentifier, $asset, $version), true];
        }

        $url = $external['url'] ?? null;

        if (self::isHttpsUrl($url)) {
            return [$url, false];
        }

        if (is_string($url) && self::isSameOriginPath($url)) {
            if (in_array($type, self::EXTERNAL_ONLY_TYPES, true)) {
                Log::warning('Template external skipped because same-origin url is not applicable to this type.', [
                    'type' => $type,
                    'url' => $url,
                ]);

                return null;
            }

            return [$url, true];
        }

        Log::warning('Template external skipped because url is neither https nor a same-origin path.', [
            'type' => $type,
            'url' => is_scalar($url) ? $url : gettype($url),
        ]);

        return null;
    }

    /**
     * `asset` 경로가 안전한지 판정합니다.
     *
     * 서버측 자산 서빙이 다시 검증하지만, URL 을 만들기 전에 걸러야 잘못된 선언이
     * 페이지에 실려 나가지 않는다.
     *
     * @param  string  $path  `dist/` 이하 상대 경로
     * @return bool 안전하면 true
     */
    private static function isSafeAssetPath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        return preg_match('#^[A-Za-z0-9._\-/]+$#', $path) === 1;
    }

    /**
     * same-origin path-only URL 인지 판정합니다.
     *
     * 접두 문자열만 보면 `/\evil.com/x.css` 같은 값이 통과한다 — 브라우저는 그것을
     * 외부 origin 으로 해석한다. 저장측·런타임·정적검사가 공유하는 정규화(SSoT:
     * `TrustedScriptHosts::normalizeForOriginCheck`)를 거친 뒤 판정한다.
     *
     * @param  string  $url  검사 대상 URL
     * @return bool same-origin 경로면 true
     */
    private static function isSameOriginPath(string $url): bool
    {
        $normalized = SafeLayoutExpressions::normalizeForOriginCheck($url);

        if (str_starts_with($normalized, '//')) {
            return false;
        }

        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $normalized) === 1) {
            return false;
        }

        return str_starts_with($normalized, '/');
    }

    /**
     * @param  array<int, array<string, mixed>>  $hints
     * @param  array<string, bool>  $seen
     */
    private static function appendHint(array &$hints, array &$seen, string $type, string $url, mixed $crossorigin): void
    {
        $key = $type.'|'.$url;

        if (isset($seen[$key])) {
            return;
        }

        $hint = [
            'type' => $type,
            'url' => $url,
        ];

        self::appendCrossorigin($hint, $crossorigin);

        $seen[$key] = true;
        $hints[] = $hint;
    }

    private static function linkRel(string $type): string
    {
        return match ($type) {
            'preconnect' => 'preconnect',
            'dns-prefetch' => 'dns-prefetch',
            'preload' => 'preload',
            'modulepreload' => 'modulepreload',
            default => 'stylesheet',
        };
    }

    private static function appendCommonAttributes(array &$attributes, array $external): void
    {
        self::appendIfString($attributes, 'id', $external['id'] ?? null);
        self::appendIfString($attributes, 'crossorigin', $external['crossorigin'] ?? null);
        self::appendIfString($attributes, 'integrity', $external['integrity'] ?? null);
        self::appendIfString($attributes, 'referrerpolicy', $external['referrerpolicy'] ?? null);
    }

    private static function appendId(array &$item, mixed $value): void
    {
        if (is_string($value) && preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1) {
            $item['id'] = $value;
        }
    }

    private static function appendCrossorigin(array &$item, mixed $value): void
    {
        if ($value === true) {
            $item['crossorigin'] = 'anonymous';

            return;
        }

        if (in_array($value, ['anonymous', 'use-credentials'], true)) {
            $item['crossorigin'] = $value;
        }
    }

    private static function appendIfAllowed(array &$item, string $key, mixed $value, array $allowedTypes, string $type): void
    {
        if (is_string($value) && in_array($type, $allowedTypes, true)) {
            $item[$key] = $value;
        }
    }

    private static function appendIfString(array &$attributes, string $key, mixed $value): void
    {
        if (is_string($value) && $value !== '') {
            $attributes[$key] = $value;
        }
    }

    private static function isHttpsUrl(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, 'https://');
    }
}
