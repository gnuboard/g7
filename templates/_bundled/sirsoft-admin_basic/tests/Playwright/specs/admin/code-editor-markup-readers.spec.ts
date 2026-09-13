/**
 * sirsoft-admin_basic — 코드 편집기: `_global.editorMarkup` 을 **읽는 6곳** 1:1 (#476, 계획서 §13)
 *
 * 편집 버퍼의 소유권이 전역에서 `<code-workspace>` 로 넘어가면서(파일별 버퍼), 전역 `editorMarkup`
 * 은 활성 버퍼가 `onChange` 로 밀어 넣는 **파생값**이 됐다. 그 값을 읽는 자리가 여섯이다 —
 * 저장(레이아웃) · 저장(확장) · 미리보기(레이아웃) · 미리보기(확장, overlay형) · 내려받기 · 복사.
 * 이 여섯이 **방금 친 글자**를 읽는지를 브라우저에서 하나씩 잰다. 선언층 단위 테스트는 «표현식이
 * 그 전역을 가리킨다» 까지만 잠그고, 실제로 값이 흘러 들어오는지는 여기서만 드러난다.
 *
 * @scenario surface=code_editor,entry=direct
 * @effects code_editor_markup_readers_receive_the_live_buffer
 *
 * 실행: `--workers=1` — 같은 시드 화면을 저장하므로 병렬이면 서로를 밟는다(`code-editor-file-crud.spec.ts` 헤더).
 */
import { readFileSync } from 'node:fs';
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';
import { runSeedLayoutCommand } from '../../../../../../../tests/Playwright/fixtures/seed-layout';
import type { Page, Request, Route } from '@playwright/test';

const EDITOR_URL = '/admin/templates/sirsoft-admin_basic/edit?route=' + encodeURIComponent('/admin/e2e-sandbox');

const SAVE_URL_RE = /\/api\/admin\/templates\/sirsoft-admin_basic\/layouts\/e2e_sandbox$/;
const PREVIEW_URL_RE = /\/api\/admin\/templates\/sirsoft-admin_basic\/layouts\/e2e_sandbox\/preview$/;
const EXT_SAVE_URL_RE = /\/api\/admin\/templates\/sirsoft-admin_basic\/layout-extensions\/\d+$/;
const EXT_PREVIEW_URL_RE = /\/api\/admin\/templates\/sirsoft-admin_basic\/layout-extensions\/\d+\/preview$/;

test.beforeEach(() => runSeedLayoutCommand(false));
test.describe.configure({ mode: 'serial' });
test.beforeEach(({}, testInfo) => testInfo.setTimeout(120_000));

async function openSandboxEditor(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto(EDITOR_URL);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[role="tree"] [role="treeitem"]', { timeout: 30_000 });
  await expect(page.locator('#editor_card').getByText('e2e_sandbox.g7.html')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#markup_editor_pane .monaco-editor').first()).toBeVisible({ timeout: 60_000 });
  await expect.poll(() => getMarkup(page), { timeout: 20_000 }).toContain('name="e2e_sandbox"');
}

/** 지금 편집기에 붙어 있는 HTML 모델의 본문 */
async function getMarkup(page: Page): Promise<string> {
  return page.evaluate(() => {
    const monaco = (window as any).monaco;
    const editor = (monaco?.editor?.getEditors?.() ?? []).find(
      (e: any) => e.getModel?.()?.getLanguageId?.() === 'html',
    );

    return editor?.getModel?.()?.getValue?.() ?? '';
  });
}

/** 모델 본문 끝에 표식 주석을 붙인다 — 자동 닫기·들여쓰기 간섭 없이 «방금 친 글자» 를 만든다. */
async function appendProbe(page: Page, probe: string): Promise<void> {
  await page.evaluate((marker) => {
    const monaco = (window as any).monaco;
    const editor = (monaco?.editor?.getEditors?.() ?? []).find(
      (e: any) => e.getModel?.()?.getLanguageId?.() === 'html',
    );
    const model = editor?.getModel?.();
    if (!model) throw new Error('html 모델을 찾지 못했습니다');
    model.setValue(`${model.getValue()}\n<!-- ${marker} -->`);
  }, probe);
  // 워크스페이스는 활성 버퍼를 300ms 로 묶어 전역에 올린다 — 그 뒤에 읽는 자리가 값을 본다.
  await page.waitForTimeout(600);
}

/** 요청 본문(JSON)의 `content` 필드 */
function bodyContent(request: Request): string {
  try {
    return String(JSON.parse(request.postData() ?? '{}')?.content ?? '');
  } catch {
    return '';
  }
}

/** 성공 응답으로 대신 답한다 — 시드 행을 실제로 바꾸지 않는다. */
async function fulfillOk(route: Route, data: Record<string, unknown>): Promise<void> {
  await route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ success: true, message: 'ok', data }),
  });
}

/**
 * 확장 조각 행을 찾아 연다. 없으면 `null` — 갈래 자체가 없으면 검증 대상이 없다.
 */
async function openExtensionFragment(page: Page): Promise<boolean> {
  const extensionSection = page.locator('[role="treeitem"]').filter({ hasText: /확장|Extension/ }).first();

  if ((await extensionSection.count()) > 0) {
    await extensionSection.click();
  }

  let extensionRow = page.locator('[role="treeitem"]').filter({ has: page.locator('i.fa-circle-plus') }).first();

  if ((await extensionRow.count()) === 0) {
    const groups = page.locator('[role="treeitem"][aria-expanded="false"]');

    for (let index = 0; index < (await groups.count()); index += 1) {
      await groups.nth(index).click();
    }

    extensionRow = page.locator('[role="treeitem"]').filter({ has: page.locator('i.fa-circle-plus') }).first();
  }

  if ((await extensionRow.count()) === 0) {
    return false;
  }

  await extensionRow.click();
  await expect.poll(() => getMarkup(page), { timeout: 30_000 }).toContain('<extension ');

  return true;
}

test.describe('@sirsoft-admin_basic 코드 편집기 — editorMarkup 을 읽는 6곳', () => {
  /**
   * @scenario reader=save_layout, surface=browser
   * @effects code_editor_save_reads_the_live_buffer
   */
  test('① 저장(레이아웃)은 방금 친 본문을 실어 보낸다', async ({ page, layoutEditToken }) => {
    await openSandboxEditor(page, layoutEditToken);
    const probe = `READER_SAVE_${Date.now()}`;
    const sent: string[] = [];

    await page.route(SAVE_URL_RE, async (route) => {
      if (route.request().method() !== 'PUT') {
        await route.fallback();

        return;
      }

      sent.push(bodyContent(route.request()));
      await fulfillOk(route, { markup_warnings: [] });
    });

    await appendProbe(page, probe);
    await page.getByTestId('layout-save').click();

    await expect.poll(() => sent.length, { timeout: 15_000 }).toBeGreaterThan(0);
    expect(sent[0], '저장 본문이 편집 중인 버퍼를 읽지 못했다').toContain(probe);
  });

  /**
   * @scenario reader=save_extension, surface=browser
   * @effects code_editor_extension_save_reads_the_live_buffer
   */
  test('② 저장(확장 조각)은 방금 친 본문을 실어 보낸다', async ({ page, layoutEditToken }) => {
    await openSandboxEditor(page, layoutEditToken);
    const opened = await openExtensionFragment(page);
    test.skip(!opened, '이 템플릿에 등록된 확장 조각이 없다 — 갈래 자체가 없으면 검증 대상이 없다');

    const probe = `READER_EXT_SAVE_${Date.now()}`;
    const sent: string[] = [];

    await page.route(EXT_SAVE_URL_RE, async (route) => {
      if (route.request().method() !== 'PUT') {
        await route.fallback();

        return;
      }

      sent.push(bodyContent(route.request()));
      await fulfillOk(route, { markup_warnings: [] });
    });

    await appendProbe(page, probe);
    await page.getByTestId('extension-save').click();

    await expect.poll(() => sent.length, { timeout: 15_000 }).toBeGreaterThan(0);
    expect(sent[0]).toContain(probe);
  });

  /**
   * @scenario reader=preview_layout, surface=browser
   * @effects code_editor_preview_reads_the_live_buffer
   */
  test('③ 미리보기(레이아웃)는 저장하지 않은 본문을 올려 컴파일한다', async ({ page, layoutEditToken }) => {
    await openSandboxEditor(page, layoutEditToken);
    const probe = `READER_PREVIEW_${Date.now()}`;
    const sent: string[] = [];

    await page.route(PREVIEW_URL_RE, async (route) => {
      sent.push(bodyContent(route.request()));
      await fulfillOk(route, { token: 'e2e-readers-preview' });
    });
    // 토큰 주소로 새 창이 열린다 — 서버를 부르지 않고 빈 문서로 답한다.
    await page.context().route(/\/preview\/e2e-readers-preview$/, (route) =>
      route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>preview</title>' }),
    );

    await appendProbe(page, probe);
    await page.getByTestId('layout-preview').click();

    await expect.poll(() => sent.length, { timeout: 15_000 }).toBeGreaterThan(0);
    expect(sent[0], '미리보기가 저장본이 아니라 편집 중인 버퍼를 올려야 한다').toContain(probe);
  });

  /**
   * overlay 형 조각만 혼자 미리볼 수 있다 — 주입형은 대상 레이아웃을 고르는 창으로 넘어가고, 그
   * 창은 JSON 산출물(`editorContent`)을 보낸다(다른 읽기 자리). 어느 쪽이든 **버튼이 그 갈래로
   * 이어지는지**를 잰다.
   *
   * @scenario reader=preview_extension, surface=browser
   * @effects code_editor_extension_preview_reads_the_live_buffer
   */
  test('④ 미리보기(확장 조각)는 overlay 형이면 편집 중인 본문을 올린다', async ({ page, layoutEditToken }) => {
    await openSandboxEditor(page, layoutEditToken);
    const opened = await openExtensionFragment(page);
    test.skip(!opened, '이 템플릿에 등록된 확장 조각이 없다 — 갈래 자체가 없으면 검증 대상이 없다');

    const probe = `READER_EXT_PREVIEW_${Date.now()}`;
    const sent: string[] = [];

    await page.route(EXT_PREVIEW_URL_RE, async (route) => {
      sent.push(bodyContent(route.request()));
      await fulfillOk(route, { token: 'e2e-readers-ext-preview' });
    });
    await page.context().route(/\/preview\/e2e-readers-ext-preview$/, (route) =>
      route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>preview</title>' }),
    );

    await appendProbe(page, probe);
    await page.getByTestId('extension-preview').click();

    // overlay 형: 곧바로 POST. 주입형: 대상 레이아웃 선택 창.
    await expect
      .poll(async () => sent.length > 0 || (await page.locator('[role="dialog"]').count()) > 0, { timeout: 15_000 })
      .toBe(true);

    if (sent.length > 0) {
      expect(sent[0]).toContain(probe);
    } else {
      await expect(page.locator('[role="dialog"]').first(), '주입형 조각은 대상 레이아웃 선택 창으로 이어진다').toBeVisible();
    }
  });

  /**
   * @scenario reader=download, surface=browser
   * @effects code_editor_download_reads_the_live_buffer
   */
  test('⑤ 내려받기는 편집 중인 본문을 .g7.html 파일로 만든다', async ({ page, layoutEditToken }) => {
    await openSandboxEditor(page, layoutEditToken);
    const probe = `READER_DOWNLOAD_${Date.now()}`;

    await appendProbe(page, probe);

    const downloadPromise = page.waitForEvent('download', { timeout: 15_000 });
    await page.getByTestId('layout-download-markup').click();
    const download = await downloadPromise;

    expect(download.suggestedFilename()).toBe('e2e_sandbox.g7.html');

    const saved = await download.path();
    expect(saved, '내려받은 파일이 디스크에 남지 않았다').toBeTruthy();
    expect(readFileSync(saved as string, 'utf8')).toContain(probe);
  });

  /**
   * @scenario reader=copy, surface=browser
   * @effects code_editor_copy_reads_the_live_buffer
   */
  test('⑥ 복사는 편집 중인 본문을 클립보드에 담는다', async ({ page, context, layoutEditToken }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await openSandboxEditor(page, layoutEditToken);
    const probe = `READER_COPY_${Date.now()}`;

    await appendProbe(page, probe);
    await page.getByTestId('layout-copy-markup').click();

    await expect
      .poll(() => page.evaluate(() => navigator.clipboard.readText()), { timeout: 15_000 })
      .toContain(probe);
  });
});
