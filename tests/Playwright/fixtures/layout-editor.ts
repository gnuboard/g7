/**
 * 레이아웃 편집기 spec 공용 헬퍼.
 *
 * 편집기 캔버스의 노드는 `data-editor-path` 로 지목하는데, 그 경로의 **첫 세그먼트는 베이스
 * 레이아웃(`_user_base` / `_admin_base`)의 루트 자식 인덱스**다. 베이스 레이아웃 루트에 컴포넌트가
 * 하나 추가되면 본문 루트 인덱스가 통째로 밀려 그 인덱스를 리터럴로 박아 둔 spec 이 전부
 * `node not found` 로 죽는다.
 *
 * 실제로 그렇게 됐다 — `_user_base` 루트가 작성 시점에는
 * `0:Toast / 1:PageTransitionIndicator / 2:user_layout_root` 였는데
 * `header_currency_inject_anchor` Div 가 0번에 삽입되어 본문이 2 → 3 으로 밀렸고, `'2.children…'`
 * 을 상수로 둔 spec 7개(31건)가 한꺼번에 실패했다.
 *
 * 그래서 인덱스를 세지 않고 **본문 루트의 DOM id 로 경로를 조회**한다. 베이스 레이아웃에 무엇이
 * 더 얹혀도 영향받지 않는다.
 */
import type { Page } from '@playwright/test';

/** 사용자 템플릿 본문 루트의 DOM id (`_user_base.json` 의 `user_layout_root`) */
export const USER_BODY_ROOT_ID = 'user_layout_root';

/** 관리자 템플릿 본문 루트의 DOM id (`_admin_base.json`) */
export const ADMIN_BODY_ROOT_ID = 'admin_layout_root';

/**
 * 편집기 캔버스에서 본문 루트의 `data-editor-path` 를 조회합니다.
 *
 * @param page Playwright page (편집기 진입 + 캔버스 렌더 완료 상태)
 * @param rootId 본문 루트의 DOM id (기본값: 사용자 템플릿)
 * @returns 본문 루트의 editor path (예: `"3"`)
 * @throws 해당 id 노드가 없거나 editor path 가 없을 때
 */
export async function bodyRootPath(page: Page, rootId: string = USER_BODY_ROOT_ID): Promise<string> {
  const path = await page.evaluate(
    (id) => document.querySelector(`#${id}`)?.getAttribute('data-editor-path') ?? null,
    rootId,
  );

  if (!path) {
    const seen = await page.evaluate(() =>
      Array.from(document.querySelectorAll('[data-editor-path]'))
        .filter((el) => !(el.getAttribute('data-editor-path') ?? '').includes('.'))
        .map((el) => `${el.getAttribute('data-editor-path')}#${el.id || '(no-id)'}`)
        .join(', '),
    );
    throw new Error(`본문 루트(#${rootId})의 data-editor-path 를 찾지 못했습니다. 루트 노드: ${seen}`);
  }

  return path;
}

/**
 * 본문 루트 기준 상대 경로를 절대 editor path 로 만듭니다.
 *
 * @param page Playwright page
 * @param relative 본문 루트 아래 상대 경로 (예: `"children.5.children.0"`)
 * @param rootId 본문 루트의 DOM id (기본값: 사용자 템플릿)
 * @returns 절대 editor path
 */
export async function editorPath(
  page: Page,
  relative: string,
  rootId: string = USER_BODY_ROOT_ID,
): Promise<string> {
  const root = await bodyRootPath(page, rootId);

  return relative ? `${root}.${relative}` : root;
}
