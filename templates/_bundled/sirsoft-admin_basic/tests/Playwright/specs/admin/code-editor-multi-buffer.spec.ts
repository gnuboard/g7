/**
 * sirsoft-admin_basic — 파일별 편집 버퍼 (#476)
 *
 * PO 가 제보한 유실을 브라우저에서 잠근다. 종전에는 편집 버퍼가 전역 변수 **한 벌**이라
 * 파일을 고르면 재조회 응답이 그 전역을 무조건 덮었고, 저장하지 않은 수정이 아무 경고 없이
 * 사라졌다. 단위 테스트(`CodeWorkspace.buffers.test.tsx`)가 규칙을 잠그고, 여기서는
 * **실제 Monaco 와 실제 재조회**를 거쳐도 같은 결과인지를 잰다 — 그 둘 사이(모델 교체·
 * 재조회 타이밍)가 이 결함이 살던 자리이기 때문이다.
 *
 * 저장은 하지 않는다. 버퍼는 브라우저 안에서만 사는 값이라 서버를 건드리지 않고 잴 수 있다.
 *
 * @scenario surface=code_editor,entry=direct
 * @effects code_editor_keeps_unsaved_edits_across_file_switches
 *
 * 실행: `code-editor-*.spec.ts` 는 `--workers=1` 로 돌린다 — 사유는
 * `code-editor-file-crud.spec.ts` 헤더 참조(같은 시드 화면을 공유해 병렬에서 서로를 밟는다).
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page } from '@playwright/test';

/** 편집 표식 — 실제 파일에는 없는 문자열이라 버퍼에서만 나온다 */
const MARK = '<!--G7-BUFFER-PROBE-->';

/**
 * 코드 편집기를 열고 트리가 그려질 때까지 기다립니다.
 */
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

/** 지금 활성 HTML 모델의 값 */
async function markup(page: Page): Promise<string> {
  return page.evaluate(() => {
    const editors = (window as any).monaco?.editor?.getEditors?.() ?? [];
    const html = editors.find((e: any) => e.getModel()?.getLanguageId?.() === 'html');

    return html?.getModel()?.getValue?.() ?? '';
  });
}

/** 트리에서 보이는 파일 행들 (폴더·구역 제외) */
function fileRows(page: Page) {
  return page.locator('[role="treeitem"]:not([aria-expanded])');
}

/** 편집기에 표식을 친다 — Monaco 의 입력 파이프라인을 그대로 지난다. */
async function typeMark(page: Page): Promise<void> {
  await page.evaluate((mark) => {
    const editors = (window as any).monaco.editor.getEditors();
    const html = editors.find((e: any) => e.getModel()?.getLanguageId?.() === 'html');

    html.focus();
    html.setPosition({ lineNumber: 1, column: 1 });
    html.trigger('keyboard', 'type', { text: mark });
  }, MARK);
}

// 같은 시드 화면(e2e_sandbox)에서 저장·생성·삭제를 겹쳐 하므로 병렬이면 서로의 상태를 밟는다 — 직렬이 정본이다.
test.describe.configure({ mode: 'serial' });

test.describe('@sirsoft-admin_basic 코드 편집기 — 파일별 버퍼', () => {
  // @scenario branch=layout, buffer_state=dirty, leave=reload
  /**
   * @scenario promise=unsaved_edits_survive_file_switch, surface=browser
   *
   * @effects code_editor_keeps_unsaved_edits_across_file_switches
   */
  test('A 를 고치고 B 를 열었다가 A 로 돌아와도 편집분이 남는다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    const rows = fileRows(page);
    // `title`(전체 경로)은 행 자체가 아니라 이름 span 이 단다 — 트리가 폴더 구조로 바뀌면서
    // 행에는 아이콘·배지·버튼이 함께 들어갔기 때문이다. 행에서 바로 읽으면 null 이 나온다.
    const firstName = (await rows.nth(0).locator('[title]').first().getAttribute('title')) ?? '';
    expect(firstName, '첫 파일 행의 title(전체 경로)을 읽지 못했다').not.toBe('');

    await typeMark(page);
    await expect.poll(() => markup(page), { timeout: 15_000 }).toContain(MARK);

    // 미저장 표시가 트리에 선다.
    await expect(page.locator('[role="treeitem"] [aria-label]').first()).toBeVisible();

    // B 로 이동 — 서버 본문이 새로 온다.
    await rows.nth(1).click();
    await expect.poll(() => markup(page), { timeout: 20_000 }).not.toContain(MARK);

    // A 로 복귀 — 재조회가 돌아도 내 편집분이 이겨야 한다.
    await rows.filter({ has: page.locator(`[title="${firstName}"]`) }).first().click();
    await expect
      .poll(() => markup(page), { timeout: 20_000 })
      .toContain(MARK);
  });

  // @scenario branch=layout, buffer_state=clean, leave=reload
  /**
   * 되돌리기 스택도 파일별이다 — 모델을 나누지 않으면 A 의 `Ctrl+Z` 가 B 의 편집을 되돌린다.
   *
   * @scenario promise=per_file_undo_stack, surface=browser
   *
   * @effects code_editor_keeps_one_monaco_model_per_file
   */
  test('파일마다 다른 모델을 쓴다 (되돌리기·커서가 섞이지 않는다)', async ({ page, layoutEditToken }) => {
    // 이 케이스만 예산을 늘린다 — 판정 전에 **2초를 그냥 기다린다**(모델 교체는 이벤트를 내지
    // 않아 폴링할 신호가 없다). 편집기 진입(Monaco 로드 + 본문 도착)에 기본 30초의 대부분을
    // 쓰고 나면 그 2초가 남지 않는다. 실측 2026-09-12: 6회 중 1회가 30초에서 끊겼고
    // 단독 재실행 2회는 통과했다(기계 부하에 좌우되는 플레이크).
    test.setTimeout(60_000);

    await openEditor(page, layoutEditToken);

    const rows = fileRows(page);
    const firstUri = await page.evaluate(() => {
      const editors = (window as any).monaco.editor.getEditors();

      return editors.find((e: any) => e.getModel()?.getLanguageId?.() === 'html')?.getModel()?.uri?.toString() ?? '';
    });

    await rows.nth(1).click();

    const secondUri = await page.evaluate(async () => {
      await new Promise((resolve) => setTimeout(resolve, 2000));
      const editors = (window as any).monaco.editor.getEditors();

      return editors.find((e: any) => e.getModel()?.getLanguageId?.() === 'html')?.getModel()?.uri?.toString() ?? '';
    });

    expect(firstUri).not.toBe('');
    expect(secondUri).not.toBe('');
    expect(secondUri, '파일이 바뀌었는데 같은 모델을 쓰면 되돌리기 스택이 섞인다').not.toBe(firstUri);
  });

  /**
   * 탭은 **열려 있는 것**을 보여 준다 — 편집기가 한 파일만 그리더라도.
   *
   * @scenario promise=open_files_are_visible, surface=browser
   *
   * @effects code_editor_shows_open_files_as_tabs
   */
  test('들른 파일이 탭으로 쌓이고 미저장 탭에 표시가 붙는다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    const rows = fileRows(page);

    await typeMark(page);
    await expect.poll(() => markup(page), { timeout: 15_000 }).toContain(MARK);

    await rows.nth(1).click();
    await page.waitForTimeout(2000);

    const tabs = page.locator('[role="tablist"] [role="tab"]');
    expect(await tabs.count(), '들른 파일이 탭으로 남지 않는다').toBeGreaterThanOrEqual(2);

    // 미저장 탭에는 점이 붙는다 (색 하나에 기대지 않도록 접근성 라벨을 함께 단다).
    const dirtyMarks = page.locator('[role="tab"] [role="img"][aria-label]');
    expect(await dirtyMarks.count()).toBeGreaterThanOrEqual(1);
  });

  /**
   * 새로고침·탭 닫기는 브라우저가 막아 세운다.
   *
   * @scenario promise=reload_guard, surface=browser
   *
   * @effects code_editor_guards_reload_with_unsaved_edits
   */
  test('미저장이 있으면 새로고침을 막아 세운다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    await typeMark(page);
    await expect.poll(() => markup(page), { timeout: 15_000 }).toContain(MARK);

    let dialogSeen = false;
    page.on('dialog', (dialog) => {
      dialogSeen = true;
      void dialog.accept();
    });

    await page.reload({ waitUntil: 'domcontentloaded' });

    expect(dialogSeen, 'beforeunload 가 발화하지 않으면 편집분이 말없이 사라진다').toBe(true);
  });
});

test.describe('@sirsoft-admin_basic 코드 편집기 — 파일을 여는 것은 편집이 아니다', () => {
  // @scenario branch=layout, buffer_state=clean, leave=file_switch
  /**
   * 탭을 열 때마다 없는 컴파일 오류가 뜨던 결함 (PO 제보 2026-09-12).
   *
   * 편집기는 제어 컴포넌트라 `value` prop 이 바뀌면 그대로 change 를 올려 준다. 파일을 눌러
   * 서버 본문이 도착하기 전에는 그 파일의 버퍼가 아직 없어 **빈 값**이 올라오는데, 그것을 사람의
   * 편집으로 치면 빈 본문으로 `markup-compile` 이 돌아
   * «컴파일 오류 — 저장이 막힙니다 / 변환할 마크업(.g7.html)이 필요합니다» 배너가 떴다.
   * 다른 탭에 갔다 오면 제대로 된 본문으로 다시 돌아 사라지는, 화면에서만 보이는 종류였다.
   *
   * 여기서 잠그는 명제는 둘이다 — **파일을 여는 것만으로는 서버 변환이 돌지 않는다**,
   * 그리고 **오류 배너가 서지 않는다**. 단위 테스트는 change 핸들러만 볼 수 있어
   * 「빈 값이 실제로 편집기에서 되돌아오는」 그 타이밍을 재현하지 못한다.
   *
   * @scenario promise=opening_a_file_is_not_an_edit, surface=browser
   *
   * @effects code_editor_keeps_unsaved_edits_across_file_switches
   */
  test('파일을 연달아 열어도 컴파일 오류 배너가 서지 않는다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    const compileCalls: string[] = [];
    page.on('request', (request) => {
      if (request.url().includes('/markup-compile')) compileCalls.push(request.url());
    });

    const rows = fileRows(page);
    const count = Math.min(await rows.count(), 6);
    expect(count).toBeGreaterThan(1);

    const errorPanel = page.locator('[role="alert"]', { hasText: '컴파일 오류' });

    for (let i = 0; i < count; i += 1) {
      await rows.nth(i).click();
      // 본문이 실제로 갈릴 때까지 기다린다 — 기다리지 않으면 결함이 살던 창을 지나쳐 버린다.
      await page.waitForTimeout(700);
      await expect(errorPanel).toHaveCount(0);
    }

    // 여는 것만으로는 서버 변환이 한 번도 돌지 않아야 한다.
    expect(compileCalls, `markup-compile 이 ${compileCalls.length}회 발화했다`).toHaveLength(0);
  });
});
