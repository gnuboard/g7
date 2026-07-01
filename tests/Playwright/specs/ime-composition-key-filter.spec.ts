/**
 * IME(한글) 조합 중 Enter 글자누락/이중제출 방지 — 공개#54.
 *
 * @scenario ime-composition-key-filter
 * @effects search_input_korean_then_enter_no_char_loss,
 *          search_input_korean_then_enter_no_double_submit,
 *          ime_composing_enter_does_not_fire_key_filter_action,
 *          non_composing_enter_fires_key_filter_action_normally
 *
 * 대상: 관리자 회원 목록(/admin/users) 검색창(#search_input) — `key:"Enter"` → navigate 바인딩.
 *
 * 핵심 회귀 가드는 단위 테스트(ActionDispatcher.test.ts IME T1~T5 + isImeComposing 분기, 전부 green)가
 * key 필터/ESC 리스너 양 경로의 조합 가드를 구조적으로 잠근다. 본 spec 은 라이브 브라우저에서
 * 조합 중 keydown(isComposing=true)이 navigate 를 발화시키지 않고, 조합 종료 후 일반 Enter 만
 * 발화시키는지(이중 제출 0)를 검증한다.
 *
 * 활성화 전 사전 작업:
 *   1. 회원 목록 화면이 비어 있어도 검색창은 렌더되므로 별도 시드 불필요.
 *   2. core.users.read 권한 토큰 fixture (issueToken).
 *   3. test.describe.skip → test.describe (data-testid 추가 없이 #search_input id 로 접근 가능).
 *
 * 단위 테스트가 가드 로직을 잠그고 있어, 본 라이브 spec 은 PO 환경에서의 최종 확증용이다.
 */
import { test, expect, issueToken, authenticatePage } from '../fixtures/auth';

test.describe.skip('IME 조합 중 Enter 글자누락/이중제출 방지 (공개#54 — 라이브 확증)', () => {
  test('조합 중 Enter 는 검색 navigate 를 발화하지 않고, 조합 종료 후 Enter 만 발화한다', async ({ page }) => {
    const token = issueToken('core.users.read');
    await authenticatePage(page, token);

    await page.goto('/admin/users');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const search = page.locator('#search_input');
    await expect(search).toBeVisible({ timeout: 10_000 });
    await search.click();

    const urlBefore = page.url();

    // 1) 조합 중(isComposing=true) Enter keydown 발화 → navigate 미발생 (URL 불변)
    await search.evaluate((el: HTMLInputElement) => {
      el.value = '홍길';
      el.dispatchEvent(new Event('input', { bubbles: true }));
      const ev = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true });
      Object.defineProperty(ev, 'isComposing', { value: true });
      el.dispatchEvent(ev);
    });
    await page.waitForTimeout(300);
    expect(page.url(), '조합 중 Enter 가 검색을 발화시켰음(글자누락/조기 제출)').toBe(urlBefore);

    // 2) 조합 종료 후 일반 Enter → navigate 1회 발화 (search 쿼리 반영)
    await search.evaluate((el: HTMLInputElement) => {
      const ev = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true });
      Object.defineProperty(ev, 'isComposing', { value: false });
      el.dispatchEvent(ev);
    });
    await page.waitForTimeout(500);
    expect(page.url(), '조합 종료 후 Enter 가 검색을 발화시키지 못함').not.toBe(urlBefore);
  });
});
