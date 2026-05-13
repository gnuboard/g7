/**
 * @file admin-login-session-expired-toast.test.tsx
 * @description 관리자 로그인 레이아웃 세션 만료 토스트 init_action 구조 검증 (Issue #301)
 *
 * 코어 TemplateApp 가 401 fetch 실패 시 `?reason=session_expired` 쿼리와 함께
 * 관리자 로그인 페이지로 리다이렉트하면 init_actions 의 토스트가 트리거된다.
 * 본 테스트는 그 init_action 의 JSON 구조 무결성을 보장한다.
 */

import { describe, it, expect } from 'vitest';
import adminLoginJson from '../../layouts/admin_login.json';

describe('sirsoft-admin_basic 로그인 레이아웃 - 세션 만료 토스트 init_action (Issue #301)', () => {
  it('최상위 init_actions 배열이 존재해야 한다', () => {
    expect(Array.isArray((adminLoginJson as any).init_actions)).toBe(true);
    expect((adminLoginJson as any).init_actions.length).toBeGreaterThan(0);
  });

  it('session_expired 토스트 액션이 login_page_wrapper 의 lifecycle.onMount 에 등록되어야 한다', () => {
    // engine-v1.47.x 명문화: init_actions 는 첫 렌더 전에 실행되어 globalState 갱신이
    // React 리렌더로 반영되지 않을 수 있음. 대신 wrapper Div 의 lifecycle.onMount 사용 →
    // 마운트 후 실행이라 Toast 컴포넌트와 globalStateUpdater 모두 준비된 상태.
    const components = (adminLoginJson as any).components as any[];
    const wrapper = components.find((c) => c.id === 'login_page_wrapper');
    expect(wrapper).toBeDefined();
    expect(Array.isArray(wrapper.lifecycle?.onMount)).toBe(true);

    const toastAction = wrapper.lifecycle.onMount.find(
      (a: any) => a.handler === 'toast' && typeof a.if === 'string' && a.if.includes('session_expired')
    );
    expect(toastAction).toBeDefined();
    // if 표현식은 전체를 {{}} 로 감싸야 ConditionEvaluator 가 식으로 평가한다.
    // 1) "{{route?.query?.reason}} === 'session_expired'" — {{}} 부분만 보간 후
    //    reason 부재 시 " === 'session_expired'" 같은 비-빈 문자열 → truthy 회귀
    // 2) "route?.query?.reason === 'session_expired'" — {{}} 없음 → 원본 문자열 그대로 → 항상 truthy 회귀
    // 3) "{{route?.query?.reason === 'session_expired'}}" ← 정답: 식 전체를 평가
    expect(toastAction.if).toBe("{{query?.reason === 'session_expired'}}");
    expect(toastAction.if.startsWith('{{')).toBe(true);
    expect(toastAction.if.endsWith('}}')).toBe(true);
    // 잘못된 형태 회귀 차단
    // 1) {{}} 부분 보간 — reason 부재 시 비-빈 문자열 → 항상 truthy 회귀
    expect(toastAction.if).not.toBe("{{route?.query?.reason}} === 'session_expired'");
    // 2) {{}} 미사용 — 원본 문자열 그대로 → 항상 truthy 회귀
    expect(toastAction.if).not.toBe("route?.query?.reason === 'session_expired'");
    // 3) route?.query 경로 — G7 컨텍스트는 query 가 root 에 직접 노출됨 (route.query 가 아님)
    //    → 항상 undefined → 비교 결과 false → 토스트 미발화 회귀
    expect(toastAction.if).not.toBe("{{route?.query?.reason === 'session_expired'}}");
    expect(toastAction.params).toMatchObject({
      type: 'warning',
      message: '$t:auth.session_expired_toast',
    });
  });

  it('init_actions 의 toast 발화는 제거되었어야 한다 (lifecycle.onMount 로 이전됨, 회귀 차단)', () => {
    const initActions = (adminLoginJson as any).init_actions as any[];
    const toastInInit = initActions.find(
      (a) => a.handler === 'toast' && typeof a.if === 'string' && a.if.includes('session_expired')
    );
    expect(toastInInit).toBeUndefined();
  });

  it('기존 init_action(initTheme, setState)는 그대로 보존되어야 한다 (회귀 방지)', () => {
    const initActions = (adminLoginJson as any).init_actions as any[];

    const themeAction = initActions.find((a) => a.handler === 'initTheme');
    expect(themeAction).toBeDefined();

    const setStateAction = initActions.find((a) => a.handler === 'setState');
    expect(setStateAction).toBeDefined();
    expect(setStateAction.params?.target).toBe('local');
    expect(setStateAction.params?.loginForm).toBeDefined();
  });

  it('Toast 컴포넌트가 components 최상단에 마운트되어야 한다 (independent layout, _admin_base 미상속)', () => {
    // admin_login 은 _admin_base 를 extends 하지 않는 독립 레이아웃이므로,
    // toast 핸들러가 _global.toasts 에 push 한 메시지를 렌더할 Toast 컴포넌트가
    // 레이아웃 자체에 포함되어야 한다. 누락 시 init_actions 의 toast 가 발화돼도
    // 화면에 노출되지 않는 회귀 발생 (DB 토큰 강제 삭제 시나리오).
    const components = (adminLoginJson as any).components as any[];
    const toastComponent = components[0];
    expect(toastComponent).toBeDefined();
    expect(toastComponent.name).toBe('Toast');
    expect(toastComponent.type).toBe('composite');
    expect(toastComponent.props?.toasts).toBe('{{_global.toasts}}');
  });

  it('번역 키가 i18n 자원에 등록되어 있어야 한다 (ko/en partial)', async () => {
    const koAuth = await import('../../lang/partial/ko/auth.json');
    const enAuth = await import('../../lang/partial/en/auth.json');

    expect((koAuth as any).default.session_expired_toast).toBe(
      '세션이 만료되었습니다. 다시 로그인해 주세요.'
    );
    expect((enAuth as any).default.session_expired_toast).toBe(
      'Your session has expired. Please log in again.'
    );
  });
});
