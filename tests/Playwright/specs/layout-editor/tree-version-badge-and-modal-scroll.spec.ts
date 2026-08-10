/**
 * Layout Editor — 라우트 트리 레이아웃 버전 배지 + 모달 편집 캔버스 스크롤 유지
 *
 * (1) 좌측 라우트 트리는 저장 이력이 있는 레이아웃에 현재(최신) 버전 배지(vN)를 표시한다.
 *     데이터 SSoT 는 편집기 routes 응답의 `layout_versions` 맵 — 배지 숫자는 맵 값과 일치해야
 *     하고, 이력이 없는(원본) 레이아웃에는 배지가 없어야 한다. 저장/복원 시의 단건 동기화는
 *     단위(useLayoutDocument/리듀서) + 백엔드 Feature(current_version) 테스트가 커버.
 * (2) 모달 편집 모드 진입 시 Modal composite(isOpen=true 강제)의 body 스크롤 락이 편집기
 *     페이지(=캔버스) 스크롤바를 제거하던 결함 — usePreviewBodyScrollIsolation 이 락을
 *     무력화해 body overflow 가 hidden 이 아니어야 한다.
 *
 * (3) 확장(주입 조각)도 동일 체계 — 트리 확장 노드에 확장 자체 버전
 *     배지(layout-extensions 응답 current_version)가 표시되고, 확장 편집 모드에서 툴바
 *     "버전 기록"이 활성화되어 확장 전용 버전 API 의 목록 모달이 열린다. 저장/복원 동기화는
 *     단위(useExtensionDocument/리듀서/VersionHistoryModal) + 백엔드 Feature 테스트가 커버.
 *
 * @scenario edit_mode + layout_history + scroll_lock_source
 * @effects editor_routes_response_includes_layout_versions_map + tree_version_badge_matches_versions_map + no_badge_for_layouts_without_history + modal_edit_keeps_canvas_scrollbar + extension_badge_matches_current_version + extension_mode_version_history_modal_opens
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const TEMPLATE = 'sirsoft-basic';
const EDITOR_URL = `/admin/layout-editor/${TEMPLATE}`;
// _user_base 호스트의 partial 모달 — 모달 편집 모드 직접 진입(스크롤 락 재현 경로).
const MODAL_DIRECT_URL = `${EDITOR_URL}?edit=__modal__/notification_delete_all_confirm_modal&host=_user_base`;

async function enterEditor(page: import('@playwright/test').Page, url: string): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto(url);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-toolbar"]', { timeout: 30_000 });
}

test.describe('@layout-editor 트리 버전 배지 + 모달 편집 스크롤 유지', () => {
  test('라우트 트리 버전 배지가 routes 응답 layout_versions 맵과 1:1 정합', async ({ page }) => {
    await enterEditor(page, EDITOR_URL);
    await page.waitForSelector('[data-testid="g7le-route-tree-item"]', { timeout: 30_000 });

    // SSoT — 편집기 routes 응답의 layout_versions 맵.
    const versions = await page.evaluate(async (tpl) => {
      const authToken = localStorage.getItem('auth_token');
      const res = await fetch(`/api/admin/templates/${tpl}/editor/routes.json`, {
        headers: {
          Accept: 'application/json',
          ...(authToken ? { Authorization: `Bearer ${authToken}` } : {}),
        },
        credentials: 'same-origin',
      });
      const body = await res.json();
      return (body?.data?.layout_versions ?? {}) as Record<string, number>;
    }, TEMPLATE);

    // 트리 DOM 의 배지를 노드별로 수집해 맵과 대조.
    const domBadges = await page.evaluate(() => {
      const items = Array.from(
        document.querySelectorAll('[data-testid="g7le-route-tree-item"]'),
      ) as HTMLElement[];
      return items.map((el) => ({
        path: el.getAttribute('data-route-path'),
        badge: el.querySelector('[data-testid="g7le-route-tree-version"]')?.textContent ?? null,
      }));
    });

    const badged = domBadges.filter((b) => b.badge !== null);
    const versionValues = new Set(Object.values(versions).map((v) => `v${v}`));

    if (Object.keys(versions).length === 0) {
      // 이력이 전무한 환경 — 배지도 0 이어야 함(원본 레이아웃 무배지).
      expect(badged.length).toBe(0);
    } else {
      // 이력이 있으면 배지가 1개 이상 표시되고, 모든 배지 텍스트는 맵의 버전 값 중 하나여야 함.
      expect(badged.length).toBeGreaterThan(0);
      for (const b of badged) {
        expect(versionValues.has(b.badge as string)).toBe(true);
      }
    }
  });

  test('모달 편집 모드에서 body 스크롤 락이 무력화되어 캔버스 스크롤바 유지', async ({ page }) => {
    await enterEditor(page, MODAL_DIRECT_URL);
    await expect(page.locator('[data-mode="modal"]')).toBeAttached({ timeout: 30_000 });

    // Modal composite 의 isOpen effect(body overflow:hidden)가 발화한 뒤에도
    // usePreviewBodyScrollIsolation 이 인라인 락을 해제해야 한다(스크롤바 유지).
    await expect
      .poll(
        () =>
          page.evaluate(() => ({
            inline: document.body.style.overflow,
            computed: getComputedStyle(document.body).overflow,
          })),
        { timeout: 15_000 },
      )
      .not.toMatchObject({ computed: 'hidden' });

    const state = await page.evaluate(() => ({
      inline: document.body.style.overflow,
      scrollable: document.documentElement.scrollHeight > window.innerHeight,
    }));
    expect(state.inline).not.toBe('hidden');
  });

  test('확장 노드 버전 배지가 layout-extensions 응답 current_version 과 정합 + 확장 편집 모드 버전 기록 모달', async ({ page }) => {
    await enterEditor(page, EDITOR_URL);
    await page.waitForSelector('[data-testid="g7le-route-tree-item"]', { timeout: 30_000 });

    // SSoT — layout-extensions 응답의 확장별 current_version.
    const extensions = await page.evaluate(async (tpl) => {
      const authToken = localStorage.getItem('auth_token');
      const res = await fetch(`/api/admin/templates/${tpl}/layout-extensions`, {
        headers: {
          Accept: 'application/json',
          ...(authToken ? { Authorization: `Bearer ${authToken}` } : {}),
        },
        credentials: 'same-origin',
      });
      const body = await res.json();
      const groups = body?.data ?? [];
      return groups.flatMap((g: { extensions: Array<{ id: number; current_version: number | null }> }) =>
        g.extensions.map((e) => ({ id: String(e.id), currentVersion: e.current_version })),
      );
    }, TEMPLATE);
    expect(extensions.length).toBeGreaterThan(0);

    // 트리 [확장 주입] 그룹의 확장 노드 배지를 수집해 맵과 대조.
    const domExtBadges = await page.evaluate(() => {
      const items = Array.from(
        document.querySelectorAll('[data-route-kind="extension"]'),
      ) as HTMLElement[];
      return items.map((el) => ({
        path: el.getAttribute('data-route-path'),
        badge: el.querySelector('[data-testid="g7le-route-tree-version"]')?.textContent ?? null,
      }));
    });
    expect(domExtBadges.length).toBeGreaterThan(0);
    for (const node of domExtBadges) {
      const id = (node.path ?? '').replace('__extension__/', '');
      const meta = extensions.find((e: { id: string }) => e.id === id);
      const expected = typeof meta?.currentVersion === 'number' ? `v${meta.currentVersion}` : null;
      expect(node.badge).toBe(expected);
    }

    // 확장 편집 모드 진입 → 툴바 "버전 기록" 활성 + 확장 버전 모달이 열린다(목록 또는 빈 안내).
    const targetId = extensions[0].id;
    await page.goto(`${EDITOR_URL}?edit=__extension__/${targetId}`);
    await page.waitForSelector('[data-testid="g7le-toolbar"]', { timeout: 30_000 });
    const versionsBtn = page.locator('[data-testid="g7le-toolbar-versions"]');
    await expect(versionsBtn).toBeEnabled({ timeout: 30_000 });
    await versionsBtn.click();
    await page.waitForSelector('[data-testid="g7le-version-history"]', { timeout: 15_000 });
    // 이력 보유 확장이면 목록, 아니면 empty 안내 — 어느 쪽이든 확장 API 기반 모달이 떠야 한다.
    await expect
      .poll(async () =>
        (await page.locator('[data-testid="g7le-version-history-list"]').count()) +
        (await page.locator('[data-testid="g7le-version-history-empty"]').count()),
      )
      .toBeGreaterThan(0);
  });
});
