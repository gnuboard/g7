/**
 * sirsoft-admin_basic — 코드 편집기 패널 폭 규약과 저장 후 목록 갱신
 *
 * 브라우저 실측(2026-08-05)에서 드러난 두 결함을 화면 쪽에서 잠근다. 둘 다 응답에도
 * 콘솔에도 신호가 없고 화면에서만 드러나는 종류라, 레이아웃 JSON 구조 단언만으로는
 * "실제로 그렇게 그려지는가 / 실제로 그 요청이 나가는가" 가 남지 않는다.
 *
 *  1. **패널 폭** — 두 카드 어디에도 폭 규약이 없어 flex 가 max-content 비율로 폭을
 *     나눴다. 모듈 파티션 레이아웃 이름(줄바꿈 지점 없는 80자 이상)이 목록을 밀어 올려
 *     편집기가 절반 이하로 눌렸다(실측 목록 814 / 편집기 473).
 *  2. **저장 후 목록** — 저장 성공 시 상세만 다시 불러서, 방금 저장한 파일의 목록 행이
 *     저장 전 설명·크기·수정일을 그대로 달고 있었다.
 *
 * 저장은 응답을 고정해 실제 파일을 건드리지 않는다. 검사 대상은 **브라우저가 실제로
 * 만든 요청 순서** 다 — 목록 재조회가 저장 뒤에 오지 않으면 화면은 낡은 행을 그린다.
 *
 * @scenario surface=code_editor,entry=direct
 * @effects code_editor_editor_panel_wider_than_file_list, code_editor_list_refetched_after_save
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page, Route } from '@playwright/test';

/** 목록 조회 — 뒤에 세그먼트가 붙는 단건/버전 조회와 구분한다 */
const LIST_URL_RE = /\/api\/admin\/templates\/[^/]+\/layouts(\?[^/]*)?$/;

/** 단건 저장 — 목록 URL 뒤에 레이아웃 이름 한 세그먼트 */
const SAVE_URL_RE = /\/api\/admin\/templates\/[^/]+\/layouts\/[^/?]+(\?.*)?$/;

/**
 * 코드 편집기를 열고 파일 목록이 그려질 때까지 기다립니다.
 *
 * @param page Playwright page
 * @param token 인증 토큰
 */
async function openCodeEditor(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto('/admin/templates/sirsoft-admin_basic/edit');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('#file_list_card', { timeout: 30_000 });
  await expect(page.locator('#editor_card').getByText(/\.json$/).first()).toBeVisible({ timeout: 30_000 });
}

test.describe('@sirsoft-admin_basic 코드 편집기 패널 폭', () => {
  test('편집기가 파일 목록보다 넓고, 긴 파일명이 목록 폭을 넘지 않는다', async ({ page, layoutEditToken }) => {
    await openCodeEditor(page, layoutEditToken);

    const measured = await page.evaluate(() => {
      const list = document.querySelector('#file_list_card')!;
      const editor = document.querySelector('#editor_card')!;

      // 목록 안에서 가로 스크롤을 만드는 요소 — 긴 파일명이 끊기지 않으면 여기서 잡힌다
      const overflowing = [...list.querySelectorAll('*')].filter((e) => e.scrollWidth > e.clientWidth + 1).length;

      return {
        listWidth: Math.round(list.getBoundingClientRect().width),
        editorWidth: Math.round(editor.getBoundingClientRect().width),
        overflowing,
      };
    });

    expect(measured.editorWidth, '편집기가 파일 목록보다 좁으면 코드를 볼 수 없다').toBeGreaterThan(
      measured.listWidth,
    );
    expect(measured.overflowing, '목록 안에 가로 스크롤이 생기면 파일명이 끊기지 않은 것이다').toBe(0);
  });
});

test.describe('@sirsoft-admin_basic 코드 편집기 저장 후 목록 갱신', () => {
  test('저장이 성공하면 파일 목록을 다시 부른다', async ({ page, layoutEditToken }) => {
    const calls: string[] = [];

    // 저장은 고정 응답 — 실제 파일과 lock_version 을 건드리지 않는다
    await page.route(SAVE_URL_RE, async (route: Route) => {
      if (route.request().method() !== 'PUT') {
        await route.fallback();

        return;
      }

      calls.push('save');
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, message: '저장되었습니다.', data: { lock_version: 1 } }),
      });
    });

    page.on('request', (req) => {
      if (req.method() === 'GET' && LIST_URL_RE.test(req.url())) calls.push('list');
    });

    await openCodeEditor(page, layoutEditToken);

    const before = calls.length;
    await page.getByTestId('layout-save').click();

    await expect
      .poll(() => calls.includes('save'), { timeout: 20_000 })
      .toBe(true);

    // 저장 뒤에 목록 조회가 한 번 더 나가야 한다. 상세만 다시 부르면 방금 저장한 행이
    // 저장 전 설명·크기·수정일을 그대로 단 채 남는다.
    await expect
      .poll(() => calls.slice(calls.indexOf('save')).includes('list'), { timeout: 20_000 })
      .toBe(true);

    expect(calls.length, '진입 시점 요청만으로 통과하면 안 된다').toBeGreaterThan(before);
  });
});
