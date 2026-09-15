<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use Exception;

/**
 * `.g7.html` 마크업 형식 컴파일 과정에서 발생하는 모든 오류의 단일 예외.
 *
 * 이 예외는 작성자(사람·AI)에게 **어디를 어떻게 고쳐야 하는지**를 알려주는 것이
 * 유일한 목적이다. 따라서 세 가지를 항상 함께 싣는다:
 *
 *  1. **위치** — 소스 파일 경로 + 라인 + 컬럼. 컴파일 대상이 수백 줄짜리 레이아웃
 *     문서이므로 위치 없는 메시지는 사실상 쓸모가 없다.
 *  2. **에러 코드** — `LayoutMarkupErrorCode` 안정 식별자. 다국어 메시지가 번역되어도
 *     테스트·문서·audit 룰은 이 코드로 오류를 지목할 수 있다.
 *  3. **제안** — 올바른 표기를 메시지 본문에 포함한다(`<Div>` → `<div>`). G7 문법은
 *     HTML 유사 방언이라 Vue/JSX 관용구 반사 작성이 예상되는 지대이며, 제안 없는
 *     거부는 작성자를 같은 실수로 되돌린다.
 *
 * 메시지 본문은 하드코딩하지 않고 `lang/{ko,en}/exceptions.php` 의
 * `layout_markup.*` 키에서 가져온다(예외 다국어 규정).
 */
class CompileError extends Exception
{
    /**
     * 프로퍼티명이 `line`/`column` 이 아닌 `sourceLine`/`sourceColumn` 인 것은 이름
     * 취향이 아니다 — `Exception::$line` 은 **예외를 던진 PHP 파일의 라인**이라 같은
     * 이름을 쓰면 소스 파일의 라인을 덮어써 오류 좌표가 조용히 뒤바뀐다.
     *
     * @param  LayoutMarkupErrorCode  $errorCode  실패 사유 코드
     * @param  int  $sourceLine  오류 발생 라인 (1-base)
     * @param  int  $sourceColumn  오류 발생 컬럼 (1-base, 문자 단위)
     * @param  array<string, string|int>  $params  메시지 치환 파라미터
     * @param  string|null  $sourcePath  소스 파일 경로 (문자열 직접 컴파일 시 null)
     */
    public function __construct(
        private readonly LayoutMarkupErrorCode $errorCode,
        private readonly int $sourceLine,
        private readonly int $sourceColumn,
        private readonly array $params = [],
        private readonly ?string $sourcePath = null,
    ) {
        parent::__construct($this->buildMessage());
    }

    /**
     * 소스 파일의 좌표가 아닌, **파일 전체**에 대한 오류를 만듭니다.
     *
     * 레지스트리 매니페스트(`components.json`)처럼 작성자가 쓴 `.g7.html` 의 특정 지점을
     * 가리킬 수 없는 오류가 있다. 이때 라인·컬럼을 0 으로 두고 위치 봉투도 파일명만 싣는다 —
     * 있지도 않은 "1행 1열" 을 지어내면 작성자가 그 자리를 들여다보게 만든다.
     *
     * @param  LayoutMarkupErrorCode  $code  실패 사유 코드
     * @param  string|null  $path  대상 파일 경로
     * @param  array<string, string|int>  $params  메시지 치환 파라미터
     * @return self 컴파일 오류
     */
    public static function inFile(LayoutMarkupErrorCode $code, ?string $path, array $params = []): self
    {
        return new self($code, 0, 0, $params, $path);
    }

    /**
     * 실패 사유 코드를 반환합니다.
     *
     * @return LayoutMarkupErrorCode 에러 코드
     */
    public function getErrorCode(): LayoutMarkupErrorCode
    {
        return $this->errorCode;
    }

    /**
     * 오류 발생 라인을 반환합니다.
     *
     * @return int 라인 번호 (1-base)
     */
    public function getSourceLine(): int
    {
        return $this->sourceLine;
    }

    /**
     * 오류 발생 컬럼을 반환합니다.
     *
     * @return int 컬럼 번호 (1-base, 문자 단위)
     */
    public function getSourceColumn(): int
    {
        return $this->sourceColumn;
    }

    /**
     * 소스 파일 경로를 반환합니다.
     *
     * @return string|null 파일 경로, 문자열 직접 컴파일 시 null
     */
    public function getSourcePath(): ?string
    {
        return $this->sourcePath;
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, string|int> 치환 파라미터
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * 위치 접두어가 없는 오류 본문만 반환합니다.
     *
     * @return string 다국어 처리된 오류 본문
     */
    public function getDetail(): string
    {
        return $this->errorCode->message($this->params);
    }

    /**
     * 리포트·테스트용 구조화 표현을 반환합니다.
     *
     * @return array{code: string, line: int, column: int, path: string|null, detail: string} 구조화된 오류 정보
     */
    public function toArray(): array
    {
        return [
            'code' => $this->errorCode->value,
            'line' => $this->sourceLine,
            'column' => $this->sourceColumn,
            'path' => $this->sourcePath,
            'detail' => $this->getDetail(),
        ];
    }

    /**
     * 위치 + 본문 + 코드를 결합한 최종 메시지를 생성합니다.
     *
     * @return string 다국어 처리된 전체 메시지
     */
    private function buildMessage(): string
    {
        $replace = [
            'line' => $this->sourceLine,
            'column' => $this->sourceColumn,
            'code' => $this->errorCode->value,
        ];

        $message = match (true) {
            // 좌표 없는 파일 오류 (inFile) — 라인·컬럼을 지어내지 않는다.
            $this->sourceLine === 0 => __('exceptions.layout_markup.error_in_file', [
                'file' => (string) $this->sourcePath,
                'code' => $this->errorCode->value,
            ]),
            $this->sourcePath === null => __('exceptions.layout_markup.error_at_inline', $replace),
            default => __('exceptions.layout_markup.error_at', $replace + ['file' => $this->sourcePath]),
        };

        // 본문은 봉투 치환이 모두 끝난 뒤에 끼워 넣는다. 본문에는 작성자가 쓴 원문
        // (속성명 등)이 실리므로, 함께 치환하면 `:line` 같은 이름의 속성이 라인 번호로
        // 둔갑한다.
        return str_replace(':detail', $this->getDetail(), $message);
    }
}
