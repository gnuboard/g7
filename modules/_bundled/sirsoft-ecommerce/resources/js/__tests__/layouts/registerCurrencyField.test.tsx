// e2e:allow 확장 조각 정적 계약 — 회원가입 화면(통화 1개/2개)은 Chrome MCP 로 실측했다(이력 20261104_0040).
import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * 가입폼 통화 필드(layout_extensions 주입) 구조 잠금 테스트 (D2 / L-T7-2, D-LOGIN-CUR)
 *
 * - 가입폼(auth/register)에 결제 통화 Select 가 주입된다(name=preferred_currency → registerForm 자동 바인딩).
 * - 기본 선택값 우선순위: 세션 통화(_global.preferredCurrency) > 기본통화 폴백.
 *   (_global.preferredCurrency 는 initPreferredCurrency 가 세션>locale추정 순으로 이미 해석한 값)
 * - options 는 코어 공통 노출 _global.modules['sirsoft-ecommerce'].language_currency 에서 읽고
 *   미설정 환율 통화는 제외(셀렉터 노출 규칙 §5).
 * - 이커머스 설치 게이트: currencies 존재 조건(if)으로만 렌더.
 * - 고를 수 있는 통화(기본 통화 또는 환율이 잡힌 통화)가 둘 이상일 때만 렌더 (2026-11-03).
 *
 * @scenario-manifest tests/scenarios/mp08-signup-currency.yaml
 */
describe('register-currency-field.json — 가입폼 통화 필드(D2/L-T7-2)', () => {
  const fieldPath = path.resolve(
    __dirname,
    '../../../../resources/extensions/register-currency-field.json',
  );
  const raw = fs.readFileSync(fieldPath, 'utf-8');
  const json = JSON.parse(raw);

  const findSelect = (node: any): any => {
    if (!node || typeof node !== 'object') return null;
    if (node.name === 'Select' && node.props?.name === 'preferred_currency') return node;
    for (const child of node.children ?? []) {
      const found = findSelect(child);
      if (found) return found;
    }
    return null;
  };

  const rootComponent = json.injections
    .flatMap((inj: any) => inj.components ?? [])
    .find(Boolean);

  const select = json.injections
    .flatMap((inj: any) => inj.components ?? [])
    .map((c: any) => findSelect(c))
    .find(Boolean);

  // @scenario signup_path=user_register, module_state=installed
  // @effects signup_form_default_is_session_currency
  it('가입폼(auth/register)을 대상으로 register_extension_fields 앵커에 주입한다', () => {
    expect(json.target_layout).toBe('auth/register');
    expect(json.injections[0].target_id).toBe('register_extension_fields');
  });

  // @scenario signup_path=user_register, module_state=installed
  // @effects signup_form_default_is_session_currency
  it('통화 Select 가 주입되어 있다(name=preferred_currency 폼 바인딩)', () => {
    expect(select).toBeTruthy();
    expect(select.props.name).toBe('preferred_currency');
  });

  // @scenario signup_path=user_register, module_state=installed
  // @effects signup_form_default_is_session_currency
  it('기본 선택값은 세션 통화(_global.preferredCurrency)를 1순위로 한다 (D-LOGIN-CUR)', () => {
    expect(select.props.value).toContain('_global.preferredCurrency');
    // 폴백: 기본통화(default_currency)까지
    expect(select.props.value).toContain('default_currency');
  });

  // @scenario signup_path=user_register, module_state=installed
  // @effects signup_form_default_is_session_currency
  it('options 는 모듈 노출 글로벌에서 읽고 미설정 환율 통화를 제외한다 (§5)', () => {
    expect(select.props.options).toContain('_global.modules');
    expect(select.props.options).toContain('language_currency');
    expect(select.props.options).toContain('is_default');
    expect(select.props.options).toContain('exchange_rate');
  });

  // @scenario signup_path=user_register, module_state=installed
  // @effects signup_form_default_is_session_currency
  it('이커머스 설치 게이트: currencies 존재 조건(if)으로만 렌더', () => {
    expect(rootComponent.if).toContain("_global.modules");
    expect(rootComponent.if).toContain('language_currency');
  });

  /**
   * 고를 통화가 하나뿐이면 묻지 않는다 (2026-11-03) — 원화만 쓰는 쇼핑몰도 가입 때 선택 칸(선택지 1개)이 떴다.
   * 가입 뒤 통화는 기본 통화 지정 리스너(AssignDefaultCurrencyOnRegisterListener)가 정한다.
   */
  describe('선택 가능한 통화 수에 따른 표시 (if 식 평가)', () => {
    const evalIf = (currencies: any[] | undefined): boolean => {
      const expr = String(rootComponent.if).replace(/^\{\{|\}\}$/g, '');
      // eslint-disable-next-line no-new-func
      return Boolean(new Function('_global', `return (${expr});`)({ modules: { 'sirsoft-ecommerce': { language_currency: { currencies } } } }));
    };
    const krw = { code: 'KRW', is_default: true, exchange_rate: null };

    // @scenario selectable_currencies=one
    // @effects signup_currency_field_only_when_choosable
    it('기본 통화 하나뿐이면 칸을 그리지 않는다', () => {
      expect(evalIf([krw])).toBe(false);
    });

    // @scenario selectable_currencies=one
    // @effects signup_currency_field_only_when_choosable
    it('환율이 잡히지 않은 다른 통화가 있어도 고를 수 있는 것이 하나면 그리지 않는다', () => {
      expect(evalIf([krw, { code: 'USD', is_default: false, exchange_rate: 0 }])).toBe(false);
    });

    // @scenario selectable_currencies=many
    // @effects signup_currency_field_only_when_choosable
    it('고를 수 있는 통화가 둘 이상이면 그린다', () => {
      expect(evalIf([krw, { code: 'USD', is_default: false, exchange_rate: 1380 }])).toBe(true);
    });

    // @scenario selectable_currencies=unset
    // @effects signup_currency_field_only_when_choosable
    it('이커머스 설정이 없으면 그리지 않는다', () => {
      expect(evalIf(undefined)).toBe(false);
    });
  });
});

describe('_register_form.json — 확장 주입 앵커 존재', () => {
  const formPath = path.resolve(
    __dirname,
    '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/auth/_register_form.json',
  );
  const formRaw = fs.readFileSync(formPath, 'utf-8');
  const formJson = JSON.parse(formRaw);

  const hasAnchor = (node: any): boolean => {
    if (!node || typeof node !== 'object') return false;
    if (node.id === 'register_extension_fields') return true;
    return (node.children ?? []).some(hasAnchor);
  };

  // @scenario signup_path=user_register, module_state=installed
  // @effects signup_form_default_is_session_currency
  it('register_extension_fields 주입 앵커가 폼에 존재한다', () => {
    expect(hasAnchor(formJson)).toBe(true);
  });
});
