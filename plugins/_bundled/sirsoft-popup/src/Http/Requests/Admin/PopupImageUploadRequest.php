<?php

namespace Plugins\Sirsoft\Popup\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\Popup\Enums\ImageDevice;
use Plugins\Sirsoft\Popup\Support\UploadLimit;

/**
 * 팝업 이미지 업로드 요청 — 형식 JPG·PNG·WEBP·GIF, 최대 용량은 설정과 서버 PHP 한도 중 작은 값.
 *
 * 업로드는 언제나 임시 묶음(temp_key)에 들어가고 팝업에는 등록·수정 저장 때 붙는다 — 저장 전에는 사이트 화면이 바뀌지 않는다.
 * 권장 크기(환경설정의 PC 너비×높이)는 강제하지 않는다 — 화면이 응답 크기로 안내만 한다.
 * PHP 한도를 넘으면 파일이 라라벨에 닿기 전에 잘리므로 그 경우를 가려 전용 안내로 답한다.
 */
class PopupImageUploadRequest extends FormRequest
{
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
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        if ($this->exceededServerLimit()) {
            return [
                'file' => [function (string $attribute, mixed $value, Closure $fail) {
                    $fail(__('sirsoft-popup::validation.file.server_limit', ['mb' => UploadLimit::serverLimitMb()]));
                }],
            ];
        }

        $maxKb = (int) floor(UploadLimit::effectiveMb() * 1024);

        return [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:'.$maxKb, 'dimensions:max_width=4000,max_height=4000'],
            'device' => ['required', Rule::enum(ImageDevice::class)],
            // 화면이 만든 UUID 만 받는다 — 떼어 낸 이미지의 내부 묶음(`released:{팝업 ID}`)에 끼어들지 못하게
            'temp_key' => ['required', 'string', 'uuid'],
        ];
    }

    /**
     * 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 규칙 => 메시지
     */
    public function messages(): array
    {
        return [
            'file.max' => __('sirsoft-popup::validation.file.max', ['mb' => UploadLimit::effectiveMb()]),
            'file.mimes' => __('sirsoft-popup::validation.file.mimes'),
            'file.mimetypes' => __('sirsoft-popup::validation.file.mimes'),
            'file.dimensions' => __('sirsoft-popup::validation.file.dimensions'),
        ];
    }

    /**
     * 서버 PHP 한도에 걸려 파일이 비어 들어왔는지 판정합니다.
     *
     * @return bool PHP 한도 초과면 true
     */
    private function exceededServerLimit(): bool
    {
        $file = $this->file('file');
        if ($file !== null && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return true;
        }

        // post_max_size 를 넘으면 본문 전체가 비어 들어온다
        $postMax = UploadLimit::iniBytes((string) ini_get('post_max_size'));
        $length = (int) $this->server('CONTENT_LENGTH', 0);

        return $file === null && $postMax > 0 && $length > $postMax;
    }

    /**
     * 검증 오류에 쓸 필드 이름을 반환합니다.
     *
     * @return array<string, string> 필드 => 이름
     */
    public function attributes(): array
    {
        return [
            'file' => __('sirsoft-popup::validation.attributes.file'),
            'device' => __('sirsoft-popup::validation.attributes.device'),
            'temp_key' => __('sirsoft-popup::validation.attributes.temp_key'),
        ];
    }
}
