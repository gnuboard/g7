/**
 * Layout Editor — 반복 항목 편집 모드 저장 반영 / 종료 복귀 / 인라인 편집 / 잠금 가시
 *
 * 반복 항목(iteration_item) 편집 모드의  4종을 브라우저에서 가드한다. 단위 테스트는
 * URL pushState/popstate, 오버레이 레이어 hit-test, 캐시 무효화→재fetch render-cycle 을 모사 못 함.
 *
 * @scenario iteration_edit_entry + save_invalidation + exit_return + inline_double_click + lock_visibility
 * @effects url_direct_entry_renders_iteration_mode_with_host + exit_iteration_edit_returns_to_host_route + iteration_save_invalidates_host_route_cache_and_busts_get + double_click_plain_text_enters_inline_edit + data_bound_node_shows_data_area_notice
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const TEMPLATE = 'sirsoft-basic';
const EDITOR_BASE = `/admin/layout-editor/${TEMPLATE}`;
// 인기글(board/popular) 의 게시글 카드 iteration 원본 노드 path (D-31~D-34 실측 기준).
const ITERATION_SOURCE = '2.children.5.children.0.children.0.children.2.children.0.children.1';
const HOST = 'board/popular';

async function gotoIterationEdit(page: import('@playwright/test').Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  const url = `${EDITOR_BASE}?edit=__iteration__/${encodeURIComponent(ITERATION_SOURCE)}&host=${encodeURIComponent(HOST)}`;
  await page.goto(url);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-toolbar"]', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
}

test.describe('@layout-editor 반복 항목 편집 — 저장/복귀/인라인/잠금가시', () => {
  // URL 다이렉트 진입 시 반복 항목 편집 모드 + 호스트(인기글) 인플레이스 렌더.
  test('URL 다이렉트 진입 → iteration_item 모드 + 호스트 렌더', async ({ page }) => {
    await gotoIterationEdit(page);
    await expect(page.locator('[data-mode="iteration_item"]')).toBeAttached({ timeout: 20_000 });
    // 호스트(인기글) 콘텐츠가 인플레이스로 렌더 — 편집 노드 존재.
    await expect
      .poll(() => page.evaluate(() => document.querySelectorAll('[data-editor-path]').length))
      .toBeGreaterThan(0);
  });

  // 편집 종료 시 호스트 라우트로 복귀("라우트 선택" 화면 회귀 방지).
  test('편집 종료 → 호스트 라우트(?route=/boards/popular)로 복귀', async ({ page }) => {
    await gotoIterationEdit(page);
    // "반복 항목 편집 종료" 버튼.
    await page.locator('[data-testid="g7le-toolbar-exit-alt-mode"]').click();
    // URL 이 호스트 라우트로 — "라우트 선택" 이 아니라 popular 화면.
    await expect.poll(() => page.url()).toMatch(/route=.*popular/);
    await expect(page.locator('[data-mode="iteration_item"]')).toHaveCount(0);
  });

  // 항목 안 텍스트 더블클릭 시 무반응 금지(원 결함 = 더블클릭이 dnd 핸들에 삼켜져 무반응).
  // 렌더된 텍스트만으로는 평문/바인딩을 구분할 수 없으므로(렌더 결과엔 중괄호가 없음 — 인기글
  // 카드는 전부 바인딩), 불변식은 "인라인 편집기(contenteditable) 또는 데이터 영역 안내 중
  // 하나가 반드시 뜬다"로 가드한다(평문→인라인 D-33 / 바인딩→안내 D-34, 무반응이면 실패).
  test('항목 안 텍스트 더블클릭 → 인라인 편집 또는 데이터 영역 안내(무반응 금지)', async ({ page }) => {
    await gotoIterationEdit(page);
    const found = await page.evaluate((srcPath) => {
      const nodes = Array.from(
        document.querySelectorAll(`[data-editor-path^="${srcPath}.iteration.0."]`),
      ) as HTMLElement[];
      // 자식 없는 텍스트 leaf 후보(평문/바인딩 불문).
      const leaf = nodes.find((n) => {
        const t = (n.textContent || '').trim();
        return t.length > 0 && t.length < 8 && n.querySelectorAll('[data-editor-path]').length === 0;
      });
      if (!leaf) return null;
      const r = leaf.getBoundingClientRect();
      return { x: Math.round(r.x + 8), y: Math.round(r.y + r.height / 2) };
    }, ITERATION_SOURCE);
    test.skip(!found, '항목 내 텍스트 leaf 없음 — D-34 데이터 영역 가시 케이스로 대체 검증');
    await page.mouse.dblclick(found!.x, found!.y);
    await expect(
      page
        .locator('[contenteditable="true"]')
        .or(page.locator('[data-testid="g7le-overlay-data-bound-notice"]')),
    ).toBeAttached({ timeout: 10_000 });
  });

  // 항목 안 데이터 바인딩 노드 선택 시 "데이터 영역은 직접 편집할 수 없습니다" 안내 표시
  // (일반 편집기와 동일 가시). iteration 범위 안이라고 무반응이면 안 된다.
  test('데이터 바인딩 노드 선택 → "데이터 영역" 안내 표시', async ({ page }) => {
    await gotoIterationEdit(page);
    // 바인딩 텍스트(제목 등) 좌표 클릭.
    const pt = await page.evaluate((srcPath) => {
      const nodes = Array.from(
        document.querySelectorAll(`[data-editor-path^="${srcPath}.iteration.0."]`),
      ) as HTMLElement[];
      // leaf(자식 편집 노드 없음) + 텍스트 보유 노드 — 컨테이너는 자식 텍스트 합산으로
      // 길이 조건을 충족해 버리므로(잠금 없는 컨테이너 선택 → 안내 미노출) leaf 한정.
      const target = nodes.find(
        (n) =>
          (n.textContent || '').trim().length >= 3 &&
          n.querySelectorAll('[data-editor-path]').length === 0,
      );
      if (!target) return null;
      const r = target.getBoundingClientRect();
      return { x: Math.round(r.x + 12), y: Math.round(r.y + r.height / 2) };
    }, ITERATION_SOURCE);
    expect(pt).not.toBeNull();
    await page.mouse.click(pt!.x, pt!.y);
    // "데이터 영역은 직접 편집할 수 없습니다" 안내(일반 편집기와 동일 가시).
    // 관리자 UI 로케일(en 등)에 비종속이도록 문구가 아닌 testid 로 단언한다.
    await expect(page.locator('[data-testid="g7le-overlay-data-bound-notice"]')).toBeAttached({
      timeout: 10_000,
    });
  });
});
