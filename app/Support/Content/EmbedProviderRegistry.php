<?php

namespace App\Support\Content;

use App\Extension\HookManager;
use App\Rules\EmbedCustomProviderRule;
use Illuminate\Support\Facades\Log;

/**
 * 외부 영상 서비스 목록 — 코어 프리셋·운영자 직접 추가·확장 제공을 한 형식으로 합친다 (#883 5단계, D16)
 *
 * 세 출처 모두 「주소에서 꺼낸 id 로 정해진 https 플레이어 주소를 조립」하는 한 방식만 허용한다 — 자유 HTML·임의 iframe 은 없다.
 * 주소 형식은 정규식 대신 `*`(경로 구분자 `/` 를 뺀 아무 글자)와 `{id}`(영상 id 자리 1개)만 쓰는 간단 형식이고, 여기서 정규식으로
 * 바꿔 서버(봇 정화·저장 정화)와 클라이언트(표시·편집기 미리보기)가 **같은 정규식**을 쓴다.
 *
 * 우선순위: 같은 주소가 여러 서비스에 맞으면 프리셋 → 직접 추가 → 확장 순서로 첫 번째. 확장 제공분은 운영자가 켜야(`embed_presets` 의
 * `ext:{확장}:{id}` 키) 쓰이고, 검증(`EmbedCustomProviderRule::validateEntry`)을 통과하지 못하면 경고 로그 뒤 버린다.
 *
 * @since 7.1.5
 */
final class EmbedProviderRegistry
{
    /** 확장 제공 서비스 필터 훅 */
    public const FILTER_HOOK = 'core.content.filter_embed_providers';

    /** id 글자 규칙 → 정규식 조각 */
    public const ID_RULES = [
        'alnum' => '[A-Za-z0-9]{1,64}',
        'alnum_dash' => '[A-Za-z0-9_-]{1,64}',
        'digits' => '[0-9]{1,64}',
    ];

    /** 화면 비율 */
    public const ASPECTS = ['16:9', '9:16', '4:3', '1:1'];

    /** iframe 허용 권한 후보 */
    public const ALLOW_PERMISSIONS = ['fullscreen', 'autoplay', 'encrypted-media', 'picture-in-picture'];

    /** iframe 허용 권한 기본값 */
    public const DEFAULT_ALLOW = ['fullscreen', 'encrypted-media', 'picture-in-picture'];

    /**
     * 코어 프리셋 3종 — id => [name, patterns, player_url, id_rule(정규식 조각), aspect, aspect_by_pattern?]
     *
     * 서비스 공식 문서에 iframe 플레이어 주소가 있는 서비스만 둔다(YouTube 퍼가기·Vimeo 플레이어·TikTok Embed Player). 공식 iframe
     * 주소가 없거나(script 방식 퍼가기, 계정별 플레이어 ID 필요) 서비스를 끝낸 곳은 프리셋으로 두지 않고 운영자가 「직접 추가」로 넣는다 —
     * 서비스마다의 재생 정책(지역·로그인·사이트 주소 제한)은 코어가 따라가지 않는다. 주소 형식·플레이어 주소는 그 공식 형식이다. 서비스가 형식을 바꾸면 운영자는 같은 서비스를 「직접 추가」로 고쳐
     * 쓸 수 있고, 코어는 다음 패치에서 프리셋을 고친다.
     *
     * @return array<string, array<string, mixed>> 프리셋
     */
    public static function presets(): array
    {
        return [
            'youtube' => [
                'name' => 'YouTube',
                'patterns' => [
                    'https://*youtube.com/watch?v={id}',
                    'https://youtu.be/{id}',
                    'https://*youtube.com/shorts/{id}',
                    'https://*youtube.com/embed/{id}',
                ],
                'player_url' => 'https://www.youtube-nocookie.com/embed/{id}',
                'id_regex' => '[A-Za-z0-9_-]{11}',
                'aspect' => '16:9',
                'aspect_overrides' => [2 => '9:16'],
            ],
            'vimeo' => [
                'name' => 'Vimeo',
                'patterns' => ['https://vimeo.com/{id}', 'https://player.vimeo.com/video/{id}'],
                'player_url' => 'https://player.vimeo.com/video/{id}',
                'id_regex' => self::ID_RULES['digits'],
                'aspect' => '16:9',
            ],
            'tiktok' => [
                'name' => 'TikTok',
                'patterns' => ['https://*tiktok.com/@*/video/{id}'],
                'player_url' => 'https://www.tiktok.com/player/v1/{id}',
                'id_regex' => self::ID_RULES['digits'],
                'aspect' => '9:16',
            ],
        ];
    }

    /**
     * 간단 주소 형식을 정규식으로 바꿉니다 — PHP·JS 가 같은 문자열을 쓴다(구분자 없음, 명명 그룹 `id`).
     *
     * `https://` 는 `https?://` 로 받아 들이고, `*` 는 `[^/?#]*`, `{id}` 는 id 정규식 조각이다. 끝에는 쿼리·조각(`?…`·`&…`·`#…`)을 허용한다.
     *
     * @param  string  $pattern  간단 주소 형식
     * @param  string  $idRegex  id 정규식 조각
     * @return string 정규식 본문 (예: `^https?://(?:[^/?#]*)youtube\.com/watch\?v=(?<id>…)(?:[?&#].*)?$`)
     */
    public static function compilePattern(string $pattern, string $idRegex): string
    {
        $body = preg_replace('#^https?://#i', '', trim($pattern)) ?? '';
        $out = '';
        $start = 0;
        // 호스트 맨 앞 * 는 하위 도메인 자리(없거나 `www.`·`m.` 등)만 — `notyoutube.com` 같은 남의 호스트가 맞지 않게
        if (str_starts_with($body, '*')) {
            $out = '(?:[^/?#.]+\.)*';
            $start = str_starts_with($body, '*.') ? 2 : 1;
        }
        $length = strlen($body);
        for ($i = $start; $i < $length; $i++) {
            if (substr($body, $i, 4) === '{id}') {
                $out .= '(?<id>'.$idRegex.')';
                $i += 3;

                continue;
            }
            $char = $body[$i];
            $out .= $char === '*' ? '[^/?#]*' : self::escape($char);
        }

        return '^https?://'.$out.'/?(?:[?&#].*)?$';
    }

    /**
     * 정규식 메타 문자 하나를 이스케이프합니다(JS·PHP 공통 — `-`·`:` 등은 그대로 둔다).
     *
     * @param  string  $char  글자
     * @return string 이스케이프한 글자
     */
    private static function escape(string $char): string
    {
        return str_contains('.^$+?()[]{}|\\/', $char) ? '\\'.$char : $char;
    }

    /**
     * 설정에 따라 쓰는 서비스 목록 — 켜진 프리셋 + 사용 중인 직접 추가 + 켜진 확장 제공.
     *
     * @param  array<string, mixed>  $settings  코어 「본문 콘텐츠」 설정
     * @return array<int, array{id: string, name: string, source: string, patterns: array<int, string>, aspects: array<int, string>, player_url: string, aspect: string, allow: array<int, string>}> 서비스
     */
    public static function enabled(array $settings): array
    {
        $toggles = is_array($settings['embed_presets'] ?? null) ? $settings['embed_presets'] : [];
        $providers = [];

        foreach (self::presets() as $id => $preset) {
            if (! empty($toggles[$id])) {
                $providers[] = self::fromPreset($id, $preset);
            }
        }

        foreach (is_array($settings['embed_custom_providers'] ?? null) ? $settings['embed_custom_providers'] : [] as $entry) {
            if (is_array($entry) && ! empty($entry['enabled']) && EmbedCustomProviderRule::validateEntry($entry, true) === []) {
                $providers[] = self::fromEntry($entry, 'custom', (string) $entry['id']);
            }
        }

        foreach (self::extensionProviders() as $key => $entry) {
            if (! empty($toggles[$key])) {
                $providers[] = self::fromEntry($entry, 'extension', $key);
            }
        }

        return $providers;
    }

    /**
     * 확장이 제공한 서비스(검증 통과분) — `ext:{확장}:{id}` => 항목.
     *
     * @return array<string, array<string, mixed>> 확장 제공 서비스
     */
    public static function extensionProviders(): array
    {
        try {
            $raw = HookManager::applyFilters(self::FILTER_HOOK, []);
        } catch (\Throwable) {
            $raw = [];
        }
        $result = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (! is_array($entry) || ! isset($entry['extension'], $entry['id'])) {
                continue;
            }
            $errors = EmbedCustomProviderRule::validateEntry($entry, true);
            if ($errors !== []) {
                Log::warning('확장이 등록한 외부 영상 서비스가 검증을 통과하지 못해 버림', ['entry' => $entry['id'], 'errors' => $errors]);

                continue;
            }
            $result['ext:'.$entry['extension'].':'.$entry['id']] = $entry;
        }

        return $result;
    }

    /**
     * 확장 제공 서비스의 켜고 끄기 목록 — 환경설정 「본문 콘텐츠」 탭이 `embed_presets` 의 `ext:{확장}:{id}` 키를 그린다.
     *
     * @return array<int, array{key: string, extension: string, id: string, name: string}> 선택지
     */
    public static function extensionProviderOptions(): array
    {
        $options = [];
        foreach (self::extensionProviders() as $key => $entry) {
            $options[] = ['key' => $key, 'extension' => (string) $entry['extension'], 'id' => (string) $entry['id'], 'name' => (string) ($entry['name'] ?? $entry['id'])];
        }

        return $options;
    }

    /**
     * 주소를 해석합니다 — 맞는 첫 서비스와 꺼낸 id, 조립한 플레이어 주소.
     *
     * @param  string  $url  원본 주소
     * @param  array<int, array<string, mixed>>  $providers  서비스 목록 (`enabled()` 결과)
     * @return array{provider: array<string, mixed>, id: string, player_url: string, aspect: string}|null 해석 결과
     */
    public static function resolve(string $url, array $providers): ?array
    {
        foreach ($providers as $provider) {
            foreach ($provider['patterns'] as $index => $regex) {
                if (preg_match('#'.str_replace('#', '\#', $regex).'#i', $url, $m) === 1 && isset($m['id'])) {
                    return [
                        'provider' => $provider,
                        'id' => $m['id'],
                        'player_url' => str_replace('{id}', rawurlencode($m['id']), $provider['player_url']),
                        'aspect' => $provider['aspects'][$index] ?? $provider['aspect'],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * 저장 전 입력값으로 예시 주소를 해석합니다 — 「서비스 추가」 창의 예시 주소 확인. 저장 때와 같은 컴파일 규칙이다.
     *
     * @param  array<string, mixed>  $entry  입력값 (url_patterns·player_url·id_rule)
     * @param  string  $sampleUrl  예시 주소
     * @return array{matched: bool, id: string|null, player_url: string|null, patterns: array<int, string>} 결과
     */
    public static function preview(array $entry, string $sampleUrl): array
    {
        $entry += ['id' => 'preview', 'name' => 'preview', 'enabled' => true];
        $provider = self::fromEntry($entry, 'custom', 'preview');
        $resolved = self::resolve(trim($sampleUrl), [$provider]);

        return [
            'matched' => $resolved !== null,
            'id' => $resolved['id'] ?? null,
            'player_url' => $resolved['player_url'] ?? null,
            'patterns' => $provider['patterns'],
        ];
    }

    /**
     * 화면용 목록 — 컴파일한 정규식·플레이어 주소 형식만(원본 HTML 없음).
     *
     * @param  array<string, mixed>  $settings  코어 설정
     * @return array<int, array<string, mixed>> 서비스
     */
    public static function toFrontendArray(array $settings): array
    {
        return array_map(fn (array $p) => [
            'id' => $p['id'],
            'name' => $p['name'],
            'source' => $p['source'],
            'patterns' => $p['patterns'],
            'aspects' => $p['aspects'],
            'player_url' => $p['player_url'],
            'aspect' => $p['aspect'],
            'allow' => $p['allow'],
        ], self::enabled($settings));
    }

    /**
     * 프리셋을 공통 형식으로.
     *
     * @param  string  $id  프리셋 id
     * @param  array<string, mixed>  $preset  프리셋
     * @return array<string, mixed> 서비스
     */
    private static function fromPreset(string $id, array $preset): array
    {
        $patterns = array_map(fn ($p) => self::compilePattern($p, $preset['id_regex']), $preset['patterns']);
        $aspects = [];
        foreach ($patterns as $i => $_) {
            $aspects[$i] = $preset['aspect_overrides'][$i] ?? $preset['aspect'];
        }

        return [
            'id' => $id,
            'name' => $preset['name'],
            'source' => 'preset',
            'patterns' => $patterns,
            'aspects' => $aspects,
            'player_url' => $preset['player_url'],
            'aspect' => $preset['aspect'],
            'allow' => self::DEFAULT_ALLOW,
        ];
    }

    /**
     * 직접 추가·확장 제공 항목을 공통 형식으로.
     *
     * @param  array<string, mixed>  $entry  항목
     * @param  string  $source  출처 (custom|extension)
     * @param  string  $id  식별자
     * @return array<string, mixed> 서비스
     */
    private static function fromEntry(array $entry, string $source, string $id): array
    {
        $idRegex = self::ID_RULES[$entry['id_rule'] ?? 'alnum_dash'] ?? self::ID_RULES['alnum_dash'];
        $patterns = array_map(fn ($p) => self::compilePattern((string) $p, $idRegex), array_values($entry['url_patterns']));
        $aspect = in_array($entry['aspect'] ?? null, self::ASPECTS, true) ? (string) $entry['aspect'] : '16:9';
        $allow = array_values(array_intersect(is_array($entry['allow'] ?? null) ? $entry['allow'] : self::DEFAULT_ALLOW, self::ALLOW_PERMISSIONS));

        return [
            'id' => $id,
            'name' => (string) ($entry['name'] ?? $id),
            'source' => $source,
            'patterns' => $patterns,
            'aspects' => array_fill(0, count($patterns), $aspect),
            'player_url' => (string) $entry['player_url'],
            'aspect' => $aspect,
            'allow' => $allow,
        ];
    }
}
