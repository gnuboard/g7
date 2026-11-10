<?php

namespace App\Rules;

use App\Support\Content\EmbedProviderRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 운영자가 직접 추가한 외부 영상 서비스 목록 검증 (#883 5단계, D16)
 *
 * 항목마다 `id`(영문 소문자 시작 2~32자, 프리셋·다른 항목과 중복 금지), `name`(1~40자), `url_patterns`(1~5개 — `http(s)://` 로 시작,
 * `{id}` 정확히 1개, 호스트의 `*` 는 맨 앞 하위 도메인 자리 `*.example.com` 만), `player_url`(**https 만**, `{id}` 정확히 1개, 이 사이트
 * 자신의 호스트 금지), `id_rule`·`aspect`·`allow`(정해진 값만)를 본다. 최대 30개. 위반은 항목 번호·필드별 문구로 알린다.
 *
 * @since 7.1.5
 */
class EmbedCustomProviderRule implements ValidationRule
{
    /** 최대 항목 수 */
    public const MAX_PROVIDERS = 30;

    /**
     * 목록을 검증합니다.
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }
        if (! is_array($value)) {
            $fail(__('validation.settings.embed_provider_invalid', ['index' => 1, 'field' => 'list']));

            return;
        }
        if (count($value) > self::MAX_PROVIDERS) {
            $fail(__('validation.settings.embed_provider_too_many', ['max' => self::MAX_PROVIDERS]));

            return;
        }

        $seen = array_fill_keys(array_keys(EmbedProviderRegistry::presets()), true);
        foreach (array_values($value) as $i => $entry) {
            $index = $i + 1;
            if (! is_array($entry)) {
                $fail(__('validation.settings.embed_provider_invalid', ['index' => $index, 'field' => 'entry']));

                continue;
            }
            foreach (self::validateEntry($entry, false) as $field => $key) {
                $fail(__($key, ['index' => $index, 'field' => $field]));
            }
            $id = (string) ($entry['id'] ?? '');
            if ($id !== '' && isset($seen[$id])) {
                $fail(__('validation.settings.embed_provider_id_duplicate', ['index' => $index, 'id' => $id]));
            }
            $seen[$id] = true;
        }
    }

    /**
     * 항목 하나를 검증합니다 — 위반 필드 => 문구 키 (없으면 빈 배열). 확장 제공 항목 검증에도 쓴다.
     *
     * @param  array<string, mixed>  $entry  항목
     * @param  bool  $skipName  이름 검사를 건너뛸지(확장 제공은 이름이 다국어일 수 있음)
     * @return array<string, string> 위반
     */
    public static function validateEntry(array $entry, bool $skipName): array
    {
        $errors = [];

        if (! is_string($entry['id'] ?? null) || preg_match('/^[a-z][a-z0-9_]{1,31}$/', $entry['id']) !== 1) {
            $errors['id'] = 'validation.settings.embed_provider_id_format';
        }

        $name = $entry['name'] ?? null;
        if (! $skipName && (! is_string($name) || mb_strlen(trim($name)) < 1 || mb_strlen($name) > 40)) {
            $errors['name'] = 'validation.settings.embed_provider_name';
        }

        $patterns = $entry['url_patterns'] ?? null;
        if (! is_array($patterns) || count($patterns) < 1 || count($patterns) > 5) {
            $errors['url_patterns'] = 'validation.settings.embed_provider_patterns_count';
        } else {
            foreach ($patterns as $pattern) {
                if (! self::validUrlPattern($pattern)) {
                    $errors['url_patterns'] = 'validation.settings.embed_provider_pattern_format';

                    break;
                }
            }
        }

        $player = $entry['player_url'] ?? null;
        if (! is_string($player) || ! str_starts_with($player, 'https://') || substr_count($player, '{id}') !== 1) {
            $errors['player_url'] = 'validation.settings.embed_provider_player_https';
        } else {
            $host = strtolower((string) parse_url(str_replace('{id}', 'x', $player), PHP_URL_HOST));
            $siteHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
            if ($host === '' || str_contains($host, '*') || ($siteHost !== '' && $host === $siteHost)) {
                $errors['player_url'] = 'validation.settings.embed_provider_player_host';
            }
        }

        // 사용 여부는 참/거짓만 받는다 — 문자열 "false" 를 받으면 레지스트리가 켜진 것으로 읽는다
        if (array_key_exists('enabled', $entry) && ! is_bool($entry['enabled'])) {
            $errors['enabled'] = 'validation.settings.embed_provider_option';
        }

        if (isset($entry['id_rule']) && ! array_key_exists($entry['id_rule'], EmbedProviderRegistry::ID_RULES)) {
            $errors['id_rule'] = 'validation.settings.embed_provider_option';
        }
        if (isset($entry['aspect']) && ! in_array($entry['aspect'], EmbedProviderRegistry::ASPECTS, true)) {
            $errors['aspect'] = 'validation.settings.embed_provider_option';
        }
        if (isset($entry['allow']) && (! is_array($entry['allow']) || array_diff($entry['allow'], EmbedProviderRegistry::ALLOW_PERMISSIONS) !== [])) {
            $errors['allow'] = 'validation.settings.embed_provider_option';
        }

        return $errors;
    }

    /**
     * 주소 형식 한 줄이 규칙에 맞는지.
     *
     * @param  mixed  $pattern  주소 형식
     * @return bool 맞으면 true
     */
    private static function validUrlPattern(mixed $pattern): bool
    {
        if (! is_string($pattern) || preg_match('#^https?://#i', $pattern) !== 1 || substr_count($pattern, '{id}') !== 1 || strlen($pattern) > 255) {
            return false;
        }

        $rest = preg_replace('#^https?://#i', '', $pattern);
        $host = explode('/', (string) $rest, 2)[0];
        if ($host === '' || str_contains($host, '{id}')) {
            return false;
        }
        // 호스트의 * 는 맨 앞 하위 도메인 자리만 (`*.example.com` 또는 `*example.com`)
        $star = strpos($host, '*');
        if ($star !== false && ($star !== 0 || substr_count($host, '*') > 1)) {
            return false;
        }

        return preg_match('/^\*?\.?[a-z0-9.-]+\.[a-z]{2,}$/i', $host) === 1;
    }
}
