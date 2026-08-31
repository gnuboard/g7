<?php

namespace App\Rules;

use App\Rules\Concerns\ValidatesExtensionAssetPath;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Template 자산 경로의 Path Traversal 방지 검증.
 *
 * 판정 본문은 형제 두 규칙과 **공유한다** (\App\Rules\Concerns\ValidatesExtensionAssetPath).
 * 종전에는 세 클래스가 주석까지 같은 사본이었고, 한쪽에만 들어간 보강이 나머지 둘에서
 * 누락되어 같은 입력이 통로마다 다르게 판정됐다.
 */
class SafeTemplatePath implements ValidationRule
{
    use ValidatesExtensionAssetPath;

    /**
     * @param  string  $basePath  봉쇄 기준 디렉토리
     * @param  bool  $rejectHiddenSegments  점으로 시작하는 세그먼트를 이 계층에서 거부할지 여부.
     *                                      공개 스토리지 통로는 소비 서비스가 같은 규칙을
     *                                      404 로 강제하므로 끈다 (정적 서빙과 같은 코드).
     */
    public function __construct(
        private string $basePath,
        private bool $rejectHiddenSegments = true
    ) {}

    /**
     * Path Traversal 방지 검증.
     *
     * @param  string  $attribute  속성명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $this->validateExtensionAssetPath($value, $fail, $this->basePath, 'validation.template_path', $this->rejectHiddenSegments);
    }
}
