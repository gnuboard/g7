import { useEffect, useState } from 'react';

/** 폭으로 배치를 고를 때 쓰는 Tailwind 기본 경계 — 엔진 responsive 구간 `640-` 과 같은 값 */
export const SM_MIN_WIDTH = 640;

/**
 * 화면 폭을 지켜봅니다 — 폭으로 형태·배치를 고르는 부품이 쓴다(Tailwind 폭 접두 `gpp:sm:` 대신).
 *
 * 엔진 `ResponsiveManager` 와 같은 값을 쓴다 — `G7Core.useResponsive()` 의 폭(레이아웃 편집기 미리보기에서는 기기 폭),
 * 훅이 없을 때만 `window.innerWidth`. `matchMedia` 는 소수 배율 화면의 경계 폭에서 1px 어긋나 쓰지 않는다
 * (docs/frontend/responsive-layout.md 「확장 코드에서 같은 경계를 판정할 때」).
 *
 * @param fallback 창도 엔진 훅도 없을 때(SSR 등)의 폭
 * @returns 화면 폭(px)
 */
export function useViewportWidth(fallback: number): number {
  const useResponsive = (window as { G7Core?: { useResponsive?: () => { width?: number } | null } }).G7Core?.useResponsive;
  // G7Core 는 페이지가 뜰 때 한 번 정해진다 — 렌더마다 훅 호출 여부가 바뀌지 않는다
  const responsive = useResponsive?.();
  const [innerWidth, setInnerWidth] = useState(() => (typeof window !== 'undefined' ? window.innerWidth : fallback));
  const hasEngineWidth = typeof responsive?.width === 'number';

  useEffect(() => {
    if (hasEngineWidth) {
      return undefined;
    }
    const onResize = () => setInnerWidth(window.innerWidth);
    onResize();
    window.addEventListener('resize', onResize);

    return () => window.removeEventListener('resize', onResize);
  }, [hasEngineWidth]);

  return hasEngineWidth ? (responsive?.width as number) : innerWidth;
}
