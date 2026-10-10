/**
 * @file checkout-payment-brand-mark.test.tsx
 * @description 체크아웃 결제수단 목록의 브랜드 마크 데이터 기반 렌더 + 애플페이 iOS 게이팅 검증.
 *
 * 과거 각 PG 플러그인의 체크아웃 DOM 인젝터가 렌더 후 브랜드 마크를 주입하던 것을,
 * 카탈로그 `_cached_brand_mark` 를 코어 레이아웃이 BrandMark 컴포넌트로 직접 렌더하도록 통일했다.
 * 애플페이 iOS 게이팅도 injector 밖(목록 필터 + appConfig.isIos)으로 이관했다. 그 필터는 주문서 레이아웃의
 * 계산값 `selectablePaymentMethods` 에 있고, 결제수단 칸은 그 계산값을 돈다(빈 목록 안내·결제하기 잠금과 같은 출처).
 *
 * 검증 축:
 *  - 구조: BrandMark 노드가 method._cached_brand_mark 에 바인딩 + method._cached_brand_mark 없을 때 Icon 폴백
 *  - 조건 평가: 결제수단 칸이 도는 계산값이 requires_ios 수단을 appConfig.isIos 에 따라 포함/제외
 *
 * @effects none_form_falls_back_to_icon, applepay_shown_on_ios, applepay_hidden_on_non_ios, non_ios_methods_unaffected
 */
// @scenario mark_form=badge, requires_ios=false, device=ios
// @scenario mark_form=badge, requires_ios=false, device=non_ios
// @scenario mark_form=badge, requires_ios=false, device=ipados_desktop_ua
// @scenario mark_form=svg, requires_ios=false, device=ios
// @scenario mark_form=svg, requires_ios=false, device=non_ios
// @scenario mark_form=none, requires_ios=false, device=ios
// @scenario mark_form=none, requires_ios=false, device=non_ios

import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import checkoutPaymentJson from '../../layouts/partials/shop/_checkout_payment.json';
import checkoutJson from '../../layouts/shop/checkout.json';

/** 객체 트리에서 조건을 만족하는 첫 노드를 깊이우선 탐색 */
function findNode(node: any, predicate: (n: any) => boolean): any {
  if (node == null || typeof node !== 'object') return undefined;
  if (predicate(node)) return node;
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) {
      for (const item of value) {
        const found = findNode(item, predicate);
        if (found !== undefined) return found;
      }
    } else if (value && typeof value === 'object') {
      const found = findNode(value, predicate);
      if (found !== undefined) return found;
    }
  }
  return undefined;
}

const brandMarkNode = findNode(
  checkoutPaymentJson,
  (n) => n.name === 'BrandMark'
);

const iconFallbackNode = findNode(
  checkoutPaymentJson,
  (n) => n.name === 'Icon' && typeof n.if === 'string' && n.if.includes('_cached_brand_mark')
);

const iterationNode = findNode(
  checkoutPaymentJson,
  (n) => n.iteration && n.iteration.item_var === 'method'
);

const engine = new DataBindingEngine();

describe('체크아웃 결제수단 브랜드 마크 렌더', () => {
  describe('구조', () => {
    /**
     * @scenario mark_form=badge, requires_ios=false, device=non_ios
     * @effects brand_mark_flows_to_cached
     */
    it('BrandMark 노드가 method._cached_brand_mark 에 바인딩되어야 한다', () => {
      expect(brandMarkNode, 'BrandMark 노드').toBeDefined();
      expect(brandMarkNode.props.brandMark).toBe('{{method._cached_brand_mark}}');
      // 브랜드 마크가 있는 수단만 렌더 (if 가드)
      expect(brandMarkNode.if).toBe('{{method._cached_brand_mark}}');
    });

    /**
     * @scenario mark_form=none, requires_ios=false, device=non_ios
     * @effects none_form_falls_back_to_icon
     */
    it('브랜드 마크가 없는 수단은 Icon 폴백을 렌더해야 한다', () => {
      expect(iconFallbackNode, 'Icon 폴백 노드').toBeDefined();
      expect(iconFallbackNode.if).toBe('{{!method._cached_brand_mark}}');
      expect(iconFallbackNode.props.name).toBe("{{method._cached_icon ?? 'circle-question'}}");
    });
  });

  describe('애플페이 iOS 게이팅 (결제수단 칸이 도는 계산값)', () => {
    const source = (checkoutJson as any).computed?.selectablePaymentMethods as string;

    const methods = [
      { id: 'dbank', is_active: true },
      { id: 'nhnkcp_naverpay', is_active: true },
      { id: 'nhnkcp_applepay', is_active: true, requires_ios: true },
      { id: 'inactive', is_active: false },
    ];

    const evalSource = (isIos: boolean): any[] => {
      const ctx = {
        paymentSettings: { data: { order_settings: { payment_methods: methods } } },
        _global: { appConfig: { isIos } },
      };
      const stripped = source.replace(/^\{\{/, '').replace(/\}\}$/, '');
      return engine.evaluateExpression(stripped, ctx) as any[];
    };

    /**
     * @scenario mark_form=badge, requires_ios=true, device=ios
     * @effects requires_ios_flag_carried
     */
    it('결제수단 칸이 계산값을 돌고, 계산값이 iOS·활성 여부로 거른다', () => {
      expect(iterationNode, 'iteration 노드').toBeDefined();
      expect(iterationNode.iteration.source).toContain('_computed.selectablePaymentMethods');
      expect(source).toContain('requires_ios');
      expect(source).toContain('isIos');
      expect(source).toContain('is_active');
    });

    /**
     * @scenario mark_form=badge, requires_ios=true, device=ios
     * @effects applepay_shown_on_ios
     */
    it('iOS 기기: 애플페이 포함, 비활성 수단 제외', () => {
      const ids = evalSource(true).map((m) => m.id);
      expect(ids).toContain('nhnkcp_applepay');
      expect(ids).toContain('nhnkcp_naverpay');
      expect(ids).toContain('dbank');
      expect(ids).not.toContain('inactive');
    });

    /**
     * @scenario mark_form=badge, requires_ios=true, device=non_ios
     * @effects applepay_hidden_on_non_ios, non_ios_methods_unaffected
     */
    it('비-iOS 기기: 애플페이 제외 (게이팅), 나머지 활성 수단 유지', () => {
      const ids = evalSource(false).map((m) => m.id);
      expect(ids).not.toContain('nhnkcp_applepay');
      expect(ids).toContain('nhnkcp_naverpay');
      expect(ids).toContain('dbank');
      expect(ids).not.toContain('inactive');
    });
  });
});
