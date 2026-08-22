/**
 * @file pluginSettingsPaymentMethods.test.tsx
 * @description S4 신설 섹션(결제 방식 · 가상계좌) 구조 검증.
 *
 * order_sheet_mode 토글 / 결제수단 9종 체크박스(name=method_*) / 노출 조건(if) /
 * 가상계좌 옵션(입금기한·현금영수증·에스크로 3-상태·웹훅) / 에스크로 운영 안내(W-6) /
 * responsive.portable 을 검증한다.
 *
 * @scenario toss_payment_methods_vbank_escrow
 * @effects escrow_operation_notice_shown_when_escrow_enabled
 */

import { describe, it, expect } from 'vitest';
import pluginSettingsLayout from '../../../layouts/admin/plugin_settings.json';

/** 레이아웃 트리를 순회하며 조건을 만족하는 모든 노드를 수집한다. */
function collect(node: unknown, pred: (n: Record<string, unknown>) => boolean): Record<string, unknown>[] {
  const out: Record<string, unknown>[] = [];
  const walk = (n: unknown) => {
    if (!n || typeof n !== 'object') return;
    const v = n as Record<string, unknown>;
    if (pred(v)) out.push(v);
    for (const child of Object.values(v)) walk(child);
  };
  walk(node);
  return out;
}

function findById(node: unknown, id: string): Record<string, unknown> | undefined {
  return collect(node, (n) => n.id === id)[0];
}

function nameOf(node: Record<string, unknown>): string {
  const props = (node.props ?? {}) as Record<string, unknown>;
  return typeof props.name === 'string' ? props.name : '';
}

describe('plugin_settings — 결제 방식 섹션', () => {
  it('order_sheet_mode 토글이 존재한다', () => {
    const toggles = collect(pluginSettingsLayout, (n) => n.name === 'Toggle' && nameOf(n) === 'order_sheet_mode');
    expect(toggles).toHaveLength(1);
  });

  it('결제수단 9종 체크박스가 method_* name 으로 존재한다', () => {
    const checkboxes = collect(pluginSettingsLayout, (n) => n.name === 'Checkbox');
    const names = checkboxes.map(nameOf).filter((s) => s.startsWith('method_'));

    expect(names).toEqual(
      expect.arrayContaining([
        'method_card',
        'method_virtual_account',
        'method_transfer',
        'method_mobile_phone',
        'method_tosspay',
        'method_kakaopay',
        'method_naverpay',
        'method_payco',
        'method_samsungpay',
      ])
    );
    expect(names).toHaveLength(9);
  });

  it('결제수단 9종의 라벨이 Label 래핑 + Span 텍스트로 렌더된다', () => {
    // Checkbox 컴포넌트는 label prop 을 렌더하지 않는다 (부모 Label 이 처리).
    // label prop 에만 의존하면 화면에 라벨이 표시되지 않으므로 사용을 금지한다.
    const checkboxes = collect(pluginSettingsLayout, (n) => n.name === 'Checkbox');
    const withLabelProp = checkboxes.filter((n) => (n.props as Record<string, unknown>)?.label !== undefined);
    expect(withLabelProp).toHaveLength(0);

    // 각 method_* 체크박스는 Label 로 감싸이고 형제 Span 이 라벨 텍스트를 갖는다.
    const wrappers = collect(pluginSettingsLayout, (n) => {
      if (n.name !== 'Label' || !Array.isArray(n.children)) return false;
      const children = n.children as Record<string, unknown>[];
      return children.some((c) => c.name === 'Checkbox' && nameOf(c).startsWith('method_'));
    });
    expect(wrappers).toHaveLength(9);

    for (const wrapper of wrappers) {
      const children = wrapper.children as Record<string, unknown>[];
      const checkbox = children.find((c) => c.name === 'Checkbox')!;
      const span = children.find((c) => c.name === 'Span');
      expect(span, `${nameOf(checkbox)} 라벨 Span 누락`).toBeDefined();
      expect(String(span!.text)).toBe(`$t:sirsoft-tosspayments.settings.${nameOf(checkbox)}`);
    }
  });

  it('결제수단 체크박스는 boolean 저장을 보장한다 (autoBinding 우회 + checked 강제 + change 액션)', () => {
    // 엔진의 폼 자동바인딩은 현재 값이 boolean 일 때만 checked 바인딩을 쓴다
    // (DynamicRenderer `isCheckedBinding`: typeof currentValue === 'boolean').
    // 저장값이 null/undefined 면 value 바인딩으로 떨어져 빈 문자열('')이 전송되고,
    // 서버가 그것을 null 로 저장하면 defaults(false) 를 덮어 영구 고착된다.
    // → 레이아웃이 boolean 을 직접 보장해야 한다.
    const checkboxes = collect(pluginSettingsLayout, (n) => n.name === 'Checkbox' && nameOf(n).startsWith('method_'));
    expect(checkboxes).toHaveLength(9);

    for (const box of checkboxes) {
      const name = nameOf(box);
      const props = (box.props ?? {}) as Record<string, unknown>;

      // 자동바인딩의 value 분기 개입을 차단한다.
      expect(props.autoBinding, `${name}: autoBinding=false 누락`).toBe(false);

      // checked 는 !! 로 boolean 강제 — 저장값이 null 이어도 false 로 렌더된다.
      expect(String(props.checked), `${name}: checked 가 boolean 강제(!!)가 아님`).toBe(
        `{{!!_local.form?.${name}}}`
      );

      // change 액션이 $event.target.checked(항상 boolean)를 직접 기록한다.
      const actions = (box.actions ?? []) as Record<string, unknown>[];
      const change = actions.find((a) => a.type === 'change');
      expect(change, `${name}: change 액션 누락`).toBeDefined();

      const params = (change!.params ?? {}) as Record<string, unknown>;
      expect(params[`form.${name}`], `${name}: boolean 이 아닌 값을 기록`).toBe('{{$event.target.checked}}');
      expect(params.hasChanges, `${name}: hasChanges 미설정 — 저장 버튼이 비활성 유지됨`).toBe(true);
    }
  });

  it('결제수단 그룹은 order_sheet_mode 가 켜졌을 때만 노출된다 (if)', () => {
    const group = findById(pluginSettingsLayout, 'enabled_methods_group');
    expect(group).toBeDefined();
    expect(group!.if).toContain('order_sheet_mode');
  });

  it('체크박스 그리드는 responsive.portable 로 1열 스택한다', () => {
    const grid = findById(pluginSettingsLayout, 'method_checkbox_grid');
    expect(grid).toBeDefined();
    const responsive = grid!.responsive as Record<string, any> | undefined;
    expect(responsive?.portable?.props?.className).toContain('grid-cols-1');
  });
});

describe('plugin_settings — 가상계좌 섹션', () => {
  it('입금기한 입력(number) 이 존재한다', () => {
    const inputs = collect(pluginSettingsLayout, (n) => n.name === 'Input' && nameOf(n) === 'vbank_valid_hours');
    expect(inputs).toHaveLength(1);
    const props = inputs[0].props as Record<string, unknown>;
    expect(props.type).toBe('number');
  });

  it('현금영수증 유형 Select 가 3옵션(없음/소득공제/지출증빙)을 갖는다', () => {
    const selects = collect(pluginSettingsLayout, (n) => n.name === 'Select' && nameOf(n) === 'vbank_cash_receipt_type');
    expect(selects).toHaveLength(1);
    const options = (selects[0].props as any).options as { value: string }[];
    expect(options.map((o) => o.value)).toEqual(['', '소득공제', '지출증빙']);
  });

  it('에스크로 Select 가 3-상태(off/on/buyer_choice)를 갖는다', () => {
    const selects = collect(pluginSettingsLayout, (n) => n.name === 'Select' && nameOf(n) === 'use_escrow');
    expect(selects).toHaveLength(1);
    const options = (selects[0].props as any).options as { value: string }[];
    expect(options.map((o) => o.value)).toEqual(['off', 'on', 'buyer_choice']);
  });

  it('에스크로 운영 안내(부분취소 불가 · 배송정보 등록)가 use_escrow !== off 일 때만 노출된다 (W-6)', () => {
    const notice = findById(pluginSettingsLayout, 'escrow_operation_notice');
    expect(notice).toBeDefined();
    expect(notice!.if).toBe("{{_local.form?.use_escrow !== 'off'}}");

    const texts = collect(notice, (n) => typeof n.text === 'string').map((n) => n.text as string);
    expect(texts).toContain('$t:sirsoft-tosspayments.settings.escrow_no_partial_cancel_notice');
    expect(texts).toContain('$t:sirsoft-tosspayments.settings.escrow_shipping_info_notice');
  });

  it('배송정보 안내는 토스 상점관리자 외부 링크를 제공한다 (W-6)', () => {
    const notice = findById(pluginSettingsLayout, 'escrow_operation_notice');
    const links = collect(notice, (n) => n.name === 'A');

    expect(links).toHaveLength(1);
    const props = links[0].props as Record<string, unknown>;
    expect(props.href).toBe('https://app.tosspayments.com/');
    expect(props.target).toBe('_blank');
    expect(props.rel).toBe('noopener noreferrer');
    expect(links[0].text).toBe('$t:sirsoft-tosspayments.settings.escrow_shipping_info_link');
  });

  it('웹훅 secret 검증 토글이 존재한다', () => {
    const toggles = collect(pluginSettingsLayout, (n) => n.name === 'Toggle' && nameOf(n) === 'webhook_secret_verify');
    expect(toggles).toHaveLength(1);
  });

  it('웹훅 URL 복사 버튼이 copyToClipboard 핸들러를 사용한다', () => {
    const btn = findById(pluginSettingsLayout, 'webhook_copy_button');
    expect(btn).toBeDefined();
    const actions = btn!.actions as Array<Record<string, unknown>>;
    expect(actions[0].handler).toBe('copyToClipboard');
  });

  it('웹훅 URL 복사 버튼은 portable 에서 full-width 로 전환된다', () => {
    const btn = findById(pluginSettingsLayout, 'webhook_copy_button');
    const responsive = btn!.responsive as Record<string, any> | undefined;
    expect(responsive?.portable?.props?.className).toContain('w-full');
  });
});

describe('plugin_settings — 기존 섹션 보존', () => {
  it('기존 저장 버튼 sticky 는 유지된다', () => {
    const footer = findById(pluginSettingsLayout, 'footer_buttons');
    expect((footer!.props as any).className).toContain('sticky-footer-buttons');
  });
});
