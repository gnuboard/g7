import React from 'react';

export interface HelloModuleMemoCardProps {
  /** 메모 제목 */
  title?: string;

  /** 메모 본문 */
  body?: string;

  /** 강조 표시 여부 */
  highlighted?: boolean;

  /**
   * 왼쪽 강조선 색 (이 템플릿 판본에만 있는 prop)
   *
   * 대체본은 원본의 props 를 모두 받되 자기 것을 더 둘 수 있다. 원본을 쓰는 다른 템플릿에는
   * 이 prop 이 없으므로, 이것을 쓰는 레이아웃은 이 템플릿 전용이 된다.
   */
  accent?: string;

  /** 사용자 정의 클래스 */
  className?: string;

  /** DOM id — 레이아웃 편집기 [속성] 탭의 「요소 ID」가 여기에 닿는다 */
  id?: string;

  /** 레이아웃 편집기가 주입하는 표식과 선택 핸들러 (편집 모드 전용) — 원본과 같은 계약으로 루트에 펼친다 */
  editorAttrs?: Record<string, unknown>;
}

/**
 * `gnuboard7-hello_module` 이 제공하는 `MemoCard` 를 **이 템플릿이 대체한 판본**.
 *
 * 개념 ② 의 최소 예시다 — 템플릿이 확장 소유 컴포넌트를 자기 구현으로 갈아끼운다. 디렉토리
 * 이름(`overrides/gnuboard7-hello_module/`)이 곧 대상 확장 식별자이며, 레이아웃 오버라이드
 * (`layouts/overrides/{확장식별자}/`)와 같은 규약이다.
 *
 * **대체해도 마크업 표면은 그대로다.** 작성자는 여전히 같은 태그를 쓰고 레이아웃도 고치지 않는다:
 *
 * ```html
 * <gnuboard7-hello_module.memo-card data-type="composite" title="{{memo.title}}"/>
 * ```
 *
 * 갈리는 것은 구현체뿐이다. 그래서 오버라이드를 붙이거나 떼는 것이 레이아웃에 파급되지 않는다.
 *
 * 대체 권한은 **템플릿에만** 있다. 반대 방향 — 확장이 템플릿 소유 컴포넌트를 대체하는 것 —
 * 은 열려 있지 않다. 확장이 자기 컴포넌트를 특정 템플릿에서만 다르게 내려는 것이라면 그것은
 * 대체가 아니라 판본 선택이고, 확장 쪽 `components/for/{템플릿식별자}/` 가 그 자리다.
 *
 * 대상 확장의 버전 제약은 매니페스트(`components.json` 의 `overrides[].targetVersion`)에
 * 선언한다. 대상이 그 제약을 벗어나면 이 대체본은 폐기되고 **원본이 자리를 지킨다** — 화면이
 * 조용히 사라지지 않는 것이 그 규약의 요점이다.
 */
export const HelloModuleMemoCard: React.FC<HelloModuleMemoCardProps> = ({
  title = '',
  body = '',
  highlighted = false,
  accent = '',
  className = '',
  id,
  editorAttrs,
}) => {
  return (
    <article
      id={id}
      className={`border-l-4 pl-3 ${
        accent || (highlighted ? 'border-l-amber-500' : 'border-l-gray-300')
      } ${className}`}
      {...editorAttrs}
    >
      <h3 className="text-sm font-bold uppercase tracking-wide text-gray-800">{title}</h3>
      <p className="text-sm text-gray-600">{body}</p>
    </article>
  );
};

HelloModuleMemoCard.displayName = 'HelloModuleMemoCard';
