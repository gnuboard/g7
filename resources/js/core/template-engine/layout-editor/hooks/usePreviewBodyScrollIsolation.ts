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
 * 같은 이유로 편집 대상 템플릿 CSS 의 `html { scroll-behavior: smooth }`(sirsoft-basic `main.css`)도 편집기
 * 문서에 걸린다. 그러면 편집기 문서 스크롤이 모두 부드러운 스크롤이 된다. 미리보기 안 컴포넌트가
 * focus() 하거나 노드를 scrollIntoView 하면 화면이 한동안 움직이고, 그 사이 누른 좌표가 어긋난다(드래그가
 * 시작되지 않음, 열린 ⓘ 메뉴가 포인터 밑을 지나가며 mouseleave 로 닫힘). 편집기 생존 동안 `<html>` 인라인
 * `scroll-behavior: auto` 로 덮고, 언마운트 때 원래 인라인 값으로 되돌린다.
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

    // 템플릿 CSS 의 html scroll-behavior 를 인라인으로 덮는다(인라인이 비-important 규칙을 이긴다).
    const html = document.documentElement;
    const prevScrollBehavior = html.style.scrollBehavior;
    html.style.scrollBehavior = 'auto';

    // 미리보기 안 요소의 자동 포커스가 편집기 문서를 스크롤하지 않게 한다(이월 4). 실측: sirsoft-popup 의
    // 팝업 대화창(`g7pp-dialog`)이 마운트 때 focus() 해 편집기를 열자마자 문서가 1155px 내려갔다. 런타임 화면에서는
    // 정상 동작(창이 보이게 스크롤)이지만, 편집기 미리보기는 같은 문서라 편집기 화면 전체가 움직인다. 미리보기 frame
    // 안 요소의 focus 에만 `preventScroll: true` 를 더하고, 그 밖(편집기 chrome·모달)은 그대로 둔다.
    const proto = HTMLElement.prototype;
    const originalFocus = proto.focus;
    const previewFocus = function (this: HTMLElement, options?: FocusOptions): void {
      if (typeof this.closest === 'function' && this.closest('.g7le-preview-frame')) {
        originalFocus.call(this, { ...(options ?? {}), preventScroll: true });
        return;
      }
      originalFocus.call(this, options);
    };
    proto.focus = previewFocus;

    return () => {
      obs?.disconnect();
      html.style.scrollBehavior = prevScrollBehavior;
      // 그사이 다른 코드가 다시 덮지 않았을 때만 되돌린다.
      if (proto.focus === previewFocus) proto.focus = originalFocus;
    };
  }, []);
}
