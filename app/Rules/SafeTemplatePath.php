<?php

namespace App\Rules;

use App\Support\Filesystem\PathGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class SafeTemplatePath implements ValidationRule
{
    /**
     * Path Traversal 패턴 목록
     */
    private const TRAVERSAL_PATTERNS = [
        '../',
        '..\\',
        '//',
        '%2e%2e%2f',  // URL encoded ../
        '%2e%2e/',    // Partial URL encoded
        '%2e%2e%5c',  // URL encoded ..\
        '%2e%2e\\',   // Partial URL encoded
        '..%2f',      // Mixed encoding
        '..%5c',      // Mixed encoding
        '.%2e/',      // Partial encoding
        '.%2e\\',     // Partial encoding
    ];

    /**
     * 규칙을 만듭니다.
     *
     * @param  string  $basePath  템플릿 루트 절대 경로 (이 폴더 밖 경로는 거부)
     */
    public function __construct(
        private string $basePath
    ) {}

    /**
     * Path Traversal 방지 검증
     *
     * @param  string  $attribute  검증 대상 속성 이름
     * @param  mixed  $value  템플릿 루트 기준 상대 경로
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('validation.template_path.must_be_string'));

            return;
        }

        // URL 디코딩 (다중 인코딩 공격 방지)
        $decodedPath = $value;
        $previousPath = '';

        // 최대 5회 디코딩 (무한 루프 방지)
        for ($i = 0; $i < 5 && $decodedPath !== $previousPath; $i++) {
            $previousPath = $decodedPath;
            $decodedPath = urldecode($decodedPath);
        }

        // Path Traversal 패턴 감지
        $lowerPath = strtolower($decodedPath);
        foreach (self::TRAVERSAL_PATTERNS as $pattern) {
            if (Str::contains($lowerPath, strtolower($pattern))) {
                $fail(__('validation.template_path.traversal_detected', ['pattern' => $pattern]));

                return;
            }
        }

        // 절대 경로 방지 (Windows와 Linux 모두 지원)
        if (
            Str::startsWith($decodedPath, '/') ||
            Str::startsWith($decodedPath, '\\') ||
            preg_match('/^[a-zA-Z]:/', $decodedPath)  // Windows 드라이브 문자
        ) {
            $fail(__('validation.template_path.absolute_path_not_allowed'));

            return;
        }

        // NULL 바이트 공격 방지
        if (Str::contains($decodedPath, "\0")) {
            $fail(__('validation.template_path.null_byte_detected'));

            return;
        }

        // 실제 경로 — 아직 없는 파일이면 가장 가까운 실재 상위 폴더 기준으로 정리한다(파일 존재 여부는 Controller 에서 체크).
        $fullPath = PathGuard::resolve($this->basePath.'/'.$decodedPath);

        // 기준 경로 외부 접근 방지 — 폴더 경계를 지킨다(문자열 접두 비교는 `base-x` 같은 형제 폴더를 통과시켰다, #144).
        $realBasePath = realpath($this->basePath);
        if ($realBasePath === false || ! PathGuard::isWithin($realBasePath, $fullPath)) {
            $fail(__('validation.template_path.outside_base_directory'));

            return;
        }
    }
}
