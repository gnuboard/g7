<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;

/**
 * `<permissions>` 를 권한 배열 또는 조건형 객체로 컴파일하는 공용 층.
 *
 * 문서 최상위(레이아웃 전체 권한)와 노드 레벨(요소별 조건 렌더 권한, D36)이 **같은 문법**을 쓰므로
 * 두 층이 이 한 곳을 공유한다 — `<modal>`/`<slot>` 이 위치로 의미가 갈리듯 `<permissions>` 도
 * 문서 직속이면 레이아웃 권한, 노드 안이면 그 노드의 권한이고 컴파일 규칙은 동일하다. 규칙을 양쪽에
 * 복제하면 한쪽만 고쳐 어긋나므로(같은 자리를 두 코드가 컴파일하는 함정) 여기 모은다.
 *
 * 두 형태:
 *   1. 평면 AND — `<permission>` 자식들 → `["a", "b"]` (전부 필요)
 *   2. 조건형 — 단일 `<any-of>`/`<all-of>` → `{"or": [...]}` / `{"and": [...]}` (생성 OR 수정 등)
 * 두 형태 혼용은 금지(평면 `<permission>` 과 조건 블록을 섞을 수 없다).
 */
final class PermissionsCompiler
{
    /**
     * `<permissions>` 요소를 권한 문자열 배열 또는 조건 객체로 컴파일합니다.
     *
     * @param  MarkupElement  $element  `<permissions>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<int|string, mixed> 권한 문자열 배열 또는 조건 객체
     *
     * @throws CompileError 자식이 `<permission>`/`<any-of>`/`<all-of>` 가 아니거나 두 형태를 혼용한 경우
     */
    public function compile(MarkupElement $element, ?string $path): array
    {
        $conditional = null;
        $flat = [];

        foreach ($element->children as $child) {
            if ($child->name === 'any-of' || $child->name === 'all-of') {
                // 조건 블록은 단독이어야 한다 — 평면 권한과 섞이거나 둘 이상일 수 없다.
                if ($conditional !== null || $flat !== []) {
                    throw $this->unexpected($child, $path);
                }

                $conditional = $this->condition($child, $path);

                continue;
            }

            if ($child->name !== 'permission' || $conditional !== null) {
                throw $this->unexpected($child, $path);
            }

            $flat[] = (string) ($child->text ?? '');
        }

        return $conditional ?? $flat;
    }

    /**
     * 조건형 권한(`<any-of>`/`<all-of>`)을 `{or|and: [...]}` 로 컴파일합니다.
     *
     * @param  MarkupElement  $element  `<any-of>` 또는 `<all-of>` 요소
     * @param  string|null  $path  소스 파일 경로
     * @return array<string, array<int, mixed>> `{or|and: [...]}`
     *
     * @throws CompileError 자식이 `<permission>`/`<any-of>`/`<all-of>` 가 아닌 경우
     */
    private function condition(MarkupElement $element, ?string $path): array
    {
        $operator = $element->name === 'all-of' ? 'and' : 'or';
        $operands = [];

        foreach ($element->children as $child) {
            $operands[] = match ($child->name) {
                'permission' => (string) ($child->text ?? ''),
                'all-of', 'any-of' => $this->condition($child, $path),
                default => throw new CompileError(LayoutMarkupErrorCode::UnexpectedStructuralChild, $child->line, $child->column, [
                    'tag' => $child->name,
                    'parent' => $element->name,
                ], $path),
            };
        }

        return [$operator => $operands];
    }

    /**
     * `<permissions>` 안에 올 수 없는 자식 오류를 만듭니다.
     *
     * @param  MarkupElement  $child  자식 요소
     * @param  string|null  $path  소스 파일 경로
     * @return CompileError 컴파일 오류
     */
    private function unexpected(MarkupElement $child, ?string $path): CompileError
    {
        return new CompileError(LayoutMarkupErrorCode::UnexpectedStructuralChild, $child->line, $child->column, [
            'tag' => $child->name,
            'parent' => 'permissions',
        ], $path);
    }
}
