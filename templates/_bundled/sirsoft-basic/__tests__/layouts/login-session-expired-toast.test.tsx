// e2e:allow 레이아웃 JSON 판독을 모양(actions 배열 첨자)에서 뜻(layoutShapeUtils.actionsOf)으로 옮긴 테스트 전용 리팩토링 — 대상 레이아웃·핸들러·런타임 동작은 바뀌지 않았고, 단언이 보는 명제도 동일하다 (U-SHAPEASSERT 판정 ⒜ · D67 ⓐ 미전환 잔여분 해소).
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
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

describe('sirsoft-basic 로그인 레이아웃 - 세션 만료 토스트 init_action (Issue #301)', () => {
  it('최상위 init_actions 가 하나 이상 있어야 한다', () => {
    // 컨테이너 모양(배열이냐 단일 객체냐)은 단언하지 않는다 — 컴파일러가 단일 원소를 객체로
    // 접으므로 그 모양을 외우면 다음 굽기에서 깨진다(D67 ⓐ · U-SHAPEASSERT).
    expect(asArray((loginJson as any).init_actions).length).toBeGreaterThan(0);
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
