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
}

/**
 * 메모 한 건을 카드로 보여주는 기본 판본.
 *
 * 이 컴포넌트는 **모듈이 제공하는 컴포넌트** 의 최소 예시다. 저작자는 모듈 식별자가 앞에 붙은
 * 태그로 쓴다:
 *
 * ```html
 * <gnuboard7-hello-module-memo-card title="{{memo.title}}" body="{{memo.body}}"/>
 * ```
 *
 * 태그의 `gnuboard7-hello-module` 은 모듈 식별자 `gnuboard7-hello_module` 의 언더스코어를
 * 하이픈으로 접은 것이다 — 저작 태그명에는 언더스코어를 쓸 수 없기 때문이다. 레지스트리에
 * 등록되는 이름은 실제 식별자를 그대로 쓴 `gnuboard7-hello_module.MemoCard` 다.
 */
export const MemoCard: React.FC<MemoCardProps> = ({
  title = '',
  body = '',
  highlighted = false,
  className = '',
}) => {
  const tone = highlighted
    ? 'border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-blue-950'
    : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800';

  return (
    <div className={`rounded-lg border p-4 ${tone} ${className}`}>
      <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100">{title}</h3>
      <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">{body}</p>
    </div>
  );
};

MemoCard.displayName = 'MemoCard';
