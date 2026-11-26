/**
 * @file export-row-menu.ts
 * @description 내보내기 관리 표의 행 「작업」 메뉴에서 항목을 고른다
 *
 * 행 메뉴(ActionMenu)는 fixed 위치라 스크롤이 일어나면 닫힌다. Playwright 는 클릭 전에 대상을 화면 안으로 스크롤하는데,
 * 스크롤 이벤트는 다음 화면 갱신 때 전달된다 — 화면 밖 행의 [작업]을 바로 누르면 메뉴가 열린 직후 그 늦게 온 스크롤
 * 이벤트에 닫히고, 항목 클릭은 사라진 요소를 끝까지 기다린다. 그래서 행을 먼저 화면 가운데로 옮기고 스크롤 이벤트가
 * 지나간 뒤 연다. 목록이 스스로 새로고침돼 메뉴가 닫혀도 열기부터 항목 클릭까지를 통째로 다시 한다.
 */

import { expect, type Locator, type Page } from '@playwright/test';

/**
 * 행의 [작업] 메뉴를 열어 항목을 누른다.
 *
 * @param page 페이지
 * @param row 대상 행
 * @param itemName 메뉴 항목 이름
 * @returns 없음
 */
export async function chooseRowMenuItem(page: Page, row: Locator, itemName: string): Promise<void> {
  const trigger = row.getByRole('button', { name: '작업' });
  const item = page.getByRole('menuitem', { name: itemName });

  await expect(async () => {
    await trigger.evaluate((element) => element.scrollIntoView({ block: 'center' }));
    // 스크롤 이벤트가 전달되는 두 프레임을 흘려보낸다 — 그 전에 열면 메뉴가 그 이벤트에 닫힌다
    await page.evaluate(() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))));
    if (!(await item.isVisible())) {
      await trigger.click();
    }
    await item.click({ timeout: 2_000 });
  }).toPass({ timeout: 20_000 });
}
