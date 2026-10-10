/**
 * 배송정책 폼 클레임 확장 — 회수지 · 국가별 클레임 배송비 (#599 S10)
 *
 * 이 화면의 저장 경로는 둘이다. 국가별 클레임 배송비 3축은 본 폼 PUT 이 받고, 회수지는
 * 본 폼이 받지 않아 `claim-settings` 로 따로 나간다. 그래서 브라우저로만 드러나는 축이 둘:
 *
 *  ① **회수지를 입력하고 저장했는데 이어지는 요청이 안 나가면** 성공 토스트만 뜨고 값이
 *     사라진다 — 정책 저장은 200 이라 화면에는 실패가 나타나지 않는다.
 *  ② **산정 방식이 '원 정책 재계산' 인데 고정액 입력이 남아 있으면** 운영자는 그 값이
 *     쓰인다고 읽는다. 실제로는 정책 규칙이 다시 계산하므로 표시와 동작이 갈린다.
 *
 * @scenario claim_scope=pickup_address
 * @effects shipping_policy_claim_address_persists, shipping_policy_claim_fee_mode_toggles_fixed_inputs
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const POLICY_API = '/api/modules/sirsoft-ecommerce/admin/shipping-policies';

/**
 * 수정 가능한 배송정책 한 건을 찾습니다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 인증 토큰
 * @returns 정책 id (없으면 null)
 */
async function discoverPolicyId(
  request: import('@playwright/test').APIRequestContext,
  token: string,
): Promise<number | null> {
  const res = await request.get(`${POLICY_API}?per_page=20`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });

  // 목록 API 자체가 실패했으면 그것은 "정책이 없다" 가 아니다. null 로 뭉뚱그리면 토큰 권한
  // 소실·401·500·라우트 삭제가 전부 "표본 없음" skip 으로 흡수돼, 이 파일이 유일하게 실측하는
  // 두 축이 아무것도 단언하지 않고 초록으로 끝난다.
  expect(res.status(), '배송정책 목록 API 가 실패했다 — 표본 부족이 아니라 장애다').toBe(200);

  const rows = (await res.json())?.data?.data ?? [];

  return rows.length > 0 ? Number(rows[0].id) : null;
}

// 두 테스트가 같은 정책(목록 첫 행)의 회수지·본문을 실제로 바꾼다 — 병렬이면 한쪽의 원복이 다른 쪽
// 측정값을 지운다. 로컬은 멀티워커라 순차를 명시한다.
test.describe.configure({ mode: 'serial' });

test.describe('배송정책 클레임 확장', () => {
  test('회수지를 입력하고 저장하면 재진입에서 그대로 남는다', async ({ page, request, shippingPolicyToken }) => {
    // 이 케이스 하나가 API 4왕복(회수지 GET · 정책 GET · 원복 PUT · 재조회) + 페이지 로드 +
    // 저장 + 20초 poll 을 한다. 기본 30초 타임아웃과 실측 소요(단독 19s · 다른 spec 과 같은
    // 배치면 33s)가 겹쳐, 배치 실행에서만 비결정적으로 timeout red 가 났다 — 리포트는 그때마다
    // "9/9" 와 "8/9" 사이를 오갔고 어느 쪽도 화면 결함이 아니었다. 형제 spec
    // (admin-order-list-claim-filter)과 같은 상한을 명시해 그 흔들림을 없앤다.
    test.setTimeout(120_000);

    const policyId = await discoverPolicyId(request, shippingPolicyToken);

    if (policyId === null) {
      // `test.skip` 은 리포트에서 "돌았다" 와 "건너뛰었다" 를 가르지 못한다 — 이 spec 이
      // 유일하게 실측하는 회수지 왕복이 표본 부재로 사라져도 9/9 로 보고된다. 형제 spec
      // (admin-order-list-claim-filter · admin-settings-claim-tab)처럼 사실을 남기고 실패로 끝낸다.
      test.info().annotations.push({
        type: 'sample-missing',
        description: '배송정책이 한 건도 없어 회수지 왕복을 확인할 수 없다',
      });

      expect(policyId, '배송정책 표본이 없어 이 spec 이 아무것도 실측하지 못했다').not.toBeNull();

      return;
    }

    const headers = { Authorization: `Bearer ${shippingPolicyToken}`, Accept: 'application/json' };
    const before = await request.get(`${POLICY_API}/${policyId}/claim-settings`, { headers });
    expect(before.ok()).toBeTruthy();
    // 원복 스냅샷은 **저장이 쓰는 범위 전체**를 담아야 한다. [저장] 버튼의 claim-settings PUT 은
    // `addresses.exchange` 와 `addresses.return` 을 **둘 다** 싣는다(폼이 두 블록을 함께 직렬화한다).
    // return 하나만 되돌리면, 교환 회수지가 등록된 상점에서 이 측정을 돌렸을 때 교환 회수지가
    // 폼이 직렬화한 형태로 덮이거나 지워진 채 영구히 남는다 — 되돌리지 않은 축은 어떤 단언도
    // 관측하지 않으므로 리포트는 초록이다.
    const originalAddresses = (await before.json())?.data?.addresses ?? {};
    const originalReturn = originalAddresses?.return ?? null;
    const originalExchange = originalAddresses?.exchange ?? null;

    // [저장] 은 회수지 PUT 하나가 아니라 **정책 본문 PUT 도 함께** 낸다. 회수지만 되돌리면
    // 라이브 정책의 국가별 설정·요율이 폼이 직렬화한 형태로 덮인 채 남는다 — 폼이 한 필드라도
    // 정규화하면(빈 문자열 → 0, 배열 순서, 신규 국가 기본값) 그 변형이 영구화된다.
    const policyBefore = await request.get(`${POLICY_API}/${policyId}`, { headers });
    expect(policyBefore.ok()).toBeTruthy();
    const originalPolicy = (await policyBefore.json())?.data ?? null;

    // 원복은 finally 에 둔다 — 어서션이 실패하면 그 자리에서 끝나므로, 본문 끝에 두면
    // 실패 경로에서만 운영자 회수지가 측정값인 채 남는다.
    try {
      await authenticatePage(page, shippingPolicyToken);
      await page.goto(`/admin/ecommerce/shipping-policies/${policyId}/edit`);
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
      await expect(page.locator('#claim_addresses_section')).toBeVisible({ timeout: 30_000 });

      const marker = 'E2E 반품 담당자';
      await page.locator('#claim_address_return_recipient_name').fill(marker);
      await page.locator('#claim_address_return_address1').fill('서울시 강남구 테헤란로 1');
      await page.locator('#claim_address_return_address1').blur();

      await page.locator('#footer_save_button').click();

      await expect
        .poll(async () => {
          const res = await request.get(`${POLICY_API}/${policyId}/claim-settings`, { headers });

          return res.ok() ? (await res.json())?.data?.addresses?.return?.recipient_name : null;
        }, { timeout: 20_000 })
        .toBe(marker);
    } finally {
      // 이 세션이 라이브에 쓴 것은 둘이다 — 회수지(claim-settings PUT)와 정책 본문(policy PUT).
      // **원복 요청을 먼저 전부 보내고 검증은 그 뒤에 모아서** 한다. 종전엔 회수지 원복과
      // 그 검증 5건이 정책 본문 원복보다 앞에 있어, 그 검증 중 하나만 실패해도 예외가 던져져
      // 정책 본문 원복이 실행되지 않았다 — 실패 경로(이 finally 가 존재하는 유일한 이유)에서
      // 라이브 배송정책이 폼 직렬화 형태로 남는 형태다. 원복 사이에 단언을 끼우지 않는다.
      const restored = await request.put(`${POLICY_API}/${policyId}/claim-settings`, {
        headers,
        data: { addresses: { exchange: originalExchange, return: originalReturn } },
      });

      const policyRestored = originalPolicy
        ? await request.put(`${POLICY_API}/${policyId}`, { headers, data: originalPolicy })
        : null;

      expect(restored.ok(), '측정값 원복 실패 — 회수지가 변경된 채 남았다').toBeTruthy();

      // 응답 OK 만으로는 "보낸 것" 밖에 검사하지 못한다. 두 축이 실제로 측정 전 값으로
      // 돌아왔는지 다시 읽어 확인한다 — 이 단언이 없으면 원복 payload 가 한 축을 빠뜨려도
      // 초록으로 끝난다(이 파일이 방금까지 그랬다).
      const after = await request.get(`${POLICY_API}/${policyId}/claim-settings`, { headers });
      expect(after.ok(), '원복 확인 조회가 실패했다').toBeTruthy();

      const afterAddresses = (await after.json())?.data?.addresses ?? {};

      // 재조회 범위는 **쓰기 범위**와 같아야 한다. [저장]의 claim-settings PUT 은 회수지
      // 5필드를 양측으로 싣는데(구조 계약 `adminShippingPolicyClaim.test.ts` 가 그 목록을
      // 고정한다) 두 칸만 견주면 나머지 세 칸이 폼 직렬화 형태로 남아도 초록이다.
      // 형제 spec(`admin-settings-claim-tab`)은 이미 측정 전 값 전건을 루프로 돈다.
      const WRITTEN_FIELDS = ['recipient_name', 'address1', 'address2', 'zipcode', 'phone'] as const;

      for (const [side, before] of [
        ['exchange', originalExchange],
        ['return', originalReturn],
      ] as const) {
        for (const field of WRITTEN_FIELDS) {
          expect(
            afterAddresses?.[side]?.[field] ?? null,
            `${side} 회수지 ${field} 가 측정 전 값으로 돌아오지 않았다`,
          ).toBe(before?.[field] ?? null);
        }
      }

      if (originalPolicy) {
        expect(policyRestored?.ok(), '측정값 원복 실패 — 배송정책 본문이 변경된 채 남았다').toBeTruthy();

        // 회수지와 같은 이유로 정책 본문도 재조회한다. 스냅샷은 GET 리소스 형태라 PUT 요청
        // 형태와 같다는 보장이 없어, 200 을 받고도 라이브 정책이 다르게 정규화된 채 남을 수 있다.
        const policyAfter = await request.get(`${POLICY_API}/${policyId}`, { headers });
        expect(policyAfter.ok(), '정책 본문 원복 확인 조회가 실패했다').toBeTruthy();

        const restoredPolicy = (await policyAfter.json())?.data ?? {};
        expect(restoredPolicy?.name ?? null, '정책 이름이 측정 전 값으로 돌아오지 않았다').toEqual(originalPolicy?.name ?? null);
        expect(
          restoredPolicy?.is_active ?? null,
          '정책 사용 여부가 측정 전 값으로 돌아오지 않았다',
        ).toBe(originalPolicy?.is_active ?? null);

        // 위 주석이 걱정하는 정규화(빈 문자열→0, 배열 순서, 신규 국가 기본값)는 국가별 설정 축에서
        // 일어난다 — 그 축을 견주지 않으면 이름·사용 여부만 같아도 초록이다. 정책 PUT 은 국가별
        // 행을 지우고 다시 만들어 행 `id` 가 바뀌므로(실측 136→138) `id` 는 비교에서 뺀다.
        const stripIds = (rows: unknown): unknown =>
          Array.isArray(rows) ? rows.map(({ id: _id, ...rest }: any) => rest) : rows;
        expect(
          stripIds(restoredPolicy?.country_settings ?? null),
          '국가별 설정이 측정 전 값으로 돌아오지 않았다',
        ).toEqual(stripIds(originalPolicy?.country_settings ?? null));
      }
    }
  });

  test('산정 방식이 원 정책 재계산이면 고정액 입력이 사라지고 안내가 대신 선다', async ({ page, request, shippingPolicyToken }) => {
    // 형제 케이스와 같은 상한. 기본 30초는 API 왕복 + 페이지 로드 + 30초 가시성 대기를
    // 담기에 좁아, 배치 실행에서 비결정적 timeout 이 난다(13차 G1 과 같은 결).
    test.setTimeout(120_000);

    const policyId = await discoverPolicyId(request, shippingPolicyToken);

    if (policyId === null) {
      test.info().annotations.push({
        type: 'sample-missing',
        description: '배송정책이 한 건도 없어 산정 방식 분기를 확인할 수 없다',
      });

      expect(policyId, '배송정책 표본이 없어 이 spec 이 아무것도 실측하지 못했다').not.toBeNull();

      return;
    }

    await authenticatePage(page, shippingPolicyToken);
    await page.goto(`/admin/ecommerce/shipping-policies/${policyId}/edit`);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await expect(page.locator('#claim_country_fee_section')).toBeVisible({ timeout: 30_000 });

    await page.locator('#claim_fee_mode_original_policy input[type="radio"]').click();
    await expect(page.locator('#claim_fixed_fee_fields')).toHaveCount(0);
    await expect(page.locator('#claim_fee_original_policy_notice')).toBeVisible();

    await page.locator('#claim_fee_mode_fixed input[type="radio"]').click();
    await expect(page.locator('#claim_fixed_fee_fields')).toBeVisible();
    await expect(page.locator('#claim_fee_original_policy_notice')).toHaveCount(0);

    // 저장하지 않고 벗어난다 — 이 spec 은 표시 분기만 본다
  });
});
