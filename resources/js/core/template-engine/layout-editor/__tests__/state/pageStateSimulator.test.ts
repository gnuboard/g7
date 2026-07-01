/**
 * pageStateSimulator.test.ts — applyInitialPatch 합성
 *
 * 검증:
 *  - sampleGlobal baseline 위에 state.global 부분 머지 (local/global 양쪽)
 *  - 편집 모드 외(isEditMode=false)면 baseline 그대로 (no-op 디그레이드)
 *  - patch 부재면 baseline 그대로
 *  - 상태 전환 시 불변 baseline 으로부터 재계산 → 이전 패치 자동 되돌림
 *  - baseline 불변(깊은 복제)
 */

import { describe, it, expect } from 'vitest';
import {
  applyInitialPatch,
  resolveSampleOverride,
  getFormErrors,
} from '../../state/pageStateSimulator';
import type { EditorStateItemSpec } from '../../spec/specTypes';

describe('applyInitialPatch — 페이지 상태 초기 패치 합성', () => {
  it('global/local baseline 위에 state 패치를 부분 머지한다', () => {
    const result = applyInitialPatch({
      globalBaseline: { currentUser: { uuid: 'x', is_admin: false }, settings: { theme: 'light' } },
      localBaseline: { step: 'idle' },
      patch: { global: { currentUser: { is_admin: true } }, local: { step: 'password' } },
    });
    // global: is_admin 만 덮고 uuid/settings 는 baseline 보존
    expect(result.global).toEqual({
      currentUser: { uuid: 'x', is_admin: true },
      settings: { theme: 'light' },
    });
    expect(result.local).toEqual({ step: 'password' });
  });

  it('isEditMode=false 면 패치를 적용하지 않고 baseline 을 반환한다 (no-op 디그레이드)', () => {
    const result = applyInitialPatch({
      globalBaseline: { a: 1 },
      localBaseline: { b: 2 },
      patch: { global: { a: 99 }, local: { b: 99 } },
      isEditMode: false,
    });
    expect(result.global).toEqual({ a: 1 });
    expect(result.local).toEqual({ b: 2 });
  });

  it('patch 가 없으면 baseline 만 반환한다 (states 미선언/scope 미매칭)', () => {
    const result = applyInitialPatch({
      globalBaseline: { a: 1 },
      localBaseline: { b: 2 },
      patch: null,
    });
    expect(result.global).toEqual({ a: 1 });
    expect(result.local).toEqual({ b: 2 });
  });

  it('상태 전환 — 불변 baseline 으로부터 재계산하므로 이전 패치가 자동 되돌려진다', () => {
    const globalBaseline = { mode: 'view', currentUser: { is_admin: false } };

    // 상태 A: is_admin=true 패치
    const stateA = applyInitialPatch({
      globalBaseline,
      patch: { global: { currentUser: { is_admin: true } } },
    });
    expect((stateA.global.currentUser as any).is_admin).toBe(true);

    // 상태 B 로 전환: mode 만 패치 — 같은 baseline 에서 재계산하면 A 의 is_admin 패치는 사라짐
    const stateB = applyInitialPatch({
      globalBaseline,
      patch: { global: { mode: 'edit' } },
    });
    expect((stateB.global.currentUser as any).is_admin).toBe(false);
    expect(stateB.global.mode).toBe('edit');
  });

  it('baseline 을 변경하지 않는다 (깊은 복제)', () => {
    const globalBaseline = { nested: { value: 1 } };
    applyInitialPatch({ globalBaseline, patch: { global: { nested: { value: 2 } } } });
    expect(globalBaseline.nested.value).toBe(1);
  });

  it('baseline/patch 미지정 시 빈 객체를 반환한다', () => {
    const result = applyInitialPatch({});
    expect(result.global).toEqual({});
    expect(result.local).toEqual({});
  });
});

describe('applyInitialPatch — formErrors 경로 주입 ', () => {
  it('_local. 접두 경로는 local baseline 에 접두사 제거 후 deep set 한다', () => {
    const result = applyInitialPatch({
      localBaseline: {},
      patch: null,
      formErrors: { '_local.errors.email': ['이메일 오류'], '_local.errors.name': ['이름 오류'] },
    });
    expect(result.local).toEqual({ errors: { email: ['이메일 오류'], name: ['이름 오류'] } });
    expect(result.global).toEqual({});
  });

  it('_global. 접두 경로는 global baseline 에 접두사 제거 후 deep set 한다', () => {
    const result = applyInitialPatch({
      globalBaseline: { settings: { theme: 'light' } },
      formErrors: { '_global.loginErrors.email': '로그인 실패', '_global.loginError': '실패 메시지' },
    });
    expect(result.global).toEqual({
      settings: { theme: 'light' },
      loginErrors: { email: '로그인 실패' },
      loginError: '실패 메시지',
    });
  });

  it('접두사 없는 경로는 local 에 그대로 주입한다 (레거시 단순 필드명 호환)', () => {
    const result = applyInitialPatch({
      formErrors: { email: '오류' },
    });
    expect(result.local).toEqual({ email: '오류' });
  });

  it('formErrors 주입 시 baseline 의 기존 형제 키를 보존한다', () => {
    const result = applyInitialPatch({
      localBaseline: { errors: { phone: ['기존'] }, mode: 'edit' },
      formErrors: { '_local.errors.email': ['신규'] },
    });
    // 같은 errors 객체 안에 phone 보존 + email 추가
    expect(result.local).toEqual({ errors: { phone: ['기존'], email: ['신규'] }, mode: 'edit' });
  });

  it('isEditMode=false 면 formErrors 도 주입하지 않는다 (디그레이드)', () => {
    const result = applyInitialPatch({
      isEditMode: false,
      formErrors: { '_local.errors.email': ['x'] },
    });
    expect(result.local).toEqual({});
  });

  it('initialState 패치와 formErrors 를 함께 합성한다', () => {
    const result = applyInitialPatch({
      localBaseline: {},
      patch: { local: { mode: 'edit' } },
      formErrors: { '_local.errors.email': ['오류'] },
    });
    expect(result.local).toEqual({ mode: 'edit', errors: { email: ['오류'] } });
  });

  it('baseline 의 중첩 객체를 변형하지 않는다 (formErrors deep set 도 복제)', () => {
    const localBaseline = { errors: { phone: ['기존'] } };
    applyInitialPatch({
      localBaseline,
      formErrors: { '_local.errors.email': ['신규'] },
    });
    expect(localBaseline.errors).toEqual({ phone: ['기존'] });
  });

  // 점을 포함한 리터럴 leaf 키(대괄호 표기). /shop/checkout 의
  // 주문자 입력칸은 `_local.errors?.['orderer.name']` 처럼 키 자체에 점이 박혀 있어
  // dot-split 으로는 표현할 수 없었다(중첩 `errors.orderer.name` 으로 오해석).
  // `['...']` 안의 점은 칸막이가 아니라 키의 일부로 보존해야 한다.
  it('대괄호 표기 안의 점은 리터럴 leaf 키로 보존한다 (점 포함 필드명 — /shop/checkout)', () => {
    const result = applyInitialPatch({
      localBaseline: {},
      formErrors: { "_local.errors['orderer.name']": ['이름을 입력해 주세요'] },
    });
    // errors 서랍 안에 'orderer.name' 이라는 단일 키(점 포함) — 중첩 아님
    expect(result.local).toEqual({ errors: { 'orderer.name': ['이름을 입력해 주세요'] } });
  });

  it('대괄호 표기와 일반 점 구분자를 혼용한다 (errors.[키] / errors → 점키)', () => {
    const result = applyInitialPatch({
      localBaseline: {},
      formErrors: {
        "_local.errors['orderer.name']": ['이름 오류'],
        "_local.errors['orderer.phone']": ['전화 오류'],
        '_local.errors.email': ['이메일 오류'],
      },
    });
    expect(result.local).toEqual({
      errors: {
        'orderer.name': ['이름 오류'],
        'orderer.phone': ['전화 오류'],
        email: ['이메일 오류'],
      },
    });
  });

  it('쌍따옴표 대괄호 표기도 동일하게 처리한다 (["..."])', () => {
    const result = applyInitialPatch({
      localBaseline: {},
      formErrors: { '_local.errors["orderer.name"]': ['오류'] },
    });
    expect(result.local).toEqual({ errors: { 'orderer.name': ['오류'] } });
  });

  it('대괄호 표기 안의 점 포함 키도 기존 형제 키를 보존한다', () => {
    const result = applyInitialPatch({
      localBaseline: { errors: { email: ['기존'] } },
      formErrors: { "_local.errors['orderer.name']": ['신규'] },
    });
    expect(result.local).toEqual({ errors: { email: ['기존'], 'orderer.name': ['신규'] } });
  });
});

describe('applyInitialPatch — query/route 패치 (전수조사 미커버 발굴)', () => {
  it('query 패치는 queryBaseline(빈 객체) 위에 머지된다 (진입 맥락 변종)', () => {
    const r = applyInitialPatch({
      queryBaseline: {},
      patch: { query: { tab: 'seo', error: 'duplicate' } },
    });
    expect(r.query).toEqual({ tab: 'seo', error: 'duplicate' });
  });

  it('route 패치 값은 baseline 위에 덮어쓰고, null 값 키는 제거한다 (신규 작성 모드)', () => {
    const r = applyInitialPatch({
      routeBaseline: { id: '123', slug: 'abc' },
      patch: { route: { id: null } },
    });
    // id 제거(신규 작성 — {{!route.id}}), slug 보존
    expect(r.route).toEqual({ slug: 'abc' });
  });

  it('route 패치로 값 교체도 가능하다', () => {
    const r = applyInitialPatch({
      routeBaseline: { id: '1' },
      patch: { route: { id: '999' } },
    });
    expect(r.route).toEqual({ id: '999' });
  });

  it('patch 없으면 query/route baseline 그대로 (기존 동작 보존)', () => {
    const r = applyInitialPatch({ queryBaseline: { a: 1 }, routeBaseline: { id: 'x' } });
    expect(r.query).toEqual({ a: 1 });
    expect(r.route).toEqual({ id: 'x' });
  });

  it('isEditMode=false 면 query/route 패치도 적용하지 않는다', () => {
    const r = applyInitialPatch({
      isEditMode: false,
      queryBaseline: {},
      routeBaseline: { id: 'x' },
      patch: { query: { tab: 'seo' }, route: { id: null } },
    });
    expect(r.query).toEqual({});
    expect(r.route).toEqual({ id: 'x' });
  });

  it('query/route baseline 을 변형하지 않는다 (깊은 복제)', () => {
    const queryBaseline = { nested: { v: 1 } };
    const routeBaseline = { id: '1' };
    applyInitialPatch({ queryBaseline, routeBaseline, patch: { query: { nested: { v: 2 } }, route: { id: null } } });
    expect(queryBaseline.nested.v).toBe(1);
    expect(routeBaseline.id).toBe('1');
  });
});

describe('resolveSampleOverride — sampleData 오버라이드 어댑터 ', () => {
  it('byDataSourceId 오버라이드가 있으면 그 스펙을 반환한다', () => {
    const item: EditorStateItemSpec = {
      id: 's1',
      sampleDataOverrides: { byDataSourceId: { me: { data: null } } },
    };
    expect(resolveSampleOverride(item)).toEqual({ byDataSourceId: { me: { data: null } } });
  });

  it('빈 오버라이드({})는 undefined 로 디그레이드한다', () => {
    expect(resolveSampleOverride({ id: 's', sampleDataOverrides: {} })).toBeUndefined();
  });

  it('item/오버라이드 부재 시 undefined', () => {
    expect(resolveSampleOverride(null)).toBeUndefined();
    expect(resolveSampleOverride({ id: 's' })).toBeUndefined();
  });
});

describe('getFormErrors — 폼 검증 실패 맵 ', () => {
  it('formErrors 맵을 그대로 반환한다', () => {
    const item: EditorStateItemSpec = {
      id: 's',
      formErrors: { '_local.errors.email': ['오류'] },
    };
    expect(getFormErrors(item)).toEqual({ '_local.errors.email': ['오류'] });
  });

  it('빈 맵/부재 시 undefined (no-op 디그레이드)', () => {
    expect(getFormErrors({ id: 's', formErrors: {} })).toBeUndefined();
    expect(getFormErrors({ id: 's' })).toBeUndefined();
    expect(getFormErrors(null)).toBeUndefined();
  });
});

/**
 * sampleGlobal._local 을 localBaseline 으로 쓰면 폼 시드가 전 상태에 공통 적용된다.
 * checkout 의 받는분/주소/배송메모를 상태별 중복 없이 채우는 방안
 * (sampleGlobal._local baseline). PreviewCanvas 가 baselineSeed._local 을
 * localBaseline 으로 전달하는 경로를 단위로 검증한다.
 */
describe('sampleGlobal._local baseline — checkout 폼 시드 전 상태 공통', () => {
  // sampleGlobal.json 의 _local 과 동일 형태의 baseline 시드
  const shippingSeed = {
    selectedAddressId: 1,
    shipping: { recipient_name: '샘플 수령인', zipcode: '06236', country_code: 'KR' },
    shippingMemo: 'door',
  };

  it('normal(패치 없음) 상태에서 baseline 폼 시드가 그대로 흐른다', () => {
    const result = applyInitialPatch({
      localBaseline: shippingSeed,
      patch: null,
      isEditMode: true,
    });
    expect(result.local.selectedAddressId).toBe(1);
    expect((result.local.shipping as Record<string, unknown>).recipient_name).toBe('샘플 수령인');
    expect(result.local.shippingMemo).toBe('door');
  });

  it('payment_error(query 패치만) 상태에서도 폼 시드가 유지된다 (날아가지 않음)', () => {
    const result = applyInitialPatch({
      localBaseline: shippingSeed,
      patch: { local: { orderError: true }, query: { error: 'confirm_failed' } },
      isEditMode: true,
    });
    // 상태 고유 패치(orderError)와 baseline 시드(shipping)가 공존
    expect(result.local.orderError).toBe(true);
    expect((result.local.shipping as Record<string, unknown>).recipient_name).toBe('샘플 수령인');
    expect(result.query.error).toBe('confirm_failed');
  });

  it('validation_failed(formErrors) 상태에서 폼 시드 + 검증 오류가 공존한다', () => {
    const result = applyInitialPatch({
      localBaseline: shippingSeed,
      formErrors: { "_local.errors['orderer.name']": ['주문자 이름을 입력해 주세요'] },
      isEditMode: true,
    });
    // baseline 배송지 시드 보존
    expect((result.local.shipping as Record<string, unknown>).zipcode).toBe('06236');
    // 검증 오류는 점-포함 키로 그 위에 주입
    expect((result.local.errors as Record<string, unknown>)['orderer.name']).toEqual([
      '주문자 이름을 입력해 주세요',
    ]);
  });

  it('빈 local 패치({})는 baseline 폼 시드를 그대로 보존한다 (A안 마커 트리거)', () => {
    // normal 상태의 `initialState.local: {}` 마커가 PreviewCanvas 의 _localInit
    // force-inject 를 트리거하되, baseline(sampleGlobal._local) 시드는 덮지 않는다.
    const result = applyInitialPatch({
      localBaseline: shippingSeed,
      patch: { local: {} },
      isEditMode: true,
    });
    expect(result.local.selectedAddressId).toBe(1);
    expect((result.local.shipping as Record<string, unknown>).recipient_name).toBe('샘플 수령인');
    expect(result.local.shippingMemo).toBe('door');
  });

  it('isEditMode=false(운영 렌더) 면 폼 시드를 적용하지 않는다 (호스트 영향 0)', () => {
    const result = applyInitialPatch({
      localBaseline: shippingSeed,
      patch: { local: { orderError: true } },
      isEditMode: false,
    });
    // baseline 그대로 반환되며 patch 미적용 — 단, localBaseline 자체는 운영에서
    // PreviewCanvas 가 호출되지 않으므로(편집기 전용) 흐르지 않는다. 게이트 동작만 검증.
    expect(result.local.orderError).toBeUndefined();
  });
});
