/**
 * iteration id 보간 + HTML id 유일성
 *
 * 결함: iteration 안 노드 id 가 표현식(`item_{{$idx}}`)이어도 일반 렌더 경로에서 보간되지
 * 않아 리터럴 그대로 출력 → row 마다 같은 HTML id 중복(W3C 위반) + 같은 리터럴 id 로 React
 * reconciliation 이 행을 합쳐 렌더 누락. 슬롯에 주입된 컴포넌트(헤더 통화 셀렉터)도 데스크톱/
 * 모바일 SlotContainer 양쪽 마운트 시 정적 root id 중복.
 *
 * 수정(engine-v1.52.4):
 *  - DynamicRenderer 가 id 표현식을 iteration 컨텍스트로 보간(resolvedComponentId).
 *  - SlotContainer 가 주입 컴포넌트 root id 를 컨테이너 id 로 스코프.
 *  - 대시보드 반복 목록·게시판/이커머스 위젯 id 동적화.
 *
 * 본 spec 은 실제 브라우저 DOM 에서 중복 id 0 + 미보간 리터럴 0 을 잠근다(단위/시뮬레이션은
 * 보간 부재로 인한 reconciliation 합쳐짐을 포착하지 못해 실측이 필수 — feedback #238 계열).
 *
 * @scenario admin_dashboard_render + layout_editor_core_id_chip
 * @effects html_id_unique_no_duplicates + no_uninterpolated_literal_id + core_id_chip_affordance
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

test.describe('@layout-editor iteration id 유일성', () => {
  test('관리자 대시보드 렌더 시 HTML id 중복 0 + 미보간 리터럴 0', async ({ page }) => {
    const token = issueToken(
      'core.dashboard.read',
      'core.dashboard.activities',
      'core.modules.read',
      'core.plugins.read',
      'core.templates.read',
      'core.language_packs.read',
      'core.notification-logs.read',
    );
    await authenticatePage(page, token);

    await page.goto('/admin/dashboard');
    await page.waitForLoadState('networkidle', { timeout: 40_000 });
    // 반복 목록(모듈 등)이 채워질 때까지 대기 — 로케일 무관 구조 마커(반복 항목 DOM id).
    // 보간이 동작하면 module_item_0 같은 고유 id 가 생기고, 깨졌으면 module_item_{{$idx}} 가 됨.
    await page.waitForFunction(
      () => document.querySelectorAll('[id^="module_item_"], [id^="activity_item_"]').length > 0,
      { timeout: 30_000 },
    );

    const report = await page.evaluate(() => {
      const all = Array.from(document.querySelectorAll('[id]'))
        .map((el) => el.id)
        .filter(Boolean);
      const seen = new Set<string>();
      const dup = new Set<string>();
      for (const id of all) {
        if (seen.has(id)) dup.add(id);
        else seen.add(id);
      }
      const literals = all.filter((id) => id.includes('{{') || id.includes('}}'));
      // iteration 보간이 실제로 일어났는지 — 동적 id 표본(있을 때만 확인)
      const interpolated = all.filter((id) => /^(activity_item|module_item|plugin_item)_\d+$/.test(id));
      return {
        total: all.length,
        duplicates: Array.from(dup),
        literals,
        interpolatedSampleCount: interpolated.length,
      };
    });

    // 핵심 단언: 중복 id 0, 미보간 리터럴 0.
    expect(report.literals, '미보간 `{{...}}` 리터럴 id 가 남아있음').toEqual([]);
    expect(report.duplicates, `중복 HTML id: ${report.duplicates.join(', ')}`).toEqual([]);
  });

  test('레이아웃 편집기 요소 ID 칸에 데이터 칩 진입점(BindingChipTextInput) 노출', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-admin_basic?route=*/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await expect(page.getByTestId('g7le-toolbar-add-element')).toBeVisible({ timeout: 40_000 });

    // 캔버스 미리보기가 대시보드 노드를 렌더할 때까지 대기 — modules_badge own-content 노드.
    await page.waitForFunction(() => !!document.getElementById('modules_badge'), { timeout: 40_000 });

    // 선택 가능한 own-content 노드(modules_badge) 선택 → ⓘ → 속성 편집 → Props 탭 → 요소 ID.
    // 캔버스 선택은 합성 포인터 시퀀스로 위임(편집기 클릭 위임은 wrapper 등록 — 메모리 규율).
    const opened = await page.evaluate(async () => {
      const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));
      const el = document.getElementById('modules_badge');
      if (!el) return { step: 'no-node' };
      el.scrollIntoView({ block: 'center' });
      await sleep(200);
      const r = el.getBoundingClientRect();
      const cx = Math.round(r.left + r.width / 2);
      const cy = Math.round(r.top + r.height / 2);
      const opts = {
        bubbles: true, cancelable: true, composed: true, view: window,
        clientX: cx, clientY: cy, button: 0, pointerId: 1, pointerType: 'mouse', isPrimary: true,
      } as PointerEventInit & MouseEventInit;
      const target = (document.elementFromPoint(cx, cy) as HTMLElement) || el;
      target.dispatchEvent(new PointerEvent('pointerdown', opts));
      target.dispatchEvent(new PointerEvent('pointerup', opts));
      target.dispatchEvent(new MouseEvent('mousedown', opts));
      target.dispatchEvent(new MouseEvent('mouseup', opts));
      target.dispatchEvent(new MouseEvent('click', opts));
      await sleep(200);
      const info = document.querySelector('[data-testid="g7le-overlay-info-button"]') as HTMLElement | null;
      if (!info) return { step: 'no-info' };
      info.click();
      await sleep(200);
      const edit = document.querySelector('[data-testid="g7le-context-menu-edit-props"]') as HTMLElement | null;
      if (!edit) return { step: 'no-edit-menu' };
      edit.click();
      await sleep(300);
      const propsTab = document.querySelector('[data-testid="g7le-property-tab-props"]') as HTMLElement | null;
      if (propsTab) propsTab.click();
      await sleep(250);
      return { step: 'done' };
    });
    expect(opened.step, `편집기 속성 모달 진입 실패: ${opened.step}`).toBe('done');

    // "요소 ID" 컨트롤 + 데이터 칩 진입점([🔗 데이터])이 노출되어야 한다.
    await expect(page.getByTestId('g7le-widget-core-id')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('g7le-core-id-add-data')).toBeVisible({ timeout: 10_000 });

    // 칩 편집기로 전환되는지 — [🔗 데이터] 클릭 → BindingChipTextInput 노출.
    await page.getByTestId('g7le-core-id-add-data').click();
    await expect(page.getByTestId('g7le-core-id-chip-input-box')).toBeVisible({ timeout: 10_000 });
  });
});
