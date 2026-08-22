<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class SafePluginPath implements ValidationRule
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

    public function __construct(
        private string $basePath
    ) {}

    /**
     * Path Traversal 방지 검증
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail(__('validation.plugin_path.must_be_string'));

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
                $fail(__('validation.plugin_path.traversal_detected', ['pattern' => $pattern]));

                return;
            }
        }

        // 절대 경로 방지 (Windows와 Linux 모두 지원)
        if (
            Str::startsWith($decodedPath, '/') ||
            Str::startsWith($decodedPath, '\\') ||
            preg_match('/^[a-zA-Z]:/', $decodedPath)  // Windows 드라이브 문자
        ) {
            $fail(__('validation.plugin_path.absolute_path_not_allowed'));

            return;
        }

        // NULL 바이트 공격 방지
        if (Str::contains($decodedPath, "\0")) {
            $fail(__('validation.plugin_path.null_byte_detected'));

            return;
        }

        // 경로 정규화
        $normalizedPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $decodedPath);

        // 실제 경로 검증
        $fullPath = realpath($this->basePath.DIRECTORY_SEPARATOR.$normalizedPath);

        // realpath 실패 시 (파일이 존재하지 않을 수 있음)
        if ($fullPath === false) {
            // 경로 구성만 검증 (파일 존재 여부는 Controller에서 체크)
            $fullPath = $this->basePath.DIRECTORY_SEPARATOR.$normalizedPath;
        }

        // 기준 경로 외부 접근 방지
        $realBasePath = realpath($this->basePath);
        if ($realBasePath === false || ! Str::startsWith($fullPath, $realBasePath)) {
            $fail(__('validation.plugin_path.outside_base_directory'));

            return;
        }
    }
}
