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

  it('가입폼(auth/register)을 대상으로 register_extension_fields 앵커에 주입한다', () => {
    expect(json.target_layout).toBe('auth/register');
    expect(json.injections[0].target_id).toBe('register_extension_fields');
  });

  it('통화 Select 가 주입되어 있다(name=preferred_currency 폼 바인딩)', () => {
    expect(select).toBeTruthy();
    expect(select.props.name).toBe('preferred_currency');
  });

  it('기본 선택값은 세션 통화(_global.preferredCurrency)를 1순위로 한다 (D-LOGIN-CUR)', () => {
    expect(select.props.value).toContain('_global.preferredCurrency');
    // 폴백: 기본통화(default_currency)까지
    expect(select.props.value).toContain('default_currency');
  });

  it('options 는 모듈 노출 글로벌에서 읽고 미설정 환율 통화를 제외한다 (§5)', () => {
    expect(select.props.options).toContain('_global.modules');
    expect(select.props.options).toContain('language_currency');
    expect(select.props.options).toContain('is_default');
    expect(select.props.options).toContain('exchange_rate');
  });

  it('이커머스 설치 게이트: currencies 존재 조건(if)으로만 렌더', () => {
    expect(rootComponent.if).toContain("_global.modules");
    expect(rootComponent.if).toContain('language_currency');
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

  it('register_extension_fields 주입 앵커가 폼에 존재한다', () => {
    expect(hasAnchor(formJson)).toBe(true);
  });
});
