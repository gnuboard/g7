<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 사용자 추가 에셋(`custom/`) 상대 경로 검증 규칙
 *
 * 판정은 **세그먼트 단위**로 한다. 문자열 포함 검사(`str_contains('..')`)는 정상 파일명
 * (`v1..2.css`)을 막으면서 정작 인코딩된 탈출은 놓치고, 접두 비교(`str_starts_with`)는
 * 아직 없는 파일에서 `realpath` 가 실패해 형제 디렉토리(`custom-evil/`)를 통과시킨다.
 *
 * 서비스 계층에도 같은 판정이 있다. 중복이 아니라 이중화다 — 검증은 사용자에게 422 로
 * 사유를 알리는 자리이고, 서비스의 판정은 다른 호출부(콘솔·훅)까지 덮는 최종 방어선이다.
 */
class SafeCustomAssetPath implements ValidationRule
{
    /**
     * @param  array<int, string>|null  $allowedExtensions  허용 확장자 (null 이면 확장자 검사 생략)
     */
    public function __construct(private readonly ?array $allowedExtensions = null) {}

    /**
     * 경로가 안전한지 검증합니다.
     *
     * @param  string  $attribute  속성명
     * @param  mixed  $value  값
     * @param  Closure  $fail  실패 콜백
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('custom_assets.errors.invalid_path', ['path' => '']));

            return;
        }

        $normalized = str_replace('\\', '/', trim($value));

        if ($normalized === '' || str_starts_with($normalized, '/')) {
            $fail(__('custom_assets.errors.invalid_path', ['path' => $value]));

            return;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                $fail(__('custom_assets.errors.invalid_path', ['path' => $value]));

                return;
            }
        }

        if ($this->allowedExtensions === null) {
            return;
        }

        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));

        if (! in_array($extension, $this->allowedExtensions, true)) {
            $fail(__('custom_assets.errors.extension_not_allowed', ['extension' => $extension]));
        }
    }
}
