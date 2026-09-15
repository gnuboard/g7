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
 * 효과 요약(마커 아님 — 평문): locked_node_shows_insertion_plus_via_anchor, insertion_inserts_outside_locked_group_as_sibling.
 */
import type { Page } from '@playwright/test';
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

async function openRegisterEditor(page: Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2Fregister');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  // state 기본값은 'visible' 인데 프레임 하위 첫 노드는 빈 앵커(header_currency_inject_anchor)나
  // `hidden` 클래스 요소일 수 있어 끝내 visible 이 되지 않는다 — 렌더 완료 판정은 존재로 한다.
  await page.waitForSelector('[data-testid="g7le-preview-frame"] [data-editor-path]', {
    state: 'attached',
    timeout: 30_000,
  });
}

async function openHomeEditor(page: Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  // state 기본값은 'visible' 인데 프레임 하위 첫 노드는 빈 앵커(header_currency_inject_anchor)나
  // `hidden` 클래스 요소일 수 있어 끝내 visible 이 되지 않는다 — 렌더 완료 판정은 존재로 한다.
  await page.waitForSelector('[data-testid="g7le-preview-frame"] [data-editor-path]', {
    state: 'attached',
    timeout: 30_000,
  });
}

/** 합성 마우스 시퀀스로 노드 선택(드래그 핸들이 실제 click 을 가로채므로 dispatch). */
async function selectByText(page: Page, matcher: string, maxLen: number): Promise<Record<string, unknown>> {
  return page.evaluate(
    async ({ matcher, maxLen }) => {
      const frame = document.querySelector('[data-testid="g7le-preview-frame"]')!;
      const re = new RegExp(matcher);
      // maxLen 제약을 반드시 함께 적용한다. textContent 는 자손 텍스트를 모두 포함하므로
      // 길이 제한 없이는 화면 전체를 감싼 컨테이너가 먼저 걸려(진입점이 아니라 폼 전체) 선택
      // 결과가 엉뚱해진다 — 실측에서 회원가입 폼 컨테이너가 잡혔다.
      const cand = (Array.from(frame.querySelectorAll('[data-editor-path]')) as HTMLElement[])
        .map((e) => ({ el: e, r: e.getBoundingClientRect() }))
        .filter(
          (x) =>
            re.test(x.el.textContent || '') &&
            (x.el.textContent || '').trim().length <= maxLen &&
            x.r.width > 0 &&
            x.r.height > 0,
        );
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
        path: pick.el.getAttribute('data-editor-path'),
        lockKind: sel?.getAttribute('data-lock-kind') ?? null,
        affordancesMounted: !!document.querySelector('[data-testid="g7le-insertion-affordances"]'),
        enabledInViewCount: plus.filter((b) => b.disabled === 'false' && b.inView).length,
      };
    },
    { matcher, maxLen },
  );
}

/** 지정 경로의 노드를 합성 마우스 시퀀스로 선택하고 잠금 종류를 돌려준다. */
async function selectByPathAndLockKind(page: Page, path: string): Promise<string | null> {
  return page.evaluate(async (p: string) => {
    const el = document
      .querySelector('[data-testid="g7le-preview-frame"]')!
      .querySelector(`[data-editor-path="${CSS.escape(p)}"]`) as HTMLElement | null;
    if (!el) return null;
    const r = el.getBoundingClientRect();
    const cx = r.left + r.width / 2;
    const cy = r.top + r.height / 2;
    for (const t of ['pointerover', 'pointerenter', 'pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click'])
      el.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, clientX: cx, clientY: cy, view: window }));
    await new Promise((res) => setTimeout(res, 350));
    return document.querySelector('[data-testid="g7le-overlay-selected"]')?.getAttribute('data-lock-kind') ?? null;
  }, path);
}

test.describe('@layout-editor 잠긴 영역 요소 추가(+) 복원', () => {
  /** @effects locked_node_shows_insertion_plus_via_anchor */
  test('확장 주입 조각 선택 → 잠금이어도 + 버튼이 조각 박스 경계에 화면 내 노출', async ({ page }) => {
    await openRegisterEditor(page);
    const res = await selectByText(page, '마케팅 동의|광고성 이메일', 30);

    expect(res.found).toBe(true);
    expect(res.lockKind).toBe('extension');
    expect(res.affordancesMounted).toBe(true);
    // anchor=조각 박스라 + 버튼이 화면 안에 떠야 한다(이전 거대 박스 화면밖 회귀 차단).
    expect(res.enabledInViewCount as number).toBeGreaterThan(0);
  });

  /** @effects locked_node_shows_insertion_plus_via_anchor */
  test('데이터 반복(인기글/최근글) 영역 선택 → data_bound 여도 + 버튼이 화면 내 노출', async ({ page }) => {
    await openHomeEditor(page);
    const res = await selectByText(page, '최근 게시글|샘플 게시글|게시글', 30);

    expect(res.found).toBe(true);
    if (res.lockKind === 'data_bound') {
      expect(res.affordancesMounted).toBe(true);
      expect(res.enabledInViewCount as number).toBeGreaterThan(0);
    }
  });

  /**
   * + 버튼이 **보이는가** 와 눌렀을 때 **어디에 들어가는가** 는 다른 명제다. 앞의 두 테스트는
   * 노출만 본다 — 잠긴 묶음 *안쪽* 에 꽂혀도 통과한다. 이 테스트가 삽입 위치를 잠근다.
   *
   * 저장하지 않는다 — 편집기 문서에만 반영되므로 사이트 레이아웃은 그대로 남는다.
   */
  /** @effects insertion_inserts_outside_locked_group_as_sibling */
  test('잠긴 조각의 + 로 요소를 추가하면 묶음 안이 아니라 형제로 들어간다', async ({ page }) => {
    await openRegisterEditor(page);
    const res = await selectByText(page, '마케팅 동의|광고성 이메일', 30);

    expect(res.found).toBe(true);
    expect(res.lockKind).toBe('extension');
    const anchor = res.path as string;
    expect(anchor).toBeTruthy();

    // 선택된 잎만 보면 "묶음 안" 을 못 잰다(자손 0이라 무엇이 들어와도 0 그대로일 수 있다).
    // 잎에서 위로 올라가며 잠금이 유지되는 **가장 바깥 확장 잠금 조상** = 묶음 박스를 찾는다.
    const chain = await page.evaluate((leaf: string) => {
      const frame = document.querySelector('[data-testid="g7le-preview-frame"]')!;
      const el = frame.querySelector(`[data-editor-path="${CSS.escape(leaf)}"]`);
      const out: string[] = [];
      let cur: Element | null = el;
      while (cur && cur !== frame) {
        const p = cur.getAttribute?.('data-editor-path');
        if (p) out.push(p);
        cur = cur.parentElement;
      }
      return out.reverse(); // 얕은 → 깊은
    }, anchor);

    let groupPath = anchor;
    for (const p of chain) {
      if ((await selectByPathAndLockKind(page, p)) === 'extension') {
        groupPath = p; // 가장 얕은 확장 잠금 노드 = 묶음 박스
        break;
      }
    }
    // 묶음 박스를 다시 선택해 둔다(+ 어포던스가 그 박스 기준으로 뜨도록).
    expect(await selectByPathAndLockKind(page, groupPath)).toBe('extension');

    const groupHandle = await page.evaluateHandle(
      (sel: string) =>
        document
          .querySelector('[data-testid="g7le-preview-frame"]')!
          .querySelector(`[data-editor-path="${CSS.escape(sel)}"]`) as HTMLElement | null,
      groupPath,
    );
    const totalBefore = await page.evaluate(
      () =>
        document.querySelector('[data-testid="g7le-preview-frame"]')!.querySelectorAll('[data-editor-path]')
          .length,
    );
    const innerBefore = await groupHandle.evaluate((el) => (el ? el.querySelectorAll('[data-editor-path]').length : -1));
    expect(innerBefore).toBeGreaterThanOrEqual(0); // 잠긴 요소를 실제로 붙잡았다

    // 화면 안에 떠 있는 활성 + 버튼을 누른다 → 팔레트 모달
    const plus = page.locator('[data-testid^="g7le-insertion-"][data-disabled="false"]').first();
    await expect(plus).toBeVisible({ timeout: 10_000 });
    await plus.click();

    await expect(page.getByTestId('g7le-palette-item-Div')).toBeVisible({ timeout: 10_000 });
    await page.getByTestId('g7le-palette-item-Div').click();

    // 삽입이 실제로 일어났는지 먼저 확정한다(안 일어났는데 "안 늘었다"로 통과하는 공허 방지).
    await expect
      .poll(
        async () =>
          page.evaluate(
            () =>
              document
                .querySelector('[data-testid="g7le-preview-frame"]')!
                .querySelectorAll('[data-editor-path]').length,
          ),
        { timeout: 10_000 },
      )
      .toBeGreaterThan(totalBefore);

    const after = await groupHandle.evaluate((el) => ({
      connected: !!el && el.isConnected,
      inner: el ? el.querySelectorAll('[data-editor-path]').length : -1,
    }));

    // 잠긴 요소가 재렌더로 교체됐다면 이 측정은 근거가 없다 — 공허 통과를 막는다.
    expect(after.connected).toBe(true);
    expect(after.inner).toBe(innerBefore);
  });
});
