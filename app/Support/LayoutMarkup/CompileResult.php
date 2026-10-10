<?php

namespace App\Support\LayoutMarkup;

/**
 * `.g7.html` 문서 하나를 컴파일한 결과 — 산출 JSON + lint 경고.
 *
 * 컴파일이 성공했다는 것과 경고가 없다는 것은 별개다. 강검증 위반은 CompileError 로 던져져 여기까지
 * 오지 못하지만, lint 경고는 컴파일을 막지 않으므로 산출 JSON 과 함께 실려 온다. 커맨드·설치 경로가
 * 둘을 나눠 다룰 수 있게(JSON 은 쓰고 경고는 보고) 값 객체로 묶는다.
 */
final class CompileResult
{
    /**
     * @param  array<string, mixed>  $json  산출 레이아웃 JSON
     * @param  array<int, LintWarning>  $warnings  lint 경고 목록 (없으면 빈 배열)
     */
    public function __construct(
        public readonly array $json,
        public readonly array $warnings = [],
    ) {}

    /**
     * lint 경고가 하나라도 있는지 반환합니다.
     *
     * @return bool 경고가 있으면 true
     */
    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
