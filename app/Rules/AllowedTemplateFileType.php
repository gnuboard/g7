<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AllowedTemplateFileType implements ValidationRule
{
    /**
     * 허용된 파일 확장자 목록
     */
    private const ALLOWED_EXTENSIONS = [
        // Scripts
        'js', 'mjs',

        // Styles
        'css',

        // Data
        'json',

        // Images
        'png', 'jpg', 'jpeg', 'svg', 'webp', 'gif',

        // Fonts
        'woff', 'woff2', 'ttf', 'otf', 'eot',
    ];

    /**
     * 허용된 파일 타입인지 검증
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail(__('validation.template_path.must_be_string'));
            return;
        }

        $extension = strtolower(pathinfo($value, PATHINFO_EXTENSION));

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $fail(__('validation.template_path.file_type_not_allowed', [
                'extension' => $extension,
                'allowed' => implode(', ', self::ALLOWED_EXTENSIONS),
            ]));
            return;
        }
    }

    /**
     * 허용된 확장자 목록 반환
     *
     * @return array
     */
    public static function getAllowedExtensions(): array
    {
        return self::ALLOWED_EXTENSIONS;
    }
}
