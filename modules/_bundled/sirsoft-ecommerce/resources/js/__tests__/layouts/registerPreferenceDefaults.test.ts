// e2e:allow 확장 조각 정적 계약 — 그리는 것이 없는 초기화 전용 조각이라 조건식과 심는 경로만 잰다. 가입 화면의 칸은 회원 항목 계약(PHPUnit UserPreferenceFieldSetTest)이 정한다.
import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * 가입 폼의 결제 통화, 배송국가 초기값 조각(register-preference-defaults) 구조 잠금 테스트 (D-LOGIN-CUR, D6)
 *
 * 두 칸은 회원 항목(core.user) 선언대로 가입 화면의 공용 조각이 그린다(종전의 주입 조각 2개는 없어졌다).
 * 이 조각은 그리는 것 없이 초기화 액션만 기여해, 방문자가 헤더 셀렉터로 골라 둔 통화와 배송국가를
 * 가입 폼 상태(registerForm)의 회원 항목 키에 심는다.
 *
 * - 통화: 고를 수 있는 통화(기본 통화 또는 환율이 잡힌 통화)가 둘 이상이고 지금 값이 그 안에 있을 때만 (2026-11-03 결정 유지).
 * - 배송국가: 해외배송을 켰고 지금 값이 활성 국가일 때만.
 * - 가입 화면의 확장 주입 자리(register_extension_fields)는 서드파티 호환을 위해 빈 채로 남아 있다.
 *
 * @scenario-manifest tests/scenarios/mp08-signup-currency.yaml
 */
const extensionsDir = path.resolve(__dirname, '../../../../resources/extensions');
const json = JSON.parse(fs.readFileSync(path.join(extensionsDir, 'register-preference-defaults.json'), 'utf-8'));

const actionFor = (key: string): any =>
  (json.init_actions ?? []).find((action: any) => Object.keys(action.params ?? {}).includes(`registerForm.${key}`));

const evalIf = (action: any, global: Record<string, unknown>): boolean => {
  const expr = String(action.if).replace(/^\{\{|\}\}$/g, '');
  // eslint-disable-next-line no-new-func
  return Boolean(new Function('_global', `return (${expr});`)(global));
};

describe('register-preference-defaults.json — 가입 폼 초기값 조각', () => {
  // @scenario signup_path=user_register, module_state=installed
  // @effects signup_form_default_is_session_currency
  it('가입 화면(auth/register)에 그리는 것 없이 초기화 액션만 기여한다', () => {
    expect(json.target_layout).toBe('auth/register');
    expect(json.injections ?? []).toEqual([]);
    expect(json.init_actions).toHaveLength(2);
    expect(json.init_actions.every((action: any) => action.handler === 'setState' && action.params?.target === 'local')).toBe(true);
  });

  // @scenario signup_path=user_register, module_state=installed
  // @effects signup_form_default_is_session_currency
  it('결제 통화는 회원 항목 키에 세션 통화(_global.preferredCurrency)를 심는다', () => {
    const action = actionFor('ecommerce_preferred_currency');
    expect(action).toBeTruthy();
    expect(action.params['registerForm.ecommerce_preferred_currency']).toBe('{{_global.preferredCurrency}}');
    // 가입 요청의 옛 키(preferred_currency)로는 심지 않는다
    expect(JSON.stringify(json.init_actions)).not.toContain('registerForm.preferred_currency');
  });

  // @scenario signup_path=user_register, module_state=installed
  // @effects signup_form_default_is_session_currency
  it('배송국가는 회원 항목 키에 세션 배송국가(_global.preferredShippingCountry)를 심는다', () => {
    const action = actionFor('ecommerce_preferred_shipping_country');
    expect(action).toBeTruthy();
    expect(action.params['registerForm.ecommerce_preferred_shipping_country']).toBe('{{_global.preferredShippingCountry}}');
  });

  describe('결제 통화를 심는 조건 (if 식 평가)', () => {
    const action = actionFor('ecommerce_preferred_currency');
    const krw = { code: 'KRW', is_default: true, exchange_rate: null };
    const usd = { code: 'USD', is_default: false, exchange_rate: 1380 };
    const global = (currencies: unknown, preferredCurrency: unknown) => ({
      preferredCurrency,
      modules: { 'sirsoft-ecommerce': { language_currency: { currencies } } },
    });

    // @scenario selectable_currencies=one
    // @effects signup_currency_field_only_when_choosable
    it('기본 통화 하나뿐이면 심지 않는다 (칸도 그려지지 않는다)', () => {
      expect(evalIf(action, global([krw], 'KRW'))).toBe(false);
    });

    // @scenario selectable_currencies=one
    // @effects signup_currency_field_only_when_choosable
    it('환율이 잡히지 않은 다른 통화가 있어도 고를 수 있는 것이 하나면 심지 않는다', () => {
      expect(evalIf(action, global([krw, { code: 'USD', is_default: false, exchange_rate: 0 }], 'KRW'))).toBe(false);
    });

    // @scenario selectable_currencies=many
    // @effects signup_currency_field_only_when_choosable
    it('고를 수 있는 통화가 둘 이상이고 지금 통화가 그 안에 있으면 심는다', () => {
      expect(evalIf(action, global([krw, usd], 'USD'))).toBe(true);
    });

    // @scenario selectable_currencies=many
    // @effects signup_currency_field_only_when_choosable
    it('지금 통화가 고를 수 없는 값이면 심지 않는다 (검증에서 막힐 값을 넣지 않는다)', () => {
      expect(evalIf(action, global([krw, usd], 'GBP'))).toBe(false);
      expect(evalIf(action, global([krw, usd], undefined))).toBe(false);
    });

    // @scenario selectable_currencies=unset
    // @effects signup_currency_field_only_when_choosable
    it('이커머스 설정이 없으면 심지 않는다', () => {
      expect(evalIf(action, global(undefined, 'KRW'))).toBe(false);
    });
  });

  describe('배송국가를 심는 조건 (if 식 평가)', () => {
    const action = actionFor('ecommerce_preferred_shipping_country');
    const countries = [
      { code: 'KR', is_active: true },
      { code: 'US', is_active: true },
      { code: 'JP', is_active: false },
    ];
    const global = (enabled: unknown, preferredShippingCountry: unknown) => ({
      preferredShippingCountry,
      modules: { 'sirsoft-ecommerce': { shipping: { international_shipping_enabled: enabled, available_countries: countries } } },
    });

    // @scenario signup_path=user_register, module_state=installed
    // @effects signup_form_default_is_session_currency
    it('해외배송을 켰고 지금 국가가 활성 국가면 심는다', () => {
      expect(evalIf(action, global(true, 'US'))).toBe(true);
    });

    // @scenario signup_path=user_register, module_state=installed
    // @effects signup_form_default_is_session_currency
    it('해외배송을 껐거나 지금 국가가 비활성이면 심지 않는다', () => {
      expect(evalIf(action, global(false, 'US'))).toBe(false);
      expect(evalIf(action, global(true, 'JP'))).toBe(false);
      expect(evalIf(action, global(true, undefined))).toBe(false);
    });
  });
});

describe('가입 화면에 칸을 그리던 주입 조각은 없다', () => {
  // @scenario signup_path=user_register, module_state=installed
  // @effects signup_form_default_is_session_currency
  it('register-currency-field, register-shipping-country-field 조각 파일이 없다', () => {
    for (const name of ['register-currency-field', 'register-shipping-country-field']) {
      expect(fs.existsSync(path.join(extensionsDir, `${name}.json`))).toBe(false);
      expect(fs.existsSync(path.join(extensionsDir, `${name}.g7.html`))).toBe(false);
    }
  });

  // @scenario signup_path=user_register, module_state=installed
  // @effects signup_form_default_is_session_currency
  it('이커머스 조각 어느 것도 register_extension_fields 자리에 주입하지 않는다', () => {
    const injecting = fs.readdirSync(extensionsDir)
      .filter((file) => file.endsWith('.json'))
      .filter((file) => fs.readFileSync(path.join(extensionsDir, file), 'utf-8').includes('register_extension_fields'));
    expect(injecting).toEqual([]);
  });
});

describe('_register_form.json — 확장 주입 자리 유지', () => {
  const formPath = path.resolve(
    __dirname,
    '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/auth/_register_form.json',
  );
  const formJson = JSON.parse(fs.readFileSync(formPath, 'utf-8'));

  const hasAnchor = (node: any): boolean => {
    if (!node || typeof node !== 'object') return false;
    if (node.id === 'register_extension_fields') return true;
    return (node.children ?? []).some(hasAnchor);
  };

  // @scenario signup_path=user_register, module_state=installed
  // @effects signup_form_default_is_session_currency
  it('register_extension_fields 주입 자리가 폼에 남아 있다 (서드파티 호환)', () => {
    expect(hasAnchor(formJson)).toBe(true);
  });
});
