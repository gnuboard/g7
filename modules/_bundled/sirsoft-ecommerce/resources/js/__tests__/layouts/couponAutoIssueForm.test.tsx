/**
 * @file couponAutoIssueForm.test.tsx
 * @description 쿠폰 등록·수정 폼의 자동발급 칸 (#952 A·B)
 *
 * - 발급방법이 자동이면 조건(가입·첫구매·생일)을, 아니면 수동 조건을 보인다
 * - 첫구매면 발급 시점 선택을 보이고, 조건을 바꾸면 시점을 채우거나 비운다
 * - 생일 조건은 생일 항목 지정 여부로 경고/안내를 가른다
 * - 자동발급이면 회원당 한도 칸 대신 「한 번만」 안내를 보인다
 * - 발급 알림 구획은 다운로드 쿠폰에서 숨기고, 저장된 쿠폰만 채널 카드를 보인다
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const here = path.dirname(fileURLToPath(import.meta.url));
const formDir = path.resolve(here, '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_form');
const load = (file: string): any => JSON.parse(fs.readFileSync(path.join(formDir, file), 'utf-8'));

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

const basic = load('_partial_basic_info.json');
const issue = load('_partial_issue_settings.json');
const notify = load('_partial_notification_settings.json');
const scope = (form: Record<string, any>, extra: Record<string, any> = {}) => ({ _local: { form }, route: {}, coupon: undefined, coupon_abilities: undefined, ...extra });

describe('쿠폰 폼 — 자동발급 조건 (#952)', () => {
  // @scenario case=admin_coupon_form_condition_fields
  // @effects coupon_form_auto_condition_fields
  it('자동발급이면 자동 조건 칸, 아니면 수동 조건 칸을 보인다', () => {
    const auto = byRef(basic, 'field_issue_condition_auto');
    const manual = byRef(basic, 'field_issue_condition_manual');
    expect(evaluate(auto.if, scope({ issue_method: 'auto' }))).toBe(true);
    expect(evaluate(manual.if, scope({ issue_method: 'auto' }))).toBe(false);
    expect(evaluate(manual.if, scope({ issue_method: 'direct' }))).toBe(true);
  });

  // @scenario case=admin_coupon_form_first_purchase_trigger
  // @effects coupon_form_first_purchase_trigger
  it('첫구매일 때만 발급 시점 선택이 보이고, 선택지는 시점 다섯 가지다', () => {
    const trigger = byRef(basic, 'field_first_purchase_trigger');
    expect(evaluate(trigger.if, scope({ issue_method: 'auto', issue_condition: 'first_purchase' }))).toBe(true);
    expect(evaluate(trigger.if, scope({ issue_method: 'auto', issue_condition: 'signup' }))).toBe(false);
    const text = JSON.stringify(trigger);
    for (const value of ['payment_complete', 'preparing', 'shipping', 'delivered', 'confirmed']) {
      expect(text).toContain(`"value":"${value}"`);
      expect(text).toContain(`first_purchase_trigger_${value}`);
    }
  });

  // @scenario case=admin_coupon_form_condition_change_resets_trigger
  // @effects coupon_form_first_purchase_trigger
  it('조건을 첫구매로 바꾸면 시점을 구매확정으로 채우고, 다른 조건이면 비운다', () => {
    const text = JSON.stringify(basic);
    expect(text).toContain("$event.target.value === 'first_purchase' ? (_local.form?.first_purchase_trigger_status ?? 'confirmed') : null");
    // 발급방법을 바꾸면 조건을 기본값으로 되돌리고 시점을 비운다
    expect(text).toContain("$event.target.value === 'auto' ? 'signup' : 'manual'");
  });

  // @scenario case=admin_coupon_form_birthday_warning
  // @effects coupon_form_birthday_field_warning
  it('생일 조건 — 생일 항목이 지정되지 않았으면 경고, 지정됐으면 안내를 보인다', () => {
    const warning = byRef(basic, 'birthday_field_warning');
    const guide = byRef(basic, 'birthday_issue_guide');
    const form = { issue_method: 'auto', issue_condition: 'birthday' };
    const edit = (configured: boolean) => scope(form, { route: { id: 5 }, coupon: { data: { birthday_field_configured: configured } } });
    expect(evaluate(warning.if, edit(false))).toBe(true);
    expect(evaluate(guide.if, edit(false))).toBe(false);
    expect(evaluate(warning.if, edit(true))).toBe(false);
    expect(evaluate(guide.if, edit(true))).toBe(true);
  });

  // @scenario case=admin_coupon_form_auto_limit
  // @effects coupon_form_auto_limit_hidden
  it('자동발급이면 재발급(회원당 한도) 칸 대신 「한 번만」 안내를 보인다', () => {
    expect(evaluate(byRef(issue, 'auto_issue_limit_guide').if, scope({ issue_method: 'auto' }))).toBe(true);
    expect(evaluate(byRef(issue, 'field_reissue').if, scope({ issue_method: 'auto' }))).toBe(false);
    expect(evaluate(byRef(issue, 'field_reissue').if, scope({ issue_method: 'download' }))).toBe(true);
  });
});

describe('쿠폰 폼 — 발급 알림 구획 (#952)', () => {
  // @scenario case=admin_coupon_form_notification_section
  // @effects coupon_form_notification_section
  it('다운로드 쿠폰이면 구획을 숨기고, 알림을 켜야 광고성 선택이 보인다', () => {
    const section = byRef(notify, 'notification_settings_section');
    expect(evaluate(section.if, scope({ issue_method: 'download' }))).toBe(false);
    expect(evaluate(section.if, scope({ issue_method: 'auto' }))).toBe(true);
    const marketing = byRef(notify, 'field_notify_marketing_only');
    expect(evaluate(marketing.if, scope({ notify_enabled: true }))).toBe(true);
    expect(evaluate(marketing.if, scope({ notify_enabled: false }))).toBe(false);
  });

  // @scenario case=admin_coupon_form_notification_card
  // @effects coupon_form_notification_card_after_save
  it('새 쿠폰은 「저장하면 만들어진다」 안내, 저장된 켠 쿠폰은 채널 카드, 켜고 아직 저장 안 했으면 저장 안내를 보인다', () => {
    const create = byRef(notify, 'notification_create_hint');
    const unsaved = byRef(notify, 'notification_unsaved_hint');
    const card = byRef(notify, 'coupon_notification_card');
    const on = { notify_enabled: true };
    expect(evaluate(create.if, scope(on))).toBe(true);
    expect(evaluate(card.if, scope(on))).toBe(false);
    expect(evaluate(unsaved.if, scope(on, { route: { id: 7 }, coupon: { data: { notify_enabled: false } } }))).toBe(true);
    expect(evaluate(card.if, scope(on, { route: { id: 7 }, coupon: { data: { notify_enabled: true } } }))).toBe(true);
  });

  // @scenario case=admin_coupon_form_channel_toggle
  // @effects coupon_form_notification_channel_toggle
  it('채널 카드는 그 쿠폰의 알림 종류(coupon_issued.{id}) 템플릿을 돌고, 켜고 끄기는 템플릿 토글 API 를 부른다', () => {
    const each = collect(notify, (n) => String(refOf(n)).startsWith('coupon_notification_channel_') && n.iteration)[0];
    expect(each.iteration.source).toContain("d.type === 'coupon_issued.' + route.id");
    const toggle = collect(each, (n) => n.handler === 'apiCall')[0];
    expect(toggle.target).toContain('/toggle-active');
    expect(JSON.stringify(toggle)).toContain('refetchDataSource');
  });
});
