import React, { forwardRef, useCallback, useEffect, useRef } from 'react';

// G7Core 전역 객체의 스타일 헬퍼 접근
const G7Core = () => (window as any).G7Core;

/** 연타 잠금이 `disabled` 를 끝내 받지 못했을 때 스스로 풀리는 시간(ms) */
const REPEAT_CLICK_LOCK_FALLBACK_MS = 1500;

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'danger' | 'success';
  size?: 'sm' | 'md' | 'lg';
  /**
   * 연타 잠금 — 첫 클릭 즉시 스스로 잠가, 처리 중 표시(`disabled`)가 화면에 그려지기 전의 두 번째 클릭을 무시한다.
   *
   * `disabled="{{…submitting}}"` 는 첫 클릭의 상태 변경이 그려진 뒤에야 걸려 더블클릭이 요청을 한 번 더 보낸다(#736 S2).
   * 화면이 `disabled` 를 그리면 잠금을 그 판정에 넘기고, 끝내 오지 않으면 잠시 뒤 풀린다.
   * @default false
   */
  lockRepeatClick?: boolean;
}

/**
 * 기본 버튼 컴포넌트
 */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(({
  children,
  variant = 'primary',
  size = 'md',
  className = '',
  lockRepeatClick = false,
  onClick,
  disabled,
  ...props
}, ref) => {
  const baseClasses = 'button';

  // G7Core.style.mergeClasses를 사용하여 충돌하는 Tailwind 클래스 병합
  const mergedClassName = G7Core()?.style?.mergeClasses?.(baseClasses, className)
    ?? `${baseClasses} ${className}`;

  const lockedRef = useRef(false);
  const fallbackTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const releaseLock = useCallback(() => {
    lockedRef.current = false;
    if (fallbackTimerRef.current !== null) {
      clearTimeout(fallbackTimerRef.current);
      fallbackTimerRef.current = null;
    }
  }, []);

  // 화면이 disabled 를 그리면 그 판정이 막으므로 잠금을 넘긴다 — 다시 풀리면(실패 후) 곧바로 누를 수 있다.
  useEffect(() => {
    if (disabled) {
      releaseLock();
    }
  }, [disabled, releaseLock]);

  useEffect(() => releaseLock, [releaseLock]);

  const handleClick = useCallback((event: React.MouseEvent<HTMLButtonElement>) => {
    if (lockRepeatClick) {
      if (lockedRef.current) {
        event.preventDefault();
        return;
      }
      lockedRef.current = true;
      fallbackTimerRef.current = setTimeout(releaseLock, REPEAT_CLICK_LOCK_FALLBACK_MS);
    }
    onClick?.(event);
  }, [lockRepeatClick, onClick, releaseLock]);

  return (
    <button
      ref={ref}
      className={mergedClassName}
      disabled={disabled}
      onClick={onClick || lockRepeatClick ? handleClick : undefined}
      {...props}
    >
      {children}
    </button>
  );
});

Button.displayName = 'Button';
