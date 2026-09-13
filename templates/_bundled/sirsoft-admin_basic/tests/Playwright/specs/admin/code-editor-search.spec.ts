/**
 * sirsoft-admin_basic — 파일명·내용 검색 (#476)
 *
 * 종전에는 파일 검색이 **0건**이었다. 144개 평면 목록을 눈으로 훑는 수밖에 없었다.
 *
 * 여기서 잰다 — 파일명 검색은 **왕복 0회**로 즉시 걸러지고(트리가 이름을 모두 들고 있다),
 * 내용 검색만 서버를 한 번 부른다. 그리고 서버가 돌려준 줄 번호가 **편집기의 줄 번호와 같은
 * 좌표계**인지 — 그래야 결과를 눌러 그 줄로 갈 수 있다. 이 일치는 서버가 DB 의 JSON 이 아니라
 * 역변환한 마크업을 훑을 때만 성립하므로, 구현이 되돌아가면 여기서 드러난다.
 *
 * @scenario surface=code_editor,entry=direct
 * @effects code_editor_searches_names_and_contents
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
}

// 같은 시드 화면(e2e_sandbox)에서 저장·생성·삭제를 겹쳐 하므로 병렬이면 서로의 상태를 밟는다 — 직렬이 정본이다.
test.describe.configure({ mode: 'serial' });

test.describe('@sirsoft-admin_basic 코드 편집기 — 검색', () => {
  /**
   * 파일명 검색은 **서버를 부르지 않는다** — 트리가 이름을 모두 들고 있기 때문이다.
   *
   * @scenario promise=name_search_is_instant, surface=browser
   *
   * @effects code_editor_filters_names_without_a_round_trip
   */
  test('파일명 검색은 즉시 걸러지고 걸린 조각이 강조된다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    const before = await page.locator('[role="treeitem"]:not([aria-expanded])').count();

    await page.getByTestId('file-tree-search').fill('dashboard');

    await expect
      .poll(() => page.locator('[role="treeitem"]:not([aria-expanded])').count(), { timeout: 5_000 })
      .toBeLessThan(before);

    expect(await page.locator('[role="tree"] .bg-yellow-200, [role="tree"] mark').count()).toBeGreaterThan(0);
  });

  /**
   * 2자 미만은 서버를 부르지 않는다 — 템플릿 전체를 역변환해 훑는 비싼 일이기 때문이다.
   *
   * @scenario promise=content_search_minimum_length, surface=browser
   *
   * @effects code_editor_skips_content_search_below_two_characters
   */
  test('검색어가 1자면 내용 검색을 부르지 않는다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    const searchCalls: string[] = [];
    page.on('request', (request) => {
      if (request.url().includes('/layouts/search')) {
        searchCalls.push(request.url());
      }
    });

    await page.getByTestId('file-tree-search').fill('a');
    await page.waitForTimeout(3000);

    expect(searchCalls, '1자 검색이 서버로 나갔다').toHaveLength(0);
  });

  /**
   * @scenario promise=content_search_summary, surface=browser
   *
   * @effects code_editor_searches_contents_with_editor_line_numbers
   */
  test('내용 검색이 요약과 줄 번호를 돌려준다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    const searchCalls: string[] = [];
    page.on('request', (request) => {
      if (request.url().includes('/layouts/search')) {
        searchCalls.push(request.url());
      }
    });

    await page.getByTestId('file-tree-search').fill('data-source');

    await expect.poll(() => searchCalls.length, { timeout: 20_000 }).toBeGreaterThan(0);
    await expect(page.getByTestId('file-tree-search-summary')).toBeVisible({ timeout: 20_000 });

    const summary = (await page.getByTestId('file-tree-search-summary').textContent()) ?? '';
    expect(summary, '요약 문구에 치환되지 않은 파라미터가 남아 있다').not.toMatch(/:(matches|files)\b/);
    expect(summary).toMatch(/\d/);
  });

  /**
   * **좌표계 일치** — 결과 줄을 누르면 편집기가 정확히 그 줄로 간다.
   *
   * 서버가 DB 의 JSON 을 훑도록 되돌아가면 줄 번호가 조용히 어긋나고, 결과를 눌렀을 때
   * 엉뚱한 줄로 간다. 응답에도 콘솔에도 신호가 없어 브라우저에서만 드러나는 종류다.
   *
   * @scenario promise=search_result_jumps_to_the_right_line, surface=browser
   *
   * @effects code_editor_search_result_click_reveals_the_matching_line
   */
  test('결과 줄을 누르면 그 파일의 그 줄로 이동한다', async ({ page, layoutEditToken }) => {
    await openEditor(page, layoutEditToken);

    await page.getByTestId('file-tree-search').fill('data-source');
    await expect(page.getByTestId('file-tree-search-summary')).toBeVisible({ timeout: 25_000 });

    // 매치 줄 — 트리에서 파일 아래로 펼쳐진 행이다.
    const hit = page.locator('[role="treeitem"][aria-label]').filter({ hasText: /\d/ }).first();
    test.skip((await hit.count()) === 0, '내용 매치가 없다 — 이 템플릿에서는 검증 대상이 없다');

    const expectedLine = Number((await hit.innerText()).trim().split(/\s+/)[0]);
    test.skip(!Number.isFinite(expectedLine) || expectedLine <= 0, '줄 번호를 읽지 못했다');

    await hit.click();

    const actual = await page.evaluate(async () => {
      await new Promise((resolve) => setTimeout(resolve, 2500));
      const editors = (window as any).monaco?.editor?.getEditors?.() ?? [];
      const html = editors.find((e: any) => e.getModel()?.getLanguageId?.() === 'html');

      return {
        line: html?.getPosition?.()?.lineNumber ?? 0,
        content: html?.getModel?.()?.getLineContent?.(html?.getPosition?.()?.lineNumber ?? 1) ?? '',
      };
    });

    expect(actual.line, '검색 결과의 줄 번호와 편집기 커서가 어긋났다').toBe(expectedLine);
    expect(actual.content.toLowerCase()).toContain('data-source');
  });
});
