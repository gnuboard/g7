<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class TemplateExternals
{
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
     * @param  array<int, mixed>  $externals
     * @return array<int, array<string, mixed>>
     */
    public static function normalize(array $externals): array
    {
        $normalized = [];
        $seen = [];

        foreach ($externals as $external) {
            if (! is_array($external)) {
                continue;
            }

            $item = self::normalizeItem($external);

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

        return $attributes;
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

    private static function normalizeItem(array $external): ?array
    {
        $type = $external['type'] ?? null;
        $url = $external['url'] ?? null;

        if (! is_string($type) || ! in_array($type, self::TYPES, true) || ! self::isHttpsUrl($url)) {
            return null;
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
