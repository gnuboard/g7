/**
 * Layout Editor 라우트 트리 — 소스 경로 줄을 읽는 spec 공통 헬퍼.
 *
 * **왜 한 곳으로 모으는가**: 경로 줄 `<span>` 은 `className`(스타일)과 `data-testid`(테스트)를
 * 함께 단다(`RouteTreePanel.tsx`). 소비자가 각자 셀렉터를 고르면 한쪽 표기만 훑은 일괄 수정이
 * 다른 쪽을 조용히 남긴다 — S19 ⑦(R1) 에서 실제로 그렇게 두 spec 이 `.json` 정규식으로 남아
 * 영원히 매칭되지 않는 대기가 됐다(실브라우저 red). 소비를 이 파일 하나로 모아, 표기 변경이
 * **상수 한 줄 교체**로 끝나게 한다.
 *
 * 확장자가 소스 SSoT(`.g7.html`)인 근거는 S19 ⑦(R1) — 짝 `.json` 은 D63 이후 기계 산출이라
 * 사람이 열 파일이 아니다.
 *
 * 새 spec 이 경로 줄을 직접 `querySelector` 하는 것은 audit 룰
 * `route-tree-path-authoring-extension` 이 막는다.
 */
import type { Page } from '@playwright/test';

/** 경로 줄 엘리먼트 셀렉터 — 클래스가 아니라 testid 로 통일한다. */
export const ROUTE_TREE_LAYOUT_PATH_SELECTOR = '[data-testid="g7le-route-tree-layout-path"]';

/** 라우트 트리가 표기하는 레이아웃 소스 확장자 (`RouteTreePanel.tsx::AUTHORING_EXTENSION` 과 동치). */
export const ROUTE_TREE_AUTHORING_EXTENSION = '.g7.html';

/** `admin_user_list` → `admin_user_list.g7.html` (표기 줄의 말미와 일치하는 파일명). */
export function routeTreeLayoutFileName(layoutName: string): string {
  return `${layoutName}${ROUTE_TREE_AUTHORING_EXTENSION}`;
}

/**
 * 라우트 트리에 해당 레이아웃의 경로 줄이 렌더될 때까지 대기한다.
 *
 * @param page Playwright 페이지
 * @param layoutName 레이아웃 이름 (확장자 없이)
 * @param timeout 대기 한도(ms)
 * @returns 대기 완료 Promise
 */
export async function waitForRouteTreeLayout(
  page: Page,
  layoutName: string,
  timeout = 30_000,
): Promise<void> {
  await page.waitForFunction(
    ({ selector, fileName }: { selector: string; fileName: string }) =>
      Array.from(document.querySelectorAll(selector)).some((s) =>
        (s.textContent || '').trim().endsWith(fileName),
      ),
    { selector: ROUTE_TREE_LAYOUT_PATH_SELECTOR, fileName: routeTreeLayoutFileName(layoutName) },
    { timeout },
  );
}

/**
 * 라우트 트리에서 해당 레이아웃의 라우트 항목을 클릭해 선택한다.
 *
 * 직접 URL 진입은 route 미선택 상태가 되므로 트리 클릭이 필요하다. 경로 줄에서 위로 올라가
 * `role="button"` 조상을 찾아 클릭한다(모달 항목도 같은 레이아웃을 참조하므로, 트리상 앞에 오는
 * 라우트 본체가 먼저 매칭된다).
 *
 * @param page Playwright 페이지
 * @param layoutName 레이아웃 이름 (확장자 없이)
 * @returns 클릭 완료 Promise
 */
export async function selectRouteTreeLayout(page: Page, layoutName: string): Promise<void> {
  await waitForRouteTreeLayout(page, layoutName);
  await page.evaluate(
    ({ selector, fileName }: { selector: string; fileName: string }) => {
      const span = Array.from(document.querySelectorAll(selector)).find((s) =>
        (s.textContent || '').trim().endsWith(fileName),
      );
      let el: Element | null = span ?? null;
      while (el && el.getAttribute('role') !== 'button' && el.tagName !== 'BUTTON') {
        el = el.parentElement;
      }
      el?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    },
    { selector: ROUTE_TREE_LAYOUT_PATH_SELECTOR, fileName: routeTreeLayoutFileName(layoutName) },
  );
}
