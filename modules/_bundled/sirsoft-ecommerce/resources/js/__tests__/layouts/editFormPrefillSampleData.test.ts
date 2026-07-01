/**
 * 관리자 수정(edit) 폼 prefill 샘플 계약 테스트
 *
 * 배경: stub 스캔("샘플")은 통과했으나 thin(거의 빈) 샘플이라 편집기 수정 화면이
 * 빈 폼으로 렌더된 결함. 편집기 미리보기는 runtime
 * API→form 흐름을 실행하지 않으므로, 수정 폼이 채워지려면:
 *   (1) 데이터소스 .data 가 실제 Resource shape 로 충실해야 하고(읽기전용 표시/abilities),
 *   (2) edit_mode 페이지 상태가 initialState.local.form 으로 입력칸을 직접 시드해야 한다.
 *
 * 실제 Resource shape 대조: ShippingPolicyResource(+CountrySetting) / CouponResource /
 * CouponIssueResource.
 *
 * 바인딩 SSoT: admin_ecommerce_shipping_policy_form.json (Form dataKey=form: name/is_active/
 * is_default + country_settings[]), admin_ecommerce_promotion_coupon_form.json (Form dataKey=form),
 * admin_ecommerce_promotion_coupon_list.json (couponIssues 발급내역 모달).
 */
import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';

function findProjectRoot(startDir: string): string {
  let dir = startDir;
  while (dir !== path.dirname(dir)) {
    if (fs.existsSync(path.join(dir, 'artisan'))) return dir;
    dir = path.dirname(dir);
  }
  return path.resolve(startDir, '../../../../../../..');
}

const REPO_ROOT = findProjectRoot(__dirname);
const spec = JSON.parse(
  fs.readFileSync(path.join(REPO_ROOT, 'modules/_bundled/sirsoft-ecommerce/editor-spec.json'), 'utf-8'),
);
const byId = spec.sampleData.byDataSourceId as Record<string, any>;

function hasStub(node: unknown): boolean {
  if (node === '샘플') return true;
  if (Array.isArray(node)) {
    if (node.length === 1 && node[0] === '샘플') return true;
    return node.some(hasStub);
  }
  if (node && typeof node === 'object') return Object.values(node).some(hasStub);
  return false;
}

/** thin 판정 — leaf<6 & 최대배열<2 면 거의 빈 샘플 (stub 스캔의 사각) */
function isThin(node: any): boolean {
  let leaves = 0,
    maxArr = 0;
  function walk(n: any) {
    if (Array.isArray(n)) {
      maxArr = Math.max(maxArr, n.length);
      n.forEach(walk);
    } else if (n && typeof n === 'object') Object.values(n).forEach(walk);
    else leaves++;
  }
  walk(node);
  return leaves < 6 && maxArr < 2;
}

function findGroup(re: RegExp) {
  return spec.states.groups.find((g: any) => re.test(g.scope?.match || ''));
}

describe('관리자 수정 폼 데이터소스 충실도', () => {
  describe('thin 가드 — 수정 화면 데이터소스가 빈약하지 않다', () => {
    for (const id of ['policy', 'coupon', 'couponIssues']) {
      it(`${id} 는 stub 0 + thin 아님`, () => {
        expect(byId[id]).toBeTruthy();
        expect(hasStub(byId[id])).toBe(false);
        expect(isThin(byId[id]), `${id} 가 여전히 thin`).toBe(false);
      });
    }
  });

  describe('policy — 배송정책 (ShippingPolicyResource)', () => {
    const d = byId.policy.data;
    it('기본 정보 채움', () => {
      expect(d.name.ko).toBeTruthy();
      expect(d.is_default).toBe(true);
      expect(d.fee_summary).toBeTruthy();
    });
    it('country_settings 복수 + 부과정책 분기 (conditional_free + range)', () => {
      expect(d.country_settings.length).toBeGreaterThanOrEqual(2);
      const policies = d.country_settings.map((c: any) => c.charge_policy);
      expect(policies).toContain('conditional_free');
      const kr = d.country_settings.find((c: any) => c.country_code === 'KR');
      expect(kr.base_fee).toBeGreaterThan(0);
      expect(kr.free_threshold).toBeGreaterThan(0);
      expect(kr.extra_fee_enabled).toBe(true);
      expect(kr.extra_fee_settings.length).toBeGreaterThanOrEqual(1);
    });
  });

  describe('coupon — 쿠폰 (CouponResource)', () => {
    const d = byId.coupon.data;
    it('수정 폼 표시 필드 채움', () => {
      expect(d.name.ko).toBeTruthy();
      expect(d.target_type).toBeTruthy();
      expect(d.discount_type).toBeTruthy();
      expect(d.discount_value).toBeGreaterThan(0);
      expect(d.issue_method).toBeTruthy();
      expect(d.issue_count_formatted).toBeTruthy();
    });
  });

  describe('couponIssues — 발급내역 (CouponIssueResource)', () => {
    const d = byId.couponIssues.data;
    it('발급 행 ≥3 + 상태 분기 (사용/발급/만료)', () => {
      expect(d.data.length).toBeGreaterThanOrEqual(3);
      const statuses = new Set(d.data.map((r: any) => r.status));
      expect(statuses.size).toBeGreaterThanOrEqual(3);
      for (const r of d.data) {
        expect(r.coupon_code).toBeTruthy();
        expect(r.status_label).toBeTruthy();
        expect(r.issued_at).toBeTruthy();
      }
    });
  });

  describe('edit_mode 폼 시드 — initialState.local.form (편집기 미리보기 prefill)', () => {
    it('배송정책 edit_mode 가 form 을 시드한다 (name + country_settings)', () => {
      const g = findGroup(/shipping-policies\/:id\/edit/);
      const em = g.items.find((s: any) => s.id === 'edit_mode');
      const form = em.initialState?.local?.form;
      expect(form, 'edit_mode.initialState.local.form 미시드').toBeTruthy();
      expect(form.name.ko).toBeTruthy();
      expect(form.country_settings.length).toBeGreaterThanOrEqual(2);
      // 가시성 플래그 동반 (conditional_free → base_fee + free_threshold 표시)
      expect(em.initialState.local.showBaseFee).toBe(true);
      expect(em.initialState.local.showFreeThreshold).toBe(true);
    });
    it('쿠폰 edit_mode 가 form 을 시드한다 (name + target_type + discount)', () => {
      const g = findGroup(/promotion-coupons\/:id\/edit/);
      const em = g.items.find((s: any) => s.id === 'edit_mode');
      const form = em.initialState?.local?.form;
      expect(form, 'edit_mode.initialState.local.form 미시드').toBeTruthy();
      expect(form.name.ko).toBeTruthy();
      expect(form.target_type).toBeTruthy();
      expect(form.discount_value).toBeGreaterThan(0);
    });
  });

  describe('배송정책 부과정책 유형별 페이지 상태', () => {
    const g = findGroup(/shipping-policies\/:id\/edit/);
    // 부과정책별 상태 + 가시성 플래그 매핑 (handler REQUIRES_* 와 일치)
    const expected: Record<string, { cp: string; flags: Partial<Record<string, boolean>> }> = {
      charge_fixed: { cp: 'fixed', flags: { showBaseFee: true, showFreeThreshold: false, showRanges: false } },
      edit_mode: { cp: 'conditional_free', flags: { showBaseFee: true, showFreeThreshold: true } },
      charge_range: { cp: 'range_amount', flags: { showRanges: true, showBaseFee: false } },
      charge_per_weight: { cp: 'per_weight', flags: { showBaseFee: true, showUnitValue: true } },
      charge_free: { cp: 'free', flags: { showBaseFee: false, showRanges: false, showApiSettings: false } },
      charge_api: { cp: 'api', flags: { showApiSettings: true } },
    };

    it('6개 부과정책 상태가 모두 존재한다', () => {
      const ids = g.items.map((s: any) => s.id);
      for (const id of Object.keys(expected)) {
        expect(ids, `${id} 상태 누락`).toContain(id);
      }
    });

    for (const [id, { cp, flags }] of Object.entries(expected)) {
      it(`${id} → charge_policy=${cp} + 가시성 플래그 정합`, () => {
        const item = g.items.find((s: any) => s.id === id);
        expect(item).toBeTruthy();
        const cs0 = item.initialState?.local?.form?.country_settings?.[0];
        expect(cs0, `${id} country_settings[0] 미시드`).toBeTruthy();
        expect(cs0.charge_policy).toBe(cp);
        for (const [flag, val] of Object.entries(flags)) {
          expect(item.initialState.local[flag], `${id}.${flag}`).toBe(val);
        }
      });
    }

    it('range_amount 상태는 ranges.tiers 복수, api 상태는 api_endpoint 채움', () => {
      const range = g.items.find((s: any) => s.id === 'charge_range');
      expect(range.initialState.local.form.country_settings[0].ranges.tiers.length).toBeGreaterThanOrEqual(2);
      const api = g.items.find((s: any) => s.id === 'charge_api');
      expect(api.initialState.local.form.country_settings[0].api_endpoint).toMatch(/^https?:\/\//);
    });
  });
});
