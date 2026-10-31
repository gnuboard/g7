import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * 관리자 회원편집 통화 필드(layout_extensions 주입) 구조 잠금 테스트 (D6 + D9-관리자)
 *
 * D6 회귀: options/value 는 유저 템플릿 전용 글로벌(_global.availableCurrencies/defaultCurrency)이
 * 아니라 코어 공통 노출 _global.modules['sirsoft-ecommerce'].language_currency 에서 읽는다.
 *
 * D9 회귀: 관리자 회원 수정 페이지의 결제 통화도 별도 저장버튼을 두지 않고
 * 회원정보 저장 시 함께 저장한다. 따라서 주입 필드는 name 바인딩 Select 만 두고
 * (폼 dataKey=form 에 자동 바인딩), 별도 저장버튼·apiCall 을 갖지 않는다.
 *
 * 코어 저장 통합(결정 2026-09-28): 폼은 코어 회원 저장 API 하나만 부르고, 통화는 그 요청 본문에
 * 실려 이커머스가 코어 훅으로 검증·저장한다. 모듈 전용 엔드포인트 호출이 폼에 남아 있으면 안 되고,
 * 검증 오류(필드 키 ecommerce_preferred_currency)는 주입 칸 아래에 떠야 한다.
 */
describe('admin-user-currency-field.json — D6 자기완결 + D9 통합저장', () => {
  const fieldPath = path.resolve(
    __dirname,
    '../../../../resources/extensions/admin-user-currency-field.json',
  );
  const raw = fs.readFileSync(fieldPath, 'utf-8');
  const json = JSON.parse(raw);

  const findSelect = (node: any): any => {
    if (!node || typeof node !== 'object') return null;
    if (node.name === 'Select' && node.props?.name === 'ecommerce_preferred_currency') return node;
    for (const child of node.children ?? []) {
      const found = findSelect(child);
      if (found) return found;
    }
    return null;
  };

  const select = json.injections
    .flatMap((inj: any) => inj.components ?? [])
    .map((c: any) => findSelect(c))
    .find(Boolean);

  /**
   * @scenario entry_point=admin_update, field=currency
   * @effects form_field_rides_core_save_body
   */
  it('통화 Select 노드가 주입되어 있다(name=ecommerce_preferred_currency 폼 바인딩)', () => {
    expect(select).toBeTruthy();
    expect(select.props.name).toBe('ecommerce_preferred_currency');
  });

  /**
   * @scenario entry_point=admin_update, field=currency
   * @effects field_options_follow_selectable_currencies
   */
  it('options 는 모듈 노출 글로벌(_global.modules ... language_currency.currencies)에서 읽는다 (D6)', () => {
    expect(select.props.options).not.toContain('_global.availableCurrencies');
    expect(select.props.options).toContain('_global.modules');
    expect(select.props.options).toContain('language_currency');
    expect(select.props.options).toContain('is_default');
    expect(select.props.options).toContain('exchange_rate');
  });

  /**
   * @scenario entry_point=admin_update, field=currency
   * @effects field_options_follow_selectable_currencies
   */
  it('value 폴백은 템플릿 종속 _global.defaultCurrency 를 쓰지 않는다 (D6)', () => {
    expect(select.props.value).not.toContain('_global.defaultCurrency');
    expect(select.props.value).toContain('ecommerce_preferred_currency');
  });

  /**
   * @scenario entry_point=admin_update, field=currency
   * @effects form_calls_only_core_save_api
   */
  it('주입 필드에 별도 통화 저장 apiCall 이 없다 (D9 — 회원정보 저장에 통합)', () => {
    expect(raw).not.toContain('admin/users/');
    expect(raw).not.toContain('"handler": "apiCall"');
  });

  /**
   * @scenario entry_point=admin_update, field=currency
   * @effects form_calls_only_core_save_api
   */
  it('주입 필드에 별도 저장 버튼 라벨(user_currency.save_button)이 없다 (D9)', () => {
    expect(raw).not.toContain('user_currency.save_button');
  });

  /**
   * @scenario entry_point=admin_update, field=currency
   * @effects field_error_shown_under_field
   */
  it('코어 저장 요청의 검증 오류(ecommerce_preferred_currency)를 칸 아래에 띄운다', () => {
    expect(raw).toContain('_local.errors?.ecommerce_preferred_currency');
  });
});

describe('admin_user_form.json — 코어 회원 저장 한 번에 통화까지 (코어 저장 통합)', () => {
  const formPath = path.resolve(
    __dirname,
    '../../../../../../../templates/_bundled/sirsoft-admin_basic/layouts/admin_user_form.json',
  );
  const formRaw = fs.readFileSync(formPath, 'utf-8');
  const form = JSON.parse(formRaw);

  /** 트리에서 apiCall 액션을 모두 모은다. */
  const collectApiCalls = (node: unknown, acc: any[] = []): any[] => {
    if (Array.isArray(node)) {
      node.forEach((n) => collectApiCalls(n, acc));
      return acc;
    }
    if (node && typeof node === 'object') {
      const obj = node as Record<string, any>;
      if (obj.handler === 'apiCall') acc.push(obj);
      Object.values(obj).forEach((v) => collectApiCalls(v, acc));
    }
    return acc;
  };

  /**
   * @scenario entry_point=admin_update, field=currency
   * @effects form_calls_only_core_save_api
   */
  it('저장 흐름에 이커머스 전용 엔드포인트 호출이 없다', () => {
    expect(formRaw).not.toContain('/api/modules/sirsoft-ecommerce/admin/users/');
    const moduleCalls = collectApiCalls(form).filter((c) => String(c.target ?? '').includes('/api/modules/'));
    expect(moduleCalls).toEqual([]);
  });

  /**
   * @scenario entry_point=admin_update, field=currency
   * @effects form_field_rides_core_save_body
   */
  it('회원 저장 요청은 폼 전체(_local.form)를 본문으로 보낸다 — 주입 칸 값이 함께 실린다', () => {
    const saveCalls = collectApiCalls(form).filter((c) => String(c.target ?? '').includes('/api/admin/users'));
    expect(saveCalls.length).toBeGreaterThan(0);
    for (const call of saveCalls) {
      expect(call.params?.body).toBe('{{_local.form}}');
    }
  });
});
