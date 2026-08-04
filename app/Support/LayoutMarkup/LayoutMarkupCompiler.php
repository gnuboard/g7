<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;

/**
 * `.g7.html` 저작 파일 하나를 검증된 JSON 레이아웃으로 만드는 최상위 파사드 (S6).
 *
 * 아래 층들이 각 단계를 맡는다 — 토크나이저(S1)가 평면 토큰을, 트리 빌더가 중첩 요소를,
 * DocumentCompiler(S2~S5)가 문서 JSON 을 만든다. 이 파사드는 그 사슬을 한 줄로 잇고, 끝에
 * **강검증과 lint**(S6)를 얹는다. 그래서 커맨드·설치 경로는 이 클래스 하나만 부르면 된다.
 *
 * 강검증 위반은 CompileError 로 던져 컴파일을 세운다(잘못된 JSON 은 만들지 않는다). lint 경고는
 * 컴파일을 막지 않고 결과에 실려 온다(만들되 권하지 않는다). 이 경계가 S6 의 핵심이다 — 현행
 * 파일 설치 경로에는 구조 검증이 아예 없어(계획서 "검증·서빙 파이프라인 사실"), 컴파일 타임이
 * 저작 안전을 담보하는 유일한 지점이기 때문이다.
 */
final class LayoutMarkupCompiler
{
    private readonly MarkupTokenizer $tokenizer;

    private readonly MarkupTreeBuilder $treeBuilder;

    private readonly DocumentCompiler $document;

    private readonly LayoutValidator $validator;

    private readonly LayoutLinter $linter;

    /**
     * @param  ComponentRegistry  $registry  대상 템플릿(+활성 확장) 컴포넌트 레지스트리
     */
    public function __construct(private readonly ComponentRegistry $registry)
    {
        $this->tokenizer = new MarkupTokenizer;
        $this->treeBuilder = new MarkupTreeBuilder;
        $this->document = new DocumentCompiler($registry);
        $this->validator = new LayoutValidator;
        $this->linter = new LayoutLinter($registry);
    }

    /**
     * `.g7.html` 소스를 검증된 JSON 레이아웃 + lint 경고로 컴파일합니다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @param  string|null  $path  소스 파일 경로 (오류·경고 메시지용)
     * @param  CompileContext|null  $context  검증·lint 문맥 (없으면 기본 문맥 = config 정책만)
     * @return CompileResult 산출 JSON + lint 경고
     *
     * @throws CompileError 토큰화·트리·노드·문서·강검증 어느 단계든 위반 시
     */
    public function compile(string $source, ?string $path = null, ?CompileContext $context = null): CompileResult
    {
        $context ??= new CompileContext;

        $tokens = $this->tokenizer->tokenize($source, $path);
        $roots = $this->treeBuilder->build($tokens, $path);
        $json = $this->document->compile($roots, $path);

        $this->assertCommentsAttached($roots, $json, $path);

        // DocumentCompiler 가 단일 루트를 이미 보장했으므로 roots[0] 은 존재한다.
        $rootKind = $roots[0]->name;

        $this->validator->validate($json, $rootKind, $this->registry, $context, $path);
        $warnings = $this->linter->lint($json, $rootKind, $context, $path);

        return new CompileResult($json, $warnings);
    }

    /**
     * 저작본의 주석이 전부 산출 JSON 에 실렸는지 확인하고, 실리지 못한 자리를 거부합니다 (S21).
     *
     * 지키는 명제는 **주석은 산출에 남거나 거부된다 — 조용히 사라지지 않는다** 하나다. 왕복
     * 게이트는 JSON→마크업→JSON 이라 마크업에만 있던 주석의 소실을 볼 수 없어, 그 자리를 보는
     * 눈이 여기 말고는 없다.
     *
     * 판정을 **태그 목록으로 적지 않는다.** 같은 태그라도 자리에 따라 갈리기 때문이다 — Modal
     * 자식 `<footer>` 는 `props.footer` 로 접혀 주석 자리가 없고, 같은 이름의 `Footer` 컴포넌트
     * 노드는 있다. 그래서 대조 대상은 언제나 그 컴파일이 실제로 낸 산출이다.
     *
     * 같은 문구를 여러 자리에 쓸 수 있으므로 포함 여부가 아니라 **다중집합**으로 센다.
     *
     * @param  array<int, MarkupElement>  $roots  저작 트리 루트 목록
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string|null  $path  소스 파일 경로
     *
     * @throws CompileError 주석이 산출에 실리지 못한 경우
     */
    private function assertCommentsAttached(array $roots, array $json, ?string $path): void
    {
        $authored = [];
        $this->collectAuthoredComments($roots, $authored);

        if ($authored === []) {
            return;
        }

        $pool = [];
        $this->collectEmittedComments($json, $pool);
        $remaining = array_count_values($pool);

        foreach ($authored as $entry) {
            $text = $entry['element']->comment;

            if (($remaining[$text] ?? 0) > 0) {
                $remaining[$text]--;

                continue;
            }

            throw new CompileError(
                LayoutMarkupErrorCode::CommentNotAttachable,
                $entry['element']->line,
                $entry['element']->column,
                ['tag' => $entry['element']->name, 'hint' => $this->attachmentHint($entry['element']->name)],
                $path,
            );
        }
    }

    /**
     * 저작 트리의 주석 달린 요소를 문서 순서대로 모읍니다.
     *
     * @param  array<int, MarkupElement>  $elements  요소 목록
     * @param  array<int, array{element: MarkupElement}>  $out  수집 결과 (참조)
     */
    private function collectAuthoredComments(array $elements, array &$out): void
    {
        foreach ($elements as $element) {
            if ($element->comment !== null) {
                $out[] = ['element' => $element];
            }

            $this->collectAuthoredComments($element->children, $out);
        }
    }

    /**
     * 산출 JSON 어디에 있든 `comment` 값을 전부 모읍니다.
     *
     * @param  mixed  $node  JSON 조각
     * @param  array<int, string>  $out  수집 결과 (참조)
     */
    private function collectEmittedComments(mixed $node, array &$out): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ($key === 'comment' && is_string($value)) {
                $out[] = $value;
            }

            $this->collectEmittedComments($value, $out);
        }
    }

    /**
     * 거부된 자리에 대해 정본 자리를 안내하는 문구를 고릅니다.
     *
     * 안내일 뿐 판정이 아니다 — 판정은 산출 대조가 이미 끝냈고, 여기서는 가장 자주 걸리는 자리에
     * 구체적인 대안을 준다.
     *
     * @param  string  $tag  거부된 태그명
     * @return string 안내 문구
     */
    private function attachmentHint(string $tag): string
    {
        return match ($tag) {
            'on' => __('exceptions.layout_markup.comment_hint.on'),
            default => __('exceptions.layout_markup.comment_hint.default'),
        };
    }
}
