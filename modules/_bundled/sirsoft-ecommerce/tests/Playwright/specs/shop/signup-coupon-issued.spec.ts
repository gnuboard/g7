/**
 * 가입 쿠폰 — 스스로 가입한 회원의 쿠폰함에 가입 쿠폰이 들어온다 (#952 A·C)
 *
 * 관리자 API 로 발급중인 「가입」 자동발급 쿠폰을 만들고, 공개 가입 API 로 시험 회원을 가입시킨 뒤,
 * 그 회원으로 로그인해 마이페이지 쿠폰함 화면에 그 쿠폰 카드(발급 경로 「가입」)가 보이는지 잰다.
 * 사이트에 만든 것은 afterAll 이 지운다: 시험 쿠폰(이름 `i952-e2e-`)과 시험 회원(메일 `i952.e2e.`).
 * 가입이 닫혔거나 사이트가 운영자 필수 항목을 더 요구해 가입이 422 면 건너뛴다(그 계약은 member-fields 시험이 잰다).
 *
 * 소속 시나리오(마커 아님 — 평문): coupon-auto-issue
 * 효과 요약(마커 아님 — 평문): signup_coupon_issued
 */
import type { APIRequestContext } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import { issueToken } from '../../../../../../../tests/Playwright/fixtures/auth';
import { bearer, deleteE2eMember, findMemberByEmail, openApiContext } from '../../../../../../../tests/Playwright/fixtures/member-fields';

const COUPON_API = '/api/modules/sirsoft-ecommerce/admin/promotion-coupons';
const PASSWORD = 'Qz7!vLm#2pRt';
const tag = `${Date.now()}`;
const email = `i952.e2e.${tag}@example.test`;
const couponName = `i952-e2e-가입-${tag}`;

let api: APIRequestContext;
let adminToken = '';
let couponId: number | null = null;

test.beforeAll(async ({ playwright }, testInfo) => {
  api = await openApiContext(playwright, testInfo);
  adminToken = issueToken(
    'sirsoft-ecommerce.promotion-coupon.read',
    'sirsoft-ecommerce.promotion-coupon.create',
    'sirsoft-ecommerce.promotion-coupon.delete',
    'core.users.read',
    'core.users.delete',
  );
});

test.afterAll(async () => {
  const run = async (fn: () => Promise<unknown>) => {
    try {
      await fn();
    } catch {
      // 정리 실패는 다음 정리를 막지 않는다
    }
  };
  if (couponId !== null) {
    await run(() => api.delete(`${COUPON_API}/${couponId}`, { headers: bearer(adminToken) }));
  }
  await run(async () => {
    const member = await findMemberByEmail(api, adminToken, email);
    if (member?.uuid) await deleteE2eMember(api, adminToken, String(member.uuid));
  });
  await api.dispose();
});

test.describe('가입 쿠폰 (#952)', () => {
  // @scenario case=register_browser
  // @effects signup_coupon_issued
  test('스스로 가입하면 쿠폰함에 가입 쿠폰 카드가 보인다', async ({ page }) => {
    const created = await api.post(COUPON_API, {
      headers: { ...bearer(adminToken), 'Content-Type': 'application/json' },
      data: {
        name: { ko: couponName, en: couponName },
        target_type: 'order_amount',
        discount_type: 'fixed',
        discount_value: 1000,
        min_order_amount: 0,
        issue_method: 'auto',
        issue_condition: 'signup',
        issue_status: 'issuing',
        per_user_limit: 0,
        valid_type: 'days_from_issue',
        valid_days: 30,
        is_combinable: false,
        target_scope: 'all',
      },
    });
    expect(created.status(), (await created.text()).slice(0, 400)).toBe(201);
    couponId = Number((await created.json())?.data?.id);

    const registered = await api.post('/api/auth/register', {
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      data: { name: 'i952 시험 회원', nickname: `i952e2e${tag.slice(-6)}`, email, password: PASSWORD, password_confirmation: PASSWORD, agree_terms: true, agree_privacy: true },
    });
    const registeredText = await registered.text();
    test.skip(registered.status() === 403 || registered.status() === 422, `가입 불가(${registered.status()}) — ${registeredText.slice(0, 200)}`);
    expect(registered.status(), `가입 응답 본문: ${registeredText.slice(0, 300)}`).toBe(201);

    const login = await api.post('/api/auth/login', {
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      data: { email, password: PASSWORD },
    });
    const token = String((await login.json())?.data?.token ?? '');
    test.skip(token === '', '로그인 토큰 없음(가입 인증·2단계 인증 등 추가 단계가 켜진 사이트)');

    await authenticatePage(page, token);
    await page.goto('/mypage/coupons');
    const card = page.locator('#coupon_card_list').getByText(couponName);
    await expect(card).toBeVisible({ timeout: 30_000 });
  });
});
