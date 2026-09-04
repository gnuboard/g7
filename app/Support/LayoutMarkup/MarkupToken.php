<?php

namespace App\Support\LayoutMarkup;

use App\Enums\MarkupTokenType;

/**
 * 토크나이저가 방출하는 토큰 하나 (평면 스트림의 원소).
 *
 * 토큰 스트림은 **중첩을 표현하지 않는다** — `<div><span/></div>` 는 시작·시작·종료
 * 3토큰이며, 짝 맞춤과 트리 구성은 상위 컴파일러가 수행한다. 토크나이저 층을 얇게
 * 유지하는 대가로, 미닫힘·불일치 종료 태그 같은 **중첩 오류는 여기서 검출되지 않는다**
 * (파일 끝에서 태그 자체가 끝나지 않은 경우만 토크나이저 오류다).
 *
 * 모든 토큰은 소스 위치(라인·컬럼)를 싣는다. 이 위치가 곧 상위 층 오류 메시지의
 * 좌표가 되므로, 토큰이 위치를 잃으면 컴파일러 전체가 "어디가 틀렸는지" 를 잃는다.
 */
final class MarkupToken
{
    /**
     * @param  MarkupTokenType  $type  토큰 종류
     * @param  int  $line  토큰 시작 라인 (1-base)
     * @param  int  $column  토큰 시작 컬럼 (1-base)
     * @param  string|null  $name  태그명 (태그 토큰 전용, 소문자)
     * @param  array<int, MarkupAttribute>  $attributes  속성 목록 (시작 태그 전용, 소스 순서 보존)
     * @param  bool  $selfClosing  자기닫힘 여부 (`/>` 명시 또는 void 요소 자동 닫힘)
     * @param  bool  $void  void 요소(내용을 가질 수 없는 요소) 여부
     * @param  string|null  $text  텍스트·주석 내용 (해당 토큰 전용)
     */
    private function __construct(
        public readonly MarkupTokenType $type,
        public readonly int $line,
        public readonly int $column,
        public readonly ?string $name = null,
        public readonly array $attributes = [],
        public readonly bool $selfClosing = false,
        public readonly bool $void = false,
        public readonly ?string $text = null,
    ) {}

    /**
     * 시작 태그 토큰을 생성합니다.
     *
     * @param  string  $name  태그명 (소문자)
     * @param  array<int, MarkupAttribute>  $attributes  속성 목록
     * @param  bool  $selfClosing  자기닫힘 여부
     * @param  bool  $void  void 요소 여부
     * @param  int  $line  시작 라인
     * @param  int  $column  시작 컬럼
     * @return self 시작 태그 토큰
     */
    public static function startTag(
        string $name,
        array $attributes,
        bool $selfClosing,
        bool $void,
        int $line,
        int $column,
    ): self {
        return new self(
            type: MarkupTokenType::StartTag,
            line: $line,
            column: $column,
            name: $name,
            attributes: $attributes,
            selfClosing: $selfClosing,
            void: $void,
        );
    }

    /**
     * 종료 태그 토큰을 생성합니다.
     *
     * @param  string  $name  태그명 (소문자)
     * @param  int  $line  시작 라인
     * @param  int  $column  시작 컬럼
     * @return self 종료 태그 토큰
     */
    public static function endTag(string $name, int $line, int $column): self
    {
        return new self(type: MarkupTokenType::EndTag, line: $line, column: $column, name: $name);
    }

    /**
     * 텍스트 토큰을 생성합니다.
     *
     * @param  string  $text  텍스트 (문자 참조 디코드 후)
     * @param  int  $line  시작 라인
     * @param  int  $column  시작 컬럼
     * @return self 텍스트 토큰
     */
    public static function text(string $text, int $line, int $column): self
    {
        return new self(type: MarkupTokenType::Text, line: $line, column: $column, text: $text);
    }

    /**
     * 주석 토큰을 생성합니다.
     *
     * @param  string  $text  주석 내용 (`<!--` 와 `-->` 사이 원문)
     * @param  int  $line  시작 라인
     * @param  int  $column  시작 컬럼
     * @return self 주석 토큰
     */
    public static function comment(string $text, int $line, int $column): self
    {
        return new self(type: MarkupTokenType::Comment, line: $line, column: $column, text: $text);
    }

    /**
     * 이름으로 속성을 찾습니다.
     *
     * @param  string  $name  속성명 (대소문자 구분)
     * @return MarkupAttribute|null 속성, 없으면 null
     */
    public function attribute(string $name): ?MarkupAttribute
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute->name === $name) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * 속성 존재 여부를 판정합니다.
     *
     * @param  string  $name  속성명 (대소문자 구분)
     * @return bool 존재하면 true
     */
    public function hasAttribute(string $name): bool
    {
        return $this->attribute($name) !== null;
    }

    /**
     * 공백만으로 이루어진 텍스트 토큰인지 판정합니다.
     *
     * 소스 인덴트에서 생기는 공백 텍스트를 상위 컴파일러가 걸러낼 때 쓴다 —
     * 토크나이저는 판정만 제공하고 스스로 버리지 않는다(공백 원문 보존).
     *
     * @return bool 텍스트 토큰이면서 공백뿐이면 true
     */
    public function isWhitespaceOnly(): bool
    {
        return $this->type === MarkupTokenType::Text && trim((string) $this->text, " \t\n\r\0\x0B") === '';
    }
}
