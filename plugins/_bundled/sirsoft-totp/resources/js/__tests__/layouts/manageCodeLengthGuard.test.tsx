// @vitest-environment jsdom
/**
 * @file manageCodeLengthGuard.test.tsx
 * @description 인증 앱 관리 — 해제·백업 코드 재발급 버튼의 코드 길이 잠금
 *
 * 서버는 코드를 6자 이상으로만 받는데(min:6), 버튼은 한 글자만 쳐도 열려 눌러 봐야
 * 검증 오류가 났다. 배포 JSON 의 두 버튼을 그대로 렌더해 확인한다.
 */

import React from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import settingsLayout from '../../../layouts/admin/plugin_settings.json';

const BUTTON_IDS = ['totp_security_regenerate', 'totp_security_disable'];

let utils: ReturnType<typeof createLayoutTest> | null = null;

beforeEach(() => {
  const registry: any = ComponentRegistry.getInstance();
  registry.registry = {};
  for (const [name, tag] of Object.entries({ Button: 'button', Span: 'span' })) {
    registry.registry[name] = {
      component: ({ text, children, ...props }: any) => React.createElement(tag, props, children || text),
      metadata: { name, type: 'basic' },
    };
  }
  registry.registry.Fragment = { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } };
});

afterEach(() => {
  utils?.cleanup();
  utils = null;
  (ComponentRegistry.getInstance() as any).registry = {};
});

/**
 * 입력한 코드로 두 버튼을 렌더하고 잠김 여부를 돌려준다.
 *
 * @param code 입력한 코드
 * @returns 버튼 id → 잠김 여부
 */
async function disabledStates(code: string): Promise<Record<string, boolean>> {
  utils = createLayoutTest(
    { version: '1.0.0', layout_name: 'totp_manage', components: BUTTON_IDS.map((id) => findById(settingsLayout, id)) } as any,
    { initialState: { _local: { code, busy: false } } },
  );
  const { container } = await utils.render();
  const buttons = [...container.querySelectorAll('button')] as HTMLButtonElement[];
  utils.cleanup();
  utils = null;
  return Object.fromEntries(BUTTON_IDS.map((id, index) => [id, buttons[index].disabled]));
}

describe('인증 앱 해제·재발급 코드 길이', () => {
  /**
   * @scenario code_length=short
   * @effects manage_buttons_require_six_characters
   */
  it.each(['1', '12345', '  12345  '])('코드가 6자 미만(%j)이면 두 버튼 모두 잠긴다', async (code) => {
    expect(await disabledStates(code)).toEqual({ totp_security_regenerate: true, totp_security_disable: true });
  });

  /**
   * @scenario code_length=enough
   * @effects manage_buttons_require_six_characters
   */
  it.each(['123456', 'ABCD-EFGH'])('코드가 6자 이상(%j)이면 두 버튼이 열린다', async (code) => {
    expect(await disabledStates(code)).toEqual({ totp_security_regenerate: false, totp_security_disable: false });
  });
});
