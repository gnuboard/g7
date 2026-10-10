<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use LogicException;

/**
 * `.g7.html` 마크업 형식의 경량 토크나이저 — 소스 문자열을 평면 토큰 스트림으로 바꾼다.
 *
 * HTML5 파서를 쓰지 않는 이유는 두 가지다. ① 브라우저 파서는 **오류를 복구**하도록
 * 설계되어 있어(미닫힘 태그 자동 보정 등) 작성 실수를 침묵으로 삼킨다 — 컴파일러가
 * 존재하는 이유와 정반대다. ② `<column>`·`<on>` 같은 커스텀 엘리먼트에 HTML 의 암묵
 * 규칙(`<p>` 자동 닫힘, `<table>` foster parenting)이 개입하면 작성 의도가 왜곡된다.
 *
 * 따라서 본 토크나이저는 HTML5 의 **부분집합**만 인정하고 나머지는 라인·컬럼이 붙은
 * `CompileError` 로 거부한다. 인정 범위:
 *
 *  - **요소·텍스트·주석**만. DOCTYPE·CDATA·처리 명령(`<?`)은 거부한다.
 *  - **속성값은 항상 인용**(`"` 또는 `'`). 무인용 값은 거부한다(문법 3원칙 — 속성 JSON 추방과
 *    같은 취지로, 값 경계를 눈으로 확정할 수 있게 한다).
 *  - **불리언 속성은 존재/부재**로 표기한다(`<field required>`). 값 없는 속성은 `value === null`.
 *  - **태그명은 소문자·kebab**. `<Div>` 는 제안형 오류로 거부한다.
 *  - **속성명은 원문 보존**(대소문자·대괄호·콜론). `:serverSidePagination`·`filters[0][field]` 가 실사용이다.
 *  - **문자 참조는 세미콜론 필수**(`&amp;`). 세미콜론 없는 `&` 는 문자 그대로 보존한다 —
 *    표현식 `{{a && b}}` 를 작성 가능하게 유지하기 위한 의도적 선택이다. 반대로 `&foo;` 처럼
 *    참조 모양인데 알려지지 않은 이름은 오타로 보고 거부한다.
 *  - **raw text 요소 없음**. `<script>` 는 외부 JS 로더 선언(`src`/`id`)이라 본문을 갖지 않으므로,
 *    HTML 의 `<script>` raw text 규칙을 적용하지 않고 일반 요소로 다룬다.
 *
 * 검출하지 **않는** 것: 태그 짝 맞춤·중첩 오류. 토큰 스트림은 중첩을 모르므로 미닫힘
 * `<div>` 는 트리를 만드는 상위 컴파일러가 잡는다(파일 끝에서 태그 자체가 끝나지 않은
 * 경우만 여기서 잡는다).
 *
 * 전처리: BOM 제거 + 개행 정규화(CRLF·CR → LF). 개행 정규화를 생략하면 Windows 에서
 * 작성한 파일의 텍스트 노드에 `\r` 이 섞여 산출 JSON 이 플랫폼마다 달라진다.
 */
final class MarkupTokenizer
{
    /**
     * 태그명 규칙 — 소문자 kebab, 또는 확장 컴포넌트의 `확장식별자.케밥`.
     *
     * 점 앞은 확장 식별자(`vendor-name`, 단어 구분 밑줄 허용)를 그대로 싣는다. 컴파일러는 점에서 나눠
     * JSON 의 `식별자.이름` 을 목록 없이 만든다(ComponentRegistry::fromTag).
     */
    public const TAG_NAME_PATTERN = '/^(?:[a-z0-9_]+(?:-[a-z0-9_]+)+\\.)?[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';

    /**
     * 내용을 가질 수 없어 종료 태그 없이 자동으로 닫히는 요소.
     *
     * HTML5 표준 void 요소 14종에서 **`meta`·`img`·`input` 을 제외한 11종**이다. `<meta>` 는
     * 문서 메타 섹션이며 하위에 `<seo>` 를 두므로(매핑 사전 A) 컨테이너다 — void 로
     * 두면 `<seo>` 마크업이 문법상 불가능해진다.
     *
     * `<img>`·`<input>` 도 void 에서 뺐다(D36) — G7 의 Img·Input 컴포넌트는 `<on>` 액션을
     * 실을 수 있는데(클릭 이미지·onChange 폼필드), void 로 두면 자식을 못 실어 액션 있는 노드가
     * 마크업 문법으로 표현되지 못한다. 빈 경우는 자가닫힘(`<input .../>`)으로, 액션이 있으면 열고
     * 닫아(`<input>…<on/>…</input>`) 담는다. 나머지(`br`/`hr`/`col` 등)는 액션 대상이 아니라 존치 —
     * `<br>` 을 쓴 작성자가 "미등록 태그" 대신 "닫히지 않은 태그" 라는 엉뚱한 오류를 받지 않게 한다.
     */
    public const VOID_ELEMENTS = [
        'area',
        'base',
        'br',
        'col',
        'embed',
        'hr',
        'link',
        'param',
        'source',
        'track',
        'wbr',
    ];

    /** HTML5 공백 문자 (tab, LF, FF, CR, space) */
    private const WHITESPACE = " \t\n\f\r";

    /** 전처리를 마친 소스 */
    private string $source = '';

    /** 소스 바이트 길이 */
    private int $length = 0;

    /** 현재 바이트 오프셋 */
    private int $offset = 0;

    /** 현재 라인 (1-base) */
    private int $line = 1;

    /** 현재 라인이 시작되는 바이트 오프셋 */
    private int $lineStart = 0;

    /** 오류 메시지에 실을 소스 경로 */
    private ?string $path = null;

    /**
     * 소스를 토큰 스트림으로 변환합니다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @param  string|null  $path  소스 파일 경로 (오류 메시지용, 문자열 직접 컴파일 시 null)
     * @return array<int, MarkupToken> 토큰 스트림 (소스 순서 보존)
     *
     * @throws CompileError 문법 부분집합을 벗어난 표기를 만난 경우
     */
    public function tokenize(string $source, ?string $path = null): array
    {
        $this->source = $this->preprocess($source);
        $this->length = strlen($this->source);
        $this->offset = 0;
        $this->line = 1;
        $this->lineStart = 0;
        $this->path = $path;

        $tokens = [];

        while ($this->offset < $this->length) {
            $before = $this->offset;

            $tokens[] = $this->source[$this->offset] === '<'
                ? $this->consumeMarkup()
                : $this->consumeText();

            // 모든 분기는 반드시 오프셋을 전진시킨다. 하나라도 제자리에 머물면 무한
            // 루프이므로, 조용히 매달리는 대신 즉시 터뜨린다(퍼지 입력 방어).
            if ($this->offset === $before) {
                throw new LogicException('MarkupTokenizer stalled at offset '.$before);
            }
        }

        return $tokens;
    }

    /**
     * BOM 을 제거하고 개행을 LF 로 정규화합니다.
     *
     * @param  string  $source  원본 소스
     * @return string 전처리된 소스
     */
    private function preprocess(string $source): string
    {
        if (str_starts_with($source, "\u{FEFF}")) {
            $source = substr($source, 3);
        }

        return str_replace(["\r\n", "\r"], "\n", $source);
    }

    /**
     * `<` 로 시작하는 구간을 해석해 토큰 하나를 반환합니다.
     *
     * @return MarkupToken 태그 또는 주석 토큰
     *
     * @throws CompileError 지원하지 않는 마크업이거나 텍스트의 `<` 를 이스케이프하지 않은 경우
     */
    private function consumeMarkup(): MarkupToken
    {
        if ($this->matches('<!--')) {
            return $this->consumeComment();
        }

        if ($this->matches('<!') || $this->matches('<?')) {
            throw $this->error(LayoutMarkupErrorCode::UnsupportedMarkup, ['found' => $this->preview()]);
        }

        if ($this->matches('</')) {
            return $this->consumeEndTag();
        }

        $next = $this->source[$this->offset + 1] ?? '';

        if (ctype_alpha($next)) {
            return $this->consumeStartTag();
        }

        throw $this->error(LayoutMarkupErrorCode::UnescapedLt);
    }

    /**
     * 주석(`<!-- -->`)을 소비합니다.
     *
     * 주석 내용은 문자 참조를 디코드하지 않고 원문 그대로 싣는다 (HTML 주석 의미론).
     *
     * @return MarkupToken 주석 토큰
     *
     * @throws CompileError 주석이 닫히지 않은 경우
     */
    private function consumeComment(): MarkupToken
    {
        $line = $this->line;
        $column = $this->column();

        $contentStart = $this->offset + 4;
        $end = strpos($this->source, '-->', $contentStart);

        if ($end === false) {
            throw $this->error(LayoutMarkupErrorCode::EofInComment);
        }

        $text = substr($this->source, $contentStart, $end - $contentStart);
        $this->advance($end + 3 - $this->offset);

        return MarkupToken::comment($text, $line, $column);
    }

    /**
     * 시작 태그를 소비합니다.
     *
     * @return MarkupToken 시작 태그 토큰
     *
     * @throws CompileError 태그명·속성 표기가 부분집합을 벗어난 경우
     */
    private function consumeStartTag(): MarkupToken
    {
        $line = $this->line;
        $column = $this->column();

        $this->advance(1);
        $name = $this->consumeTagName();

        /** @var array<int, MarkupAttribute> $attributes */
        $attributes = [];
        $seen = [];
        $selfClosing = false;

        while (true) {
            $spacing = $this->skipWhitespace();

            if ($this->offset >= $this->length) {
                throw $this->error(LayoutMarkupErrorCode::EofInTag, ['tag' => $name]);
            }

            $char = $this->source[$this->offset];

            if ($char === '>') {
                $this->advance(1);
                break;
            }

            if ($char === '/' && ($this->source[$this->offset + 1] ?? '') === '>') {
                $this->advance(2);
                $selfClosing = true;
                break;
            }

            // `class="a"id="b"` — HTML5 파서는 관대하지만, 속성 경계가 눈에 보이지 않으면
            // 작성자가 값의 끝을 오해한다. 공백을 강제한다.
            if ($spacing === 0 && $attributes !== []) {
                throw $this->error(LayoutMarkupErrorCode::MissingAttributeSeparator, [
                    'tag' => $name,
                    'attr' => $this->previewAttributeName(),
                ]);
            }

            $attribute = $this->consumeAttribute($name);

            if (isset($seen[$attribute->name])) {
                throw new CompileError(
                    LayoutMarkupErrorCode::DuplicateAttribute,
                    $attribute->line,
                    $attribute->column,
                    ['tag' => $name, 'attr' => $attribute->name],
                    $this->path,
                );
            }

            $seen[$attribute->name] = true;
            $attributes[] = $attribute;
        }

        $void = in_array($name, self::VOID_ELEMENTS, true);

        return MarkupToken::startTag(
            name: $name,
            attributes: $attributes,
            selfClosing: $selfClosing || $void,
            void: $void,
            line: $line,
            column: $column,
        );
    }

    /**
     * 종료 태그를 소비합니다.
     *
     * @return MarkupToken 종료 태그 토큰
     *
     * @throws CompileError 종료 태그에 속성·자기닫힘이 붙거나 void 요소의 종료 태그인 경우
     */
    private function consumeEndTag(): MarkupToken
    {
        $line = $this->line;
        $column = $this->column();

        $this->advance(2);
        $name = $this->consumeTagName();

        $this->skipWhitespace();

        if ($this->offset >= $this->length) {
            throw $this->error(LayoutMarkupErrorCode::EofInTag, ['tag' => $name]);
        }

        $char = $this->source[$this->offset];

        if ($char === '/') {
            throw $this->error(LayoutMarkupErrorCode::EndTagSelfClosing, ['tag' => $name]);
        }

        if ($char !== '>') {
            throw $this->error(LayoutMarkupErrorCode::EndTagWithAttributes, ['tag' => $name]);
        }

        if (in_array($name, self::VOID_ELEMENTS, true)) {
            throw new CompileError(LayoutMarkupErrorCode::VoidEndTag, $line, $column, ['tag' => $name], $this->path);
        }

        $this->advance(1);

        return MarkupToken::endTag($name, $line, $column);
    }

    /**
     * 태그명을 소비하고 소문자·kebab 규칙을 검증합니다.
     *
     * @return string 태그명 (소문자)
     *
     * @throws CompileError 태그명이 비었거나 대문자·허용 밖 문자를 포함한 경우
     */
    private function consumeTagName(): string
    {
        $line = $this->line;
        $column = $this->column();

        $length = strcspn($this->source, self::WHITESPACE.'/>', $this->offset);
        $raw = substr($this->source, $this->offset, $length);
        $this->advance($length);

        if ($raw === '') {
            throw new CompileError(LayoutMarkupErrorCode::EmptyTagName, $line, $column, [], $this->path);
        }

        $lower = strtolower($raw);

        // 소문자 kebab. 확장 컴포넌트는 `확장식별자.케밥` — 점 앞은 확장 식별자 그대로(밑줄 허용)다.
        if (preg_match(self::TAG_NAME_PATTERN, $lower) !== 1) {
            throw new CompileError(LayoutMarkupErrorCode::InvalidTagName, $line, $column, ['found' => $raw], $this->path);
        }

        if ($raw !== $lower) {
            throw new CompileError(
                LayoutMarkupErrorCode::TagUppercase,
                $line,
                $column,
                ['found' => $raw, 'suggestion' => $lower],
                $this->path,
            );
        }

        return $lower;
    }

    /**
     * 속성 하나를 소비합니다.
     *
     * @param  string  $tag  속성이 실린 태그명 (오류 메시지용)
     * @return MarkupAttribute 속성
     *
     * @throws CompileError 속성명·값 표기가 부분집합을 벗어난 경우
     */
    private function consumeAttribute(string $tag): MarkupAttribute
    {
        $line = $this->line;
        $column = $this->column();

        $nameLength = strcspn($this->source, self::WHITESPACE.'"\'>/=<', $this->offset);

        if ($nameLength === 0) {
            throw $this->error(LayoutMarkupErrorCode::InvalidAttributeName, ['found' => $this->source[$this->offset]]);
        }

        $name = substr($this->source, $this->offset, $nameLength);
        $this->advance($nameLength);

        // `=` 앞뒤 공백은 HTML5 표준 허용 범위 — 값 유무 판정은 공백을 건너뛴 위치로 한다.
        $equals = $this->offset + strspn($this->source, self::WHITESPACE, $this->offset);

        if (($this->source[$equals] ?? '') !== '=') {
            return new MarkupAttribute($name, null, $line, $column);
        }

        $this->advance($equals - $this->offset + 1);
        $this->skipWhitespace();

        [$value, $quote] = $this->consumeAttributeValue($name, $tag);

        return new MarkupAttribute($name, $value, $line, $column, $quote);
    }

    /**
     * 인용된 속성값을 소비하고 문자 참조를 디코드합니다.
     *
     * @param  string  $name  속성명 (오류 메시지용)
     * @param  string  $tag  태그명 (오류 메시지용)
     * @return array{0: string, 1: string} [값, 따옴표 문자]
     *
     * @throws CompileError 값이 인용되지 않았거나 닫히지 않은 경우
     */
    private function consumeAttributeValue(string $name, string $tag): array
    {
        if ($this->offset >= $this->length) {
            throw $this->error(LayoutMarkupErrorCode::EofInTag, ['tag' => $tag]);
        }

        $quote = $this->source[$this->offset];

        if ($quote !== '"' && $quote !== "'") {
            $length = strcspn($this->source, self::WHITESPACE.'>', $this->offset);

            throw $this->error(LayoutMarkupErrorCode::UnquotedAttributeValue, [
                'attr' => $name,
                'found' => substr($this->source, $this->offset, max($length, 1)),
            ]);
        }

        $this->advance(1);
        $value = '';

        while (true) {
            if ($this->offset >= $this->length) {
                throw $this->error(LayoutMarkupErrorCode::UnterminatedAttributeValue, ['attr' => $name, 'quote' => $quote]);
            }

            // `<` 는 인용된 값 안에서 문자 그대로 유효하다 — `data-if="{{a < b}}"` 마크업을 위해
            // 반드시 통과시켜야 한다.
            $run = strcspn($this->source, $quote.'&', $this->offset);

            if ($run > 0) {
                $value .= substr($this->source, $this->offset, $run);
                $this->advance($run);

                continue;
            }

            if ($this->source[$this->offset] === $quote) {
                $this->advance(1);

                return [$value, $quote];
            }

            $value .= $this->consumeCharacterReference();
        }
    }

    /**
     * 텍스트를 소비하고 문자 참조를 디코드합니다.
     *
     * 공백은 원문 그대로 보존한다 — 인덴트 공백을 버릴지는 트리를 아는 상위 컴파일러가
     * 판단할 문제이며, 토크나이저가 미리 버리면 되돌릴 수 없다.
     *
     * @return MarkupToken 텍스트 토큰
     *
     * @throws CompileError 알 수 없는 문자 참조를 만난 경우
     */
    private function consumeText(): MarkupToken
    {
        $line = $this->line;
        $column = $this->column();
        $text = '';

        while ($this->offset < $this->length) {
            $run = strcspn($this->source, '<&', $this->offset);

            if ($run > 0) {
                $text .= substr($this->source, $this->offset, $run);
                $this->advance($run);

                continue;
            }

            if ($this->source[$this->offset] === '<') {
                break;
            }

            $text .= $this->consumeCharacterReference();
        }

        return MarkupToken::text($text, $line, $column);
    }

    /**
     * `&` 로 시작하는 문자 참조를 디코드합니다.
     *
     * 세미콜론으로 끝나는 참조만 인정하고, 그 밖의 `&` 는 문자 그대로 반환한다
     * (표현식 `{{a && b}}` 보존).
     *
     * @return string 디코드된 문자 (또는 참조가 아닌 `&`)
     *
     * @throws CompileError 참조 모양이지만 알 수 없거나 유효하지 않은 코드포인트인 경우
     */
    private function consumeCharacterReference(): string
    {
        if (preg_match('/\G&#(?:([0-9]+)|[xX]([0-9a-fA-F]+));/', $this->source, $matches, 0, $this->offset) === 1) {
            $codepoint = ($matches[2] ?? '') !== ''
                ? (int) hexdec($matches[2])
                : (int) $matches[1];

            $character = $this->codepointToCharacter($codepoint);

            if ($character === null) {
                throw $this->error(LayoutMarkupErrorCode::InvalidEntity, ['found' => $matches[0]]);
            }

            $this->advance(strlen($matches[0]));

            return $character;
        }

        if (preg_match('/\G&[A-Za-z][A-Za-z0-9]*;/', $this->source, $matches, 0, $this->offset) === 1) {
            $decoded = html_entity_decode($matches[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($decoded === $matches[0]) {
                throw $this->error(LayoutMarkupErrorCode::UnknownEntity, ['found' => $matches[0]]);
            }

            $this->advance(strlen($matches[0]));

            return $decoded;
        }

        $this->advance(1);

        return '&';
    }

    /**
     * 코드포인트를 UTF-8 문자로 변환합니다.
     *
     * @param  int  $codepoint  코드포인트
     * @return string|null UTF-8 문자, 표현할 수 없는 코드포인트면 null
     */
    private function codepointToCharacter(int $codepoint): ?string
    {
        // 0(NUL)·서로게이트 영역·유니코드 상한 초과는 문서에 실을 수 없는 값이다.
        if ($codepoint === 0 || $codepoint > 0x10FFFF) {
            return null;
        }

        if ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) {
            return null;
        }

        $character = mb_chr($codepoint, 'UTF-8');

        return $character === false ? null : $character;
    }

    /**
     * 공백을 건너뜁니다.
     *
     * @return int 건너뛴 공백 바이트 수
     */
    private function skipWhitespace(): int
    {
        $length = strspn($this->source, self::WHITESPACE, $this->offset);
        $this->advance($length);

        return $length;
    }

    /**
     * 현재 위치가 주어진 문자열로 시작하는지 판정합니다.
     *
     * @param  string  $needle  비교 문자열
     * @return bool 일치하면 true
     */
    private function matches(string $needle): bool
    {
        return substr_compare($this->source, $needle, $this->offset, strlen($needle)) === 0;
    }

    /**
     * 오프셋을 전진시키며 라인 위치를 갱신합니다.
     *
     * @param  int  $count  전진할 바이트 수
     * @return void 반환값 없음
     */
    private function advance(int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $chunk = substr($this->source, $this->offset, $count);
        $newlines = substr_count($chunk, "\n");

        if ($newlines > 0) {
            $this->line += $newlines;
            $this->lineStart = $this->offset + (int) strrpos($chunk, "\n") + 1;
        }

        $this->offset += $count;
    }

    /**
     * 현재 컬럼(1-base, 문자 단위)을 계산합니다.
     *
     * 바이트가 아닌 문자 단위인 이유: 소스 파일에는 한국어 라벨이 흔한데, 바이트 컬럼은
     * 편집기가 표시하는 커서 위치와 어긋나 오류 메시지의 좌표 구실을 못 한다.
     *
     * @return int 컬럼 번호 (1-base)
     */
    private function column(): int
    {
        $prefix = substr($this->source, $this->lineStart, $this->offset - $this->lineStart);

        return mb_strlen($prefix, 'UTF-8') + 1;
    }

    /**
     * 오류 메시지에 실을 현재 위치의 짧은 미리보기를 만듭니다.
     *
     * @return string 미리보기 문자열
     */
    private function preview(): string
    {
        $length = strcspn($this->source, self::WHITESPACE.'>', $this->offset);

        return substr($this->source, $this->offset, min(max($length, 1), 20));
    }

    /**
     * 현재 위치에서 시작하는 속성명만 잘라 미리보기를 만듭니다.
     *
     * @return string 속성명 미리보기
     */
    private function previewAttributeName(): string
    {
        $length = strcspn($this->source, self::WHITESPACE.'"\'>/=<', $this->offset);

        return substr($this->source, $this->offset, min(max($length, 1), 20));
    }

    /**
     * 현재 위치를 좌표로 갖는 컴파일 오류를 만듭니다.
     *
     * @param  LayoutMarkupErrorCode  $code  에러 코드
     * @param  array<string, string|int>  $params  메시지 치환 파라미터
     * @return CompileError 컴파일 오류
     */
    private function error(LayoutMarkupErrorCode $code, array $params = []): CompileError
    {
        return new CompileError($code, $this->line, $this->column(), $params, $this->path);
    }
}
