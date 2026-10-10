<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Enums\LayoutMarkupLintCode;

/**
 * `.g7.html` 소스 파일 하나를 검증된 JSON 레이아웃으로 만드는 최상위 파사드 (S6).
 *
 * 아래 층들이 각 단계를 맡는다 — 토크나이저(S1)가 평면 토큰을, 트리 빌더가 중첩 요소를,
 * DocumentCompiler(S2~S5)가 문서 JSON 을 만든다. 이 파사드는 그 사슬을 한 줄로 잇고, 끝에
 * **강검증과 lint**(S6)를 얹는다. 그래서 커맨드·설치 경로는 이 클래스 하나만 부르면 된다.
 *
 * 강검증 위반은 CompileError 로 던져 컴파일을 세운다(잘못된 JSON 은 만들지 않는다). lint 경고는
 * 컴파일을 막지 않고 결과에 실려 온다(만들되 권하지 않는다). 이 경계가 S6 의 핵심이다 — 현행
 * 파일 설치 경로에는 구조 검증이 아예 없어(계획서 "검증·서빙 파이프라인 사실"), 컴파일 타임이
 * 작성 안전을 담보하는 유일한 지점이기 때문이다.
 */
final class LayoutMarkupCompiler
{
    private readonly MarkupTokenizer $tokenizer;

    private readonly MarkupTreeBuilder $treeBuilder;

    private readonly DocumentCompiler $document;

    private readonly LayoutValidator $validator;

    private readonly LayoutLinter $linter;

    /**
     * 산출 JSON 은 템플릿과 무관하다 — 태그 이름·`data-type` 만으로 정한다. 레지스트리를 주면 그 템플릿
     * 기준 검사(미등록 컴포넌트 강검증·템플릿 기준 lint)를 더한다. 설치·업데이트·조각 주입 경로는 주지
     * 않는다(JSON 설치와 같이 템플릿을 보지 않는다).
     *
     * @param  ComponentRegistry|null  $registry  검사용 템플릿 컴포넌트 레지스트리
     */
    public function __construct(private readonly ?ComponentRegistry $registry = null)
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

        try {
            $this->validator->validate($json, $rootKind, $this->registry, $context, $path);
        } catch (CompileError $error) {
            throw $this->locateError($roots, $error, $context);
        }

        $warnings = $this->linter->lint($json, $rootKind, $context, $path);

        // lint 는 산출 JSON 을 보므로 소스 좌표를 모른다. 위치 없는 경고가 있을 때만 노드·액션에 소스 위치 표식을
        // 붙여 한 번 더 컴파일하고 그 결과로 lint 를 다시 돈다 — 경고가 그 노드·액션의 줄·칸을 싣는다(7.1.0 P16).
        // 표식 JSON 은 lint 에만 쓰고 버린다(반환 JSON 은 위의 표식 없는 산출).
        if ($this->hasUnlocatedWarning($warnings)) {
            $this->document->markSourcePositions(true);

            try {
                $marked = $this->document->compile($roots, $path);
            } finally {
                $this->document->markSourcePositions(false);
            }

            $warnings = $this->linter->lint($marked, $rootKind, $context, $path);
        }

        return new CompileResult($json, $this->locateWarnings($roots, $warnings));
    }

    /**
     * 위치(줄)가 없는 경고가 하나라도 있는지 봅니다.
     *
     * @param  array<int, LintWarning>  $warnings  lint 경고 목록
     * @return bool 위치 없는 경고가 있으면 true
     */
    private function hasUnlocatedWarning(array $warnings): bool
    {
        foreach ($warnings as $warning) {
            if ($warning->getLine() === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 산출 JSON 에서 찾은 lint 경고에 소스 줄·칸을 채웁니다.
     *
     * lint 는 산출 JSON 을 보므로 위치를 모른다(0:0). 설치되지 않은 확장 컴포넌트 경고는 편집기가 그
     * 태그를 가리켜야 고칠 수 있으므로, 경고의 컴포넌트명을 태그명(`식별자.케밥`)으로 되돌려 소스 트리에서
     * 처음 나오는 그 태그의 위치를 싣는다(2026-09-28 결정). 태그를 찾지 못하면 원래 경고를 그대로 둔다.
     *
     * @param  array<int, MarkupElement>  $roots  소스 트리 루트 목록
     * @param  array<int, LintWarning>  $warnings  lint 경고 목록
     * @return array<int, LintWarning> 위치가 채워진 경고 목록
     */
    private function locateWarnings(array $roots, array $warnings): array
    {
        return array_map(function (LintWarning $warning) use ($roots): LintWarning {
            $name = $warning->getParams()['name'] ?? null;

            $locatable = [LayoutMarkupLintCode::ExtensionComponentNotInstalled, LayoutMarkupLintCode::TemplateComponentKept];

            if ($warning->getLine() !== 0 || ! in_array($warning->getCode(), $locatable, true) || ! is_string($name)) {
                return $warning;
            }

            $element = $this->findElementByTag($roots, strtolower(ComponentRegistry::toTag($name)));

            return $element === null ? $warning : $warning->withPosition($element->line, $element->column);
        }, $warnings);
    }

    /**
     * 강검증의 레지스트리 오류에 소스 줄·칸을 채웁니다.
     *
     * 강검증은 산출 JSON 을 보므로 위치를 모른다(0:0). 그대로 두면 편집기 마커가 1행에 찍혀 작성자가 고칠 자리를
     * 찾지 못한다. 미등록 컴포넌트 오류만 그 태그를 소스에서 찾는다 — 저장본 허용 개수(`toleratedComponents`)가
     * 있으면 문서 순서로 허용분을 건너뛴 첫 초과 태그를 가리킨다(검사기가 실패시킨 것과 같은 태그).
     *
     * @param  array<int, MarkupElement>  $roots  소스 트리 루트
     * @param  CompileError  $error  강검증 오류
     * @param  CompileContext  $context  컴파일 문맥
     * @return CompileError 위치를 채운 오류(찾지 못하거나 대상 코드가 아니면 원래 오류)
     */
    private function locateError(array $roots, CompileError $error, CompileContext $context): CompileError
    {
        $name = $error->getParams()['name'] ?? null;

        if ($error->getErrorCode() !== LayoutMarkupErrorCode::ComponentNotInRegistry || $error->getSourceLine() !== 0 || ! is_string($name)) {
            return $error;
        }

        $matches = [];
        $this->collectElementsByTag($roots, strtolower(ComponentRegistry::toTag($name)), $matches);
        $element = $matches[$context->toleratedComponents[$name] ?? 0] ?? $matches[0] ?? null;

        if ($element === null) {
            return $error;
        }

        return new CompileError($error->getErrorCode(), $element->line, $element->column, $error->getParams(), $error->getSourcePath());
    }

    /**
     * 소스 트리에서 주어진 태그명의 요소를 문서 순서로 모두 모읍니다.
     *
     * @param  array<int, MarkupElement>  $elements  탐색할 요소 목록
     * @param  string  $tag  소문자 태그명
     * @param  array<int, MarkupElement>  $found  모은 요소 (참조)
     * @return void 반환값 없음
     */
    private function collectElementsByTag(array $elements, string $tag, array &$found): void
    {
        foreach ($elements as $element) {
            if (strtolower($element->name) === $tag) {
                $found[] = $element;
            }

            $this->collectElementsByTag($element->children, $tag, $found);
        }
    }

    /**
     * 소스 트리에서 주어진 태그명의 첫 요소를 찾습니다 (문서 순서).
     *
     * @param  array<int, MarkupElement>  $elements  탐색할 요소 목록
     * @param  string  $tag  소문자 태그명
     * @return MarkupElement|null 첫 요소, 없으면 null
     */
    private function findElementByTag(array $elements, string $tag): ?MarkupElement
    {
        foreach ($elements as $element) {
            if (strtolower($element->name) === $tag) {
                return $element;
            }

            $found = $this->findElementByTag($element->children, $tag);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 소스의 주석이 전부 산출 JSON 에 실렸는지 확인하고, 실리지 못한 자리를 거부합니다 (S21).
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
     * @param  array<int, MarkupElement>  $roots  소스 트리 루트 목록
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string|null  $path  소스 파일 경로
     * @return void 반환값 없음
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
     * 소스 트리의 주석 달린 요소를 문서 순서대로 모읍니다.
     *
     * @param  array<int, MarkupElement>  $elements  요소 목록
     * @param  array<int, array{element: MarkupElement}>  $out  수집 결과 (참조)
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
