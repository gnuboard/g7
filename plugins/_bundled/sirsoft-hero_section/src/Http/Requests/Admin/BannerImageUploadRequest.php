<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\HeroSection\Enums\BannerDevice;
use Plugins\Sirsoft\HeroSection\Enums\MediaKind;
use Plugins\Sirsoft\HeroSection\Support\UploadLimit;

/**
 * 배너 파일 업로드 요청 — 이미지(`kind=image`, 기본)는 JPG·PNG·WEBP, 영상(`kind=video`)은 MP4·WEBM.
 * 최대 용량은 종류별 설정(이미지 `max_upload_mb`, 영상 `max_video_upload_mb`)과 서버 PHP 한도 중 작은 값.
 * 형식은 확장자와 파일 내용(MIME) 둘 다로 본다 — 확장자만 바꾼 HTML·SVG 는 내용 판정에서 걸린다.
 *
 * 업로드는 언제나 임시 묶음(temp_key)에 들어가고 배너에는 등록·수정 저장 때 붙는다 — 저장 전에는 사이트 화면이 바뀌지 않는다.
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

        $base = [
            'kind' => ['sometimes', Rule::enum(MediaKind::class)],
            'device' => ['required', Rule::enum(BannerDevice::class)],
            'temp_key' => ['required', 'string', 'max:64'],
        ];

        if ($this->isVideo()) {
            $maxKb = (int) floor(UploadLimit::effectiveVideoMb() * 1024);

            return $base + ['file' => ['required', 'file', 'mimes:mp4,webm', 'mimetypes:video/mp4,video/webm', 'max:'.$maxKb]];
        }

        $maxKb = (int) floor(UploadLimit::effectiveMb() * 1024);

        return $base + ['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.$maxKb, 'dimensions:max_width=4000,max_height=4000']];
    }

    /**
     * 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 규칙 => 메시지
     */
    public function messages(): array
    {
        if ($this->isVideo()) {
            return [
                'file.max' => __('sirsoft-hero_section::validation.file.video_max', ['mb' => UploadLimit::effectiveVideoMb()]),
                'file.mimes' => __('sirsoft-hero_section::validation.file.video_mimes'),
                'file.mimetypes' => __('sirsoft-hero_section::validation.file.video_mimes'),
            ];
        }

        return [
            'file.max' => __('sirsoft-hero_section::validation.file.max', ['mb' => UploadLimit::effectiveMb()]),
            'file.mimes' => __('sirsoft-hero_section::validation.file.mimes'),
            'file.mimetypes' => __('sirsoft-hero_section::validation.file.mimes'),
            'file.dimensions' => __('sirsoft-hero_section::validation.file.dimensions'),
        ];
    }

    /**
     * 영상 업로드 요청인지 판정합니다.
     *
     * @return bool 영상이면 true
     */
    public function isVideo(): bool
    {
        return $this->input('kind') === MediaKind::Video->value;
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
        $postMax = UploadLimit::iniSettingBytes('post_max_size');
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
            'kind' => __('sirsoft-hero_section::validation.attributes.kind'),
            'device' => __('sirsoft-hero_section::validation.attributes.device'),
            'temp_key' => __('sirsoft-hero_section::validation.attributes.temp_key'),
        ];
    }
}
