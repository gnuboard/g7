/**
 * 배송정책 목록 — 국가 칩·배송비 요약이 화면에 남아 있는지 (공개 이슈 #76).
 *
 * 목록에서 국가별 설정을 통째로 빼면서, 이 값을 실제로 그리던 배송정책 관리 목록이 함께 비어
 * 버렸다. 응답만 보는 테스트는 "뺐다" 를 확인할 뿐 "그 값을 화면이 쓰고 있었다" 는 사실을 잡지
 * 못한다 — 그래서 브라우저에서 열어 열 내용이 실제로 채워지는지 본다.
 *
 * 최종 계약은 "제거" 가 아니라 "컬럼 축소" 다. 기본 응답이 목록 표시용 국가별 설정을 그대로
 * 싣고, `with_country_settings=1` 은 전체 컬럼이 필요한 외부 호출자를 위한 하위호환 경로다.
 * 따라서 이 spec 은 두 가지를 함께 고정한다 — 화면이 opt-in 을 켜지 않는다는 것과, 그런데도
 * 국가 칩·배송비 요약이 채워진다는 것. 한쪽만 보면 어느 방향으로 되돌아가도 통과한다.
 *
 * @scenario surface=admin_shipping_policy_list
 * @effects shipping_policy_list_renders_country_summary
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const LIST_URL = '/admin/ecommerce/shipping-policies';
const LIST_API = '/api/modules/sirsoft-ecommerce/admin/shipping-policies';

/**
 * 이 spec 전용 배송정책을 국가 설정 2건과 함께 생성합니다.
 *
 * 시드에 국가 설정이 달린 정책이 없으면 검증이 skip 으로 빠져 아무것도 확인하지 못한다.
 * 사이트의 기존 정책을 건드리는 대신 전용 정책을 만들고 종료 시 삭제한다.
 *
 * @param page Playwright 페이지
 * @returns 생성된 배송정책 (실패 시 null)
 */
async function createFixturePolicy(page: any): Promise<any | null> {
  const suffix = `${Date.now()}`.slice(-9);

  const created = await page.evaluate(
    async ({ api, name }: { api: string; name: string }) => {
      const res = await fetch(api, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          name: { ko: name },
          is_active: true,
          is_default: false,
          sort_order: 0,
          country_settings: [
            { country_code: 'KR', shipping_method: 'parcel', charge_policy: 'fixed', base_fee: 3000, extra_fee_enabled: false, is_active: true },
            { country_code: 'US', shipping_method: 'parcel', charge_policy: 'fixed', base_fee: 25000, extra_fee_enabled: false, is_active: true },
          ],
        }),
      });

      return res.json();
    },
    { api: LIST_API, name: `E2E 국가요약 픽스처 ${suffix}` },
  );

  return created?.data ?? null;
}

test.describe('배송정책 목록 국가 요약 (#76)', () => {
  test('목록 요청이 무거운 opt-in 을 켜지 않고도 국가별 설정을 받아온다', async ({
    page,
    shippingPolicyToken,
  }) => {
    await authenticatePage(page, shippingPolicyToken);

    const requests: string[] = [];
    page.on('request', (request) => {
      if (request.url().includes(LIST_API)) {
        requests.push(request.url());
      }
    });

    await page.goto(LIST_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 목록 요청이 실제로 나갔는지부터 확정한다 — 요청이 0건이면 아래 부재 단언이
    // 아무것도 검증하지 못한 채 통과한다.
    await expect.poll(() => requests.length, { timeout: 20_000 }).toBeGreaterThan(0);

    // 화면은 opt-in 을 켜지 않는다. 켜면 쓰지도 않는 구간 설정·도서산간 설정·계산 API
    // 설정까지 정책당 국가 수만큼 되받아 프루닝 효과가 0 이 된다.
    expect(
      requests.filter((url) => /with_country_settings=(1|true)/.test(url)),
      '목록 화면은 with_country_settings 를 보내지 않는다 (기본 응답이 표시용 필드를 공급한다)',
    ).toHaveLength(0);

    // 그런데도 화면이 그리는 값은 응답에 들어 있어야 한다 — 이 둘이 함께 성립해야
    // "가볍게 줄였다" 가 "화면이 비었다" 로 뒤집히지 않는다.
    const body = await page.evaluate(async (api) => {
      const res = await fetch(`${api}?per_page=10`, {
        headers: {
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
          Accept: 'application/json',
        },
      });

      return res.json();
    }, LIST_API);

    const row = (body?.data?.data ?? [])[0];

    expect(row, '배송정책이 한 건 이상 있어야 한다').toBeTruthy();
    expect(row).toHaveProperty('country_settings');
    expect(row).toHaveProperty('fee_summary');
    expect(row).toHaveProperty('countries_display');
  });

  test('국가 칩과 배송비 요약 열이 비어 있지 않다', async ({ page, shippingPolicyToken }) => {
    await authenticatePage(page, shippingPolicyToken);
    await page.goto(LIST_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 국가 설정이 달린 정책을 직접 만든다 — 시드에 없으면 검증이 통째로 비어 버린다.
    const policy = await createFixturePolicy(page);
    test.skip(!policy?.id, '배송정책을 만들 수 없다 (배송방법 코드 미등록 등)');

    await page.reload();
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    try {

      // 존재를 먼저 확정한 뒤 내용을 단언한다 — 렌더 전에 통과하는 부재 단언을 피한다.
      const rows = page.locator('tbody tr, [role="row"]');
      await expect(rows.first()).toBeVisible({ timeout: 30_000 });

      // 화면과 같은 경로로 조회한다. opt-in 을 켜서 확인하면 정작 화면이 쓰는 기본 경로가
      // 비어 있어도 통과해 버린다 — 실제로 그렇게 비었던 것이 이 spec 이 생긴 이유다.
      const body = await page.evaluate(async (api) => {
        const res = await fetch(`${api}?per_page=10`, {
          headers: {
            Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
            Accept: 'application/json',
          },
        });

        return res.json();
      }, LIST_API);

      const policies = body?.data?.data ?? [];

      // 국가 설정이 달린 정책이 하나라도 있으면 요약 필드가 채워져야 한다.
      const withCountries = policies.filter(
        (policy: any) => (policy.country_settings ?? []).length > 0,
      );

      expect(withCountries.length, '국가 설정이 달린 배송정책이 있어야 한다').toBeGreaterThan(0);

      for (const policy of withCountries) {
        expect(policy.countries_display, '국가 표시 값이 있어야 한다').toBeTruthy();
        expect(policy.fee_summary, '배송비 요약이 있어야 한다').toBeTruthy();
      }

      // 화면에도 요약 문자열이 그려져야 한다 (열이 '-' 로만 남으면 실패).
      const summary = withCountries[0].fee_summary.split(' | ')[0];
      await expect(page.locator('tbody, [role="rowgroup"]')).toContainText(summary.slice(0, 12), {
        timeout: 15_000,
      });
    } finally {
      await page.evaluate(
        async ({ api, id }: { api: string; id: number }) => {
          await fetch(`${api}/${id}`, {
            method: 'DELETE',
            headers: {
              Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
              Accept: 'application/json',
            },
          });
        },
        { api: LIST_API, id: policy.id },
      );
    }
  });
});
