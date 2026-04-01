<?php

namespace App\Http\Requests\User;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 아바타 업로드 요청 클래스
 *
 * 프로필 이미지 업로드 시 사용되는 validation 규칙을 정의합니다.
 */
class UploadAvatarRequest extends FormRequest
{
    /**
     * 최대 파일 크기 (KB)
     */
    public const MAX_FILE_SIZE = 2048;

    /**
     * 허용되는 MIME 타입 목록
     */
    public const ALLOWED_MIMES = ['jpeg', 'png', 'gif', 'webp'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'avatar' => [
                'required',
                'image',
                'mimes:'.implode(',', self::ALLOWED_MIMES),
                'max:'.self::MAX_FILE_SIZE,
            ],
        ];

        return HookManager::applyFilters('core.user.upload_avatar_rules', $rules);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => __('user.validation.avatar_required'),
            'avatar.image' => __('user.validation.avatar_image'),
            'avatar.mimes' => __('user.validation.avatar_mimes'),
            'avatar.max' => __('user.validation.avatar_max'),
        ];
    }
}
