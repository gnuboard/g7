<?php

namespace Plugins\Sirsoft\Popup\Http\Requests\Concerns;

use App\Helpers\TimezoneHelper;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\Popup\Enums\Audience;
use Plugins\Sirsoft\Popup\Enums\ContentType;
use Plugins\Sirsoft\Popup\Enums\DisplayPeriod;
use Plugins\Sirsoft\Popup\Enums\PageScope;
use Plugins\Sirsoft\Popup\Enums\PopupDevice;
use Plugins\Sirsoft\Popup\Rules\PopupRoute;
use Plugins\Sirsoft\Popup\Rules\SafeLinkUrl;
use Plugins\Sirsoft\Popup\Rules\SanitizedBodyNotEmpty;
use Plugins\Sirsoft\Popup\Support\RoutePath;

/**
 * 팝업 등록·수정 공통 규칙 — 두 요청이 같은 규칙을 쓰도록 한 곳에 둔다(Store/Update 대칭).
 *
 * 내용 유형에 따라 쓰는 필드가 갈린다: 이미지형은 alt·링크·이미지, 직접 작성형은 언어별 본문(기본 언어 필수, 정화 뒤에도 남아야 함).
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
        ];
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

        $this->merge($merged);
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

        return $names;
    }
}
