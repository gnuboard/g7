<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Enums\MarkupTokenType;

/**
 * 평면 토큰 스트림(S1)을 요소 트리로 세우고, 중첩 오류를 잡는 층.
 *
 * 토크나이저는 중첩을 모르므로 짝 맞춤·미닫힘은 **여기가 유일한 검출 지점**이다. HTML5 파서의
 * 암묵 규칙(미닫힘 자동 보정, `<p>` 자동 닫힘, 잘못된 중첩의 foster parenting)은 하나도
 * 적용하지 않는다 — 작성 실수를 조용히 다른 트리로 바꿔 놓는 것이 컴파일러의 존재 이유와
 * 정반대이기 때문이다.
 *
 * 세 가지를 함께 정한다:
 *
 *  1. **텍스트 정규화** — 개행이 섞인 앞뒤 공백만 인덴트로 보고 지운다. 개행 없는 순수 공백은
 *     작성 의도이므로 보존한다(`<span> — {{v}}</span>`·`<span> $t:auth.agree_required</span>`
 *     같은 실사용 17건이 앞 공백에 의미를 싣는다). 무조건 trim 하면 이 값들이 조용히 바뀐다.
 *  2. **빈 내용과 자기닫힘의 구분** — `<span></span>` 은 `text: ""`, `<span/>` 은 text 키 없음.
 *     둘 다 실사용이라(빈 Span·빈 Th 4건) 하나로 뭉개면 왕복이 깨진다.
 *  3. **주석 귀속** — 주석은 **직후** 요소의 `comment` 키가 된다. 귀속 대상이 없거나 한 요소에
 *     둘 이상 붙으면 거부한다(주석 내용을 조용히 버리지 않기 위해서다 — 여러 줄 주석은 한
 *     `<!-- -->` 안에 개행으로 쓰면 그대로 왕복한다).
 */
final class MarkupTreeBuilder
{
    /** 오류 메시지에 실을 소스 경로 */
    private ?string $path = null;

    /**
     * 토큰 스트림을 요소 트리로 세웁니다.
     *
     * @param  array<int, MarkupToken>  $tokens  토크나이저가 만든 토큰 스트림
     * @param  string|null  $path  소스 파일 경로 (오류 메시지용)
     * @return array<int, MarkupElement> 최상위 요소 목록 (문서 종류 판별은 상위 층 몫)
     *
     * @throws CompileError 중첩·짝 맞춤·텍스트 혼재·주석 귀속 규칙을 위반한 경우
     */
    public function build(array $tokens, ?string $path = null): array
    {
        $this->path = $path;

        /** @var array<int, array{token: MarkupToken, children: array<int, MarkupElement>, texts: array<int, MarkupToken>, comment: ?string}> $stack */
        $stack = [];
        $roots = [];
        $rootTexts = [];
        $pendingComment = null;

        foreach ($tokens as $token) {
            switch ($token->type) {
                case MarkupTokenType::Comment:
                    if ($pendingComment !== null) {
                        throw $this->error($token, LayoutMarkupErrorCode::ConsecutiveComments);
                    }

                    $pendingComment = trim((string) $token->text);
                    break;

                case MarkupTokenType::Text:
                    if ($stack === []) {
                        $rootTexts[] = $token;
                    } else {
                        $stack[count($stack) - 1]['texts'][] = $token;
                    }
                    break;

                case MarkupTokenType::StartTag:
                    if ($token->selfClosing) {
                        $element = new MarkupElement(
                            name: (string) $token->name,
                            attributes: $token->attributes,
                            children: [],
                            text: null,
                            comment: $pendingComment,
                            selfClosing: true,
                            line: $token->line,
                            column: $token->column,
                        );
                        $pendingComment = null;
                        $this->attach($element, $stack, $roots);
                        break;
                    }

                    $stack[] = ['token' => $token, 'children' => [], 'texts' => [], 'comment' => $pendingComment];
                    $pendingComment = null;
                    break;

                case MarkupTokenType::EndTag:
                    if ($stack === []) {
                        throw $this->error($token, LayoutMarkupErrorCode::UnexpectedEndTag, ['tag' => (string) $token->name]);
                    }

                    $open = $stack[count($stack) - 1];

                    if ($open['token']->name !== $token->name) {
                        throw $this->error($token, LayoutMarkupErrorCode::MismatchedEndTag, [
                            'tag' => (string) $token->name,
                            'open' => (string) $open['token']->name,
                            'open_line' => $open['token']->line,
                        ]);
                    }

                    array_pop($stack);

                    if ($pendingComment !== null) {
                        throw $this->error($token, LayoutMarkupErrorCode::DanglingComment);
                    }

                    $element = $this->close($open);
                    $this->attach($element, $stack, $roots);
                    break;
            }
        }

        if ($stack !== []) {
            $open = $stack[count($stack) - 1]['token'];

            throw $this->error($open, LayoutMarkupErrorCode::UnclosedTag, ['tag' => (string) $open->name]);
        }

        if ($pendingComment !== null) {
            throw $this->error(end($tokens) ?: null, LayoutMarkupErrorCode::DanglingComment);
        }

        $this->rejectStrayText($rootTexts);

        return $roots;
    }

    /**
     * 열린 요소를 닫아 텍스트·자식을 확정합니다.
     *
     * @param  array{token: MarkupToken, children: array<int, MarkupElement>, texts: array<int, MarkupToken>, comment: ?string}  $open  열린 요소 상태
     * @return MarkupElement 완성된 요소
     *
     * @throws CompileError 텍스트와 자식 요소가 공존하는 경우
     */
    private function close(array $open): MarkupElement
    {
        $token = $open['token'];
        $children = $open['children'];

        $raw = '';

        foreach ($open['texts'] as $text) {
            $raw .= (string) $text->text;
        }

        $text = $this->normalizeText($raw);

        // 자식 요소가 있는데 남은 텍스트가 인덴트뿐이면 텍스트는 없는 것이다. 인덴트가 아닌
        // 텍스트가 남았다면 그대로 실어 보낸다 — 그것이 오류인지는 **노드 컴파일러**가 판정한다.
        // 여기서 거부하면 `<span><class-map .../>텍스트</span>` 처럼 구조 태그(노드 키 운반체)와
        // 텍스트가 함께 오는 정상 마크업까지 막힌다. 구조 태그를 걷어낸 뒤에야 진짜 혼재를 안다.
        if ($children !== [] && $text === '') {
            $text = null;
        }

        return new MarkupElement(
            name: (string) $token->name,
            attributes: $token->attributes,
            children: $children,
            text: $text,
            comment: $open['comment'],
            selfClosing: false,
            line: $token->line,
            column: $token->column,
        );
    }

    /**
     * 인덴트 성격의 앞뒤 공백만 제거합니다.
     *
     * 판정 기준은 **개행 포함 여부**다. 작성자가 태그를 줄바꿈해서 생긴 공백에는 반드시 개행이
     * 섞이지만, 값에 의미를 싣는 공백(`" — {{v}}"` 의 앞 공백)에는 개행이 없다.
     *
     * @param  string  $raw  텍스트 토큰을 이어붙인 원문
     * @return string 정규화된 텍스트
     */
    private function normalizeText(string $raw): string
    {
        if (preg_match('/^\s*\n\s*/', $raw, $leading) === 1) {
            $raw = substr($raw, strlen($leading[0]));
        }

        if (preg_match('/\s*\n\s*$/', $raw, $trailing) === 1) {
            $raw = substr($raw, 0, strlen($raw) - strlen($trailing[0]));
        }

        return $raw;
    }

    /**
     * 요소 밖(문서 최상위)의 텍스트를 거부합니다.
     *
     * 최상위 텍스트는 담길 노드가 없어 조용히 사라진다 — 작성자는 화면에 글자가 나올 것으로
     * 기대하므로 침묵 소실 대신 거부한다.
     *
     * @param  array<int, MarkupToken>  $texts  최상위 텍스트 토큰 목록
     *
     * @throws CompileError 공백이 아닌 텍스트가 있는 경우
     */
    private function rejectStrayText(array $texts): void
    {
        foreach ($texts as $text) {
            if ($text->isWhitespaceOnly()) {
                continue;
            }

            throw $this->error($text, LayoutMarkupErrorCode::TextOutsideElement, [
                'found' => $this->preview((string) $text->text),
            ]);
        }
    }

    /**
     * 완성된 요소를 부모(없으면 루트 목록)에 붙입니다.
     *
     * @param  MarkupElement  $element  완성된 요소
     * @param  array<int, array{token: MarkupToken, children: array<int, MarkupElement>, texts: array<int, MarkupToken>, comment: ?string}>  $stack  열린 요소 스택
     * @param  array<int, MarkupElement>  $roots  최상위 요소 목록
     */
    private function attach(MarkupElement $element, array &$stack, array &$roots): void
    {
        if ($stack === []) {
            $roots[] = $element;

            return;
        }

        $stack[count($stack) - 1]['children'][] = $element;
    }

    /**
     * 오류 메시지에 실을 짧은 텍스트 미리보기를 만듭니다.
     *
     * @param  string  $text  원문 텍스트
     * @return string 미리보기
     */
    private function preview(string $text): string
    {
        $trimmed = trim($text);

        return mb_strlen($trimmed, 'UTF-8') > 20
            ? mb_substr($trimmed, 0, 20, 'UTF-8').'…'
            : $trimmed;
    }

    /**
     * 토큰 위치를 좌표로 갖는 컴파일 오류를 만듭니다.
     *
     * @param  MarkupToken|null  $token  오류 위치의 토큰
     * @param  LayoutMarkupErrorCode  $code  에러 코드
     * @param  array<string, string|int>  $params  메시지 치환 파라미터
     * @return CompileError 컴파일 오류
     */
    private function error(?MarkupToken $token, LayoutMarkupErrorCode $code, array $params = []): CompileError
    {
        return new CompileError($code, $token?->line ?? 1, $token?->column ?? 1, $params, $this->path);
    }
}
