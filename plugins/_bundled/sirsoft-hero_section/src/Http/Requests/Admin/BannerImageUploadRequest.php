<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\HeroSection\Enums\BannerDevice;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Support\UploadLimit;

/**
 * 배너 이미지 업로드 요청 — 형식 JPG·PNG·WEBP, 최대 용량은 설정과 서버 PHP 한도 중 작은 값.
 *
 * 권장 크기(PC 1920×640, 모바일 750×1000)는 강제하지 않는다 — 화면이 응답 크기로 안내만 한다.
 * PHP 한도를 넘으면 파일이 라라벨에 닿기 전에 잘리므로 그 경우를 가려 전용 안내로 답한다.
 */
class BannerImageUploadRequest extends FormRequest
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
                    $fail(__('sirsoft-hero_section::validation.file.server_limit', ['mb' => UploadLimit::serverLimitMb()]));
                }],
            ];
        }

        $maxKb = (int) floor(UploadLimit::effectiveMb() * 1024);

        return [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.$maxKb, 'dimensions:max_width=4000,max_height=4000'],
            'device' => ['required', Rule::enum(BannerDevice::class)],
            'temp_key' => ['required_without:banner_id', 'prohibits:banner_id', 'nullable', 'string', 'max:64'],
            'banner_id' => ['required_without:temp_key', 'nullable', 'integer', Rule::exists(Banner::class, 'id')],
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
            'file.max' => __('sirsoft-hero_section::validation.file.max', ['mb' => UploadLimit::effectiveMb()]),
            'file.mimes' => __('sirsoft-hero_section::validation.file.mimes'),
            'file.mimetypes' => __('sirsoft-hero_section::validation.file.mimes'),
            'file.dimensions' => __('sirsoft-hero_section::validation.file.dimensions'),
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
            'file' => __('sirsoft-hero_section::validation.attributes.file'),
            'device' => __('sirsoft-hero_section::validation.attributes.device'),
            'temp_key' => __('sirsoft-hero_section::validation.attributes.temp_key'),
            'banner_id' => __('sirsoft-hero_section::validation.attributes.banner_id'),
        ];
    }
}
