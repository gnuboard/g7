/**
 * sirsoft-admin_basic — 코드 편집기 검사 경고·보안 위반 줄 위치(P16)와 휴대폰 위쪽 정보 접기(P17) (7.1.0)
 *
 *  1. **줄 위치** — 미등록 핸들러 줄을 끼우면 경고가 그 줄·칸을 싣고, 경고 항목 버튼을 누르면 커서가 그 줄로 간다.
 *     `javascript:` 주소 줄은 보안 검사 422 의 `compile_error` 가 그 줄·칸을 싣고 오류 항목도 같은 방식으로 간다.
 *  2. **휴대폰** — 390x844 에서 설명·수정일·이력·하단 안내를 접어 두어 코드 칸이 넓고, [파일 정보 펼치기]로 연다.
 *
 * 저장하지 않는다(초안은 페이지와 함께 버려진다).
 *
 * @scenario branch=layout, input=compile_error, action=idle_compile
 * @effects code_editor_issue_rows_reveal_source_line, code_editor_mobile_info_collapsed
 *
 * 실행: `code-editor-*.spec.ts` 는 `--workers=1` 로 돈다(같은 시드 화면 공유).
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page } from '@playwright/test';

const TOGGLE = 'button[aria-controls="code_editor_file_desc code_editor_file_meta code_editor_inline_notice"]';

async function openBasicCodeEditor(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto('/admin/templates/sirsoft-basic/edit');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  // 서버 본문이 모델에 실린 뒤라야 줄을 끼울 자리가 있다
  await page.waitForFunction(
    () => /<layout\b/.test((window as any).monaco?.editor?.getEditors?.()[0]?.getModel?.()?.getValue?.() ?? ''),
    null,
    { timeout: 60_000 },
  );
  await page.waitForTimeout(1500);
}

/** 첫 `<div …>` 줄 다음에 텍스트를 끼우고 끼운 줄 번호를 돌려준다 */
async function insertAfterFirstDiv(page: Page, text: string): Promise<number> {
  return page.evaluate((t) => {
    const w = window as any;
    const ed = w.monaco.editor.getEditors()[0];
    const lines: string[] = ed.getModel().getLinesContent();
    const at = lines.findIndex((l, i) => i > 0 && /^\s*<div\b[^>]*>\s*$/.test(l));
    ed.executeEdits('e2e', [{ range: new w.monaco.Range(at + 2, 1, at + 2, 1), text: t + '\n' }]);
    return at + 2;
  }, text);
}

const cursor = (page: Page) => page.evaluate(() => (window as any).monaco.editor.getEditors()[0].getPosition());
const toTop = (page: Page) => page.evaluate(() => (window as any).monaco.editor.getEditors()[0].setPosition({ lineNumber: 1, column: 1 }));

test.describe('@sirsoft-admin_basic 코드 편집기 검사 위치·휴대폰', () => {
  /**
   * @scenario branch=layout, input=compile_error, action=idle_compile
   * @effects code_editor_issue_rows_reveal_source_line
   */
  test('경고·보안 오류가 줄·칸을 싣고, 누르면 그 줄로 간다', async ({ page, layoutEditToken }) => {
    await openBasicCodeEditor(page, layoutEditToken);

    const first = page.waitForResponse((r) => r.url().includes('/markup-compile'), { timeout: 20_000 });
    const line = await insertAfterFirstDiv(page, '<button><on type="click"><action handler="noSuchHandlerE2E"/></on></button>');
    const firstResponse = await first;
    expect(firstResponse.status(), JSON.stringify(await firstResponse.json().catch(() => null)).slice(0, 300)).toBe(200);
    const row = page.locator('button', { hasText: 'noSuchHandlerE2E' }).first();
    await expect(row).toBeVisible({ timeout: 15_000 });
    await expect(row).toContainText(`${line}:`);
    await toTop(page);
    await row.click();
    await expect.poll(async () => (await cursor(page)).lineNumber).toBe(line);

    const compile = page.waitForResponse((r) => r.url().includes('/markup-compile') && r.status() === 422, { timeout: 15_000 });
    await insertAfterFirstDiv(page, '<a href="javascript:alert(1)">x</a>');
    const body = await (await compile).json();
    expect(body.errors.compile_error.code).toBe('unsafe_content');
    expect(body.errors.compile_error.line).toBe(line);
    const errRow = page.locator('[role="alert"] button').first();
    await expect(errRow).toContainText(`${line}:`);
    await toTop(page);
    await errRow.click();
    await expect.poll(async () => (await cursor(page)).lineNumber).toBe(line);
  });

  /**
   * @scenario branch=layout, input=valid_edit, action=idle_compile
   * @effects code_editor_mobile_info_collapsed
   */
  test('휴대폰 폭에서 위쪽 정보가 접혀 코드 칸이 넓고, 토글로 열고 닫는다', async ({ page, layoutEditToken }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openBasicCodeEditor(page, layoutEditToken);

    const editorHeight = () => page.evaluate(() => Math.round(document.querySelector('.monaco-editor')!.getBoundingClientRect().height));
    const toggle = page.locator(TOGGLE);
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('#code_editor_file_meta')).toBeHidden();
    const closed = await editorHeight();

    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('#code_editor_file_meta')).toBeVisible();
    const opened = await editorHeight();
    expect(closed).toBeGreaterThan(opened);

    await page.setViewportSize({ width: 1280, height: 900 });
    await expect(toggle).toBeHidden();
  });
});
