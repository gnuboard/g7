/**
 * @file adminSettingsPromotionTab.test.tsx
 * @description 쇼핑몰 설정 쿠폰 탭 — 생일 항목 선택(후보는 서버 `_meta`), 후보가 없을 때 안내, 만료 임박 알림 켜기·일수.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const here = path.dirname(fileURLToPath(import.meta.url));
const adminDir = path.resolve(here, '../../../layouts/admin');
const load = (file: string): any => JSON.parse(fs.readFileSync(path.join(adminDir, file), 'utf-8'));

function collect(node: any, match: (n: any) => boolean, out: any[] = []): any[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => collect(n, match, out));
    return out;
  }
  if (match(node)) out.push(node);
  Object.values(node).forEach((v) => collect(v, match, out));
  return out;
}

const refOf = (n: any) => String(n?.props?.['data-ref'] ?? n?.id ?? '');
const byRef = (root: any, ref: string) => collect(root, (n) => refOf(n) === ref)[0];

function evaluate(expression: string, scope: Record<string, any>): any {
  const body = String(expression).replace(/^\{\{/, '').replace(/\}\}$/, '');
  const names = Object.keys(scope);
  return new Function(...names, `return (${body});`)(...names.map((n) => scope[n]));
}

const tab = load('partials/admin_ecommerce_settings/_tab_promotion.json');
const settingsPage = fs.readFileSync(path.join(adminDir, 'admin_ecommerce_settings.json'), 'utf-8');
const t = (key: string) => key;

describe('쇼핑몰 설정 — 쿠폰 탭', () => {
  // @scenario case=admin_ecommerce_settings_promotion
  // @effects promotion_tab_registered
  it('설정 화면에 쿠폰 탭이 있고, 탭 내용은 그 탭일 때만 보인다', () => {
    expect(settingsPage).toContain('_tab_promotion');
    const content = byRef(tab, 'tab_content_promotion');
    expect(evaluate(content.if, { _global: { activeEcommerceSettingsTab: 'promotion' }, query: {} })).toBe(true);
    expect(evaluate(content.if, { _global: {}, query: { tab: 'basic_info' } })).toBe(false);
  });

  // @scenario case=admin_ecommerce_settings_promotion_candidates
  // @effects promotion_birthday_candidates
  it('생일 항목 선택지는 「지정 안 함」 + 서버가 준 후보다', () => {
    const select = byRef(tab, 'promotion_birthday_field_select');
    const options = evaluate(select.props.options, {
      $t: t,
      _local: { form: { _meta: { birthday_field_candidates: [{ value: 'birth', label: '생일' }, { value: 'off', label: '기념일 (꺼짐)' }] } } },
      settings: undefined,
    });
    expect(options.map((o: any) => o.value)).toEqual(['', 'birth', 'off']);
    expect(select.props.name).toBe('promotion.birthday_field_key');
  });

  // @scenario case=admin_ecommerce_settings_promotion_no_candidates
  // @effects promotion_birthday_no_candidates_hint
  it('후보가 없으면 회원 항목 설정으로 가는 안내를 보이고, 받기 전에는 보이지 않는다', () => {
    const hint = byRef(tab, 'promotion_birthday_no_candidates');
    expect(evaluate(hint.if, { _local: { form: { _meta: { birthday_field_candidates: [] } } }, settings: { data: {} } })).toBe(true);
    expect(evaluate(hint.if, { _local: { form: {} }, settings: undefined })).toBe(false);
    expect(JSON.stringify(hint)).toContain('/admin/settings?tab=member');
  });

  // @scenario case=admin_ecommerce_settings_promotion_expiry
  // @effects promotion_expiry_notification_fields
  it('만료 임박 알림 — 켜야 일수 칸이 보이고, 일수 범위는 서버 한계값을 쓴다', () => {
    const json = JSON.stringify(tab);
    expect(json).toContain('promotion.coupon_expiry_notification_enabled');
    const days = collect(tab, (n) => n?.props?.name === 'promotion.coupon_expiry_notification_days_before')[0];
    expect(days.props.min).toContain('coupon_expiry_notification_days_before_min');
    expect(days.props.max).toContain('coupon_expiry_notification_days_before_max');
    const wrapper = collect(tab, (n) => typeof n.if === 'string' && n.if.includes('coupon_expiry_notification_enabled'))[0];
    expect(evaluate(wrapper.if, { _local: { form: { promotion: { coupon_expiry_notification_enabled: false } } } })).toBe(false);
    expect(evaluate(wrapper.if, { _local: { form: {} } })).toBe(true);
  });
});
