/**
 * Layout Editor — 속성 편집 UX 회귀.
 *
 * 결함/요구 7건 중 브라우저 검증 대상:
 *  - 항목1: 이터레이션 영역 속성 편집이 모든 인스턴스에 함께 반영
 *  - 항목2/3: 큰 요소의 + 버튼·ⓘ 버튼이 박스 변/코너 바깥 여백(30px)으로 밀려 핸들 비가림
 *  - 항목4: 리사이즈 핸들 렌더(편집 가능 요소) — 드래그→undo 는 단위 테스트가 잠금
 *  - 항목7: 속성 편집 중 선택 외 영역 딤/잠금 + 어떤 닫기 경로로든 자동 해제
 *  - 표시 권한: 고급 탭 표시 권한 TagInput 후보 주입(코어+활성 확장) → "+ 추가" 활성
 *
 * 단위(Vitest)가 파서/오프셋/onResizeEnd 로직을 정밀 잠금하고, 본 Playwright 는
 * 실제 편집기 DOM 에서 선택→모달→패치 라운드트립과 딤/잠금 해제를 브라우저로 검증한다
 * (단위 시뮬레이션이 모사 못 하는 modal.stack 파생 + 실제 React 재렌더 경로).
 *
 * @scenario iteration_shared_edit + affordance_gap + edit_lock_dim + edit_lock_release + permission_candidate_add
 * @effects all_iteration_instances_patched + affordance_offset_30 + canvas_dim_on_modal + canvas_undim_on_close + permission_add_enabled
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

async function gotoEditor(page: Page, route = '%2F'): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto(`/admin/layout-editor/sirsoft-basic?route=${route}`);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
  await page.waitForFunction(() => document.querySelectorAll('[data-editor-path]').length > 0, {
    timeout: 20_000,
  });
}

/** 캔버스 위임 click 으로 노드 선택(실제 편집기가 받는 pointer/click 시퀀스). */
async function selectByPath(page: Page, path: string): Promise<void> {
  await page.evaluate((p) => {
    const el = document.querySelector(`[data-editor-path="${p}"]`);
    if (!el) return;
    el.scrollIntoView({ block: 'center' });
    const r = el.getBoundingClientRect();
    const cx = r.left + r.width / 2;
    const cy = r.top + r.height / 2;
    for (const type of ['pointerover', 'pointermove', 'pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click']) {
      el.dispatchEvent(new MouseEvent(type, { bubbles: true, clientX: cx, clientY: cy }));
    }
  }, path);
}

async function openModalStyleTab(page: Page): Promise<void> {
  await page.waitForSelector('[data-testid="g7le-overlay-info-button"]', { timeout: 10_000 });
  await page.getByTestId('g7le-overlay-info-button').click();
  await page.waitForSelector('[data-testid="g7le-context-menu-edit-props"]', { timeout: 5_000 });
  await page.getByTestId('g7le-context-menu-edit-props').click();
  await page.waitForSelector('[data-testid="g7le-property-modal"]', { timeout: 10_000 });
  await page.getByTestId('g7le-property-tab-style').click();
}

test.describe('@layout-editor 속성 편집 UX', () => {
  test('항목1 — 이터레이션 인스턴스 속성 편집이 모든 인스턴스에 함께 반영', async ({ page }) => {
    await gotoEditor(page);

    // iteration 인스턴스(.iteration.N)를 가진 첫 이터레이션 묶음의 owner path 와 인스턴스 수 파악
    const info = await page.evaluate(() => {
      const owners = new Map<string, number>();
      document.querySelectorAll('[data-editor-path]').forEach((e) => {
        const m = /^(.*)\.iteration\.(\d+)$/.exec(e.getAttribute('data-editor-path') ?? '');
        if (m) owners.set(m[1], Math.max(owners.get(m[1]) ?? 0, Number(m[2]) + 1));
      });
      // 가시(width>0) 인스턴스를 가진 owner 우선
      for (const [owner, count] of owners) {
        const first = document.querySelector(`[data-editor-path="${owner}.iteration.0"]`);
        if (first && first.getBoundingClientRect().width > 0 && count >= 2) {
          return { owner, count };
        }
      }
      return null;
    });
    expect(info, '가시 이터레이션 묶음이 있어야 함').not.toBeNull();
    const { owner, count } = info as { owner: string; count: number };

    // 인스턴스 #0 선택 → 묶음(템플릿) 선택됨
    await selectByPath(page, `${owner}.iteration.0`);
    await openModalStyleTab(page);

    // 텍스트 정렬 가운데 적용 (segmented — 클릭 1회 커밋)
    await page.getByTestId('g7le-control-textAlign').getByTestId('g7le-segment-center').click();

    // 모든 인스턴스가 text-center 로 동시 변경되어야 한다(핵심 — 단일 템플릿 패치).
    await expect
      .poll(async () =>
        page.evaluate(
          ({ owner, count }) => {
            let centered = 0;
            for (let i = 0; i < count; i++) {
              const el = document.querySelector(`[data-editor-path="${owner}.iteration.${i}"]`);
              if (el && getComputedStyle(el).textAlign === 'center') centered++;
            }
            return centered;
          },
          { owner, count },
        ),
      )
      .toBe(count);
  });

  test('항목2/3 — 큰(inside) 편집 가능 요소의 + 버튼·ⓘ 버튼 30px 바깥 여백', async ({ page }) => {
    await gotoEditor(page, '%2Flogin');

    // 큰(≥44px) lock-none 요소를 찾아 선택 (로그인 폼 정적 영역)
    const path = await page.evaluate(() => {
      const els = Array.from(document.querySelectorAll('[data-editor-path]'));
      for (const e of els) {
        const r = e.getBoundingClientRect();
        if (r.width >= 80 && r.height >= 50 && r.left > 360 && !/\.iteration\.\d+/.test(e.getAttribute('data-editor-path') ?? '')) {
          return e.getAttribute('data-editor-path');
        }
      }
      return null;
    });
    expect(path, '큰 편집 가능 요소가 있어야 함').not.toBeNull();
    await selectByPath(page, path as string);

    // lock-none 일 때만 + 버튼/ⓘ inside 오프셋 검증 (data_bound 면 스킵 — 환경 의존)
    const measured = await page.evaluate(() => {
      const sel = document.querySelector('[data-testid="g7le-overlay-selected"]');
      const lock = sel?.getAttribute('data-lock-kind');
      const info = document.querySelector('[data-testid="g7le-overlay-info-button"]') as HTMLElement | null;
      const above = document.querySelector('[data-testid="g7le-insertion-above"]') as HTMLElement | null;
      return {
        lock,
        infoPlacement: info?.getAttribute('data-placement') ?? null,
        infoRight: info?.style.right ?? null,
        infoTop: info?.style.top ?? null,
        aboveTop: above?.style.top ?? null,
      };
    });

    test.skip(measured.lock !== 'none' || measured.infoPlacement !== 'inside',
      '선택 요소가 lock-none 큰(inside) 박스가 아니면 오프셋 검증 스킵(환경 의존)');

    expect(measured.infoRight).toBe('-30px');
    expect(measured.infoTop).toBe('-30px');
    expect(measured.aboveTop).toBe('-30px');
  });

  test('항목7 — 속성 편집 중 캔버스 딤/잠금 + 닫기(편집 후 포함) 시 자동 해제', async ({ page }) => {
    await gotoEditor(page);

    // 임의 선택 가능한 요소 선택 (data_bound 여도 모달 열림)
    const path = await page.evaluate(() => {
      const els = Array.from(document.querySelectorAll('[data-editor-path]'));
      for (const e of els) {
        const r = e.getBoundingClientRect();
        if (r.width >= 80 && r.height >= 50 && r.left > 360 && !/\.iteration\.\d+/.test(e.getAttribute('data-editor-path') ?? '')) {
          return e.getAttribute('data-editor-path');
        }
      }
      return null;
    });
    expect(path).not.toBeNull();
    await selectByPath(page, path as string);

    await page.getByTestId('g7le-overlay-info-button').click();
    await page.getByTestId('g7le-context-menu-edit-props').click();
    await page.waitForSelector('[data-testid="g7le-property-modal"]', { timeout: 10_000 });

    // 모달 열림 → 딤/잠금 레이어 존재 + 실제 어둡게 + 클릭 차단 + 드래그 핸들 위 z-index
    const dim = await page.evaluate(() => {
      const hole = document.querySelector('[data-testid="g7le-edit-lock"]');
      const full = document.querySelector('[data-testid="g7le-edit-lock-full"]');
      const rect = (document.querySelector('[data-testid="g7le-edit-lock-top"]')
        ?? document.querySelector('[data-testid="g7le-edit-lock-full"]')) as HTMLElement | null;
      // 캔버스 드래그 핸들의 최대 z-index (편집 중 핸들 클릭 차단 검증용)
      const handleZ = Array.from(document.querySelectorAll('[data-dnd-handle-path]'))
        .map((h) => parseInt(getComputedStyle(h as HTMLElement).zIndex, 10) || 0);
      return {
        present: !!hole || !!full,
        bg: rect ? getComputedStyle(rect).backgroundColor : null,
        pointerEvents: rect ? getComputedStyle(rect).pointerEvents : null,
        dimZ: rect ? parseInt(getComputedStyle(rect).zIndex, 10) || 0 : 0,
        maxHandleZ: handleZ.length ? Math.max(...handleZ) : 0,
      };
    });
    expect(dim.present, '편집 중 딤/잠금 레이어가 있어야 함').toBe(true);
    expect(dim.bg).toBe('rgba(15, 23, 42, 0.45)');
    expect(dim.pointerEvents).toBe('auto');
    // 딤이 드래그 핸들 위에 와야 편집 중 선택 외 요소의 핸들 클릭으로 선택/이동되지 않는다
    expect(dim.dimZ).toBeGreaterThan(dim.maxHandleZ);

    // 편집을 1회 발생시켜 모달 재오픈 경로를 탄다: 고급 탭 → 표시 권한 칩 추가
    await page.getByTestId('g7le-property-tab-advanced').click();
    await page.waitForSelector('[data-testid="g7le-tag-add"]', { timeout: 5_000 });
    await expect(page.getByTestId('g7le-tag-add')).toBeEnabled(); // 표시 권한 후보 주입 확인
    await page.getByTestId('g7le-tag-add').click();
    await page.waitForSelector('[data-testid="g7le-tag-candidates"] button', { timeout: 5_000 });
    await page.locator('[data-testid="g7le-tag-candidates"] button').first().click();

    // 편집 후 "닫기" 버튼으로 닫기 → 딤/잠금이 완전히 사라져야 한다
    await page.getByTestId('g7le-property-modal-done').click();
    await expect
      .poll(async () =>
        page.evaluate(
          () => document.querySelectorAll('[data-testid^="g7le-edit-lock"]').length,
        ),
      )
      .toBe(0);
    await expect(page.locator('[data-testid="g7le-property-modal"]')).toHaveCount(0);
  });

  test('표시 권한 — 고급 탭 TagInput 후보가 편집기 전용 엔드포인트에서 fetch 되어 추가 활성', async ({ page }) => {
    await gotoEditor(page);

    // 편집기 전용 엔드포인트가 후보를 반환하는지 확인 (백엔드 배선 — G7Config 상시 노출 폐기).
    // admin 가드 엔드포인트라 Sanctum Bearer 토큰(localStorage.auth_token) 첨부 필요.
    const permCount = await page.evaluate(async () => {
      const token = localStorage.getItem('auth_token');
      const r = await fetch('/api/admin/templates/sirsoft-basic/editor/permission-candidates.json', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
      });
      if (!r.ok) return -1;
      const b = await r.json();
      return Array.isArray(b?.data?.permissions) ? b.data.permissions.length : -1;
    });
    expect(permCount, '권한 후보 엔드포인트가 후보를 반환해야 함').toBeGreaterThan(0);

    const path = await page.evaluate(() => {
      const els = Array.from(document.querySelectorAll('[data-editor-path]'));
      for (const e of els) {
        const r = e.getBoundingClientRect();
        if (r.width >= 80 && r.height >= 50 && r.left > 360 && !/\.iteration\.\d+/.test(e.getAttribute('data-editor-path') ?? '')) {
          return e.getAttribute('data-editor-path');
        }
      }
      return null;
    });
    expect(path).not.toBeNull();
    await selectByPath(page, path as string);
    await page.getByTestId('g7le-overlay-info-button').click();
    await page.getByTestId('g7le-context-menu-edit-props').click();
    await page.waitForSelector('[data-testid="g7le-property-modal"]', { timeout: 10_000 });
    await page.getByTestId('g7le-property-tab-advanced').click();

    // "+ 추가" 활성 + 후보 목록이 G7Config.permissions 수와 일치
    const addBtn = page.getByTestId('g7le-tag-add');
    await expect(addBtn).toBeEnabled();
    await addBtn.click();
    const candCount = await page.locator('[data-testid="g7le-tag-candidates"] button').count();
    expect(candCount).toBe(permCount);
  });
});
