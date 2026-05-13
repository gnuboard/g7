<?php

namespace Plugins\Sirsoft\Ckeditor5\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * CKEditor5 이미지 업로드 요청 검증
 *
 * CKEditor5 SimpleUploadAdapter가 전송하는 이미지 파일을 검증합니다.
 */
class ImageUploadRequest extends FormRequest
{
    /**
     * 권한 확인 (미들웨어 체인에서 처리)
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var int $maxSizeMb 최대 파일 크기 (MB) */
        $maxSizeMb = (int) plugin_setting('sirsoft-ckeditor5', 'imageMaxSizeMb', 2);

        /** @var int $maxSizeKb Laravel max 규칙은 KB 단위 */
        $maxSizeKb = $maxSizeMb * 1024;

        return [
            'upload' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,jpg,png,gif,webp',
                "max:{$maxSizeKb}",
            ],
        ];
    }

    /**
     * 검증 오류 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxSizeMb = (int) plugin_setting('sirsoft-ckeditor5', 'imageMaxSizeMb', 2);

        return [
            'upload.required'  => __('sirsoft-ckeditor5::messages.upload.required'),
            'upload.file'      => __('sirsoft-ckeditor5::messages.upload.invalid_file'),
            'upload.image'     => __('sirsoft-ckeditor5::messages.upload.not_image'),
            'upload.mimes'     => __('sirsoft-ckeditor5::messages.upload.invalid_mime'),
            'upload.max'       => __('sirsoft-ckeditor5::messages.upload.too_large', ['max' => $maxSizeMb]),
        ];
    }

    /**
     * 검증 실패 시 CKEditor SimpleUploadAdapter 규격으로 응답합니다.
     *
     * Laravel 기본 응답: { message, errors }
     * CKEditor 요구 형식: { error: { message } }
     *
     * @param  Validator  $validator
     * @return never
     */
    protected function failedValidation(Validator $validator): never
    {
        $firstError = collect($validator->errors()->all())->first()
            ?? __('sirsoft-ckeditor5::messages.upload.failed');

        throw new HttpResponseException(
            response()->json(['error' => ['message' => $firstError]], 422)
        );
    }
}
