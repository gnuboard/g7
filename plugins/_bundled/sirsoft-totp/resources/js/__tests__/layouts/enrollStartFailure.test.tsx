// @vitest-environment jsdom
/**
 * @file enrollStartFailure.test.tsx
 * @description 인증 앱 설정 — [등록 시작] 실패 응답의 화면 처리 (KVE-2026-3194, 1.0.3)
 *
 * 비밀번호가 틀리면(422) 문구는 비밀번호 칸 바로 아래에 보이고 입력값은 남는다(오타만 고쳐 다시 누르기).
 * 회원별 횟수를 넘기면(429) 상단 오류 상자에 안내가 뜨고, 서버가 알려 준 대기 시간 동안 시작 버튼이 잠겼다가
 * 그 뒤 스스로 풀린다. (버튼 폭은 카드의 세로 flex 정렬이 모든 폭에서 가득 채운다 — 브라우저 실측으로 확인, 클래스로 고정하지 않음)
 *
 * 배포 JSON 의 버튼 동작을 그대로 엔진에 태워 확인한다 — 사본 표현식이 아니라 실제 레이아웃이 SSoT 다.
 */

import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { actionsOf, findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import settingsLayout from '../../../layouts/admin/plugin_settings.json';

const WRONG_PASSWORD_MESSAGE = '현재 비밀번호가 올바르지 않습니다.';
const THROTTLED_MESSAGE = '비밀번호 확인을 너무 여러 번 실패했습니다. 1초 후 다시 시도해 주세요.';

const startButton = findById(settingsLayout, 'totp_security_start') as any;
const startAction = actionsOf(startButton)[0];
const passwordField = findById(settingsLayout, 'totp_security_intro_password_field') as any;

let utils: ReturnType<typeof createLayoutTest> | null = null;

/**
 * 테스트용 기본 컴포넌트를 등록한다.
 *
 * @returns 반환값 없음
 */
function registerBasics(): void {
  const registry: any = ComponentRegistry.getInstance();
  registry.registry = {};
  const tags: Record<string, string> = { Div: 'div', P: 'p', Span: 'span', Label: 'label', Button: 'button', Input: 'input' };
  for (const [name, tag] of Object.entries(tags)) {
    registry.registry[name] = {
      component: ({ text, children, ...props }: any) =>
        React.createElement(tag, props, tag === 'input' ? undefined : children || text),
      metadata: { name, type: 'basic' },
    };
  }
  registry.registry.Fragment = { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } };
}

/**
 * 등록 시작 엔드포인트만 주어진 상태·본문으로 응답하도록 fetch 를 바꿔 끼운다.
 *
 * @param status 응답 상태
 * @param body 응답 본문
 * @returns 반환값 없음
 */
function stubEnrollmentStart(status: number, body: Record<string, unknown>): void {
  globalThis.fetch = vi.fn(async (url: string) => {
    if (String(url).includes('/api/plugins/sirsoft-totp/enrollment')) {
      return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
    }

    return new Response(JSON.stringify({ success: true, data: {} }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  }) as any;
}

/**
 * 주어진 화면 상태로 컴포넌트를 띄운다.
 *
 * @param components 렌더할 컴포넌트 목록
 * @param local 초기 `_local`
 * @returns 렌더된 컨테이너
 */
async function mount(components: any[], local: Record<string, unknown>): Promise<HTMLElement> {
  utils = createLayoutTest(
    { version: '1.0.0', layout_name: 'totp_enroll_start', components } as any,
    { initialState: { _local: local } },
  );
  const { container } = await utils.render();

  return container;
}

beforeEach(() => {
  registerBasics();
  (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
});

afterEach(() => {
  utils?.cleanup();
  utils = null;
  (ComponentRegistry.getInstance() as any).registry = {};
  delete (window as any).__templateApp;
  vi.restoreAllMocks();
});

describe('[등록 시작] 실패 — 비밀번호 틀림(422)', () => {
  /**
   * @scenario case=enrollment_start_wrong_password
   * @effects start_wrong_password_shown_under_field
   */
  it('문구는 칸 아래 상태에 담기고 상단 상자는 비며, 입력한 비밀번호는 남는다', async () => {
    await mount([{ id: 'root', type: 'basic', name: 'Div' }], { currentPassword: 'typo-pass', busy: false });
    stubEnrollmentStart(422, { success: false, message: WRONG_PASSWORD_MESSAGE, errors: { current_password: [WRONG_PASSWORD_MESSAGE] } });

    await utils!.triggerAction(startAction);

    const local = utils!.getState()._local;
    expect(local.passwordError).toBe(WRONG_PASSWORD_MESSAGE);
    expect(local.error ?? null).toBeNull();
    expect(local.currentPassword).toBe('typo-pass');
    expect(local.busy).toBe(false);
    expect(local.startLocked).toBe(false);
  });

  /**
   * @scenario case=enrollment_start_wrong_password
   * @effects start_wrong_password_shown_under_field
   */
  it('칸 바로 아래에 빨간 문구 요소가 그려진다', async () => {
    const container = await mount([passwordField], { currentPassword: 'typo-pass', passwordError: WRONG_PASSWORD_MESSAGE });

    const input = container.querySelector('#totp_current_password');
    const error = container.querySelector('[role="alert"]');
    expect(input).not.toBeNull();
    expect(error?.textContent).toBe(WRONG_PASSWORD_MESSAGE);
    expect(error?.className).toContain('text-red-600');
    // 입력 칸 바로 다음 형제 — 안내 문구보다 먼저 보인다
    expect(input?.nextElementSibling).toBe(error);
  });
});

describe('[등록 시작] 실패 — 회원별 횟수 초과(429)', () => {
  /**
   * @scenario case=enrollment_password_throttled
   * @effects start_button_locked_while_throttled
   */
  it.each([
    [{ currentPassword: 'right-pass', busy: false, startLocked: true }, true],
    [{ currentPassword: 'right-pass', busy: false, startLocked: false }, false],
    [{ currentPassword: '', busy: false, startLocked: false }, true],
  ])('버튼 잠김 — 상태 %j → %s', async (local, expected) => {
    const container = await mount([startButton], local);

    expect((container.querySelector('button') as HTMLButtonElement).disabled).toBe(expected);
  });

  // 타이머 시험은 이 묶음의 마지막에 둔다 — 컴포넌트 동작이 등록한 타이머의 틱이 쓴 `_local` 값이 시험 하네스 정리 뒤에도
  // 다음 하네스의 첫 렌더에 남는 것이 관찰됐다(하네스 격리 문제, 운영 화면은 같은 화면의 잠금만 푼다).
  /**
   * @scenario case=enrollment_password_throttled
   * @effects start_button_locked_while_throttled
   */
  it('상단 상자에 안내가 뜨고 대기 시간 동안 버튼이 잠겼다가 스스로 풀린다', async () => {
    await mount([{ id: 'root', type: 'basic', name: 'Div' }], { currentPassword: 'right-pass', busy: false });
    stubEnrollmentStart(429, { success: false, message: THROTTLED_MESSAGE, errors: { retry_after: 1 } });

    await utils!.triggerAction(startAction);

    const locked = utils!.getState()._local;
    expect(locked.error).toBe(THROTTLED_MESSAGE);
    expect(locked.passwordError ?? null).toBeNull();
    expect(locked.startLocked).toBe(true);

    await new Promise((resolve) => setTimeout(resolve, 1300));

    expect(utils!.getState()._local.startLocked).toBe(false);
  });
});
