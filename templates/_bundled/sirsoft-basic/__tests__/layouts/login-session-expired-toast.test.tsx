/**
 * @file login-session-expired-toast.test.tsx
 * @description 로그인 레이아웃 세션 만료 토스트 init_action 구조 검증 (Issue #301)
 *
 * 코어 TemplateApp 가 401 fetch 실패 시 `?reason=session_expired` 쿼리와 함께
 * 로그인 페이지로 리다이렉트하면 init_actions 의 토스트가 트리거된다.
 * 본 테스트는 그 init_action 의 JSON 구조 무결성을 보장한다.
 */

import { describe, it, expect } from 'vitest';
import loginJson from '../../layouts/auth/login.json';

describe('sirsoft-basic 로그인 레이아웃 - 세션 만료 토스트 init_action (Issue #301)', () => {
  it('최상위 init_actions 배열이 존재해야 한다', () => {
    expect(Array.isArray((loginJson as any).init_actions)).toBe(true);
    expect((loginJson as any).init_actions.length).toBeGreaterThan(0);
  });

  it('session_expired 토스트 액션이 정확한 if 조건과 핸들러로 등록되어야 한다', () => {
    const initActions = (loginJson as any).init_actions as any[];
    const toastAction = initActions.find(
      (a) => a.handler === 'toast' && typeof a.if === 'string' && a.if.includes('session_expired')
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
