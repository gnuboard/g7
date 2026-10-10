import { useEffect, useState } from 'react';

/** 훅도 창도 없을 때(SSR 등)의 기본 폭 — 데스크톱 경계 */
const DEFAULT_WIDTH = 1024;

/**
 * 렌더 폭 — 컴포넌트가 폭으로 형태·기본값을 고를 때 쓰는 값.
 *
 * `G7Core.useResponsive()` 의 Provider 폭이 우선이다. 레이아웃 편집기 미리보기는 iframe 이 아니라
 * 같은 문서의 div 라서 `window.innerWidth` 는 미리보기 기기 폭과 무관하다 — 창 폭으로 고르면 모바일
 * 미리보기에서 데스크톱 형태가 그려진다(#141). 훅이 없는 환경(코어 미초기화·단위 테스트)에서만
 * 창 폭으로 폴백하고 `resize` 로 갱신한다.
 *
 * @returns 렌더 폭(px)
 */
export function useRenderWidth(): number {
  // G7Core 는 런타임 내내 같은 객체라 훅 호출 여부가 렌더마다 바뀌지 않는다
  // eslint-disable-next-line react-hooks/rules-of-hooks
  const responsive = (window as any).G7Core?.useResponsive?.() as { width?: number } | undefined;
  const providerWidth = typeof responsive?.width === 'number' ? responsive.width : null;
  const hasProvider = providerWidth !== null;

  const [fallbackWidth, setFallbackWidth] = useState<number>(() =>
    typeof window === 'undefined' ? DEFAULT_WIDTH : window.innerWidth
  );

  useEffect(() => {
    if (hasProvider || typeof window === 'undefined') {
      return;
    }

    const onResize = (): void => setFallbackWidth(window.innerWidth);

    window.addEventListener('resize', onResize);
    onResize();

    return () => window.removeEventListener('resize', onResize);
  }, [hasProvider]);

  return providerWidth ?? fallbackWidth;
}
