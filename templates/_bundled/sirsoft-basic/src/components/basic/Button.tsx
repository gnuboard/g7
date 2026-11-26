import React, { forwardRef } from 'react';

// G7Core 전역 객체의 스타일 헬퍼 접근
const G7Core = () => (window as any).G7Core;

/** 버튼 모양 — 적으면 `btn btn-{variant}` 의미 클래스를 붙인다 (7.2.1). accent 는 템플릿 강조색(포인트 색)을 따른다 */
export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'success' | 'ghost' | 'outline' | 'accent';

/** 버튼 크기 — `sm` → `btn-sm`, `lg` → `btn-lg`, `md`·미지정 → 붙이지 않음 */
export type ButtonSize = 'sm' | 'md' | 'lg';

/**
 * variant·size 로 붙일 의미 클래스를 계산합니다.
 *
 * variant 를 **적었을 때만** `btn btn-{variant}` 를 붙인다 — variant 를 적지 않은 기존 버튼(레이아웃 className 으로
 * 모양을 내는 버튼)은 바뀌지 않는다. 종전에는 prop 을 받기만 하고 클래스를 붙이지 않았다.
 *
 * @param variant 버튼 모양
 * @param size 버튼 크기
 * @returns 의미 클래스 문자열 (없으면 빈 문자열)
 */
export function buttonVariantClasses(variant?: ButtonVariant, size?: ButtonSize): string {
  const classes: string[] = [];
  if (variant) {
    classes.push('btn', `btn-${variant}`);
  }
  if (size === 'sm') {
    classes.push('btn-sm');
  } else if (size === 'lg') {
    classes.push('btn-lg');
  }
  return classes.join(' ');
}

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  /** 버튼 모양 — 적으면 `btn btn-{variant}` 를 붙인다. 생략하면 className 만 쓴다 */
  variant?: ButtonVariant;
  /** 버튼 크기 — sm → btn-sm, lg → btn-lg */
  size?: ButtonSize;
}

/**
 * 기본 버튼 컴포넌트
 */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(({
  children,
  variant,
  size,
  className = '',
  ...props
}, ref) => {
  // 커서는 부품이 기본으로 준다 — Tailwind v4 는 단추 기본 커서가 `default` 라, 레이아웃이 따로 적지 않은 단추가 화살표였다
  // (관리자 템플릿 Button 의 `button` 클래스와 같은 값). 레이아웃이 다른 커서를 주면 mergeClasses 가 그 값을 남긴다.
  const baseClasses = ['inline-flex items-center justify-center cursor-pointer disabled:cursor-not-allowed', buttonVariantClasses(variant, size)]
    .filter(Boolean)
    .join(' ');

  // G7Core.style.mergeClasses를 사용하여 충돌하는 Tailwind 클래스 병합 — 호출처 className 이 이긴다
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
