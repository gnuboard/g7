<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AllowedPluginFileType implements ValidationRule
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
        'png', 'jpg', 'jpeg', 'svg', 'webp', 'gif', 'ico',

        // Fonts
        'woff', 'woff2', 'ttf', 'otf', 'eot',
    ];

    /**
     * 허용된 파일 타입인지 검증
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('validation.plugin_path.must_be_string'));

            return;
        }

        $extension = strtolower(pathinfo($value, PATHINFO_EXTENSION));
        $allowed = self::getAllowedExtensions();

        if (! in_array($extension, $allowed, true)) {
            $fail(__('validation.plugin_path.file_type_not_allowed', [
                'extension' => $extension,
                'allowed' => implode(', ', $allowed),
            ]));

            return;
        }
    }

    /**
     * 허용된 확장자 목록 반환
     *
     * 로컬 개발 환경에서만 소스맵(`map`)을 덧붙인다. dev 빌드 산출물은
     * `//# sourceMappingURL` 이 개별 에셋 서빙 URL 을 가리키므로 서빙되어야
     * 브라우저 콘솔에 404 가 남지 않는다. 반면 소스맵에는 원본 코드 전문
     * (`sourcesContent`)이 담겨 있고 이 화이트리스트가 에셋 서빙의 유일한
     * 방어선이므로, 운영 환경에서는 어떤 경우에도 서빙하지 않는다.
     *
     * @return array<string> 허용된 확장자 목록
     */
    public static function getAllowedExtensions(): array
    {
        if (app()->environment('local')) {
            return [...self::ALLOWED_EXTENSIONS, 'map'];
        }

        return self::ALLOWED_EXTENSIONS;
    }
}
