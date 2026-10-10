<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupLintCode;

/**
 * `.g7.html` 정적 lint 이 발견한 경고 하나.
 *
 * `CompileError` 와 형제 관계이되 **던지지 않는다** — lint 은 컴파일을 막지 않고 수집되어 함께
 * 보고된다. 그래서 예외가 아니라 값 객체다. 위치(라인·컬럼·경로)를 함께 싣는 이유는 CompileError
 * 와 같다: 수백 줄짜리 문서에서 위치 없는 경고는 사실상 쓸모가 없다. 다만 lint 은 컴파일 산출
 * JSON 을 대상으로 하는 경우가 많아 소스 좌표를 특정하지 못할 수 있고, 그때는 라인·컬럼을 0 으로
 * 두어 "문서 전체" 경고임을 나타낸다(지어낸 좌표로 작성자를 엉뚱한 자리로 보내지 않는다).
 */
final class LintWarning
{
    /**
     * @param  LayoutMarkupLintCode  $code  경고 코드
     * @param  int  $line  경고 위치 라인 (1-base, 0 이면 문서 전체)
     * @param  int  $column  경고 위치 컬럼 (1-base, 0 이면 문서 전체)
     * @param  array<string, string|int>  $params  메시지 치환 파라미터
     * @param  string|null  $path  소스 파일 경로 (문자열 직접 컴파일 시 null)
     */
    public function __construct(
        private readonly LayoutMarkupLintCode $code,
        private readonly int $line = 0,
        private readonly int $column = 0,
        private readonly array $params = [],
        private readonly ?string $path = null,
    ) {}

    /**
     * 경고 코드를 반환합니다.
     *
     * @return LayoutMarkupLintCode 경고 코드
     */
    public function getCode(): LayoutMarkupLintCode
    {
        return $this->code;
    }

    /**
     * 경고 위치 라인을 반환합니다.
     *
     * @return int 라인 번호 (1-base, 0 이면 문서 전체)
     */
    public function getLine(): int
    {
        return $this->line;
    }

    /**
     * 경고 위치 컬럼을 반환합니다.
     *
     * @return int 컬럼 번호 (1-base, 0 이면 문서 전체)
     */
    public function getColumn(): int
    {
        return $this->column;
    }

    /**
     * 소스 파일 경로를 반환합니다.
     *
     * @return string|null 파일 경로, 문자열 직접 컴파일 시 null
     */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /**
     * 메시지 치환 인자를 반환합니다.
     *
     * @return array<string, mixed> 치환 인자
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * 같은 경고를 소스 위치만 바꿔 새로 만듭니다.
     *
     * lint 는 산출 JSON 을 보므로 소스 줄·칸을 모른다 — 소스 트리를 가진 쪽이 위치를 찾아 채울 때 쓴다.
     *
     * @param  int  $line  시작 태그 라인 (1-base)
     * @param  int  $column  시작 태그 칸 (1-base)
     * @return self 위치가 채워진 경고
     */
    public function withPosition(int $line, int $column): self
    {
        return new self($this->code, $line, $column, $this->params, $this->path);
    }

    /**
     * 위치 접두어가 없는 경고 본문만 반환합니다.
     *
     * @return string 다국어 처리된 경고 본문
     */
    public function getDetail(): string
    {
        return $this->code->message($this->params);
    }

    /**
     * 리포트·테스트용 구조화 표현을 반환합니다.
     *
     * @return array{code: string, line: int, column: int, path: string|null, detail: string} 구조화된 경고 정보
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'line' => $this->line,
            'column' => $this->column,
            'path' => $this->path,
            'detail' => $this->getDetail(),
        ];
    }

    /**
     * 위치 + 본문 + 코드를 결합한 사람용 한 줄 메시지를 생성합니다.
     *
     * `CompileError::buildMessage` 와 같은 봉투를 재사용한다 — 경고도 오류와 동일한 형태로
     * 보여야 작성자가 둘을 나란히 읽는다. 본문(`:detail`)은 봉투 치환이 끝난 뒤 끼워 넣어,
     * 본문에 실린 작성자 원문이 `:line` 같은 이름과 충돌하지 않게 한다.
     *
     * @return string 다국어 처리된 전체 메시지
     */
    public function toMessage(): string
    {
        $replace = ['line' => $this->line, 'column' => $this->column, 'code' => $this->code->value];

        $message = match (true) {
            $this->line === 0 => __('exceptions.layout_markup.error_in_file', [
                'file' => (string) $this->path,
                'code' => $this->code->value,
            ]),
            $this->path === null => __('exceptions.layout_markup.error_at_inline', $replace),
            default => __('exceptions.layout_markup.error_at', $replace + ['file' => $this->path]),
        };

        return str_replace(':detail', $this->getDetail(), $message);
    }
}
