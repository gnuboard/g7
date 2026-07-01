/**
 * usePreviewBodyScrollIsolation.ts — 편집기 프리뷰 body 스크롤 락 격리
 *
 * 편집기 프리뷰는 iframe 이 아닌 **동일 문서** 렌더라, 캔버스에 마운트된 템플릿
 * 컴포넌트가 문서 전역 부수효과를 일으키면 편집기 chrome 까지 영향을 받는다.
 * 대표 사례가 모달 편집 모드: `Modal` composite 가 `isOpen=true` 로 강제 마운트되며
 * `document.body.style.overflow = 'hidden'` 스크롤 락을 걸어(런타임에서는 정상 동작 —
 * 모달 뒤 페이지 스크롤 방지), 편집기 페이지 스크롤(= 캔버스 스크롤바)이 사라진다.
 *
 * 편집기 셸은 브라우저 단일 스크롤(문서 흐름) 설계라 body 스크롤이 곧 캔버스
 * 스크롤이며, 편집기 자체 모달(EditorModalContext)은 body 락을 쓰지 않는다. 따라서
 * 편집기 마운트 동안 body 의 `overflow: hidden` 인라인 지정을 계속 무력화해도 잃는
 * 동작이 없다. Lightbox 등 다른 포털형 컴포넌트가 같은 락을 걸어도 동일하게 격리된다.
 *
 * 다크 격리(usePreviewDarkIsolation)와 동형 패턴 — 단발 제거로는 모달 컴포넌트의
 * effect 재실행(isOpen 토글/재마운트)을 놓치므로 MutationObserver 로 생존 동안 유지.
 * 언마운트 시에는 복원하지 않는다(편집기 진입 전 body 는 락이 없던 상태이고, 캔버스
 * 언마운트 시 컴포넌트 cleanup 이 자체 복원을 수행한다).
 *
 * @since engine-v1.50.0
 */

import { useEffect } from 'react';

/**
 * 편집기 마운트 동안 `document.body` 인라인 `overflow: hidden` 을 무력화한다.
 *
 * 테스트/SSR 안전: `document` 부재 시 no-op. `MutationObserver` 부재 시 1회 해제만 수행.
 *
 * @returns void
 */
export function usePreviewBodyScrollIsolation(): void {
  useEffect(() => {
    if (typeof document === 'undefined' || !document.body) return;
    const body = document.body;

    const strip = (): void => {
      // 인라인 지정만 해제 — 값 검사 후 동일 값 재기록을 피해 observer 무한 루프 방지.
      if (body.style.overflow === 'hidden') {
        body.style.overflow = '';
      }
      if (body.style.overflowY === 'hidden') {
        body.style.overflowY = '';
      }
    };

    strip();

    let obs: MutationObserver | null = null;
    if (typeof MutationObserver !== 'undefined') {
      obs = new MutationObserver(strip);
      obs.observe(body, { attributes: true, attributeFilter: ['style'] });
    }

    return () => {
      obs?.disconnect();
    };
  }, []);
}
