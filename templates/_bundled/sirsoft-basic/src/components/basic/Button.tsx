import React, { forwardRef } from 'react';

// G7Core 전역 객체의 스타일 헬퍼 접근
const G7Core = () => (window as any).G7Core;

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'danger' | 'success';
  size?: 'sm' | 'md' | 'lg';
}

/**
 * 기본 버튼 컴포넌트
 */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(({
  children,
  variant = 'primary',
  size = 'md',
  className = '',
  ...props
}, ref) => {
  // 커서는 부품이 기본으로 준다 — Tailwind v4 는 단추 기본 커서가 `default` 라, 레이아웃이 따로 적지 않은 단추가 화살표였다
  // (관리자 템플릿 Button 의 `button` 클래스와 같은 값, #869 κ). 레이아웃이 다른 커서를 주면 mergeClasses 가 그 값을 남긴다.
  const baseClasses = 'inline-flex items-center justify-center cursor-pointer disabled:cursor-not-allowed';

  // G7Core.style.mergeClasses를 사용하여 충돌하는 Tailwind 클래스 병합
  const mergedClassName = G7Core()?.style?.mergeClasses?.(baseClasses, className)
    ?? `${baseClasses} ${className}`;

  return (
    <button
      ref={ref}
      className={mergedClassName}
      {...props}
    >
      {children}
    </button>
  );
});

Button.displayName = 'Button';
