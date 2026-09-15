/**
 * sirsoft-admin_basic — 코드 편집기 단축키 (#476)
 *
 * 이 화면에는 단축키가 **0건**이었다. `Ctrl+S` 조차 없어, 같은 저장소의 위지윅 편집기에는
 * SSoT·도움말 모달이 완비돼 있는데 코드 편집기만 빠져 있었다.
 *
 * 브라우저에서만 드러나는 것을 잰다 — **브라우저 기본 동작을 실제로 막는가**(`Ctrl+S` 가 페이지
 * 저장 대화상자를 띄우지 않는가), 그리고 **Monaco 안에서도 같은 동작이 나는가**. 창 리스너만
 * 달면 편집기에 포커스가 있을 때 동작이 갈린다.
 *
 * @scenario surface=code_editor,entry=direct
 * @effects code_editor_has_document_shortcuts
 *
 * 실행: `code-editor-*.spec.ts` 는 `--workers=1` 로 돌린다 — 사유는
 * `code-editor-file-crud.spec.ts` 헤더 참조(같은 시드 화면을 공유해 병렬에서 서로를 밟는다).
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page } from '@playwright/test';

async function openEditor(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto('/admin/templates/sirsoft-admin_basic/edit');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[role="tree"] [role="treeitem"]', { timeout: 30_000 });
  await page.waitForFunction(
    () => ((window as any).monaco?.editor?.getModels?.() ?? []).some((m: any) => m.getValue().length > 0),
    undefined,
    { timeout: 30_000 },
  );
}

/** 편집기에 포커스를 준다 — 단축키가 Monaco 안에서도 도는지 재기 위해서다. */
async function focusEditor(page: Page): Promise<void> {
  await page.evaluate(() => {
    const editors = (window as any).monaco.editor.getEditors();
    editors.find((e: any) => e.getModel()?.getLanguageId?.() === 'html')?.focus();
  });
}

// 같은 시드 화면(e2e_sandbox)에서 저장·생성·삭제를 겹쳐 하므로 병렬이면 서로의 상태를 밟는다 — 직렬이 정본이다.
test.describe.configure({ mode: 'serial' });

test.describe('@sirsoft-admin_basic 코드 편집기 — 단축키', () => {
  /**
   * `Ctrl+S` 가 브라우저의 "페이지 저장" 을 띄우면 그 순간 편집 흐름이 끊긴다.
   *
   * @scenario promise=ctrl_s_saves, surface=browser
   *
   * @effects code_editor_ctrl_s_saves_and_prevents_browser_default
   */
  test('Ctrl+S 가 저장 요청을 내고 브라우저 기본 동작을 막는다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    // 저장 응답은 고정한다 — 실제 레이아웃을 건드리지 않는다.
    await page.route(/\/api\/admin\/templates\/[^/]+\/layouts\/[^/?]+(\?.*)?$/, async (route) => {
      if (route.request().method() !== 'PUT') {
        await route.fallback();

        return;
      }

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, message: 'ok', data: { markup_warnings: [] } }),
      });
    });

    const saves: string[] = [];
    page.on('request', (request) => {
      if (request.method() === 'PUT' && request.url().includes('/layouts/')) {
        saves.push(request.url());
      }
    });

    await focusEditor(page);

    const prevented = await page.evaluate(() => {
      const event = new KeyboardEvent('keydown', { key: 's', ctrlKey: true, bubbles: true, cancelable: true });
      window.dispatchEvent(event);

      return event.defaultPrevented;
    });

    expect(prevented, 'preventDefault 가 없으면 브라우저의 페이지 저장 대화상자가 뜬다').toBe(true);
    await expect.poll(() => saves.length, { timeout: 15_000 }).toBeGreaterThan(0);
  });

  /**
   * @scenario promise=shortcut_help, surface=browser
   *
   * @effects code_editor_shortcut_help_lists_the_ssot
   */
  test('? 로 도움말이 열리고 SSoT 의 단축키가 모두 보인다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    await page.evaluate(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: '?', bubbles: true }));
    });

    const help = page.getByTestId('shortcut-help');
    await expect(help).toBeVisible({ timeout: 10_000 });

    const text = (await help.innerText()) ?? '';

    // 표기는 플랫폼을 따른다 — 여기(리눅스·윈도우 러너)에서는 Ctrl 이다.
    expect(text).toContain('Ctrl');
    // 번역되지 않은 키가 그대로 노출되면 도움말이 아니라 암호문이 된다.
    expect(text, '도움말에 원문 키가 노출됐다').not.toContain('$t:');
    expect(text).not.toContain('admin.templates.layout_edit.shortcuts');

    await page.keyboard.press('Escape');
    await expect(help).toBeHidden({ timeout: 10_000 });
  });

  /**
   * `Ctrl+K`(파일 빠른 이동) — 좌측 검색창에 포커스가 간다. 도움말에 실려 있어도 배선이 없으면 눌러서
   * 아무 일도 없는 단축키가 된다(4차 재검증에서 실제로 그 상태였다). 편집기 안에 포커스가 있어도
   * 같은 동작이 나야 한다(Monaco 재바인딩).
   *
   * @scenario promise=ctrl_k_focuses_search, surface=browser
   *
   * @effects code_editor_ctrl_k_focuses_the_file_search
   */
  test('Ctrl+K 로 파일 검색창에 포커스가 간다 — 편집기 안에서도', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    const search = page.getByTestId('file-tree-search');
    await expect(search).not.toBeFocused();

    await page.evaluate(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, bubbles: true, cancelable: true }));
    });

    await expect(search).toBeFocused({ timeout: 10_000 });

    // 포커스를 편집기로 돌려 두고 편집기 안에서 다시 누른다.
    await focusEditor(page);
    await expect(search).not.toBeFocused();
    await page.keyboard.press('Control+k');

    await expect(search, '편집기 안에서는 Monaco 가 Ctrl+K 를 선점하므로 재바인딩이 있어야 같은 동작이 난다').toBeFocused({
      timeout: 10_000,
    });
  });

  /**
   * 입력 자리에서는 `mod` 없는 단일 키를 가로채지 않는다 — 아니면 그 글자를 영영 칠 수 없다.
   *
   * @scenario promise=no_misfire_while_typing, surface=browser
   *
   * @effects code_editor_shortcuts_do_not_misfire_in_inputs
   */
  test('검색창에 ? 를 치면 도움말이 열리지 않고 글자가 들어간다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    const search = page.getByTestId('file-tree-search');
    await search.click();
    await search.type('?');

    await page.waitForTimeout(1000);

    await expect(page.getByTestId('shortcut-help')).toHaveCount(0);
    expect(await search.inputValue()).toContain('?');
  });

  /**
   * @scenario promise=word_wrap_toggle, surface=browser
   *
   * @effects code_editor_alt_z_toggles_word_wrap_and_remembers_it
   */
  test('Alt+Z 로 줄바꿈을 뒤집고 새로고침해도 유지된다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    const before = await page.getByTestId('word-wrap-toggle').getAttribute('aria-pressed');

    await page.evaluate(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'z', altKey: true, bubbles: true }));
    });

    await expect
      .poll(() => page.getByTestId('word-wrap-toggle').getAttribute('aria-pressed'), { timeout: 10_000 })
      .not.toBe(before);

    const after = await page.getByTestId('word-wrap-toggle').getAttribute('aria-pressed');

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-testid="word-wrap-toggle"]', { timeout: 30_000 });

    expect(
      await page.getByTestId('word-wrap-toggle').getAttribute('aria-pressed'),
      '토글 값이 기억되지 않으면 들어올 때마다 다시 눌러야 한다',
    ).toBe(after);
  });

  /**
   * 글자 크기 `Ctrl+=` / `Ctrl+-` (계획 §7.6 — 12~20px, 고른 값은 기억). 창 리스너와 Monaco 재바인딩
   * 양쪽에서 같은 동작이 나야 하고, 새로고침해도 기기 기본값(14)이 아니라 고른 값이 남아야 한다.
   *
   * @scenario promise=font_size_shortcut, surface=browser
   *
   * @effects code_editor_ctrl_plus_minus_adjusts_font_size_and_remembers_it
   */
  test('Ctrl+= / Ctrl+- 로 편집기 글자 크기를 바꾸고 새로고침해도 유지된다', async ({ page, layoutEditToken }) => {
    const STORAGE_KEY = 'g7le.codeEditor.sirsoft-admin_basic.fontSize';
    const fontSize = (): Promise<number> =>
      page.evaluate(() => {
        const lines = document.querySelector('.monaco-editor .view-lines');

        return lines ? Math.round(parseFloat(getComputedStyle(lines).fontSize)) : 0;
      });

    await openEditor(page, layoutEditToken);
    // 앞선 실행이 남긴 값이 있으면 기준선이 흔들린다 — 지우고 다시 연다.
    await page.evaluate((key) => window.localStorage.removeItem(key), STORAGE_KEY);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.monaco-editor .view-lines', { timeout: 30_000 });

    const baseline = await fontSize();
    expect(baseline, '데스크톱 기본값은 14px 이다').toBe(14);

    // 창 리스너 경로.
    await page.evaluate(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: '=', ctrlKey: true, bubbles: true, cancelable: true }));
    });
    await expect.poll(fontSize, { timeout: 10_000 }).toBe(15);

    // 편집기 안 — Monaco 재바인딩 경로.
    await focusEditor(page);
    await page.keyboard.press('Control+=');
    await expect.poll(fontSize, { timeout: 10_000 }).toBe(16);

    await page.keyboard.press('Control+-');
    await expect.poll(fontSize, { timeout: 10_000 }).toBe(15);

    expect(await page.evaluate((key) => window.localStorage.getItem(key), STORAGE_KEY)).toBe('15');

    // 새로고침 뒤에도 고른 값이 기기 기본값을 이긴다.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.monaco-editor .view-lines', { timeout: 30_000 });
    await expect.poll(fontSize, { timeout: 10_000 }).toBe(15);

    // 다음 실행을 위해 되돌린다.
    await page.evaluate((key) => window.localStorage.removeItem(key), STORAGE_KEY);
  });

  /**
   * `mod+w` 는 브라우저 탭을 닫는다 — 등록해 두면 사용자가 믿고 눌러 작업 중인 탭이 사라진다.
   *
   * @scenario promise=reserved_combos_are_not_registered, surface=browser
   *
   * @effects code_editor_avoids_browser_reserved_shortcuts
   */
  test('도움말에 mod+w 가 실리지 않는다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    await page.getByTestId('shortcut-help-open').click();

    const text = (await page.getByTestId('shortcut-help').innerText()) ?? '';
    const lines = text.split('\n').map((line) => line.trim());

    // `Ctrl + W` 단독이 없어야 한다 (`Ctrl + Shift + W` 는 있어야 한다).
    expect(lines.some((line) => /(^|\s)Ctrl \+ W$/.test(line))).toBe(false);
    expect(text).toContain('Ctrl + Shift + W');
  });
});
