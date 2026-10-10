import React from 'react';
import type { MemoCardProps } from '../../composite/MemoCard';

/**
 * `MemoCard` 의 **gnuboard7-hello_admin_template 전용 판본**.
 *
 * 개념 ③ 의 최소 예시다 — 모듈이 **자기** 컴포넌트를 특정 템플릿에서만 다른 모양으로 낸다.
 * 관리자 화면은 목록 밀도가 높아 카드보다 한 줄 표기가 맞으므로, 같은 이름·같은 props 로
 * 다른 구현을 낸다.
 *
 * 마크업 표면은 기본 판본과 **완전히 같다** — 작성자는 어느 판본이 쓰이는지 알 필요가 없고,
 * 레이아웃도 고치지 않는다:
 *
 * ```html
 * <gnuboard7-hello_module.memo-card data-type="composite" title="{{memo.title}}"/>
 * ```
 *
 * 판본 **선택은 코어가 한다**. 이 파일은 `components/for/{템플릿식별자}/` 에 있다는 사실만으로
 * "그 템플릿용" 이라고 선언하며, 활성 템플릿과 대조하는 판정은 레지스트리가 전담한다. 모듈이
 * 활성 템플릿을 읽어 스스로 분기하면 확장마다 판정 규칙과 폴백이 갈린다.
 *
 * 이 자리를 `components/overrides/` 라고 부르지 않는 이유: 오버라이드는 **남의** 컴포넌트를
 * 대체하는 것이고 그 권한은 템플릿에만 있다. 여기서 하는 일은 자기 것의 판본 선택이라 다른
 * 개념이다.
 */
export const MemoCard: React.FC<MemoCardProps> = ({
  title = '',
  body = '',
  highlighted = false,
  className = '',
  id,
  editorAttrs,
}) => {
  return (
    <div
      id={id}
      className={`flex items-baseline gap-2 border-b border-gray-200 py-2 dark:border-gray-700 ${className}`}
      {...editorAttrs}
    >
      <span
        className={`text-sm font-medium ${
          highlighted
            ? 'text-blue-700 dark:text-blue-300'
            : 'text-gray-900 dark:text-gray-100'
        }`}
      >
        {title}
      </span>
      <span className="truncate text-xs text-gray-500 dark:text-gray-400">{body}</span>
    </div>
  );
};

MemoCard.displayName = 'MemoCard';
