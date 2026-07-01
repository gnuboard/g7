/**
 * Layout Editor — 디바이스 목록 동적 수집 + 스타일 탭 portable 결선 + 분리 판정(children 기준).
 *
 * **검증 대상**:
 *  - 디바이스 토글에 portable(모바일+태블릿) 버튼이 동적 수집돼 노출된다.
 *  - 속성 모달 스타일 탭 디바이스 세부탭에 portable 세부탭이 노출된다.
 *  - 분리 판정이 children 유무 기준 — props-only 분기 노드는 '분리 생성',
 *    children 분기 노드는 '분리 해제' 를 노출(키 존재가 아닌 children 존재로 판정).
 *
 * admin_user_list 는 `user_list_content`(base p-6 → portable **props** p-4, children 없음)와
 * `search_row`(portable **children** 교체)를 함께 가져, 두 판정 분기를 한 화면에서 검증한다.
 *
 * @scenario responsive_branch_edit device_list style_scope_tab branch_separation
 * @effects device_toggle_has_portable + style_tab_has_portable + separation_mode_by_children
 */
import type { Page } from '@playwright/test';
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

/** 편집기 admin_user_list 진입 + 캔버스 핸들 렌더까지 대기. */
async function openAdminUserList(page: Page): Promise<void> {
  await page.goto('/admin/layout-editor/sirsoft-admin_basic');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () =>
      Array.from(document.querySelectorAll('.g7le-route-tree__layout-path')).some((s) =>
        /admin_user_list\.json$/.test((s.textContent || '').trim()),
      ),
    undefined,
    { timeout: 30_000 },
  );
  await page.evaluate(() => {
    const span = Array.from(document.querySelectorAll('.g7le-route-tree__layout-path')).find((s) =>
      /admin_user_list\.json$/.test((s.textContent || '').trim()),
    );
    let el: Element | null = span ?? null;
    while (el && el.getAttribute('role') !== 'button' && el.tagName !== 'BUTTON') {
      el = el.parentElement;
    }
    el?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
  });
  await page.waitForSelector('[data-dnd-handle-path]', { timeout: 30_000 });
}

/** 디바이스 토글 버튼을 testid 로 클릭. */
async function clickDevice(page: Page, device: string): Promise<void> {
  await page.evaluate((d) => {
    document
      .querySelector(`[data-testid="g7le-device-${d}"]`)
      ?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
  }, device);
  await page.waitForTimeout(80);
}

/** 캔버스 노드를 id 로 선택(합성 포인터 시퀀스 — dnd 핸들이 클릭 가로채는 경우 대비). */
async function selectNodeById(page: Page, id: string): Promise<void> {
  await page.evaluate((nid) => {
    const el = document.getElementById(nid);
    if (!el) return;
    const r = el.getBoundingClientRect();
    const o = {
      bubbles: true,
      cancelable: true,
      composed: true,
      clientX: Math.round(r.left + 15),
      clientY: Math.round(r.top + 8),
      button: 0,
      pointerId: 1,
      pointerType: 'mouse',
      isPrimary: true,
    } as PointerEventInit & MouseEventInit;
    for (const [t, C] of [
      ['pointerdown', PointerEvent],
      ['mousedown', MouseEvent],
      ['pointerup', PointerEvent],
      ['mouseup', MouseEvent],
      ['click', MouseEvent],
    ] as const) {
      el.dispatchEvent(new (C as typeof MouseEvent)(t, o));
    }
  }, id);
  await page.waitForTimeout(120);
}

test.describe('@layout-editor responsive 디바이스 목록 + 분리 판정', () => {
  test('디바이스 토글에 portable(모바일+태블릿) 버튼이 노출된다 ', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openAdminUserList(page);

    await expect(page.locator('[data-testid="g7le-device-portable"]')).toBeAttached();
    // 프리셋 4종 모두 존재.
    for (const d of ['desktop', 'tablet', 'mobile', 'portable']) {
      await expect(page.locator(`[data-testid="g7le-device-${d}"]`)).toBeAttached();
    }
  });

  test('스타일 탭 디바이스 세부탭에 portable 세부탭이 노출된다 ', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openAdminUserList(page);

    // user_list_content 선택 → ⓘ 메뉴 → 속성 설정 → 스타일 탭.
    await selectNodeById(page, 'user_list_content');
    await page.evaluate(() => {
      document
        .querySelector('[data-testid="g7le-overlay-info-button"]')
        ?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    });
    await page.waitForTimeout(120);
    await page.evaluate(() => {
      const menu = document.querySelector('[data-testid="g7le-context-menu"]') || document;
      const item = Array.from(menu.querySelectorAll('[role="menuitem"],button')).find((b) =>
        /속성 설정|속성 편집|Properties/.test(b.textContent || ''),
      );
      (item as HTMLElement | undefined)?.click();
    });
    await page.waitForSelector('[data-testid="g7le-style-scope-tabs"]', { timeout: 10_000 });
    await page.evaluate(() => {
      const styleTab = Array.from(document.querySelectorAll('button')).find((b) =>
        /^스타일$|^Style$/.test((b.textContent || '').trim()),
      );
      styleTab?.click();
    });
    await page.waitForTimeout(120);

    await expect(page.locator('[data-testid="g7le-style-bp-portable"]')).toBeAttached();
  });

  test('분리 판정은 children 유무 기준 — props-only=생성 / children=해제 ', async ({
    page,
  }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openAdminUserList(page);

    // portable 보기로 전환(분기 매칭).
    await clickDevice(page, 'portable');

    // ① props-only portable 분기 노드(user_list_content) → '분리 생성' 노출, '해제' 미노출.
    await selectNodeById(page, 'user_list_content');
    await expect(
      page.locator('[data-testid="g7le-overlay-separate-branch"]'),
      'props-only 분기 노드는 분리 생성 버튼을 노출해야 함(children 기준 판정)',
    ).toBeAttached();
    expect(
      await page.locator('[data-testid="g7le-overlay-merge-branch"]').count(),
      'props-only 노드에 분리 해제 버튼이 떠선 안 됨',
    ).toBe(0);

    // ② children 교체 분기 노드(search_row) → '분리 해제' 노출, '생성' 미노출.
    await selectNodeById(page, 'search_row');
    await expect(
      page.locator('[data-testid="g7le-overlay-merge-branch"]'),
      'children 분기 노드는 분리 해제 버튼을 노출해야 함',
    ).toBeAttached();
    expect(
      await page.locator('[data-testid="g7le-overlay-separate-branch"]').count(),
      'children 분기 노드에 분리 생성 버튼이 떠선 안 됨',
    ).toBe(0);
  });

  test('정의된 디바이스 구성 점프 버튼 — 다른 디바이스 children 구성 노드 선택 시 노출 ', async ({
    page,
  }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openAdminUserList(page);

    // 데스크톱 보기에서 portable children 구성을 가진 노드(bulk_actions_area) 선택 →
    // "↗ 모바일 구성으로 이동" 점프 버튼이 노출되어야 한다.
    await clickDevice(page, 'desktop');
    await selectNodeById(page, 'bulk_actions_area');
    await expect(
      page.locator('[data-testid="g7le-overlay-jump-device-portable"]'),
      'portable children 구성이 있는 노드는 데스크톱 보기에서 모바일 구성 점프 버튼을 노출해야 함',
    ).toBeAttached();

    // 점프 버튼 클릭 → 캔버스가 portable 보기로 전환된다.
    await page.locator('[data-testid="g7le-overlay-jump-device-portable"]').click();
    await page.waitForTimeout(150);
    await expect(page.locator('[data-testid="g7le-device-portable"]')).toHaveAttribute(
      'aria-pressed',
      'true',
    );
  });

  test('포괄 분기(mobile 보기 + portable children) → 점프 + 분리 버튼 공존, 한 컨테이너에 세로 스택 (후속)', async ({
    page,
  }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await openAdminUserList(page);

    // mobile 보기 — portable(0~1023) 이 mobile 을 포괄. bulk_actions_area 는 portable children 보유.
    await clickDevice(page, 'mobile');
    await selectNodeById(page, 'bulk_actions_area');

    // 포괄 구성으로 이동 점프 버튼 + 현재 디바이스(mobile) 전용 신규 분리 버튼이 함께 노출.
    const jump = page.locator('[data-testid="g7le-overlay-jump-device-portable"]');
    const separate = page.locator('[data-testid="g7le-overlay-separate-branch"]');
    await expect(jump, '포괄 분기에서도 이동 버튼이 노출되어야 함').toBeAttached();
    await expect(separate, '포괄 분기에서도 현재 디바이스 전용 신규 분리 버튼이 노출되어야 함').toBeAttached();

    // 두 버튼은 한 세로 스택 컨테이너 안에 담겨 가로로 겹치지 않는다.
    const container = page.locator('[data-testid="g7le-overlay-branch-affordances"]');
    await expect(container).toBeAttached();
    expect(await container.locator('[data-testid="g7le-overlay-jump-device-portable"]').count()).toBe(1);
    expect(await container.locator('[data-testid="g7le-overlay-separate-branch"]').count()).toBe(1);

    // 점프 버튼 라벨은 portable = "모바일+태블릿" / "mobile+tablet"(모바일 단독 아님).
    // 로케일 무관 — 결합 라벨은 '+' 를 포함한다(bare 'mobile' 오표기 회귀 가드).
    await expect(jump).toContainText('+');
  });

  test('유저 화면: portable 폭에서 props override + children 교체가 렌더된다 (T8/T9)', async ({
    page,
  }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    // 데스크톱 폭 — base.
    await page.setViewportSize({ width: 1400, height: 900 });
    await page.goto('/admin/users');
    await page.waitForSelector('#user_list_content', { timeout: 30_000 });
    const desktop = await page.evaluate(() => {
      const wrap = document.getElementById('user_list_content');
      const sr = document.getElementById('search_row');
      return {
        pad: wrap ? getComputedStyle(wrap).paddingTop : null,
        hasMobileRow: !!document.getElementById('mobile_search_row'),
        searchRowChildIds: sr ? Array.from(sr.children).map((c) => c.id) : [],
      };
    });
    expect(desktop.pad).toBe('24px'); // p-6
    expect(desktop.hasMobileRow).toBe(false);
    expect(desktop.searchRowChildIds).not.toContain('mobile_search_row');

    // portable 폭 — props override(p-4) + children 교체(mobile_search_row).
    await page.setViewportSize({ width: 800, height: 900 });
    await page.waitForTimeout(300);
    const portable = await page.evaluate(() => {
      const wrap = document.getElementById('user_list_content');
      const sr = document.getElementById('search_row');
      return {
        pad: wrap ? getComputedStyle(wrap).paddingTop : null,
        hasMobileRow: !!document.getElementById('mobile_search_row'),
        searchRowChildIds: sr ? Array.from(sr.children).map((c) => c.id) : [],
        baseSelectGone: !document.getElementById('search_field_select'),
      };
    });
    expect(portable.pad).toBe('16px'); // p-4 override
    expect(portable.hasMobileRow).toBe(true);
    expect(portable.searchRowChildIds).toContain('mobile_search_row');
    expect(portable.baseSelectGone).toBe(true); // base children 완전 교체
  });
});
