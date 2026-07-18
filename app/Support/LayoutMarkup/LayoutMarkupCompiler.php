<?php

namespace App\Support\LayoutMarkup;

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
        $this->linter = new LayoutLinter;
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

        // DocumentCompiler 가 단일 루트를 이미 보장했으므로 roots[0] 은 존재한다.
        $rootKind = $roots[0]->name;

        $this->validator->validate($json, $rootKind, $this->registry, $context, $path);
        $warnings = $this->linter->lint($json, $rootKind, $context, $path);

        return new CompileResult($json, $warnings);
    }
}
