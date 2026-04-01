import React, { useState, useRef, useEffect, ReactNode } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';

export interface AccordionProps {
  /** 기본 열림 상태 */
  defaultOpen?: boolean;
  /** 외부에서 열림 상태 제어 */
  isOpen?: boolean;
  /** 열림 상태 변경 콜백 */
  onToggle?: (isOpen: boolean) => void;
  /** 추가 클래스명 */
  className?: string;
  /** 인라인 스타일 */
  style?: React.CSSProperties;
  /** 자식 요소 (trigger, content 슬롯) */
  children?: ReactNode;
  /** 비활성화 여부 */
  disabled?: boolean;
}

interface AccordionSlots {
  trigger?: ReactNode;
  content?: ReactNode;
}

/**
 * Accordion 집합 컴포넌트
 *
 * 접기/펼치기가 가능한 아코디언 UI를 제공합니다.
 * trigger 슬롯과 content 슬롯을 통해 헤더와 내용을 정의합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "Accordion",
 *   "props": { "defaultOpen": false },
 *   "children": [
 *     {
 *       "slot": "trigger",
 *       "type": "basic",
 *       "name": "Div",
 *       "children": [{ "type": "basic", "name": "Span", "text": "제목" }]
 *     },
 *     {
 *       "slot": "content",
 *       "type": "basic",
 *       "name": "Div",
 *       "children": [{ "type": "basic", "name": "P", "text": "내용" }]
 *     }
 *   ]
 * }
 */
export const Accordion: React.FC<AccordionProps> = ({
  defaultOpen = false,
  isOpen: controlledIsOpen,
  onToggle,
  className = '',
  style,
  children,
  disabled = false,
}) => {
  // 내부 상태 (제어 모드가 아닐 때 사용)
  const [internalIsOpen, setInternalIsOpen] = useState(defaultOpen);

  // 제어 모드 여부 확인
  const isControlled = controlledIsOpen !== undefined;
  const isOpen = isControlled ? controlledIsOpen : internalIsOpen;

  // 콘텐츠 높이 애니메이션을 위한 ref
  const contentRef = useRef<HTMLDivElement>(null);
  const [contentHeight, setContentHeight] = useState<number | undefined>(
    defaultOpen ? undefined : 0
  );

  // 토글 핸들러
  const handleToggle = () => {
    if (disabled) return;

    const newIsOpen = !isOpen;

    if (!isControlled) {
      setInternalIsOpen(newIsOpen);
    }

    onToggle?.(newIsOpen);
  };

  // 콘텐츠 높이 계산
  useEffect(() => {
    if (contentRef.current) {
      if (isOpen) {
        setContentHeight(contentRef.current.scrollHeight);
        // 애니메이션 완료 후 높이 auto로 설정 (동적 콘텐츠 지원)
        const timer = setTimeout(() => {
          setContentHeight(undefined);
        }, 300);
        return () => clearTimeout(timer);
      } else {
        // 닫힐 때: 현재 높이로 설정 후 0으로 변경 (애니메이션 트리거)
        setContentHeight(contentRef.current.scrollHeight);
        requestAnimationFrame(() => {
          setContentHeight(0);
        });
      }
    }
  }, [isOpen]);

  // children에서 슬롯 추출
  const slots: AccordionSlots = {};

  React.Children.forEach(children, (child) => {
    if (React.isValidElement(child)) {
      const slotName = (child.props as any)?.slot;
      if (slotName === 'trigger') {
        slots.trigger = child;
      } else if (slotName === 'content') {
        slots.content = child;
      }
    }
  });

  // 슬롯이 없으면 children을 그대로 사용 (하위 호환성)
  if (!slots.trigger && !slots.content) {
    const childArray = React.Children.toArray(children);
    if (childArray.length >= 2) {
      slots.trigger = childArray[0];
      slots.content = childArray.slice(1);
    } else if (childArray.length === 1) {
      slots.content = childArray[0];
    }
  }

  return (
    <Div
      className={`border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden ${className}`}
      style={style}
    >
      {/* Trigger (헤더) */}
      <Button
        type="button"
        onClick={handleToggle}
        disabled={disabled}
        className={`w-full flex items-center justify-between p-3 text-left bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors ${
          disabled ? 'opacity-50 cursor-not-allowed' : ''
        } ${isOpen ? 'border-b border-gray-200 dark:border-gray-700' : ''}`}
        aria-expanded={isOpen}
      >
        <Div className="flex-1">
          {slots.trigger}
        </Div>
        {/* 화살표 아이콘 (기본 제공, trigger에서 오버라이드 가능) */}
        {!slots.trigger && (
          <svg
            className={`w-5 h-5 text-gray-500 dark:text-gray-400 transition-transform duration-200 ${
              isOpen ? 'rotate-180' : ''
            }`}
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M19 9l-7 7-7-7"
            />
          </svg>
        )}
      </Button>

      {/* Content (내용) */}
      <Div
        ref={contentRef}
        className="overflow-hidden transition-all duration-300 ease-in-out"
        style={{
          height: contentHeight !== undefined ? `${contentHeight}px` : 'auto',
        }}
      >
        <Div className="bg-white dark:bg-gray-800">
          {slots.content}
        </Div>
      </Div>
    </Div>
  );
};
