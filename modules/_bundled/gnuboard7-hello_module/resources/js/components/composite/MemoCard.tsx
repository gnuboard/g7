import React from 'react';

export interface MemoCardProps {
  /** 메모 제목 */
  title?: string;

  /** 메모 본문 */
  body?: string;

  /** 강조 표시 여부 */
  highlighted?: boolean;

  /** 사용자 정의 클래스 */
  className?: string;

  /** DOM id — 레이아웃 편집기 [속성] 탭의 「요소 ID」가 여기에 닿는다 */
  id?: string;

  /**
   * 레이아웃 편집기가 주입하는 표식(`data-editor-*`)과 선택 핸들러 (편집 모드 전용).
   *
   * composite 컴포넌트는 도메인 prop 만 받으므로 이 객체를 받아 시각적 루트에 펼쳐야 편집기가
   * 이 노드를 찾는다(선택·이동·삭제). 사용자 화면에서는 주입되지 않아 아무 일도 하지 않는다.
   */
  editorAttrs?: Record<string, unknown>;
}

/**
 * 메모 한 건을 카드로 보여주는 기본 판본.
 *
 * 이 컴포넌트는 **모듈이 제공하는 컴포넌트** 의 최소 예시다. 작성자는 모듈 식별자가 앞에 붙은
 * 태그로 쓴다:
 *
 * ```html
 * <gnuboard7-hello_module.memo-card data-type="composite" title="{{memo.title}}" body="{{memo.body}}"/>
 * ```
 *
 * 태그의 `gnuboard7-hello-module` 은 모듈 식별자 `gnuboard7-hello_module` 의 언더스코어를
 * 하이픈으로 접은 것이다 — 마크업 태그명에는 언더스코어를 쓸 수 없기 때문이다. 레지스트리에
 * 등록되는 이름은 실제 식별자를 그대로 쓴 `gnuboard7-hello_module.MemoCard` 다.
 */
export const MemoCard: React.FC<MemoCardProps> = ({
  title = '',
  body = '',
  highlighted = false,
  className = '',
  id,
  editorAttrs,
}) => {
  const tone = highlighted
    ? 'border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-blue-950'
    : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800';

  return (
    <div id={id} className={`rounded-lg border p-4 ${tone} ${className}`} {...editorAttrs}>
      <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100">{title}</h3>
      <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">{body}</p>
    </div>
  );
};

MemoCard.displayName = 'MemoCard';
