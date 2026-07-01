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
 * 통화 저장 호출은 템플릿 폼(admin_user_form.json) 의 저장 onSuccess 체인이 담당한다.
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

  it('통화 Select 노드가 주입되어 있다(name=ecommerce_preferred_currency 폼 바인딩)', () => {
    expect(select).toBeTruthy();
    expect(select.props.name).toBe('ecommerce_preferred_currency');
  });

  it('options 는 모듈 노출 글로벌(_global.modules ... language_currency.currencies)에서 읽는다 (D6)', () => {
    expect(select.props.options).not.toContain('_global.availableCurrencies');
    expect(select.props.options).toContain('_global.modules');
    expect(select.props.options).toContain('language_currency');
    expect(select.props.options).toContain('is_default');
    expect(select.props.options).toContain('exchange_rate');
  });

  it('value 폴백은 템플릿 종속 _global.defaultCurrency 를 쓰지 않는다 (D6)', () => {
    expect(select.props.value).not.toContain('_global.defaultCurrency');
    expect(select.props.value).toContain('ecommerce_preferred_currency');
  });

  it('주입 필드에 별도 통화 저장 apiCall 이 없다 (D9 — 회원정보 저장에 통합)', () => {
    expect(raw).not.toContain('admin/users/');
    expect(raw).not.toContain('"handler": "apiCall"');
  });

  it('주입 필드에 별도 저장 버튼 라벨(user_currency.save_button)이 없다 (D9)', () => {
    expect(raw).not.toContain('user_currency.save_button');
  });
});

describe('admin_user_form.json — 회원정보 저장에 통화 통합 (D9-관리자)', () => {
  const formPath = path.resolve(
    __dirname,
    '../../../../../../../templates/_bundled/sirsoft-admin_basic/layouts/admin_user_form.json',
  );
  const formRaw = fs.readFileSync(formPath, 'utf-8');

  it('저장 onSuccess 가 통화 저장 apiCall 을 /api/ 접두사로 체인한다', () => {
    expect(formRaw).toContain('/api/modules/sirsoft-ecommerce/admin/users/');
    expect(formRaw).toContain('currency');
  });

  it('폼 body 로 통화 필드(ecommerce_preferred_currency)가 함께 전송될 수 있다(폼 바인딩)', () => {
    expect(formRaw).toContain('ecommerce_preferred_currency');
  });
});
