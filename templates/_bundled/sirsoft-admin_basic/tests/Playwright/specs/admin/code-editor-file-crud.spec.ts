/**
 * sirsoft-admin_basic — 파일 생성·이름변경·삭제 (#476)
 *
 * 종전에는 화면에서 레이아웃 파일을 **만들 수도, 지울 수도, 이름을 바꿀 수도 없었다** —
 * 백엔드에 `POST`/`PATCH`/`DELETE` 라우트 자체가 없었다. 여기서 잠그는 것은 그 왕복이
 * 화면에서 실제로 끝까지 도는가, 그리고 **파괴적 연산이 막혀야 할 자리에서 막히는가** 다.
 *
 * 만든 파일은 이 스펙이 끝에서 지운다. 실패로 중단돼도 이름에 `__pwtmp` 접두가 붙어 있어
 * 사람이 알아볼 수 있다.
 *
 * @scenario surface=code_editor,entry=direct
 * @effects code_editor_can_create_rename_and_delete_files
 *
 * **이 묶음은 `--workers=1` 로 돌린다.** `code-editor-*.spec.ts` 는 같은 시드 화면
 * (어드민 `e2e-sandbox` 시드)을 공유하면서 저장·생성·삭제까지 하므로 병렬에서 서로를 밟는다 —
 * 실측 2026-09-12: 8스펙을 기본 병렬로 돌리면 8건 실패, 같은 코드를 `--workers=1` 로 돌리면
 * 5건(그중 4건은 스펙 노후, 1건은 제품 결함)만 남았다. 병렬 결과는 실패를 만들어 내기도,
 * 가리기도 한다.
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page } from '@playwright/test';

/*
 * 이 스펙이 만드는 파일 — 접두로 사람이 알아볼 수 있게 한다.
 *
 * 이름에 **회차 도장**을 찍는다. 삭제는 soft delete 이고 유니크 키 `(template_id, name)` 는
 * 지워진 행도 포함하므로, 고정 이름을 쓰면 두 번째 회차의 «이름변경» 이 앞 회차가 남긴
 * 묘비와 부딪혀 409 로 막힌다(실측 2026-09-12: 1회차 통과 → 2회차 같은 자리에서 실패).
 * 회차마다 이름을 갈라 두면 스펙이 몇 번을 돌아도 같은 결과가 난다.
 */
const STAMP = `${Date.now().toString(36)}${Math.floor(Math.random() * 1e4)}`;
const PROBE = `__pwtmp_code_editor_probe_${STAMP}`;
const RENAMED = `__pwtmp_code_editor_renamed_${STAMP}`;

async function openEditor(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto('/admin/templates/sirsoft-admin_basic/edit');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[role="tree"] [role="treeitem"]', { timeout: 30_000 });
}

/** 남은 흔적을 지운다 — 실패로 중단된 앞 회차의 것도 함께. */
async function cleanup(page: Page): Promise<void> {
  await page.evaluate(async (names) => {
    const token = localStorage.getItem('auth_token');
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };

    for (const name of names) {
      await fetch(`/api/admin/templates/sirsoft-admin_basic/layouts/${name}?confirm=1`, {
        method: 'DELETE',
        headers,
      }).catch(() => undefined);
    }
  }, [PROBE, RENAMED]);
}

/**
 * 이름으로 트리 행을 집는다.
 *
 * `title`(전체 경로) 은 행(`[role="treeitem"]`) 이 아니라 그 안의 라벨 `<span>` 에 붙는다
 * (`FileTree.tsx` 의 `title={node.path}`). 행 자체에 `[title=...]` 을 걸면 영영 매치되지 않는다.
 *
 * @param page Playwright page
 * @param name 파일 이름(= 전체 경로)
 * @returns 그 이름을 가진 트리 행 locator
 */
function row(page: Page, name: string) {
  return page.locator('[role="treeitem"]').filter({ has: page.locator(`[title="${name}"]`) });
}

// 같은 시드 화면(e2e_sandbox)에서 저장·생성·삭제를 겹쳐 하므로 병렬이면 서로의 상태를 밟는다 — 직렬이 정본이다.
test.describe.configure({ mode: 'serial' });

test.describe('@sirsoft-admin_basic 코드 편집기 — 파일 조작', () => {
  test.afterEach(async ({ page }) => {
    await cleanup(page).catch(() => undefined);
  });

  /**
   * @scenario promise=file_lifecycle, surface=browser
   *
   * @effects code_editor_creates_renames_and_deletes_a_file
   */
  test('새 파일 → 트리 반영 → 이름변경 → 삭제 왕복', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);
    await cleanup(page);

    // ── 생성 ──────────────────────────────────────────────────────────
    await page.getByTestId('file-tree-new-file').click();
    await expect(page.getByTestId('file-form-submit-file')).toBeVisible({ timeout: 10_000 });

    await page.locator('input[type="text"]').last().fill(PROBE);
    await page.getByTestId('file-form-submit-file').click();

    // 만든 파일이 트리에 서고 곧바로 열린다.
    await expect(row(page, PROBE)).toBeVisible({ timeout: 20_000 });
    await expect
      .poll(
        () =>
          page.evaluate(() => {
            const editors = (window as any).monaco?.editor?.getEditors?.() ?? [];
            const html = editors.find((e: any) => e.getModel()?.getLanguageId?.() === 'html');

            return html?.getModel()?.getValue?.() ?? '';
          }),
        { timeout: 20_000 },
      )
      .toContain('<layout');

    // ── 이름변경 ──────────────────────────────────────────────────────
    await row(page, PROBE).hover();
    await row(page, PROBE).locator('[role="button"]').first().click();

    await expect(page.getByTestId('file-form-submit-rename')).toBeVisible({ timeout: 10_000 });
    await page.locator('input[type="text"]').last().fill(RENAMED);
    await page.getByTestId('file-form-submit-rename').click();

    await expect(row(page, RENAMED)).toBeVisible({ timeout: 20_000 });
    await expect(row(page, PROBE)).toHaveCount(0);

    // ── 삭제 ──────────────────────────────────────────────────────────
    await row(page, RENAMED).hover();
    await row(page, RENAMED).locator('[role="button"]').last().click();

    await expect(page.getByTestId('file-delete-confirm')).toBeVisible({ timeout: 10_000 });
    await page.getByTestId('file-delete-confirm').click();

    await expect(row(page, RENAMED)).toHaveCount(0, { timeout: 20_000 });
  });

  /**
   * 새 폴더는 **서버를 부르지 않는다** — 폴더는 파일 이름의 `/` 일 뿐이다(PO 결정 D-2).
   *
   * @scenario promise=virtual_folders, surface=browser
   *
   * @effects code_editor_new_folder_is_virtual_until_a_file_is_saved
   */
  test('새 폴더는 서버 왕복 없이 트리에만 선다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    const writes: string[] = [];
    page.on('request', (request) => {
      if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(request.method()) && request.url().includes('/layouts')) {
        writes.push(`${request.method()} ${request.url()}`);
      }
    });

    await page.getByTestId('file-tree-new-folder').click();
    await expect(page.getByTestId('file-form-submit-folder')).toBeVisible({ timeout: 10_000 });

    await page.locator('input[type="text"]').last().fill('__pwtmp_folder');
    await page.getByTestId('file-form-submit-folder').click();

    await expect(page.getByText('__pwtmp_folder')).toBeVisible({ timeout: 10_000 });
    expect(writes, '새 폴더가 서버를 불렀다 — 폴더는 실체가 없어야 한다').toHaveLength(0);
  });

  /**
   * 참조가 걸린 파일은 **막히고 그 이유를 보여 준다**.
   *
   * "삭제할 수 없습니다" 만 띄우면 사용자는 무엇을 정리해야 할지 알 수 없어 결국 DB 를 연다.
   *
   * @scenario promise=delete_is_blocked_with_reasons, surface=browser
   *
   * @effects code_editor_shows_what_blocks_a_delete
   */
  test('상속 부모를 지우려 하면 막히고 참조 목록이 나온다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    const base = row(page, '_admin_base');
    test.skip((await base.count()) === 0, '_admin_base 가 없는 템플릿 — 상속 부모 케이스가 성립하지 않는다');

    await base.hover();
    await base.locator('[role="button"]').last().click();

    await expect(page.getByTestId('file-delete-confirm')).toBeVisible({ timeout: 10_000 });
    await page.getByTestId('file-delete-confirm').click();

    // 차단 사유와 참조 목록이 창 안에 남는다 (창이 닫히지 않는다).
    // `[role="alert"]` 을 문서 전체에서 찾으면 Monaco 가 자기 화면 낭독용으로 깔아 두는
    // 빈 `.monaco-alert`(항상 hidden) 가 마지막으로 잡힌다 — 창 안으로 범위를 좁힌다.
    await expect(page.locator('[role="dialog"] [role="alert"]').last()).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId('file-delete-confirm')).toBeVisible();

    // 파일은 그대로다.
    await page.keyboard.press('Escape');
    await expect(row(page, '_admin_base')).toBeVisible({ timeout: 15_000 });
  });

  /**
   * 이름 규칙은 서버가 판정하고, 그 사유가 창 안에 남는다.
   *
   * @scenario promise=invalid_name_is_rejected, surface=browser
   *
   * @effects code_editor_rejects_invalid_file_names
   */
  test('규칙을 벗어난 이름은 만들어지지 않고 사유가 뜬다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    await page.getByTestId('file-tree-new-file').click();
    await expect(page.getByTestId('file-form-submit-file')).toBeVisible({ timeout: 10_000 });

    await page.locator('input[type="text"]').last().fill('../evil');
    await page.getByTestId('file-form-submit-file').click();

    await expect(page.locator('[role="dialog"] [role="alert"]').last()).toBeVisible({ timeout: 15_000 });
    // 창이 닫히지 않는다 — 닫히면 사용자는 무엇이 잘못됐는지 볼 수 없다.
    await expect(page.getByTestId('file-form-submit-file')).toBeVisible();
  });
});
