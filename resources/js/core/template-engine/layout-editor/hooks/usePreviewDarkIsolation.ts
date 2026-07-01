/**
 * usePreviewDarkIsolation.ts — 편집기 다크 프리뷰 격리: `<html class="dark">` 제거
 *
 * 어드민 환경이 다크 테마면 `<html>` 에 `dark` 클래스가 붙는다. 이 조상 클래스는 어드민
 * 호스트 CSS 의 `.dark <desc>` 후손 셀렉터를 활성화하는데, 편집 대상 템플릿 콘텐츠도 같은
 * 이름의 Tailwind `dark:` 유틸리티 클래스를 쓰므로 어드민 CSS 의 다크 규칙이 프리뷰 콘텐츠를
 * 침범한다(라이트 프리뷰에서도 다크 색이 적용됨). 편집기 CSS rewrite 만으로는 못 막는다(다른 빌드).
 *
 * 편집기는 화면 전체를 점유하는 풀스크린 레이어이고, 편집기 chrome 은 g7le-* 인라인
 * 스타일만 쓰므로 `html.dark` 에 의존하지 않는다. 따라서 편집기 마운트 동안 `html.dark` 를
 * 제거해 어드민 CSS 다크 cascade 를 끊고, 프리뷰 다크는 프레임 마커(`.g7le-preview-dark`) +
 * rewrite CSS 로만 구동되게 한다(완전 격리). 언마운트 시 원래 상태로 복원한다.
 *
 * 어드민 테마 초기화가 편집기 React 마운트보다 늦게 `dark` 를 (재)부착할 수 있어(단발 제거로는
 * 이후 재부착을 놓침), MutationObserver 로 편집기 생존 동안 `dark` 를 계속 제거한다.
 *
 * @since engine-v1.50.0
 */

import { useEffect } from 'react';

/**
 * 편집기 마운트 동안 `documentElement` 의 `dark` 클래스를 격리(제거 유지)하고 언마운트 시 복원.
 *
 * 테스트/SSR 안전: `document` 부재 시 no-op. `MutationObserver` 부재 시 1회 제거만 수행.
 *
 * @returns void
 */
export function usePreviewDarkIsolation(): void {
  useEffect(() => {
    if (typeof document === 'undefined') return;
    const root = document.documentElement;
    let everHadDark = root.classList.contains('dark');

    const strip = (): void => {
      if (root.classList.contains('dark')) {
        everHadDark = true;
        root.classList.remove('dark');
      }
    };

    strip();

    let obs: MutationObserver | null = null;
    if (typeof MutationObserver !== 'undefined') {
      obs = new MutationObserver(strip);
      obs.observe(root, { attributes: true, attributeFilter: ['class'] });
    }

    return () => {
      obs?.disconnect();
      if (everHadDark) root.classList.add('dark');
    };
  }, []);
}
