/**
 * 관리자 클레임 목록 본체 — 서브탭 전환·목록 컨텍스트 왕복·권한 게이팅 (#599 S8b)
 *
 * 필터가 만든 URL 로 목록이 실제로 다시 그려지는지까지가 이 spec 의 검증 대상이다.
 * 브라우저에서만 드러나는 축 셋을 본다:
 *
 *  ① 유형 서브탭 전환은 유형 종속 필터의 **리셋**이다. 병합하면 앞 유형의 `statuses[]` 가
 *     따라오고, 그 값은 새 유형의 어휘에 없어 목록 전체가 422 가 된다. 화면에는 오류가 아니라
 *     직전 목록이 그대로 남으므로 URL 과 응답을 함께 봐야 드러난다. 다만 서브탭 뱃지를 센
 *     유형 공통 필터(기간·검색·회원구분·금액·통화)는 이어 간다 — 뱃지가 "그 탭으로 가면 몇 건" 이다.
 *  ② 페이지 크기·페이지 이동은 같은 목록 클러스터 안이라 걸어 둔 필터를 **보존**한다.
 *     ①과 ②는 서로 반대 규약이고, 한쪽 규약을 다른 쪽에 적용하면 둘 다 조용히 깨진다.
 *  ③ 일괄 상태 변경 컨트롤은 전이 권한과 유형이 함께 정한다 — 취소 탭에는 아예 없고,
 *     읽기 권한만 가진 운영자에게는 잠긴다.
 *
 * 소유 시나리오: `admin-claim-list-body` (축 매핑은 각 테스트에 있다)
 * @axes claim_type=exchange|return|refund|cancel
 * @effects subtab_switch_resets_type_scoped_filters, subtab_switch_carries_count_filters, list_context_preserved_across_cluster_navigation, bulk_bar_absent_for_cancel
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const LIST_URL = '/admin/ecommerce/claims';

/**
 * 현재 URL 의 쿼리 파라미터를 돌려줍니다.
 *
 * @param url 페이지 URL
 * @returns 쿼리 파라미터
 */
function queryOf(url: string): URLSearchParams {
  return new URL(url).searchParams;
}

/**
 * 목록 화면이 그려질 때까지 기다립니다.
 *
 * `networkidle` 은 쓰지 않는다 — 관리자 SPA 는 실시간 알림 연결·주기 폴링이 붙어 무통신
 * 구간이 오지 않을 수 있다. 다음 단계에 필요한 구체 신호(서브탭)만 기다린다.
 *
 * @param page Playwright 페이지
 */
async function waitForList(page: import('@playwright/test').Page): Promise<void> {
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#claim_type_subtabs')).toBeVisible({ timeout: 30_000 });
}

test.describe('[case:components-form-select-options-nonnative] 관리자 클레임 목록 본체 (#599 S8b)', () => {
  // @scenario claim_type=exchange
  // @effects list_body_replaces_placeholder, subtabs_render_above_filter_card
  test('목록에 유형 서브탭 4종과 DataGrid 본체가 그려진다', async ({ page, claimsManageToken }) => {
    await authenticatePage(page, claimsManageToken);
    await page.goto(LIST_URL);
    await waitForList(page);

    const subtabs = page.locator('#claim_type_subtabs');
    for (const label of ['교환', '반품', '환불', '취소']) {
      await expect(subtabs.getByText(label, { exact: false }).first()).toBeVisible({ timeout: 30_000 });
    }

    // S8a 가 남긴 자리표시 노드는 본체로 대체됐다.
    await expect(page.locator('#list_body_placeholder')).toHaveCount(0);
    await expect(page.locator('#claim_datagrid')).toBeVisible({ timeout: 30_000 });
  });

  /**
   * 유형 전환은 병합 예외다 — 앞 유형의 상태 필터가 따라오면 목록 전체가 422 가 된다.
   */
  // @scenario claim_type=return
  // @effects subtab_switch_resets_type_scoped_filters, subtab_switch_carries_count_filters
  test('유형 서브탭 전환이 앞 유형의 상태 필터를 떨구고 검색어는 이어 간다', async ({ page, claimsManageToken }) => {
    await authenticatePage(page, claimsManageToken);
    await page.goto(`${LIST_URL}?type=exchange&statuses%5B%5D=redelivering&search_keyword=zzz`);
    await waitForList(page);

    // 교환에만 있는 상태로 들어왔다.
    expect(queryOf(page.url()).getAll('statuses[]')).toContain('redelivering');

    await page.locator('#claim_type_subtabs').getByText('반품', { exact: false }).first().click();
    await page.waitForURL(/type=return/, { timeout: 30_000 });

    const q = queryOf(page.url());
    expect(q.get('type')).toBe('return');
    // 교환 어휘가 따라오면 반품 목록이 422 가 된다.
    expect(q.getAll('statuses[]')).toHaveLength(0);
    // 검색어는 유형 공통 필터라 이어 간다 — 「반품 N」 뱃지가 이 검색어로 센 건수다.
    expect(q.get('search_keyword')).toBe('zzz');

    // 목록이 실제로 다시 그려졌다 — 자리표시가 아니라 본체다.
    await expect(page.locator('#claim_datagrid')).toBeVisible({ timeout: 30_000 });
  });

  /**
   * 페이지 크기 변경은 같은 목록을 다시 부르는 것이다 — ①과 정반대 규약이다.
   */
  // @scenario claim_type=exchange
  // @effects list_context_preserved_across_cluster_navigation
  test('페이지 크기를 바꿔도 걸어 둔 필터와 유형이 살아남는다', async ({ page, claimsManageToken }) => {
    await authenticatePage(page, claimsManageToken);
    await page.goto(`${LIST_URL}?type=return&search_field=claim_number&search_keyword=RT`);
    await waitForList(page);

    await page.locator('#d_002_per_page_select').first().click();
    await page.getByRole('option', { name: '50', exact: true }).first().click();
    await page.waitForURL(/per_page=50/, { timeout: 30_000 });

    const q = queryOf(page.url());
    expect(q.get('per_page')).toBe('50');
    expect(q.get('page')).toBe('1');
    // 클러스터 안 이동이므로 유형과 검색 조건은 그대로다.
    expect(q.get('type')).toBe('return');
    expect(q.get('search_keyword')).toBe('RT');
  });

  /**
   * 취소는 게이트의 `type` 어휘에 없다 — 바를 그리면 누를 수 있는 버튼이 언제나 422 다.
   */
  // @scenario claim_type=cancel
  // @effects bulk_bar_absent_for_cancel, selection_closed_for_cancel_subtab
  test('취소 서브탭에는 일괄 변경 바가 없다', async ({ page, claimsManageToken }) => {
    await authenticatePage(page, claimsManageToken);
    await page.goto(`${LIST_URL}?type=cancel`);
    await waitForList(page);

    await expect(page.locator('#claim_bulk_action_section')).toHaveCount(0);

    await page.goto(`${LIST_URL}?type=exchange`);
    await waitForList(page);
    await expect(page.locator('#claim_bulk_action_section')).toBeVisible({ timeout: 30_000 });
  });

  /**
   * 컨트롤이 열려 있는데 요청이 403 이 되면 운영자는 실패 이유를 알 수 없다.
   *
   * 이 단언에는 **시점**이 있다. 잠금 근거인 `claims.data.abilities.can_update_status` 는
   * 목록 응답이 도착해야 정해지고, 그전까지 `claims` 는 undefined 라 표현식이 그대로
   * "잠금" 으로 평가된다 — 응답을 기다리지 않고 단언하면 권한이 있는 운영자에게도 통과해
   * 게이트를 전혀 검증하지 못한다(실측 확인: abilities `can_update_status: true` 인데
   * 같은 순간 트리거 버튼은 disabled).
   */
  // @scenario claim_type=exchange
  // @effects bulk_controls_gated_by_update_status_ability
  test('전이 권한이 없으면 일괄 적용이 잠긴다', async ({ page, claimsReadToken }) => {
    await authenticatePage(page, claimsReadToken);

    const listResponse = page.waitForResponse(
      (r) => r.url().includes('/admin/claims?') && r.request().method() === 'GET',
      { timeout: 30_000 },
    );

    await page.goto(`${LIST_URL}?type=exchange`);
    await waitForList(page);

    // 잠금 근거가 실제로 "권한 없음" 인지 먼저 고정한다 — 로딩 중 잠금과 구분되지 않으면
    // 이 케이스는 무엇도 증명하지 못한다.
    const body = await (await listResponse).json();
    expect(body?.data?.abilities?.can_update_status).toBe(false);

    await expect(page.locator('#cb_005_bulk_apply_button')).toBeDisabled({ timeout: 30_000 });
    // 상태 셀렉트는 커스텀 드롭다운이라 레이아웃 id 는 래퍼 Div 에 붙고 disabled 는 그 안의
    // 트리거 버튼이 받는다 — 래퍼를 그대로 단언하면 언제나 enabled 로 읽힌다.
    await expect(page.locator('#cb_002_status_select_exchange button').first()).toBeDisabled({
      timeout: 30_000,
    });
  });

  /**
   * 완료 처리 모달(D26)의 초기 선택값은 설정이 정하는데, 설정 엔드포인트는
   * `settings.read` 게이트이고 이 화면의 라우트는 `claims.read` 다 — 기본 역할 `manager`
   * 는 클레임 권한만 갖는다. 모달이 설정 응답에서 읽으면 그 운영자에게만 403 으로 비어,
   * 상점이 재고 복구를 꺼 두어도 모달은 켜진 채 열리고 그대로 실행된다(오류·경고 없음).
   * 목록 응답이 그 값을 나르는지는 브라우저에서만 확인된다 — 화면이 실제로 어느 응답을
   * 읽는지가 이 축의 검증 대상이기 때문이다.
   */
  // @scenario claim_type=exchange
  // @effects completion_defaults_reach_claims_read_operator
  test('설정 권한 없이도 완료 기본값이 목록 응답으로 도달한다', async ({ page, claimsScopedManageToken }) => {
    await authenticatePage(page, claimsScopedManageToken);

    const listResponse = page.waitForResponse(
      (r) => r.url().includes('/admin/claims?') && r.request().method() === 'GET',
      { timeout: 30_000 },
    );
    // 코어에도 `/api/admin/settings` 가 있으므로 모듈 설정 엔드포인트만 정확히 잡는다 —
    // 넓게 잡으면 이 운영자가 읽을 수 있는 다른 설정 응답이 먼저 걸려 축이 뒤바뀐다.
    const settingsResponse = page.waitForResponse(
      (r) => new URL(r.url()).pathname === '/api/modules/sirsoft-ecommerce/admin/settings',
      { timeout: 30_000 },
    );

    await page.goto(`${LIST_URL}?type=exchange`);
    await waitForList(page);

    // 설정 엔드포인트는 이 운영자에게 닫혀 있다 — 그래서 그 응답을 기본값의 출처로 쓸 수 없다.
    expect((await settingsResponse).status()).toBe(403);

    const body = await (await listResponse).json();
    expect(body?.data?.complete_defaults).toEqual({
      create_refund_request: expect.any(Boolean),
      restore_stock: expect.any(Boolean),
    });
  });

  /**
   * 유형별 어휘가 섞이면 그 값을 고른 순간 요청 전체가 422 가 된다.
   */
  // @scenario claim_type=refund
  // @effects bulk_status_vocabulary_matches_request_gate
  test('일괄 상태 셀렉트가 유형별 어휘만 낸다', async ({ page, claimsManageToken }) => {
    await authenticatePage(page, claimsManageToken);

    // 커스텀 드롭다운은 열려야 선택지를 그리고, 그 패널은 body 로 포털된다 — 트리거의
    // 하위에서 찾으면 언제나 0건이다. 페이지 전역의 열린 listbox 를 본다.
    await page.goto(`${LIST_URL}?type=refund`);
    await waitForList(page);
    await expect(page.locator('#cb_002_status_select_exchange')).toHaveCount(0);
    await page.locator('#cb_004_status_select_refund button').first().click();
    // 환불의 관리자 지정 어휘는 승인·보류·반려 3종뿐이다 (+ placeholder).
    await expect(page.locator('[role="listbox"] [role="option"]')).toHaveCount(4, { timeout: 30_000 });
    await page.keyboard.press('Escape');

    await page.goto(`${LIST_URL}?type=exchange`);
    await waitForList(page);
    await expect(page.locator('#cb_004_status_select_refund')).toHaveCount(0);
    await page.locator('#cb_002_status_select_exchange button').first().click();
    await expect(page.locator('[role="listbox"] [role="option"]')).toHaveCount(12, { timeout: 30_000 });
  });
});
