/**
 * coreProps.isolated.test.ts — coreProps isolatedState/isolatedScopeId SSoT 가드
 *
 * 검증:
 *  ① CorePropKey 에 isolatedState/isolatedScopeId 포함, apply 타입 nodeKey
 *  ② props 오염 방지(propValue 아님)
 *  ③ core-scopeid 위젯 선언
 *  ④ i18n 키 네임스페이스
 */

import { describe, it, expect } from 'vitest';
import { CORE_PROP_CONTROLS, DEFAULT_CORE_PROP_KEYS } from '../../spec/coreProps';

describe('coreProps — 격리(isolatedState/isolatedScopeId)', () => {
  it('isolatedState — toggle 위젯 + nodeKey apply', () => {
    const c = CORE_PROP_CONTROLS.isolatedState;
    expect(c.widget).toBe('toggle');
    const apply = c.apply as unknown as { type: string; nodeKey?: string };
    expect(apply.type).toBe('nodeKey');
    expect(apply.nodeKey).toBe('isolatedState');
  });

  it('isolatedScopeId — core-scopeid 위젯 + nodeKey apply', () => {
    const c = CORE_PROP_CONTROLS.isolatedScopeId;
    expect(c.widget).toBe('core-scopeid');
    const apply = c.apply as unknown as { type: string; nodeKey?: string };
    expect(apply.type).toBe('nodeKey');
    expect(apply.nodeKey).toBe('isolatedScopeId');
  });

  it('둘 다 props 오염 방지(propValue 아님)', () => {
    expect((CORE_PROP_CONTROLS.isolatedState.apply as unknown as { type: string }).type).not.toBe('propValue');
    expect((CORE_PROP_CONTROLS.isolatedScopeId.apply as unknown as { type: string }).type).not.toBe('propValue');
  });

  it('DEFAULT_CORE_PROP_KEYS 에 미포함(격리는 별도 그룹 — id 만 기본)', () => {
    expect(DEFAULT_CORE_PROP_KEYS).not.toContain('isolatedState');
    expect(DEFAULT_CORE_PROP_KEYS).not.toContain('isolatedScopeId');
  });

  it('i18n 키 네임스페이스', () => {
    expect(CORE_PROP_CONTROLS.isolatedState.label).toContain('layout_editor.core_props.isolatedState');
    expect(CORE_PROP_CONTROLS.isolatedScopeId.label).toContain('layout_editor.core_props.isolatedScopeId');
  });
});
