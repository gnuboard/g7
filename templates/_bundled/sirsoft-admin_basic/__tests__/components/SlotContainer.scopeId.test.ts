/**
 * SlotContainer.scopeId.test.ts — 슬롯 주입 컴포넌트 root id 스코프
 *
 * 같은 슬롯 컴포넌트가 여러 SlotContainer(헤더 데스크톱/모바일)에서 렌더되면 주입
 * 컴포넌트의 정적 root id 가 컨테이너마다 같은 값으로 중복 출력되어 HTML id 유일성을
 * 위반한다(통화 셀렉터 ext_admin_header_currency_selector ×2). scopeSlotChildDef 가
 * 컨테이너 고유 id 로 root id 를 스코프해 고유화하는지 검증.
 */

import { describe, it, expect } from 'vitest';
import { scopeSlotChildDef } from '../../src/components/composite/SlotContainer';

describe('scopeSlotChildDef — 슬롯 주입 id 스코프', () => {
  it('컨테이너 id + 컴포넌트 id 가 있으면 {id}__{containerId} 로 스코프한다', () => {
    const def = { id: 'ext_admin_header_currency_selector', name: 'Div' };
    const desktop = scopeSlotChildDef(def, 'header_currency_slot_desktop');
    const mobile = scopeSlotChildDef(def, 'header_currency_slot_mobile');
    expect(desktop.id).toBe('ext_admin_header_currency_selector__header_currency_slot_desktop');
    expect(mobile.id).toBe('ext_admin_header_currency_selector__header_currency_slot_mobile');
    // 두 컨테이너 결과가 서로 달라 중복 방지
    expect(desktop.id).not.toBe(mobile.id);
  });

  it('원본 컴포넌트 정의를 변형하지 않는다(불변)', () => {
    const def = { id: 'sel', name: 'Div', props: { x: 1 } };
    const scoped = scopeSlotChildDef(def, 'slot_a');
    expect(def.id).toBe('sel'); // 원본 보존
    expect(scoped).not.toBe(def); // 새 객체
    expect(scoped.name).toBe('Div');
    expect(scoped.props).toEqual({ x: 1 });
  });

  it('컨테이너 id 가 없으면 원본 그대로 반환(무영향)', () => {
    const def = { id: 'sel', name: 'Div' };
    expect(scopeSlotChildDef(def, undefined)).toBe(def);
    expect(scopeSlotChildDef(def, '')).toBe(def);
  });

  it('컴포넌트 root id 가 없으면 원본 그대로 반환(무영향)', () => {
    const noId = { name: 'Div' };
    expect(scopeSlotChildDef(noId, 'slot_a')).toBe(noId);
    const emptyId = { id: '', name: 'Div' };
    expect(scopeSlotChildDef(emptyId, 'slot_a')).toBe(emptyId);
  });

  it('id 가 문자열이 아니면 스코프하지 않는다', () => {
    const numId = { id: 123 as unknown as string, name: 'Div' };
    expect(scopeSlotChildDef(numId, 'slot_a')).toBe(numId);
  });
});
