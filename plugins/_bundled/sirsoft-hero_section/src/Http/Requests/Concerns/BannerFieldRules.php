<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Concerns;

use App\Helpers\TimezoneHelper;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\HeroSection\Enums\DisplayPeriod;
use Plugins\Sirsoft\HeroSection\Enums\HeroPosition;
use Plugins\Sirsoft\HeroSection\Enums\TextAlign;
use Plugins\Sirsoft\HeroSection\Rules\SafeLinkUrl;

/**
 * 배너 등록·수정 공통 규칙 — 두 요청이 같은 규칙을 쓰도록 한 곳에 둔다(Store/Update 대칭).
 *
 * 기간은 사이트 시간대 `datetime-local` 로 받아 검증 뒤 UTC 로 바꾼다(이커머스 쿠폰 발급 기간과 같은 규약).
 */
trait BannerFieldRules
{
    /**
     * 다국어 문구 필드
     *
     * @var array<int, string>
     */
    private array $translatableFields = ['title', 'description', 'button_text', 'image_alt'];

    /**
     * 공통 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    protected function bannerRules(): array
    {
        return [
            'position' => ['required', Rule::enum(HeroPosition::class)],
            'is_active' => ['required', 'boolean'],
            'display_period' => ['required', Rule::enum(DisplayPeriod::class)],
            'starts_at' => ['exclude_unless:display_period,range', 'required', 'date'],
            'ends_at' => ['exclude_unless:display_period,range', 'required', 'date', 'after:starts_at'],
            'title' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 100, strictLocales: true)],
            'description' => ['nullable', 'array', new TranslatableField(maxLength: 300, strictLocales: true)],
            'button_text' => ['nullable', 'array', new TranslatableField(maxLength: 30, strictLocales: true)],
            'image_alt' => ['nullable', 'array', new TranslatableField(maxLength: 200, strictLocales: true)],
            'title_visible' => ['required', 'boolean'],
            'title_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'title_align' => ['required', Rule::enum(TextAlign::class)],
            'description_visible' => ['required', 'boolean'],
            'description_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description_align' => ['required', Rule::enum(TextAlign::class)],
            'link_url' => ['nullable', 'string', 'max:2048', new SafeLinkUrl],
            'link_new_window' => ['required', 'boolean'],
        ];
    }

    /**
     * 다국어 문구의 앞뒤 공백을 지우고, 모든 언어가 빈 선택 문구는 null 로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function normalizeTranslations(): void
    {
        $merged = [];
        foreach ($this->translatableFields as $field) {
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

            $merged[$field] = ($clean === [] && $field !== 'title') ? null : $clean;
        }

        if (is_string($this->input('link_url'))) {
            $link = trim($this->input('link_url'));
            $merged['link_url'] = $link === '' ? null : $link;
        }

        $this->merge($merged);
    }

    /**
     * 검증된 값의 기간을 사이트 시간대에서 UTC 로 바꾸고, 상시 노출이면 기간을 비웁니다.
     *
     * @param  array<string, mixed>  $data  검증된 값
     * @return array<string, mixed> 변환한 값
     */
    protected function convertPeriod(array $data): array
    {
        if (($data['display_period'] ?? null) === DisplayPeriod::Range->value) {
            $data['starts_at'] = TimezoneHelper::fromSiteDateTime($data['starts_at'] ?? null);
            $data['ends_at'] = TimezoneHelper::fromSiteDateTime($data['ends_at'] ?? null);
        } else {
            $data['starts_at'] = null;
            $data['ends_at'] = null;
        }

        return $data;
    }
}
