/**
 * 파일 업로더가 처음 그려질 때 「변경 있음」을 켜지 않는다 (게시글 수정·글쓰기)
 *
 * @scenario-manifest tests/scenarios/file-uploader-mount-no-change.yaml
 * @effects admin_post_edit_save_disabled_on_entry,
 *          picking_file_enables_save,
 *          user_post_write_has_no_changes_on_entry
 *
 * 배경(#736 브라우저 실측 2026-10-05, 트러블슈팅 state-global 사례 9): 업로더(FileUploader)가 마운트
 * effect 에서 빈 목록으로 onFilesChange 를 불러, 그 이벤트로 hasChanges 를 켜는 게시판 레이아웃이
 * 열자마자 저장 버튼을 켰다. 첨부 카드가 폼 데이터 초기화 뒤에 그려지면 남는 경합이라 직접 진입·SPA
 * 이동 모두에서 거의 늘 재현됐다. 고른 파일이 생기면 여전히 저장 버튼이 켜져야 한다(대조군).
 *
 * 저장은 하지 않는다 — 고른 파일은 autoUpload=false 라 서버에 올라가지 않는다.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';
import os from 'node:os';

test.describe.configure({ mode: 'serial' });

const BOARD = 'event';

/**
 * 수정 화면을 열 게시글 id 를 관리자 목록 API 에서 하나 고른다.
 *
 * @param page 인증된 페이지
 * @param token Sanctum 토큰
 * @returns 게시글 id
 */
async function firstPostId(page: Page, token: string): Promise<number> {
  const res = await page.request.get(`/api/modules/sirsoft-board/admin/board/${BOARD}/posts?per_page=1`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  expect(res.ok()).toBe(true);
  const body = await res.json();
  const id = body?.data?.data?.[0]?.id ?? body?.data?.[0]?.id;
  expect(typeof id).toBe('number');

  return id;
}

/** 폼 데이터가 _local 에 실리고 첨부 업로더가 그려질 때까지 기다린다 */
async function waitForFormWithUploader(page: Page): Promise<void> {
  await expect
    .poll(async () => await page.evaluate(() => (window as any).G7Core?.state?.getLocal?.()?.form !== undefined), { timeout: 20_000 })
    .toBe(true);
  await expect(page.locator('input[type=file]').first()).toBeAttached({ timeout: 20_000 });
  // 업로더 마운트 effect·액션 디스패치가 끝날 시간을 준다 — 경합 결함이라 마운트 직후 단언은 거짓 초록이 된다
  await page.waitForTimeout(1_500);
}

/** 화면의 「저장」 버튼들 */
function saveButtons(page: Page) {
  return page.locator('button:visible', { hasText: /^저장$/ });
}

/** 1x1 PNG 파일을 임시 경로에 만든다 */
function tinyPng(): string {
  const file = path.join(os.tmpdir(), `g7-e2e-${Date.now()}.png`);
  fs.writeFileSync(file, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'));

  return file;
}

test.describe('파일 업로더 마운트 — 「변경 있음」 미발화', () => {
  /**
   * @scenario trigger=mount
   *
   * @effects admin_post_edit_save_disabled_on_entry
   */
  test('관리자 게시글 수정 화면은 열었을 때 저장 버튼이 꺼져 있다', async ({ page }) => {
    const token = issueToken();
    await authenticatePage(page, token);
    const id = await firstPostId(page, token);

    await page.goto(`/admin/board/${BOARD}/${id}/edit`);
    await waitForFormWithUploader(page);

    expect(await page.evaluate(() => (window as any).G7Core.state.getLocal()?.hasChanges)).not.toBe(true);
    const saves = saveButtons(page);
    await expect(saves.first()).toBeDisabled();
    await expect(saves.last()).toBeDisabled();
  });

  /**
   * @scenario trigger=user_change
   *
   * @effects picking_file_enables_save
   */
  test('파일을 고르면 저장 버튼이 켜진다 (대조군)', async ({ page }) => {
    const token = issueToken();
    await authenticatePage(page, token);
    const id = await firstPostId(page, token);

    await page.goto(`/admin/board/${BOARD}/${id}/edit`);
    await waitForFormWithUploader(page);
    await expect(saveButtons(page).first()).toBeDisabled();

    const inputs = page.locator('input[type=file]');
    const count = await inputs.count();
    let uploader = -1;
    for (let i = 0; i < count; i++) {
      if (!(await inputs.nth(i).evaluate((el) => !!el.closest('.ck')))) {
        uploader = i;
        break;
      }
    }
    expect(uploader).toBeGreaterThanOrEqual(0);
    await inputs.nth(uploader).setInputFiles(tinyPng());

    await expect(saveButtons(page).first()).toBeEnabled({ timeout: 10_000 });
    expect(await page.evaluate(() => (window as any).G7Core.state.getLocal()?.hasChanges)).toBe(true);
  });

  /**
   * @scenario trigger=mount
   *
   * @effects user_post_write_has_no_changes_on_entry
   */
  test('사용자 게시판 글쓰기 화면은 열었을 때 「변경 있음」이 꺼져 있다 (이탈 경고 대상 아님)', async ({ page }) => {
    await authenticatePage(page, issueToken());

    await page.goto(`/board/${BOARD}/write`);
    await expect(page.locator('input[type=file]').first()).toBeAttached({ timeout: 20_000 });
    await page.waitForTimeout(1_500);

    expect(await page.evaluate(() => (window as any).G7Core.state.getLocal()?.hasChanges)).not.toBe(true);
  });
});
