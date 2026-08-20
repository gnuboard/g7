/**
 * coreProps.dataKey.test.ts — coreProps dataKey SSoT 가드
 *
 * 검증:
 *  ① CorePropKey 에 dataKey 포함 + CORE_PROP_CONTROLS.dataKey 정의(위젯 core-datakey, nodeKey apply)
 *  ② DEFAULT_CORE_PROP_KEYS 에 dataKey 미포함(id 만, opt-in 보장)
 *  ③ apply 가 propValue 아님(props 오염 방지 가드)
 *  ④ resolveCorePropKeys — 미선언→['id'] / ['id','dataKey']→둘 / false→[]
 */

import { describe, it, expect } from 'vitest';
import {
  CORE_PROP_CONTROLS,
  DEFAULT_CORE_PROP_KEYS,
  resolveCorePropKeys,
} from '../../spec/coreProps';

describe('coreProps — dataKey', () => {
  it('CORE_PROP_CONTROLS.dataKey 정의(core-datakey 위젯, nodeKey apply)', () => {
    const c = CORE_PROP_CONTROLS.dataKey;
    expect(c).toBeDefined();
    expect(c.widget).toBe('core-datakey');
    const apply = c.apply as unknown as { type: string; nodeKey?: string };
    expect(apply.type).toBe('nodeKey');
    expect(apply.nodeKey).toBe('dataKey');
  });

  it('DEFAULT_CORE_PROP_KEYS 에 dataKey 미포함(id 만 — opt-in)', () => {
    expect(DEFAULT_CORE_PROP_KEYS).toEqual(['id']);
    expect(DEFAULT_CORE_PROP_KEYS).not.toContain('dataKey');
  });

  it('apply 가 propValue 아님(props 오염 방지)', () => {
    const apply = CORE_PROP_CONTROLS.dataKey.apply as unknown as { type: string };
    expect(apply.type).not.toBe('propValue');
  });

  it('resolveCorePropKeys — 미선언/부분/opt-out', () => {
    expect(resolveCorePropKeys(undefined)).toEqual(['id']);
    expect(resolveCorePropKeys(['id', 'dataKey'])).toEqual(['id', 'dataKey']);
    expect(resolveCorePropKeys(false)).toEqual([]);
    // 미지 키는 무시.
    expect(resolveCorePropKeys(['id', 'bogus'])).toEqual(['id']);
  });

  it('라벨/placeholder i18n 키가 layout_editor.core_props.dataKey 네임스페이스', () => {
    expect(CORE_PROP_CONTROLS.dataKey.label).toContain('layout_editor.core_props.dataKey');
    expect(CORE_PROP_CONTROLS.dataKey.placeholder).toContain('layout_editor.core_props.dataKey');
  });
});
