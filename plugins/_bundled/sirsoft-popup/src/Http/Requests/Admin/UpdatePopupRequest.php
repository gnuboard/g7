<?php

namespace Plugins\Sirsoft\Popup\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Helpers\ResponseHelper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;
use Plugins\Sirsoft\Popup\Enums\ContentType;
use Plugins\Sirsoft\Popup\Enums\ImageDevice;
use Plugins\Sirsoft\Popup\Http\Requests\Concerns\PopupFieldRules;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupImageRepositoryInterface;
use Plugins\Sirsoft\Popup\Rules\SelectablePopupImage;

/**
 * 팝업 수정 요청 — 등록과 같은 규칙(대칭)에, 저장 때 바꿀 이미지(`pc_image_id`·`mobile_image_id`)와 세대 번호(`revision`)를 받는다.
 *
 * 수정 화면은 이미지를 임시 묶음(`temp_key`)에 올려 두고 저장 때 화면에 남은 이미지 ID 를 보낸다. 키를 보내지 않은
 * 기기는 그대로 두고, `mobile_image_id: null` 은 모바일 이미지를 뺀다. PC 이미지는 이미지형에 필수라 null 을 받지 않는다.
 *
 * 언어팩을 꺼서 지원 목록에서 빠진 언어의 번역은 저장된 값 그대로 보존한다 — 화면이 그 키를 다시 보내도 막지 않고
 * 빼고 보내도 지우지 않는다(언어팩을 다시 켜면 그대로 보인다). 저장돼 있지 않던 지원 밖 언어는 등록처럼 막는다.
 * 이미지 위 글 상자의 글도 같다 — 상자 id 가 저장본과 같은 상자에만 되돌린다(지운 상자의 글은 살리지 않는다).
 */
class UpdatePopupRequest extends FormRequest
{
    use PopupFieldRules;

    /**
     * 검증에서 빼 두었다가 되돌려 넣을 지원 밖 언어의 저장된 번역 (필드 => 언어 => 값)
     *
     * @var array<string, array<string, string>>
     */
    private array $keptTranslations = [];

    /**
     * 같은 방식으로 보존할 글 상자 글 (기기 => 상자 id => 언어 => 글)
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private array $keptOverlayTexts = [];

    /**
     * 권한은 라우트 permission 미들웨어가 판정합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 입력을 정리하고, 다른 관리자가 먼저 저장했으면 검증보다 먼저 409 로 알립니다.
     *
     * @return void 반환값 없음
     *
     * @throws HttpResponseException 세대 번호가 다를 때(409)
     */
    protected function prepareForValidation(): void
    {
        $this->normalizePopupInput();
        $this->holdInactiveLocaleTranslations();
        // 옛 이미지가 지워졌어도 이미지 422 보다 먼저 수정 충돌을 알린다
        $revision = filter_var($this->input('revision'), FILTER_VALIDATE_INT);
        if ($this->has('revision') && $revision !== false && $revision >= 0 && $this->route('popup')?->lock_version !== $revision) {
            throw new HttpResponseException(ResponseHelper::error('sirsoft-popup::messages.popup.conflict', 409));
        }
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $popupId = (int) $this->route('popup')?->id;
        $tempKey = is_string($this->input('temp_key')) ? $this->input('temp_key') : null;

        $rules = $this->popupRules() + [
            'revision' => ['sometimes', 'required', 'integer', 'min:0'],
            'temp_key' => ['exclude_unless:content_type,image', 'nullable', 'string', 'max:64'],
            'pc_image_id' => ['exclude_unless:content_type,image', 'sometimes', 'required', 'integer', new SelectablePopupImage(ImageDevice::Pc, $popupId, $tempKey)],
            'mobile_image_id' => ['exclude_unless:content_type,image', 'sometimes', 'nullable', 'integer', new SelectablePopupImage(ImageDevice::Mobile, $popupId, $tempKey)],
        ];

        return HookManager::applyFilters('sirsoft-popup.popup.update_validation_rules', $rules, $this);
    }

    /**
     * 규칙 뒤 검사 — 이미지형인데 PC 이미지를 바꾸지 않으면 팝업에 PC 이미지가 있어야 합니다.
     *
     * @return array<int, callable> 뒤 검사 목록
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->input('content_type') !== ContentType::Image->value || $this->has('pc_image_id')) {
                    return;
                }

                $popupId = (int) $this->route('popup')?->id;
                $pc = app(PopupImageRepositoryInterface::class)->findByOwnerAndDevice($popupId, null, ImageDevice::Pc->value);
                if ($pc === null) {
                    $validator->errors()->add('pc_image', __('sirsoft-popup::validation.pc_image.required'));
                }
            },
        ];
    }

    /**
     * 검증된 값(기간 UTC 변환·안 쓰는 값 비움)을 반환합니다.
     *
     * @param  string|null  $key  특정 키만 반환
     * @param  mixed  $default  기본값
     * @return mixed 변환한 검증 값
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);
        if ($key !== null) {
            return $data;
        }

        // 이번 내용 유형이 쓰는 필드에만 보존한 번역을 되돌린다(안 쓰는 필드는 서비스가 비운다)
        foreach ($this->keptTranslations as $field => $kept) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (is_array($data[$field]) ? $data[$field] : []) + $kept;
            }
        }
        foreach ($this->keptOverlayTexts as $device => $byId) {
            foreach ($data['overlays'][$device] ?? [] as $i => $box) {
                $kept = $byId[$box['id'] ?? ''] ?? null;
                if ($kept !== null) {
                    $data['overlays'][$device][$i]['text'] = (is_array($box['text'] ?? null) ? $box['text'] : []) + $kept;
                }
            }
        }

        return $this->convertPopupInput($data);
    }

    /**
     * 지원 목록 밖 언어 중 이미 저장된 번역을 입력에서 빼 두고, 저장된 값으로 되돌려 넣을 목록에 담습니다.
     *
     * @return void 반환값 없음
     */
    private function holdInactiveLocaleTranslations(): void
    {
        $popup = $this->route('popup');
        if ($popup === null) {
            return;
        }

        $active = config('app.translatable_locales', ['ko', 'en']);
        $merged = [];
        foreach (['image_alt', 'body_html'] as $field) {
            $stored = is_array($popup->{$field}) ? $popup->{$field} : [];
            $kept = array_diff_key($stored, array_flip($active));
            if ($kept === []) {
                continue;
            }
            $this->keptTranslations[$field] = $kept;
            $input = $this->input($field);
            if (is_array($input)) {
                $merged[$field] = array_diff_key($input, $kept);
            }
        }

        $overlays = $this->holdInactiveLocaleOverlayTexts($popup->overlays, $this->input('overlays'), $active);
        if ($overlays !== null) {
            $merged['overlays'] = $overlays;
        }

        $this->merge($merged);
    }

    /**
     * 저장된 글 상자 중 입력에 같은 id 로 다시 온 상자의 지원 밖 언어 글을 입력에서 빼 두고 보존 목록에 담습니다.
     *
     * @param  mixed  $stored  저장된 `overlays`
     * @param  mixed  $input  입력 `overlays`
     * @param  array<int, string>  $active  지원 언어
     * @return array<string, mixed>|null 지원 밖 언어를 뺀 입력 (바꿀 것이 없으면 null)
     */
    private function holdInactiveLocaleOverlayTexts(mixed $stored, mixed $input, array $active): ?array
    {
        if (! is_array($stored) || ! is_array($input)) {
            return null;
        }

        $changed = false;
        foreach (ImageDevice::values() as $device) {
            foreach (is_array($stored[$device] ?? null) ? $stored[$device] : [] as $box) {
                $kept = is_array($box['text'] ?? null) ? array_diff_key($box['text'], array_flip($active)) : [];
                if ($kept !== [] && isset($box['id'])) {
                    $this->keptOverlayTexts[$device][(string) $box['id']] = $kept;
                }
            }
            if (! isset($this->keptOverlayTexts[$device]) || ! is_array($input[$device] ?? null)) {
                continue;
            }
            foreach ($input[$device] as $i => $box) {
                $kept = is_array($box) ? ($this->keptOverlayTexts[$device][(string) ($box['id'] ?? '')] ?? null) : null;
                if ($kept !== null && is_array($box['text'] ?? null)) {
                    $input[$device][$i]['text'] = array_diff_key($box['text'], $kept);
                    $changed = true;
                }
            }
        }

        return $changed ? $input : null;
    }

    /**
     * 검증 오류에 쓸 필드 이름을 반환합니다.
     *
     * @return array<string, string> 필드 => 이름
     */
    public function attributes(): array
    {
        return [
            'revision' => __('sirsoft-popup::validation.attributes.revision'),
            'temp_key' => __('sirsoft-popup::validation.attributes.temp_key'),
            'pc_image_id' => __('sirsoft-popup::validation.attributes.pc_image'),
            'mobile_image_id' => __('sirsoft-popup::validation.attributes.mobile_image'),
        ] + $this->popupAttributes();
    }
}
