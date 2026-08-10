/**
 * E2E: 목록 정렬 조작의 표시·요청·페이지 정합 (#492 브라우저 실측 회귀)
 *
 * 브라우저 실측에서 드러난 세 결함을 화면 레벨에서 잠근다. 단위 테스트는 컴포넌트 내부
 * 디스패치와 레이아웃 선언을 각각 잠그지만, "헤더에 표시된 정렬 기준" 과 "서버에 실제로
 * 나간 정렬 기준" 이 어긋나는 것은 둘을 한 화면에서 대조해야만 드러난다.
 *
 *   (1) 다른 컬럼 헤더를 누르면 그 컬럼 기준으로 정렬된다
 *       — 이전에는 화살표만 새 컬럼으로 옮겨가고 요청은 이전 컬럼이 나갔다
 *   (2) 정렬을 바꾸면 page 가 1 로 돌아간다
 *       — 이전에는 뒤쪽 페이지에 머물러 엉뚱한 구간이 보였다
 *   (3) 화면 정렬 셀렉트가 제공하는 모든 옵션이 오류 없이 동작한다
 *       — 알림 발송 이력의 "수신자명순"·"제목순" 이 422 로 실패했다
 *
 * @scenario case=list_sort_display_matches_request
 *
 * @effects sort_change_resets_page, header_sort_targets_clicked_column, screen_sort_options_all_succeed
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage, issueToken } from '../../fixtures/auth';

/**
 * 관리자 화면 진입 공통 대기.
 *
 * `networkidle` 은 폴링/실시간 연결이 있는 화면에서 idle 이 되지 않아 쓰지 않는다.
 *
 * @param page 대상 페이지
 * @param path 이동할 경로
 */
async function gotoAdmin(page: Page, path: string): Promise<void> {
  await page.goto(path);
  await page.waitForLoadState('domcontentloaded');
  await page.waitForSelector('table tbody tr, [data-testid="empty-state"]', { timeout: 20000 });
  await page.waitForTimeout(800);
}

/**
 * 정렬 화살표가 붙은 헤더의 **위치(0-based)** 를 찾습니다.
 *
 * 헤더 라벨은 실행 로케일에 따라 달라지므로(테스트 계정은 기본 로케일) 라벨 텍스트로
 * 판정하지 않는다. 화살표가 어느 컬럼에 붙었는지만 보면 표시 상태를 확정할 수 있다.
 *
 * @param page 대상 페이지
 * @returns 화살표가 붙은 헤더 인덱스 (없으면 -1)
 */
async function activeSortHeaderIndex(page: Page): Promise<number> {
  const headers = await page.locator('table thead th').allInnerTexts();

  return headers.findIndex((h) => h.includes('↑') || h.includes('↓'));
}

test.describe('목록 정렬 조작의 표시·요청·페이지 정합', () => {
  test('사용자 관리: 다른 컬럼 헤더를 누르면 그 컬럼 기준으로 정렬되고 첫 페이지로 돌아간다', async ({ page }) => {
    const token = await issueToken(['core.users.read']);
    await authenticatePage(page, token);

    // 컬럼 순서: [0] 체크박스 · [1] 이름 · [2] 역할 · [3] 이메일
    const NAME_COL = 1;
    const EMAIL_COL = 3;

    // 이름 내림차순 + 뒤쪽 페이지에서 시작
    await gotoAdmin(page, '/admin/users?sort_by=name&sort_order=desc&per_page=10&page=3');
    expect(await activeSortHeaderIndex(page), '이름 컬럼에 정렬 표시가 있어야 한다').toBe(NAME_COL);

    const requests: string[] = [];
    page.on('request', (r) => {
      if (r.url().includes('/api/admin/users?')) requests.push(decodeURIComponent(r.url()));
    });

    // 이메일 헤더 클릭
    await page.locator('table thead th').nth(EMAIL_COL).click();
    await page.waitForTimeout(2500);

    // (1) 표시된 정렬 기준이 클릭한 컬럼으로 옮겨간다
    expect(await activeSortHeaderIndex(page), '정렬 표시가 클릭한 컬럼으로 옮겨가야 한다').toBe(EMAIL_COL);

    // (1) 실제 요청도 같은 컬럼이다 — 표시만 바뀌고 요청은 이전 컬럼이던 결함 회귀 가드
    const lastRequest = requests[requests.length - 1] ?? '';
    expect(lastRequest, '정렬 요청이 클릭한 컬럼으로 나가야 한다').toContain('sort_by=email');
    expect(lastRequest).not.toContain('sort_by=name');

    // (2) 페이지가 1 로 리셋된다
    expect(page.url()).toContain('page=1');
    expect(lastRequest).toContain('page=1');

    // (3) 콘솔 오류 없음 — 목록이 실제로 렌더된다
    expect(await page.locator('table tbody tr').count()).toBeGreaterThan(0);
  });

  test('알림 발송 이력: 정렬 셀렉트가 제공하는 모든 옵션이 오류 없이 목록을 반환한다', async ({ page }) => {
    const token = await issueToken(['core.notification-logs.read']);
    await authenticatePage(page, token);

    // 화면 셀렉트가 노출하는 4개 옵션 — 이 중 2개가 422 로 실패했다
    const sortOptions = [
      { sortBy: 'sent_at', order: 'desc' },
      { sortBy: 'sent_at', order: 'asc' },
      { sortBy: 'recipient_name', order: 'asc' },
      { sortBy: 'subject', order: 'asc' },
    ] as const;

    for (const opt of sortOptions) {
      const failed: number[] = [];
      page.on('response', (res) => {
        if (res.url().includes('/api/admin/notification-logs?') && res.status() >= 400) {
          failed.push(res.status());
        }
      });

      await gotoAdmin(page, `/admin/notification-logs?sort_by=${opt.sortBy}&sort_order=${opt.order}`);

      expect(failed, `${opt.sortBy} ${opt.order} 정렬이 오류 응답을 냈다`).toEqual([]);

      const rows = await page.locator('table tbody tr').count();
      const emptyState = await page.locator('[data-testid="empty-state"]').count();
      expect(rows > 0 || emptyState > 0, `${opt.sortBy} 정렬에서 목록이 렌더되지 않았다`).toBe(true);

      page.removeAllListeners('response');
    }
  });

  test('쿠폰 목록: 서버 페이지네이션 화면의 헤더 클릭이 현재 페이지만 정렬하지 않는다 (#492 D-18)', async ({ page }) => {
    const token = await issueToken(['sirsoft-ecommerce.coupons.read']);
    await authenticatePage(page, token);

    await gotoAdmin(page, '/admin/ecommerce/promotion-coupons?per_page=10&page=1');

    const before = await page.locator('table tbody tr').allInnerTexts();
    expect(before.length, '판정에 쓸 행이 있어야 한다').toBeGreaterThan(1);

    const requests: string[] = [];
    page.on('request', (r) => {
      if (r.url().includes('/api/modules/sirsoft-ecommerce/admin/promotion-coupons?')) {
        requests.push(decodeURIComponent(r.url()));
      }
    });

    // 정렬 핸들러가 배선되지 않은 화면이므로 헤더는 정렬 어포던스를 갖지 않아야 한다.
    // (이전에는 클릭 시 요청 0건 + 현재 페이지 10행만 문자열 정렬 + 화살표 표시였다)
    const header = page.locator('table thead th').nth(3);
    expect(await header.evaluate((el) => getComputedStyle(el).cursor)).not.toBe('pointer');

    await header.click({ force: true });
    await page.waitForTimeout(1200);

    const after = await page.locator('table tbody tr').allInnerTexts();

    expect(after, '헤더 클릭이 현재 페이지 행 순서를 바꾸면 안 된다').toEqual(before);
    expect(await activeSortHeaderIndex(page), '반영할 수 없는 정렬 표시를 띄우면 안 된다').toBe(-1);
    expect(requests, '정렬 요청을 낼 수 없는 화면이므로 요청도 없어야 한다').toEqual([]);
  });

  test('쿠폰 목록: 정렬 셀렉트의 유효기간 옵션이 422 없이 목록을 반환한다 (#492 D-19)', async ({ page }) => {
    const token = await issueToken(['sirsoft-ecommerce.coupons.read']);
    await authenticatePage(page, token);

    for (const order of ['asc', 'desc'] as const) {
      const failed: number[] = [];
      page.on('response', (res) => {
        if (res.url().includes('/api/modules/sirsoft-ecommerce/admin/promotion-coupons?') && res.status() >= 400) {
          failed.push(res.status());
        }
      });

      await gotoAdmin(page, `/admin/ecommerce/promotion-coupons?sort_by=valid_to&sort_order=${order}`);

      expect(failed, `유효기간 ${order} 정렬이 오류 응답을 냈다`).toEqual([]);

      page.removeAllListeners('response');
    }
  });

  test('주문 목록: 발송일 정렬(관계 테이블 컬럼)이 422 없이 목록을 반환한다 (#492 D-19)', async ({ page }) => {
    const token = await issueToken(['sirsoft-ecommerce.orders.read']);
    await authenticatePage(page, token);

    for (const order of ['desc', 'asc'] as const) {
      const failed: number[] = [];
      page.on('response', (res) => {
        if (res.url().includes('/api/modules/sirsoft-ecommerce/admin/orders?') && res.status() >= 400) {
          failed.push(res.status());
        }
      });

      await gotoAdmin(page, `/admin/ecommerce/orders?sort_by=shipped_at&sort_order=${order}`);

      expect(failed, `발송일 ${order} 정렬이 오류 응답을 냈다`).toEqual([]);

      const rows = await page.locator('table tbody tr').count();
      const emptyState = await page.locator('[data-testid="empty-state"]').count();
      expect(rows > 0 || emptyState > 0, '발송일 정렬에서 목록이 렌더되지 않았다').toBe(true);

      page.removeAllListeners('response');
    }
  });

  test('메뉴 목록 API: 필터 없이 정렬만 줘도 순서가 반영된다 (#492 D-22)', async ({ page }) => {
    const token = await issueToken(['core.menus.read']);
    await authenticatePage(page, token);

    // 화면 판정이 아니라 엔드포인트 판정인 이유:
    // 관리자 메뉴 관리 화면은 드래그 정렬용 **계층 트리**(`hierarchical=true`)만 조회하고
    // 정렬 컨트롤을 노출하지 않는다(table 자체가 없다). D-22 는 "필터가 없으면 정렬 파라미터를
    // 통째로 버리던" 목록 엔드포인트의 결함이므로, 그 계약을 실제 브라우저 세션의 인증으로
    // 직접 대조한다. 화면에 없는 컨트롤을 조작한 척하는 판정보다 정확하다.
    const fetchNames = async (order: 'asc' | 'desc'): Promise<string[]> => {
      const res = await page.request.get(`/api/admin/menus?sort_by=name&sort_order=${order}`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      expect(res.status(), `${order} 정렬 요청이 실패했다`).toBe(200);
      const body = await res.json();
      const rows = body?.data?.data ?? body?.data ?? [];

      return (rows as Array<Record<string, unknown>>).map((r) =>
        typeof r.name === 'string' ? r.name : JSON.stringify(r.name)
      );
    };

    const asc = await fetchNames('asc');
    const desc = await fetchNames('desc');

    expect(asc.length, '판정에 쓸 행이 있어야 한다').toBeGreaterThan(1);
    expect(desc, '정렬 방향을 뒤집었는데 순서가 그대로면 정렬이 버려진 것이다').not.toEqual(asc);
    expect(desc.slice().reverse()).toEqual(asc);
  });

  test('쿠폰 목록: 상세 진입 후 뒤로가기로 복귀해도 필터 컨트롤이 URL 과 일치한다 (#492 D-20)', async ({ page }) => {
    const token = await issueToken(['sirsoft-ecommerce.coupons.read']);
    await authenticatePage(page, token);

    // 정렬·페이지를 함께 실어 라우트 진입 시드와 initLocal 동기화가 겹치는 조건을 만든다.
    await gotoAdmin(
      page,
      '/admin/ecommerce/promotion-coupons?issue_status=issuing&sort_by=name&sort_order=asc&page=3'
    );

    const seeded = await page.evaluate(
      () => (window as any).G7Core?.state?.getLocal?.()?.filter?.issueStatus
    );
    expect(seeded, '진입 시 URL query 가 필터 상태로 시드되어야 한다').toBe('issuing');

    const rowLinks = page.locator('table tbody tr td a');
    const linkCount = await rowLinks.count();
    test.skip(linkCount === 0, '판정에 쓸 목록 행이 없다');

    // 어떤 행을 눌렀는지에 따라 상세 로드 시간이 달라 경합 결과가 갈렸다 — 앞의 세 행을 모두 본다.
    for (let row = 0; row < Math.min(3, linkCount); row += 1) {
      if (row > 0) {
        await gotoAdmin(
          page,
          '/admin/ecommerce/promotion-coupons?issue_status=issuing&sort_by=name&sort_order=asc&page=3'
        );
      }

      await page.locator('table tbody tr td a').nth(row).click();
      await page.waitForLoadState('domcontentloaded');
      await page.waitForTimeout(1500);

      await page.goBack();
      await page.waitForSelector('table tbody tr, [data-testid="empty-state"]', { timeout: 20000 });
      await page.waitForTimeout(1200);

      const restored = await page.evaluate(
        () => (window as any).G7Core?.state?.getLocal?.()?.filter?.issueStatus
      );
      expect(
        restored,
        `행 ${row}: URL 은 필터가 걸린 상태인데 필터 컨트롤만 기본값으로 되돌아갔다`
      ).toBe('issuing');

      // 화면 표시도 함께 본다 — 상태만 맞고 컨트롤이 어긋나면 사용자에게는 여전히 결함이다.
      const checkedLabels = await page.evaluate(() =>
        [...document.querySelectorAll<HTMLInputElement>('input[type=radio]')]
          .filter((r) => r.checked)
          .map((r) => r.closest('label')?.textContent?.trim() ?? r.value)
      );
      expect(
        checkedLabels.some((l) => l && l !== '전체'),
        `행 ${row}: 선택된 필터 라디오가 모두 기본값이다`
      ).toBe(true);
    }
  });
});
