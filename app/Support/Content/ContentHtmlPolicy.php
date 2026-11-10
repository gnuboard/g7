<?php

namespace App\Support\Content;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;

/**
 * 본문 HTML 규칙 — 표시기·정화기·편집기가 함께 쓰는 허용 태그·속성·미디어 출처·외부 영상 (#883 5단계, D17~D21)
 *
 * 규칙은 코어 환경설정 「본문 콘텐츠」(`content` 카테고리)에 둔다 — 편집기 설정에 두면 표시기·정화기가 편집기에 의존하고, 편집기를
 * 바꾸면 표시 규칙이 사라지기 때문이다. 소비자: 두 템플릿 HtmlContent(`G7Core.contentPolicy`), 코어 봇 정화기(`HtmlSanitizer`),
 * 이커머스 저장 정화기(`PurifiesHtml`), 편집기 플러그인. 아무도 태그 목록을 하드코딩하지 않는다.
 *
 * - 안전 후보(켜고 끔): 그림 캡션(figure·figcaption), 업로드 동영상(video·source·track), 업로드 오디오(audio·source), 외부 영상 삽입(oembed)
 * - 끈 후보 중 브라우저가 원래 받는 태그(figcaption)는 표시 단계에서 내용째 지운다(`drop_tags`) — 저장 원문·편집기는 그대로라 다시 켜면 복원 (#887)
 * - 외부 영상 삽입을 끄면 `oembed` 는 원래 주소 nofollow 링크로 보인다(사람 화면은 런타임 `renderEmbeds`, 봇 화면은 `finalize('bot')`)
 * - 운영자 자유 추가(`custom_tags`): D19 거절 목록만 막고 나머지는 경고 후 허용
 * - 미디어 `src`·`poster` 는 서빙 주소 패턴에 맞아야 남는다 — 패턴은 확장이 등록한 실제 서빙 라우트에서 만든다(`MediaSourceRoutes`, #887)
 *   + 필터 훅 `core.content.filter_media_source_patterns` 추가분
 * - 저장용 규칙(`forStorage()`)은 후보 스위치·외부 영상 켜고 끄기와 무관하다 — 켜고 끄기는 표시에서만 따른다(#887)
 * - 속성 정화(`on*`·`javascript:`)는 늘 한다
 *
 * @since 7.1.5
 */
final class ContentHtmlPolicy
{
    /** 미디어 출처 패턴 필터 훅 */
    public const MEDIA_SOURCE_FILTER_HOOK = 'core.content.filter_media_source_patterns';

    /**
     * 안전 후보 — 설정 키 => [태그 => 허용 속성]
     */
    public const CANDIDATES = [
        'allow_figure_caption' => [
            'figure' => ['class'],
            'figcaption' => ['class'],
        ],
        'allow_uploaded_video' => [
            'video' => ['src', 'controls', 'preload', 'width', 'height', 'playsinline', 'poster'],
            'source' => ['src', 'type'],
            'track' => ['src', 'kind', 'srclang', 'label', 'default'],
        ],
        'allow_uploaded_audio' => [
            'audio' => ['src', 'controls', 'preload'],
            'source' => ['src', 'type'],
        ],
        'allow_external_embed' => [
            'oembed' => ['url'],
            'figure' => ['class'],
        ],
    ];

    /**
     * 후보를 끄면 표시(사람·봇 화면)에서 내용째 지울 태그 — 설정 키 => 태그 (#887).
     *
     * figcaption 은 기본 금지 목록에 없어(브라우저·DOMPurify 가 원래 받는 태그) 후보를 꺼도 그대로 보였다. 금지 목록(`forbid_tags`)에 넣으면
     * 편집기(GHS `disallow`)가 저장 단계에서 캡션을 지워 다시 켜도 복원되지 않으므로 별도 목록으로 내린다. 내용째 지우는 이유는 금지 태그의
     * 기본 동작(태그만 벗기고 글자는 남김)이면 캡션 글자가 그림 아래 맨 글자로 남기 때문이다. figure 는 그림을 감싸므로 지우지 않는다.
     */
    public const DISPLAY_DROP_WHEN_OFF = [
        'allow_figure_caption' => ['figcaption'],
    ];

    /**
     * 기본 금지 태그 — 두 템플릿 HtmlContent 의 FORBID 목록 그대로(engine 이전부터의 기준).
     * 켜진 후보 태그는 여기서 빠진다.
     */
    public const BASE_FORBID_TAGS = [
        'script', 'noscript',
        'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'portal',
        'form', 'input', 'textarea', 'select', 'button',
        'style', 'link', 'meta', 'base',
        'body', 'head', 'html', 'title',
        'svg', 'math',
        'audio', 'video', 'source', 'track', 'canvas',
        'details', 'dialog',
        'plaintext', 'xmp', 'listing',
        'marquee', 'noframes', 'noembed', 'template', 'slot',
    ];

    /**
     * 운영자 자유 추가에서 절대 받지 않는 태그 — 태그 => 사유 키 (D19 목록 그대로).
     * svg·math·details·canvas 등은 여기에 넣지 않는다(허용 + 「보안 위험」 경고).
     */
    public const REJECTED_CUSTOM_TAGS = [
        'script' => 'script', 'noscript' => 'script',
        'style' => 'style', 'link' => 'style', 'meta' => 'document', 'base' => 'document',
        'html' => 'document', 'head' => 'document', 'body' => 'document', 'template' => 'document',
        'object' => 'embed', 'embed' => 'embed', 'applet' => 'embed', 'frame' => 'embed', 'frameset' => 'embed', 'iframe' => 'embed',
        'form' => 'form', 'input' => 'form', 'textarea' => 'form', 'select' => 'form', 'option' => 'form', 'button' => 'form',
    ];

    /** 미디어 속성을 가진 태그 */
    private const MEDIA_TAGS = ['video' => ['src', 'poster'], 'audio' => ['src'], 'source' => ['src'], 'track' => ['src']];

    /**
     * @param  array<string, mixed>  $settings  「본문 콘텐츠」 설정
     */
    private function __construct(private readonly array $settings) {}

    /**
     * 설정 배열로 만듭니다(빠진 키는 기본값 — D21).
     *
     * @param  array<string, mixed>  $settings  `content` 카테고리
     * @return self 규칙
     */
    public static function fromSettings(array $settings): self
    {
        return new self($settings + self::defaults());
    }

    /**
     * 지금 코어 설정으로 만듭니다.
     *
     * @return self 규칙
     */
    public static function current(): self
    {
        return self::fromSettings(self::storedSettings());
    }

    /**
     * 저장 정화용 규칙 — 지금 설정에서 후보 스위치와 외부 영상 서비스(프리셋·직접 추가·확장 제공)를 모두 켠 규칙 (#887).
     *
     * 후보 스위치(그림 캡션·업로드 동영상·오디오·외부 영상 삽입)와 서비스 켜고 끄기는 **표시에서만** 따른다 — 저장 정화가 스위치를
     * 따르면 끈 동안 저장한 본문에서 그 요소가 지워져 다시 켜도 돌아오지 않는다. 운영자 추가 태그·미디어 출처 패턴은 지금 설정 그대로다.
     * 설정 읽기는 `current()` 와 같은 출처다. 저장 마무리는 `finalize($html, 'store')` 로 한다(외부 영상 원문 보존, 출처 불일치 미디어 제거).
     *
     * @return self 저장용 규칙
     */
    public static function forStorage(): self
    {
        $settings = self::storedSettings();

        foreach (array_keys(self::CANDIDATES) as $key) {
            $settings[$key] = true;
        }

        try {
            $providerKeys = array_merge(array_keys(EmbedProviderRegistry::presets()), array_keys(EmbedProviderRegistry::extensionProviders()));
        } catch (\Throwable) {
            $providerKeys = array_keys(EmbedProviderRegistry::presets());
        }

        $presets = is_array($settings['embed_presets'] ?? null) ? $settings['embed_presets'] : [];
        foreach ($providerKeys as $key) {
            $presets[$key] = true;
        }
        $settings['embed_presets'] = $presets;

        if (is_array($settings['embed_custom_providers'] ?? null)) {
            foreach ($settings['embed_custom_providers'] as $index => $entry) {
                if (is_array($entry)) {
                    $settings['embed_custom_providers'][$index]['enabled'] = true;
                }
            }
        }

        return self::fromSettings($settings);
    }

    /**
     * 저장된 「본문 콘텐츠」 설정 — `current()`·`forStorage()` 의 같은 읽기.
     *
     * 앱이 부팅되지 않은 자리(순수 단위 시험·부팅 전 경로)에서는 빈 배열(기본값) — 설정 저장소가 없다고 정화를 멈추지 않는다.
     *
     * @return array<string, mixed> 설정
     */
    private static function storedSettings(): array
    {
        try {
            $settings = g7_core_settings('content', []);
        } catch (\Throwable) {
            $settings = [];
        }

        return is_array($settings) ? $settings : [];
    }

    /**
     * 기본값 — 결함 고침은 켬(캡션·외부 영상 유튜브·비메오), 새 기능은 끔(업로드 동영상·오디오) (D21).
     *
     * @return array<string, mixed> 기본 설정
     */
    public static function defaults(): array
    {
        return [
            'allow_figure_caption' => true,
            'allow_uploaded_video' => false,
            'allow_uploaded_audio' => false,
            'allow_external_embed' => true,
            'embed_presets' => [
                'youtube' => true, 'vimeo' => true, 'tiktok' => false,
            ],
            'embed_custom_providers' => [],
            'custom_tags' => [],
            'media_url_ttl_minutes' => 240,
        ];
    }

    /**
     * 켜진 후보 설정 키.
     *
     * @return array<int, string> 설정 키
     */
    public function enabledCandidates(): array
    {
        return array_values(array_filter(array_keys(self::CANDIDATES), fn ($key) => ! empty($this->settings[$key])));
    }

    /**
     * 표시에서 지울 태그 — 기본 금지 목록에서 켜진 후보 태그를 뺀다.
     *
     * @return array<int, string> 금지 태그
     */
    public function forbiddenTags(): array
    {
        // 켜진 후보 태그와 운영자 추가 태그(svg 등) 모두 금지에서 뺀다 — 추가 태그만 빠지면 저장은 되고 표시기·봇 화면은 지운다
        return array_values(array_diff(self::BASE_FORBID_TAGS, $this->allowedExtraTags()));
    }

    /**
     * 기본 허용 목록에 더할 태그 — 켜진 후보 태그 + 운영자 추가 태그.
     *
     * @return array<int, string> 추가 태그
     */
    public function allowedExtraTags(): array
    {
        return array_values(array_unique(array_merge(array_keys($this->attributeRules()), $this->customTags())));
    }

    /**
     * 표시에서 내용째 지울 태그 — 꺼진 후보의 `DISPLAY_DROP_WHEN_OFF` 태그 중 다른 후보·운영자 추가 태그가 허용하지 않은 것 (#887).
     *
     * 저장 원문은 건드리지 않는다(`finalize('store')` 는 쓰지 않음) — 화면(`drop_tags`)과 봇 화면(`finalize('bot')`)만 쓴다.
     *
     * @return array<int, string> 태그
     */
    public function displayDropTags(): array
    {
        $allowed = $this->allowedExtraTags();
        $tags = [];
        foreach (self::DISPLAY_DROP_WHEN_OFF as $key => $candidateTags) {
            if (empty($this->settings[$key])) {
                $tags = array_merge($tags, array_diff($candidateTags, $allowed));
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * 운영자 추가 태그(거절 목록은 저장 검증이 막지만 읽을 때도 한 번 더 거른다).
     *
     * @return array<int, string> 태그
     */
    public function customTags(): array
    {
        $tags = is_array($this->settings['custom_tags'] ?? null) ? $this->settings['custom_tags'] : [];
        $tags = array_map(fn ($t) => strtolower(trim((string) $t)), $tags);

        return array_values(array_filter($tags, fn ($t) => preg_match('/^[a-z][a-z0-9-]{0,31}$/', $t) === 1 && ! isset(self::REJECTED_CUSTOM_TAGS[$t])));
    }

    /**
     * 켜진 후보 태그별 허용 속성.
     *
     * @return array<string, array<int, string>> 태그 => 속성
     */
    public function attributeRules(): array
    {
        $rules = [];
        foreach ($this->enabledCandidates() as $key) {
            foreach (self::CANDIDATES[$key] as $tag => $attributes) {
                $rules[$tag] = array_values(array_unique(array_merge($rules[$tag] ?? [], $attributes)));
            }
        }

        return $rules;
    }

    /**
     * 미디어 `src`·`poster` 가 맞아야 하는 서빙 주소 정규식(구분자 없음).
     *
     * 확장이 등록한 실제 서빙 라우트에서 만든 패턴(`MediaSourceRoutes::patterns()`)에 필터 훅 `core.content.filter_media_source_patterns`
     * 추가분을 더한다(#887). 라우터·레지스트리가 없는 자리는 빈 목록에서 시작한다 — 그때 미디어 요소는 출처 검사에서 지워진다.
     * 정규식으로 컴파일되지 않는 값은 버린다.
     *
     * @return array<int, string> 정규식
     */
    public function mediaSourcePatterns(): array
    {
        $registered = self::registeredMediaSourcePatterns();

        try {
            $patterns = HookManager::applyFilters(self::MEDIA_SOURCE_FILTER_HOOK, $registered);
        } catch (\Throwable) {
            $patterns = $registered;
        }

        return array_values(array_filter(is_array($patterns) ? $patterns : [], fn ($p) => is_string($p) && $p !== '' && @preg_match('#'.str_replace('#', '\#', $p).'#', '') !== false));
    }

    /**
     * 레지스트리가 만든 출처 패턴 — 컨테이너·라우터가 없는 자리는 빈 목록.
     *
     * @return array<int, string> 정규식
     */
    private static function registeredMediaSourcePatterns(): array
    {
        try {
            return app(MediaSourceRoutes::class)->patterns();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 쓰는 외부 영상 서비스.
     *
     * @return array<int, array<string, mixed>> 서비스
     */
    public function embedProviders(): array
    {
        return ! empty($this->settings['allow_external_embed']) ? EmbedProviderRegistry::enabled($this->settings) : [];
    }

    /**
     * 화면(`_global.settings.content.render_policy`)에 내려줄 규칙.
     *
     * @return array<string, mixed> 규칙
     */
    public function toFrontendArray(): array
    {
        return [
            'version' => 1,
            'forbid_tags' => $this->forbiddenTags(),
            'drop_tags' => $this->displayDropTags(),
            'extra_tags' => $this->allowedExtraTags(),
            'attributes' => $this->attributeRules(),
            'custom_tags' => $this->customTags(),
            'media_src_patterns' => $this->mediaSourcePatterns(),
            'allow_external_embed' => ! empty($this->settings['allow_external_embed']),
            'allow_uploaded_video' => ! empty($this->settings['allow_uploaded_video']),
            'allow_uploaded_audio' => ! empty($this->settings['allow_uploaded_audio']),
            'embed_providers' => ! empty($this->settings['allow_external_embed']) ? EmbedProviderRegistry::toFrontendArray($this->settings) : [],
        ];
    }

    /**
     * HTMLPurifier 정의 묶음 — 정의 캐시 키는 규칙 해시(설정이 바뀌면 다시 만든다).
     *
     * @return array{rev: int, id: string, elements: array<string, array<int, string>>} 정의
     */
    public function htmlPurifierDefinition(): array
    {
        $elements = $this->attributeRules();
        $hash = substr(hash('sha256', json_encode([$elements, $this->customTags()])), 0, 8);

        return ['rev' => (int) hexdec(substr($hash, 0, 7)), 'id' => 'g7-content-'.$hash, 'elements' => $elements];
    }

    /**
     * 정화 뒤 마무리 — DOM 한 번 순회: 미디어 출처 검사(어긋나면 요소 제거), 후보 태그 속성 허용 목록, 이벤트·`javascript:` 속성 제거,
     * `oembed` 처리(`store` 는 http(s) 주소면 서비스를 몰라도 원문 그대로 두고 `url` 외 속성만 정리, `bot` 은 늘 링크 — #887),
     * `bot` 은 꺼진 후보의 표시 제거 태그(`displayDropTags`)를 내용째 지운다 — 사람 화면(`drop_tags`)과 같게. `store` 는 저장 원문이라
     * 지우지 않는다(다시 켜면 복원, #887). 어느 서비스로 재생할지·링크로 보일지는 표시가 그때 설정으로 정한다.
     *
     * @param  string  $html  정화된 HTML
     * @param  string  $mode  `store` | `bot`
     * @return string 마무리한 HTML
     */
    public function finalize(string $html, string $mode): string
    {
        if (trim($html) === '' || ! class_exists(\DOMDocument::class)) {
            return $html;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="g7-root">'.$html.'</div></body></html>', LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return $html;
            }
            $root = $document->getElementById('g7-root');
            if (! $root) {
                return $html;
            }

            $rules = $this->attributeRules();
            $patterns = $this->mediaSourcePatterns();
            $dropTags = $mode === 'store' ? [] : $this->displayDropTags();

            /** @var array<int, \DOMElement> $elements */
            $elements = [];
            foreach ($root->getElementsByTagName('*') as $element) {
                $elements[] = $element;
            }

            foreach ($elements as $element) {
                if (! $element->parentNode) {
                    continue;
                }
                $tag = strtolower($element->tagName);

                if (in_array($tag, $dropTags, true)) {
                    $element->parentNode->removeChild($element);

                    continue;
                }

                $this->stripDangerousAttributes($element);

                if (isset(self::MEDIA_TAGS[$tag]) && isset($rules[$tag]) && ! $this->mediaSourcesAllowed($element, self::MEDIA_TAGS[$tag], $patterns)) {
                    $element->parentNode->removeChild($element);

                    continue;
                }

                if ($tag === 'oembed') {
                    $this->finalizeEmbed($document, $element, $mode);

                    continue;
                }

                if (isset($rules[$tag])) {
                    foreach (iterator_to_array($element->attributes) as $attribute) {
                        $name = strtolower($attribute->name);
                        if (! in_array($name, $rules[$tag], true) && ! str_starts_with($name, 'data-')) {
                            $element->removeAttribute($attribute->name);
                        }
                    }
                }
            }

            $out = '';
            foreach ($root->childNodes as $child) {
                $out .= $document->saveHTML($child);
            }

            return $out;
        } catch (\Throwable $e) {
            try {
                Log::warning('본문 HTML 마무리 실패 — 정화 결과 그대로', ['error' => $e->getMessage()]);
            } catch (\Throwable) {
                // 로그 저장소가 없는 자리 — 결과만 돌려준다
            }

            return $html;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * `on*` 이벤트 속성과 `javascript:`·`vbscript:`·`data:text/html` 주소를 지웁니다.
     *
     * @param  \DOMElement  $element  요소
     * @return void 반환값 없음
     */
    private function stripDangerousAttributes(\DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = strtolower(preg_replace('/[\s\x00-\x1f]+/', '', (string) $attribute->value) ?? '');
            if (str_starts_with($name, 'on')
                || str_starts_with($value, 'javascript:') || str_starts_with($value, 'vbscript:') || str_starts_with($value, 'data:text/html')) {
                $element->removeAttribute($attribute->name);
            }
        }
    }

    /**
     * 미디어 요소의 주소 속성이 모두 서빙 주소 패턴에 맞는지(비어 있으면 통과).
     *
     * @param  \DOMElement  $element  요소
     * @param  array<int, string>  $attributes  주소 속성
     * @param  array<int, string>  $patterns  정규식
     * @return bool 맞으면 true
     */
    private function mediaSourcesAllowed(\DOMElement $element, array $attributes, array $patterns): bool
    {
        foreach ($attributes as $name) {
            $value = trim($element->getAttribute($name));
            if ($value === '') {
                continue;
            }
            $matched = false;
            foreach ($patterns as $pattern) {
                if (preg_match('#'.str_replace('#', '\#', $pattern).'#', $value) === 1) {
                    $matched = true;

                    break;
                }
            }
            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * `oembed` 를 유지하거나 링크로 바꿉니다.
     *
     * 저장(`store`)은 http(s) 주소면 서비스를 몰라도 원문 그대로 둔다(#887) — 저장 때 링크로 바꾸면 운영자가 나중에 그 서비스를 더해도
     * 플레이어로 돌아오지 않는다. 봇(`bot`)은 늘 원래 주소 링크다. http(s) 가 아닌 주소(`javascript:` 등)·빈 주소는 어느 쪽이든 지운다.
     *
     * @param  \DOMDocument  $document  문서
     * @param  \DOMElement  $element  oembed
     * @param  string  $mode  store|bot
     * @return void 반환값 없음
     */
    private function finalizeEmbed(\DOMDocument $document, \DOMElement $element, string $mode): void
    {
        $url = trim($element->getAttribute('url'));
        $isHttp = $url !== '' && preg_match('#^https?://#i', $url) === 1;

        if ($mode === 'store' && $isHttp) {
            foreach (iterator_to_array($element->attributes) as $attribute) {
                if (strtolower($attribute->name) !== 'url') {
                    $element->removeAttribute($attribute->name);
                }
            }

            return;
        }

        if (! $isHttp) {
            $element->parentNode?->removeChild($element);

            return;
        }

        $link = $document->createElement('a');
        $link->setAttribute('href', $url);
        $link->setAttribute('rel', 'noopener noreferrer nofollow');
        $link->setAttribute('target', '_blank');
        $link->appendChild($document->createTextNode($url));
        $element->parentNode?->replaceChild($link, $element);
    }
}
