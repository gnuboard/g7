/**
 * Layout Editor — 커스텀 다국어 키 관리 모달 + 좀비(고아) 키.
 *
 *  T1. 진입 — 상단 🌐 다국어 버튼이 라우트 선택 시 활성, 클릭하면 관리 모달이 뜬다.
 *  T2. 목록/필터 — 모달이 현재 레이아웃의 커스텀 키를 목록으로 보여 주고
 *      전체/사용중/미사용 필터가 동작한다.
 *  T3. 좀비 생성 → 표시 — 인라인 키 생성 후 그 노드 text 를 평문으로 바꿔 저장하면,
 *      저장 시점에 백엔드가 그 키를 orphaned 로 전이하고, 관리 모달에 "미사용" 배지로 노출된다.
 *  T4. 정리 — orphaned 키를 일괄 삭제하면 목록에서 사라진다.
 *
 * 축 요약(마커 아님 — 평문): toolbar_translations_button_opens_manager, manager_lists_and_filters, orphan_created_on_save_shows_badge, orphan_bulk_delete.
 * 효과 요약(마커 아님 — 평문): toolbar_translations_button_opens_manager_modal, manager_filters_by_status_all_active_orphaned, manager_shows_orphaned_badge, save_marks_unreferenced_custom_key_as_orphaned, manager_purges_all_orphaned_keys, manager_lists_custom_keys_for_layout_with_bearer_header.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';
import { SANDBOX_I18N_NODE_ID, sandboxRouteParam } from '../../fixtures/seed-layout';

const TEMPLATE = 'sirsoft-basic';
const SANDBOX_LAYOUT = 'e2e_sandbox';

async function openEditorLogin(page: Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2Flogin');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
  await page.waitForFunction(
    () => document.querySelectorAll('[data-editor-path]').length > 0,
    { timeout: 20_000 },
  );
}

async function openManager(page: Page): Promise<void> {
  const btn = page.getByTestId('g7le-toolbar-translations');
  await expect(btn).toBeEnabled({ timeout: 10_000 });
  await btn.click();
  await page.waitForSelector('[data-testid="g7le-translation-manager"]', { timeout: 15_000 });
}

test.describe('@layout-editor 커스텀 다국어 관리 모달 (S8-2 후속)', () => {
  /** @effects toolbar_translations_button_opens_manager_modal */
  test('T1. 🌐 버튼 → 관리 모달 진입', async ({ page }) => {
    await openEditorLogin(page);
    await openManager(page);
    await expect(page.getByTestId('g7le-translation-manager-title')).toBeVisible();
    // 닫기 동작 — 컨텍스트 격리(T7) 최소 검증
    await page.getByTestId('g7le-translation-manager-footer-close').click();
    await expect(page.getByTestId('g7le-translation-manager')).toHaveCount(0);
  });

  /** @effects manager_filters_by_status_all_active_orphaned */
  test('T2. 목록/필터 동작 (비어 있으면 empty, 있으면 필터 탭)', async ({ page }) => {
    await openEditorLogin(page);
    await openManager(page);
    const list = page.getByTestId('g7le-translation-list');
    const empty = page.getByTestId('g7le-translation-manager-empty');
    // 목록 또는 빈 안내 중 하나는 떠야 한다.
    await expect(list.or(empty)).toBeVisible({ timeout: 10_000 });
    // 필터 탭 3종 노출 + 클릭 가능.
    for (const mode of ['all', 'active', 'orphaned']) {
      const tab = page.getByTestId(`g7le-translation-filter-${mode}`);
      await expect(tab).toBeVisible();
      await tab.click();
      await expect(tab).toHaveAttribute('data-active', 'true');
    }
  });

  /** @effects manager_lists_custom_keys_for_layout_with_bearer_header */
  test('T3+T4. 모달 fetch 가 Bearer 토큰을 첨부하고 응답을 렌더한다', async ({ page }) => {
    await openEditorLogin(page);
    // 관리 모달 fetch 요청의 Authorization 헤더를 가로채 검증.
    const reqPromise = page.waitForRequest(
      (r) => r.url().includes('/custom-translations') && r.method() === 'GET',
      { timeout: 15_000 },
    );
    await openManager(page);
    const req = await reqPromise;
    expect(req.headers()['authorization'] ?? '').toMatch(/^Bearer\s+.+/);
  });

  /**
   * T3+T4 — 좀비(미사용) 키 생성 → 배지 → 정리.
   *
   * 키를 **붙이는** UI 경로는 캔버스 인라인 편집뿐인데 그 훅은 자동화 부적합 판정을 받아
   * spec 이 밟을 수 없다. 그래서 부착까지는 API 로 준비하고, 검사 대상인 세 가지 —
   * ① 저장 시 참조 끊긴 키가 미사용으로 전이 ② 관리 모달의 미사용 배지 ③ 일괄 정리 —
   * 는 편집기에서 실제로 노드를 지우고 저장하는 조작으로 태운다.
   *
   * 대상은 E2E 전용 시드 화면이라 제품 화면은 건드리지 않는다. 만든 키는 마지막 정리
   * 단계가 지우고, 시드 화면 자체는 teardown 이 제거한다.
   */
  /** @effects save_marks_unreferenced_custom_key_as_orphaned, manager_shows_orphaned_badge, manager_purges_all_orphaned_keys */
  test('T3+T4. 참조가 끊긴 커스텀 키가 미사용으로 전이되고 일괄 정리된다', async ({ page }) => {
    test.setTimeout(90_000);
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    // 상대 URL fetch 는 문서 origin 이 있어야 한다 — 준비 호출 전에 사이트로 진입한다.
    await page.goto(`/admin/layout-editor/${TEMPLATE}?route=${sandboxRouteParam()}`);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const api = async (method: string, url: string, body?: unknown): Promise<any> =>
      page.evaluate(
        async ({ method, url, body, token }: { method: string; url: string; body: unknown; token: string }) => {
          const res = await fetch(url, {
            method,
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
              Authorization: `Bearer ${token}`,
            },
            credentials: 'same-origin',
            body: body === undefined ? undefined : JSON.stringify(body),
          });
          return { status: res.status, body: await res.json().catch(() => null) };
        },
        { method, url, body, token },
      );

    // ① 키 생성 — 키 문자열은 서버가 만든다(`custom.{layout}.{seq}`).
    const created = await api('POST', `/api/admin/templates/${TEMPLATE}/custom-translations`, {
      layout_name: SANDBOX_LAYOUT,
      locale: 'ko',
      value: 'E2E 좀비 키 검사용',
    });
    expect(created.status, `키 생성 실패: ${JSON.stringify(created.body)}`).toBeLessThan(300);
    const keyId: number = created.body?.data?.id ?? created.body?.id;
    const keyName: string = created.body?.data?.translation_key ?? created.body?.translation_key;
    expect(keyId, '생성된 키 id 를 받지 못했다').toBeTruthy();
    expect(keyName, '생성된 키 이름을 받지 못했다').toBeTruthy();

    // ② 시드 화면의 전용 노드에 그 키를 붙인다(참조 성립 → active).
    const shown = await api('GET', `/api/admin/templates/${TEMPLATE}/layouts/${SANDBOX_LAYOUT}`);
    expect(shown.status).toBe(200);
    const raw = shown.body?.data?.content ?? shown.body?.content;
    const content = typeof raw === 'string' ? JSON.parse(raw) : raw;
    const attach = (node: any): boolean => {
      if (!node || typeof node !== 'object') return false;
      if (node.id === SANDBOX_I18N_NODE_ID) {
        node.text = `$t:${keyName}`;
        return true;
      }
      for (const child of [...(node.children ?? []), ...Object.values(node.slots ?? {}).flat()]) {
        if (attach(child)) return true;
      }
      return false;
    };
    expect(attach(content), `시드 화면에서 ${SANDBOX_I18N_NODE_ID} 노드를 찾지 못했다`).toBe(true);
    // 낙관적 잠금 — 방금 읽은 판의 lock_version 을 그대로 되돌려준다.
    const lockVersion = shown.body?.data?.lock_version ?? shown.body?.lock_version ?? 0;
    const put = await api('PUT', `/api/admin/templates/${TEMPLATE}/layouts/${SANDBOX_LAYOUT}`, {
      content: JSON.stringify(content),
      expected_lock_version: lockVersion,
    });
    expect(put.status, `참조 부착 저장 실패: ${JSON.stringify(put.body)}`).toBe(200);

    // 부착 직후에는 사용중(active) 이어야 한다 — 이 대조가 없으면 "원래 미사용" 도 통과한다.
    const activeList = await api(
      'GET',
      `/api/admin/templates/${TEMPLATE}/custom-translations?layout_name=${SANDBOX_LAYOUT}&status=active`,
    );
    const activeIds = (activeList.body?.data ?? activeList.body ?? []).map((r: any) => r.id);
    expect(activeIds, '부착 후에도 사용중이 아니다').toContain(keyId);

    // ③ 편집기에서 그 노드를 지우고 저장 → 참조가 끊긴다
    await page.goto(`/admin/layout-editor/${TEMPLATE}?route=${sandboxRouteParam()}`);
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
    await page.waitForFunction(() => document.querySelectorAll('[data-editor-path]').length > 0, {
      timeout: 20_000,
    });

    const picked = await page.evaluate((nodeId: string) => {
      const el = document
        .querySelector('[data-testid="g7le-preview-frame"]')!
        .querySelector(`#${CSS.escape(nodeId)}`) as HTMLElement | null;
      if (!el) return false;
      el.scrollIntoView({ block: 'center' });
      const r = el.getBoundingClientRect();
      for (const t of ['pointerover', 'pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click'])
        el.dispatchEvent(
          new MouseEvent(t, {
            bubbles: true,
            cancelable: true,
            clientX: r.left + r.width / 2,
            clientY: r.top + r.height / 2,
            view: window,
          }),
        );
      return true;
    }, SANDBOX_I18N_NODE_ID);
    expect(picked, '키가 붙은 노드를 캔버스에서 찾지 못했다').toBe(true);

    await page.getByTestId('g7le-overlay-info-button').click();
    await page.getByTestId('g7le-context-menu-delete').click();

    const saved = page.waitForResponse(
      (r) => /\/api\/admin\/templates\/[^/]+\/layouts\//.test(r.url()) && r.request().method() === 'PUT',
      { timeout: 20_000 },
    );
    await page.getByTestId('g7le-toolbar-save').click();
    expect((await saved).status()).toBe(200);

    // ④ 관리 모달 — 미사용 배지
    await openManager(page);
    await page.getByTestId('g7le-translation-filter-orphaned').click();
    await expect(page.getByTestId(`g7le-translation-badge-orphaned-${keyId}`)).toBeVisible({
      timeout: 15_000,
    });

    // ⑤ 일괄 정리 — 확인 창 수락
    page.on('dialog', (d) => void d.accept());
    await page.getByTestId('g7le-translation-purge-orphaned').click();
    await expect(page.getByTestId(`g7le-translation-row-${keyId}`)).toHaveCount(0, { timeout: 15_000 });
  });
});
