import React, { useState, useEffect } from 'react';
import { Div } from '../basic/Div';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Comp:PageTransition')) ?? {
    log: (...args: unknown[]) => console.log('[Comp:PageTransition]', ...args),
    warn: (...args: unknown[]) => console.warn('[Comp:PageTransition]', ...args),
    error: (...args: unknown[]) => console.error('[Comp:PageTransition]', ...args),
};

export interface PageTransitionIndicatorProps {
  className?: string;
  style?: React.CSSProperties;
}

/**
 * PageTransitionIndicator 컴포넌트
 *
 * TransitionManager의 isPending 상태를 구독하여 페이지 전환 시
 * 상단에 로딩 바를 표시합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "PageTransitionIndicator"
 * }
 */
export const PageTransitionIndicator: React.FC<PageTransitionIndicatorProps> = ({
  className = '',
  style,
}) => {
  const [isPending, setIsPending] = useState(false);

  useEffect(() => {
    // G7Core.TransitionManager 접근
    const transitionManager = (window as any).G7Core?.TransitionManager;

    if (!transitionManager) {
      logger.warn('TransitionManager를 찾을 수 없습니다.');
      return;
    }

    // 구독 및 초기 상태 설정
    setIsPending(transitionManager.getIsPending());

    const unsubscribe = transitionManager.subscribe((newIsPending: boolean) => {
      setIsPending(newIsPending);
    });

    // 정리 함수
    return unsubscribe;
  }, []);

  if (!isPending) {
    return null;
  }

  return (
    <Div
      className={`fixed top-0 left-0 right-0 z-50 ${className}`}
      style={style}
      role="progressbar"
      aria-label="페이지 로딩 중"
    >
      <Div className="h-1 bg-gradient-to-r from-blue-500 via-blue-600 to-blue-500 animate-pulse">
        <Div
          className="h-full bg-blue-700 animate-[loading_1.5s_ease-in-out_infinite]"
          style={{
            width: '30%',
            animation: 'loading 1.5s ease-in-out infinite',
          }}
        />
      </Div>

      <style>{`
        @keyframes loading {
          0% {
            transform: translateX(-100%);
          }
          50% {
            transform: translateX(300%);
          }
          100% {
            transform: translateX(-100%);
          }
        }
      `}</style>
    </Div>
  );
};
