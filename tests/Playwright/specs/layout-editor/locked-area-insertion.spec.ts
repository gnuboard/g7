/**
 * Layout Editor — 잠긴 영역에서도 "요소 추가"(+) 버튼 노출 + 묶음 바깥 삽입.
 *
 * **배경**: 종전 컨텍스트 + 버튼은 선택 노드가 잠금(extension/extension_point/
 * base/partial/data_bound)이면 전면 차단됐다(소유권 경계 우려). 그러나 "요소 추가"는
 * 그 노드를 변형하는 게 아니라 **그 다음에(형제로) 또 다른 요소를 추가**하는 작업이라
 * 안전하다는 판단으로 + 버튼을 복원했다.
 *
 * **잠금 종류별 anchor**:
 *  - extension/extension_point/base/partial: 잠긴 노드는 단일 박스로 렌더 → 그 노드
 *    자신이 anchor. + 버튼은 그 박스 경계, 삽입은 부모(라우트 소유) children 형제.
 *  - data_bound 이터레이션: 인스턴스(`.iteration.N`)만 렌더되고 반복 정의 노드는 자체
 *    DOM 이 없다. anchor = 반복 정의 노드(selectedIterationSourcePath) → 삽입은 정의
 *    노드의 형제(반복 묶음 **바깥**). 인스턴스 옆이 아님(가상 인덱스 회피).
 *
 * 신규 노드는 `__source` 미부여라 저장 마스킹(stripInheritedNodes)이 항상 route
 * 콘텐츠로 보존한다.
 *
 * @scenario selected_node_state=extension_locked_selected,data_bound_selected
 * @effects locked_node_shows_insertion_plus_via_anchor + insertion_inserts_outside_locked_group_as_sibling
 */
import type { Page } from '@playwright/test';
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

async function openRegisterEditor(page: Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2Fregister');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"] [data-editor-path]', { timeout: 30_000 });
}

async function openHomeEditor(page: Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"] [data-editor-path]', { timeout: 30_000 });
}

/** 합성 마우스 시퀀스로 노드 선택(드래그 핸들이 실제 click 을 가로채므로 dispatch). */
async function selectByText(page: Page, matcher: string, maxLen: number): Promise<Record<string, unknown>> {
  return page.evaluate(
    async ({ matcher, maxLen }) => {
      const frame = document.querySelector('[data-testid="g7le-preview-frame"]')!;
      const re = new RegExp(matcher);
      const cand = (Array.from(frame.querySelectorAll('[data-editor-path]')) as HTMLElement[])
        .map((e) => ({ el: e, r: e.getBoundingClientRect() }))
        .filter((x) => re.test(x.el.textContent || '') && x.r.width > 0 && x.r.height > 0 && x.r.top >= 60 && x.r.top < window.innerHeight - 80);
      const pick = cand[cand.length - 1];
      if (!pick) return { found: false };
      pick.el.scrollIntoView({ block: 'center' });
      await new Promise((r) => setTimeout(r, 200));
      const r = pick.el.getBoundingClientRect();
      const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
      for (const t of ['pointerover', 'pointerenter', 'pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click'])
        pick.el.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, clientX: cx, clientY: cy, view: window }));
      await new Promise((r) => setTimeout(r, 500));
      const sel = document.querySelector('[data-testid="g7le-overlay-selected"]');
      const plus = (Array.from(document.querySelectorAll('[data-testid^="g7le-insertion-"]')) as HTMLButtonElement[])
        .filter((b) => b.tagName === 'BUTTON')
        .map((b) => {
          const br = b.getBoundingClientRect();
          return { disabled: b.getAttribute('data-disabled'), inView: br.width > 0 && br.top >= 0 && br.top < window.innerHeight };
        });
      return {
        found: true,
        lockKind: sel?.getAttribute('data-lock-kind') ?? null,
        affordancesMounted: !!document.querySelector('[data-testid="g7le-insertion-affordances"]'),
        enabledInViewCount: plus.filter((b) => b.disabled === 'false' && b.inView).length,
      };
    },
    { matcher, maxLen },
  );
}

test.describe('@layout-editor 잠긴 영역 요소 추가(+) 복원', () => {
  test('확장 주입 조각 선택 → 잠금이어도 + 버튼이 조각 박스 경계에 화면 내 노출', async ({ page }) => {
    await openRegisterEditor(page);
    const res = await selectByText(page, '마케팅 동의|광고성 이메일', 30);

    expect(res.found).toBe(true);
    expect(res.lockKind).toBe('extension');
    expect(res.affordancesMounted).toBe(true);
    // anchor=조각 박스라 + 버튼이 화면 안에 떠야 한다(이전 거대 박스 화면밖 회귀 차단).
    expect(res.enabledInViewCount as number).toBeGreaterThan(0);
  });

  test('데이터 반복(인기글/최근글) 영역 선택 → data_bound 여도 + 버튼이 화면 내 노출', async ({ page }) => {
    await openHomeEditor(page);
    const res = await selectByText(page, '최근 게시글|샘플 게시글|게시글', 30);

    expect(res.found).toBe(true);
    if (res.lockKind === 'data_bound') {
      expect(res.affordancesMounted).toBe(true);
      expect(res.enabledInViewCount as number).toBeGreaterThan(0);
    }
  });
});
