<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Concerns;

use App\Helpers\TimezoneHelper;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Plugins\Sirsoft\HeroSection\Enums\AnimationSpeed;
use Plugins\Sirsoft\HeroSection\Enums\BannerDevice;
use Plugins\Sirsoft\HeroSection\Enums\BannerMediaType;
use Plugins\Sirsoft\HeroSection\Enums\BannerTone;
use Plugins\Sirsoft\HeroSection\Enums\DisplayPeriod;
use Plugins\Sirsoft\HeroSection\Enums\HeroPosition;
use Plugins\Sirsoft\HeroSection\Enums\MediaKind;
use Plugins\Sirsoft\HeroSection\Enums\TextAlign;
use Plugins\Sirsoft\HeroSection\Enums\TextAnimation;
use Plugins\Sirsoft\HeroSection\Enums\TextOverlay;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerImageRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Rules\SafeLinkUrl;
use Plugins\Sirsoft\HeroSection\Rules\SelectableBannerImage;
use Plugins\Sirsoft\HeroSection\Support\YoutubeUrl;

/**
 * 배너 등록·수정 공통 규칙 — 두 요청이 같은 규칙을 쓰도록 한 곳에 둔다(Store/Update 대칭).
 *
 * 기간은 사이트 시간대 `datetime-local` 로 받아 검증 뒤 UTC 로 바꾼다(이커머스 쿠폰 발급 기간과 같은 규약).
 * 미디어 종류(`media_type`)는 보내지 않으면 등록은 이미지, 수정은 저장된 값 그대로다. 유튜브는 주소(`youtube_url`)로 받아
 * 검증 뒤 영상 ID(`youtube_id`)만 남기고, 영상 파일은 이미지처럼 임시 묶음에 올린 뒤 `pc_video_id`·`mobile_video_id` 로 고른다.
 */
trait BannerFieldRules
{
    /**
     * 다국어 문구 필드
     *
     * @var array<int, string>
     */
    private array $translatableFields = ['title', 'eyebrow', 'description', 'button_text', 'image_alt'];

    /**
     * 공통 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    protected function bannerRules(): array
    {
        return [
            'position' => ['required', Rule::enum(HeroPosition::class)],
            'media_type' => ['sometimes', 'required', Rule::enum(BannerMediaType::class)],
            'youtube_url' => ['exclude_unless:media_type,'.BannerMediaType::Youtube->value, 'required', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || YoutubeUrl::videoId($value) === null) {
                    $fail(__('sirsoft-hero_section::validation.youtube_url.invalid'));
                }
            }],
            'is_active' => ['required', 'boolean'],
            'display_period' => ['required', Rule::enum(DisplayPeriod::class)],
            'starts_at' => ['exclude_unless:display_period,range', 'required', 'date'],
            'ends_at' => ['exclude_unless:display_period,range', 'required', 'date', 'after:starts_at'],
            'title' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 100, strictLocales: true)],
            'eyebrow' => ['nullable', 'array', new TranslatableField(maxLength: 50, strictLocales: true)],
            // 머리글 보이기·색은 이전 화면·API 호출이 보내지 않아도 저장한 값(처음이면 보임·흰색)을 지킨다
            'eyebrow_visible' => ['sometimes', 'required', 'boolean'],
            'eyebrow_color' => ['sometimes', 'required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
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
            'presentation' => ['nullable', 'array:'.implode(',', array_keys(Banner::DEFAULT_PRESENTATION))],
            ...array_fill_keys(
                array_map(fn (string $key) => 'presentation.'.$key, Banner::PERCENT_KEYS),
                ['sometimes', 'required', 'integer', 'between:'.Banner::FOCUS_MIN.','.Banner::FOCUS_MAX],
            ),
            'presentation.overlay' => ['sometimes', 'required', Rule::enum(TextOverlay::class)],
            'presentation.text_width' => ['sometimes', 'required', 'integer', 'between:'.Banner::TEXT_WIDTH_MIN.','.Banner::TEXT_WIDTH_MAX],
            'presentation.animation' => ['sometimes', 'required', Rule::enum(TextAnimation::class)],
            'presentation.animation_speed' => ['sometimes', 'required', Rule::enum(AnimationSpeed::class)],
            'presentation.animation_delay' => ['sometimes', 'required', 'numeric', 'between:0,'.Banner::ANIMATION_DELAY_MAX, 'multiple_of:0.1'],
            'presentation.tone' => ['sometimes', 'required', Rule::enum(BannerTone::class)],
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
        if (array_key_exists('media_type', $data)) {
            $data['youtube_id'] = $data['media_type'] === BannerMediaType::Youtube->value
                ? YoutubeUrl::videoId($data['youtube_url'] ?? null)
                : null;
        }
        unset($data['youtube_url']);

        foreach ([...Banner::PERCENT_KEYS, 'text_width'] as $coordinate) {
            if (isset($data['presentation'][$coordinate])) {
                $data['presentation'][$coordinate] = (int) $data['presentation'][$coordinate];
            }
        }
        if (isset($data['presentation']['animation_delay'])) {
            // 0.1초 단위 — 문자열·부동소수 오차(0.30000000000000004)를 저장하지 않는다
            $data['presentation']['animation_delay'] = round((float) $data['presentation']['animation_delay'], 1);
        }
        if (($data['display_period'] ?? null) === DisplayPeriod::Range->value) {
            $data['starts_at'] = TimezoneHelper::fromSiteDateTime($data['starts_at'] ?? null);
            $data['ends_at'] = TimezoneHelper::fromSiteDateTime($data['ends_at'] ?? null);
        } else {
            $data['starts_at'] = null;
            $data['ends_at'] = null;
        }

        return $data;
    }

    /**
     * 영상 파일 선택 규칙을 반환합니다 — 이미지 선택과 같이 이 배너 또는 이 임시 묶음의 같은 기기 영상만 고를 수 있다.
     *
     * @param  int|null  $bannerId  수정 중인 배너 ID (등록이면 null)
     * @param  string|null  $tempKey  임시 묶음 키
     * @return array<string, mixed> 검증 규칙
     */
    protected function videoSelectionRules(?int $bannerId, ?string $tempKey): array
    {
        return [
            'pc_video_id' => ['sometimes', 'nullable', 'integer', new SelectableBannerImage(BannerDevice::Pc, $bannerId, $tempKey, MediaKind::Video)],
            'mobile_video_id' => ['sometimes', 'nullable', 'integer', new SelectableBannerImage(BannerDevice::Mobile, $bannerId, $tempKey, MediaKind::Video)],
        ];
    }

    /**
     * 미디어 종류가 영상 파일이면 PC 영상이 남는지 확인합니다 — 보낸 선택(`pc_video_id`)이 있으면 그것, 없으면 지금 붙은 영상(수정)
     * 또는 임시 묶음의 영상(등록)을 본다.
     *
     * @param  Validator  $validator  검증기
     * @param  int|null  $bannerId  수정 중인 배너 ID (등록이면 null)
     * @param  string|null  $storedType  저장된 미디어 종류 (등록이면 null)
     * @return void 반환값 없음
     */
    protected function checkVideoPresent(Validator $validator, ?int $bannerId, ?string $storedType): void
    {
        $type = $this->has('media_type') ? $this->input('media_type') : ($storedType ?? BannerMediaType::Image->value);
        if ($type !== BannerMediaType::Video->value || $validator->errors()->has('pc_video_id')) {
            return;
        }

        if ($this->has('pc_video_id')) {
            $present = $this->input('pc_video_id') !== null && $this->input('pc_video_id') !== '';
        } else {
            $tempKey = $bannerId === null && is_string($this->input('temp_key')) ? $this->input('temp_key') : null;
            $present = app(BannerImageRepositoryInterface::class)
                ->findByOwnerAndDevice($bannerId, $tempKey, BannerDevice::Pc->value, MediaKind::Video->value) !== null;
        }

        if (! $present) {
            $validator->errors()->add('pc_video', __('sirsoft-hero_section::validation.pc_video.required'));
        }
    }
}
