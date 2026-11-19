<?php

namespace Plugins\Sirsoft\Popup\Http\Requests\Concerns;

use App\Helpers\TimezoneHelper;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\Popup\Enums\Audience;
use Plugins\Sirsoft\Popup\Enums\ContentType;
use Plugins\Sirsoft\Popup\Enums\DisplayPeriod;
use Plugins\Sirsoft\Popup\Enums\ImageDevice;
use Plugins\Sirsoft\Popup\Enums\OverlayAlign;
use Plugins\Sirsoft\Popup\Enums\PageScope;
use Plugins\Sirsoft\Popup\Enums\PopupDevice;
use Plugins\Sirsoft\Popup\Rules\OverlayBoxFits;
use Plugins\Sirsoft\Popup\Rules\PopupRoute;
use Plugins\Sirsoft\Popup\Rules\SafeLinkUrl;
use Plugins\Sirsoft\Popup\Rules\SanitizedBodyNotEmpty;
use Plugins\Sirsoft\Popup\Support\Overlays;
use Plugins\Sirsoft\Popup\Support\RoutePath;

/**
 * 팝업 등록·수정 공통 규칙 — 두 요청이 같은 규칙을 쓰도록 한 곳에 둔다(Store/Update 대칭).
 *
 * 내용 유형에 따라 쓰는 필드가 갈린다: 이미지형은 alt·링크·이미지·이미지 위 글 상자, 직접 작성형은 언어별 본문(기본 언어 필수, 정화 뒤에도 남아야 함).
 * 쓰지 않는 유형의 필드는 검증에서 빼고(`exclude_unless`) 서비스가 비운다.
 * 기간은 사이트 시간대 `datetime-local` 로 받아 검증 뒤 UTC 로 바꾼다(#810·이커머스 쿠폰과 같은 규약).
 */
trait PopupFieldRules
{
    /**
     * 공통 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    protected function popupRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
            'content_type' => ['required', Rule::enum(ContentType::class)],
            'display_period' => ['required', Rule::enum(DisplayPeriod::class)],
            'starts_at' => ['exclude_unless:display_period,range', 'required', 'date'],
            'ends_at' => ['exclude_unless:display_period,range', 'required', 'date', 'after:starts_at'],
            'page_scope' => ['required', Rule::enum(PageScope::class)],
            'routes' => ['exclude_unless:page_scope,routes', 'required', 'array', 'min:1', 'max:'.RoutePath::MAX_COUNT],
            'routes.*' => ['exclude_unless:page_scope,routes', 'distinct', new PopupRoute],
            'audience' => ['required', Rule::enum(Audience::class)],
            'device' => ['required', Rule::enum(PopupDevice::class)],
            'image_alt' => ['exclude_unless:content_type,image', 'nullable', 'array', new TranslatableField(maxLength: 200, strictLocales: true)],
            'link_url' => ['exclude_unless:content_type,image', 'nullable', 'string', 'max:2048', new SafeLinkUrl],
            'link_new_window' => ['exclude_unless:content_type,image', 'sometimes', 'boolean'],
            'body_html' => ['exclude_unless:content_type,html', 'required', 'array', new LocaleRequiredTranslatable(maxLength: 65535, strictLocales: true), new SanitizedBodyNotEmpty],
            'overlays' => ['exclude_unless:content_type,image', 'nullable', 'array:'.implode(',', ImageDevice::values())],
        ] + $this->overlayRules();
    }

    /**
     * 이미지 위 글 상자 규칙을 기기별로 반환합니다 — id 중복은 기기 안에서만 본다(모바일 배치는 PC 배치를 복사해 시작한다).
     *
     * @return array<string, mixed> 검증 규칙
     */
    private function overlayRules(): array
    {
        $rules = [];
        foreach (ImageDevice::values() as $device) {
            $box = "overlays.{$device}.*";
            $rules["overlays.{$device}"] = ['exclude_unless:content_type,image', 'nullable', 'array', 'max:'.Overlays::MAX_BOXES];
            $rules[$box] = ['exclude_unless:content_type,image', 'array', new OverlayBoxFits];
            $rules["{$box}.id"] = ['exclude_unless:content_type,image', 'required', 'string', 'regex:/^[A-Za-z0-9]{8}$/', 'distinct'];
            $rules["{$box}.x"] = ['exclude_unless:content_type,image', 'required', 'numeric', 'between:0,100'];
            $rules["{$box}.y"] = ['exclude_unless:content_type,image', 'required', 'numeric', 'between:0,100'];
            $rules["{$box}.w"] = ['exclude_unless:content_type,image', 'required', 'numeric', 'between:'.Overlays::MIN_WIDTH.',100'];
            $rules["{$box}.text"] = ['exclude_unless:content_type,image', 'required', 'array', new LocaleRequiredTranslatable(maxLength: Overlays::MAX_TEXT, strictLocales: true)];
            $rules["{$box}.size"] = ['exclude_unless:content_type,image', 'required', 'numeric', 'between:'.Overlays::MIN_SIZE.','.Overlays::MAX_SIZE, 'multiple_of:0.5'];
            $rules["{$box}.color"] = ['exclude_unless:content_type,image', 'required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];
            $rules["{$box}.bold"] = ['exclude_unless:content_type,image', 'sometimes', 'boolean'];
            $rules["{$box}.align"] = ['exclude_unless:content_type,image', 'required', Rule::enum(OverlayAlign::class)];
            $rules["{$box}.bg"] = ['exclude_unless:content_type,image', 'nullable', 'array:color,opacity'];
            $rules["{$box}.bg.color"] = ['exclude_unless:content_type,image', "required_with:{$box}.bg", 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];
            $rules["{$box}.bg.opacity"] = ['exclude_unless:content_type,image', "required_with:{$box}.bg", 'integer', 'between:0,'.Overlays::MAX_OPACITY];
            $rules["{$box}.shadow"] = ['exclude_unless:content_type,image', 'sometimes', 'boolean'];
        }

        return $rules;
    }

    /**
     * 다국어 값의 앞뒤 공백을 지우고, 경로를 정규화·중복 제거하고, 빈 링크를 null 로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function normalizePopupInput(): void
    {
        $merged = [];

        foreach (['image_alt', 'body_html'] as $field) {
            $value = $this->input($field);
            if (! is_array($value)) {
                continue;
            }
            $clean = [];
            foreach ($value as $locale => $text) {
                $text = is_string($text) ? trim($text) : $text;
                if ($text !== '' && $text !== null) {
                    $clean[$locale] = $text;
                }
            }
            $merged[$field] = ($clean === [] && $field === 'image_alt') ? null : $clean;
        }

        $routes = $this->input('routes');
        if (is_array($routes)) {
            $normalized = [];
            foreach ($routes as $route) {
                if (! is_string($route)) {
                    $normalized[] = $route;

                    continue;
                }
                $route = RoutePath::normalize($route);
                if ($route !== '' && ! in_array($route, $normalized, true)) {
                    $normalized[] = $route;
                }
            }
            $merged['routes'] = $normalized;
        }

        if (is_string($this->input('link_url'))) {
            $link = trim($this->input('link_url'));
            $merged['link_url'] = $link === '' ? null : $link;
        }

        if (is_string($this->input('title'))) {
            $merged['title'] = trim($this->input('title'));
        }

        if (is_array($this->input('overlays'))) {
            $merged['overlays'] = $this->cleanOverlayTexts($this->input('overlays'));
        }

        $this->merge($merged);
    }

    /**
     * 글 상자 글을 일반 글로 정리합니다 — 태그를 빼고(HTML 로 그리지 않는다) 줄바꿈을 LF 로 맞추고 앞뒤 공백·빈 언어를 지웁니다.
     *
     * @param  array<string, mixed>  $overlays  입력 `overlays`
     * @return array<string, mixed> 정리한 값
     */
    private function cleanOverlayTexts(array $overlays): array
    {
        foreach ($overlays as $device => $boxes) {
            if (! is_array($boxes)) {
                continue;
            }
            foreach ($boxes as $i => $box) {
                if (! is_array($box) || ! is_array($box['text'] ?? null)) {
                    continue;
                }
                $clean = [];
                foreach ($box['text'] as $locale => $text) {
                    // 빈 칸은 미들웨어가 null 로 바꿔 보낸다 — 빈 언어와 같이 뺀다
                    if ($text === null) {
                        continue;
                    }
                    if (! is_string($text)) {
                        $clean[$locale] = $text;

                        continue;
                    }
                    $text = trim(str_replace(["\r\n", "\r"], "\n", strip_tags($text)));
                    if ($text !== '') {
                        $clean[$locale] = $text;
                    }
                }
                $overlays[$device][$i]['text'] = $clean;
            }
        }

        return $overlays;
    }

    /**
     * 검증된 값의 기간을 사이트 시간대에서 UTC 로 바꾸고, 상시 노출이면 기간을, 전체 페이지면 경로를 비웁니다.
     *
     * @param  array<string, mixed>  $data  검증된 값
     * @return array<string, mixed> 변환한 값
     */
    protected function convertPopupInput(array $data): array
    {
        if (($data['display_period'] ?? null) === DisplayPeriod::Range->value) {
            $data['starts_at'] = TimezoneHelper::fromSiteDateTime($data['starts_at'] ?? null);
            $data['ends_at'] = TimezoneHelper::fromSiteDateTime($data['ends_at'] ?? null);
        } else {
            $data['starts_at'] = null;
            $data['ends_at'] = null;
        }

        if (($data['page_scope'] ?? null) !== PageScope::Routes->value) {
            $data['routes'] = null;
        }

        if (($data['content_type'] ?? null) === ContentType::Image->value) {
            $data['link_new_window'] = (bool) ($data['link_new_window'] ?? false);
            $data['overlays'] = Overlays::normalize($data['overlays'] ?? null);
        }

        return $data;
    }

    /**
     * 공통 필드 이름을 반환합니다.
     *
     * @return array<string, string> 필드 => 이름
     */
    protected function popupAttributes(): array
    {
        $names = [];
        foreach ([
            'title', 'is_active', 'content_type', 'display_period', 'starts_at', 'ends_at', 'page_scope', 'routes',
            'audience', 'device', 'image_alt', 'link_url', 'link_new_window', 'body_html',
        ] as $field) {
            $names[$field] = __('sirsoft-popup::validation.attributes.'.$field);
        }
        $names['routes.*'] = __('sirsoft-popup::validation.attributes.route_item');
        $names['pc_image'] = __('sirsoft-popup::validation.attributes.pc_image');
        $names['overlays'] = __('sirsoft-popup::validation.attributes.overlays');
        foreach (ImageDevice::values() as $device) {
            // 「PC 글 상자 2」처럼 기기와 순번을 붙여 어느 상자 문제인지 알린다 — :position 은 검증기가 1부터 센 순번으로 바꾼다
            $box = __('sirsoft-popup::validation.attributes.overlay_box.'.$device);
            $names["overlays.{$device}"] = __('sirsoft-popup::validation.attributes.overlays');
            $names["overlays.{$device}.*"] = $box;
            // 번역 키는 점 대신 밑줄 — `bg` 가 문자열이라 `bg.color` 를 점 표기로 찾으면 키가 그대로 나온다
            foreach (['x', 'y', 'w', 'text', 'size', 'color', 'bold', 'align', 'bg', 'bg.color', 'bg.opacity', 'shadow', 'id'] as $key) {
                $names["overlays.{$device}.*.{$key}"] = $box.' '.__('sirsoft-popup::validation.attributes.overlay_field.'.str_replace('.', '_', $key));
            }
        }

        return $names;
    }

    /**
     * 공통 검증 메시지를 반환합니다.
     *
     * 바탕 색·진하기의 `required_with` 는 기본 문구가 `:values`(= 「PC 글 상자 :position 바탕」)를 넣는데, 검증기는 순번 자리를
     * `:attribute` 에서만 채워 `:position` 이 그대로 남는다. 바탕을 보냈다는 조건은 칸 이름(「PC 글 상자 1 바탕 색」)에 이미 담겨
     * 있으므로 필수 문구로 알린다.
     *
     * @return array<string, string> 규칙 키 => 메시지
     */
    protected function popupMessages(): array
    {
        $messages = [];
        foreach (ImageDevice::values() as $device) {
            foreach (['color', 'opacity'] as $part) {
                $messages["overlays.{$device}.*.bg.{$part}.required_with"] = __('validation.required');
            }
        }

        return $messages;
    }
}
