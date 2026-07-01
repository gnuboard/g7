/**
 * flexModel.test.ts — 정렬 박스(flex) 모델 + computed-style 자동 감지
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import {
  getFlexEditorRole,
  isRenderedAsFlex,
  resolveFlexContainerMode,
  isNodeFlexEnabled,
  FLEX_CONTAINER_CONTROL_KEYS,
  FLEX_ITEM_CONTROL_KEYS,
} from '../../spec/flexModel';
import type { EditorControlSpec } from '../../spec/specTypes';

/** flexEnable 컨트롤 모사 — classToken `flex` (editor-spec 의 flexEnable 과 동일 형태) */
const ENABLE_CONTROL: EditorControlSpec = {
  widget: 'toggle',
  group: 'flex-enable',
  onValue: 'flex',
  apply: { type: 'classToken', tokens: ['flex'] } as unknown as string,
};

afterEach(() => vi.restoreAllMocks());

describe('getFlexEditorRole', () => {
  it.each(['container', 'item', 'auto'])('%s 역할을 읽는다', (role) => {
    expect(getFlexEditorRole({ flexEditor: role as 'container' })).toBe(role);
  });
  it('미선언/잘못된 값 → null', () => {
    expect(getFlexEditorRole({})).toBeNull();
    expect(getFlexEditorRole(null)).toBeNull();
    expect(getFlexEditorRole({ flexEditor: 'nope' as 'container' })).toBeNull();
  });
});

describe('isRenderedAsFlex — computed style 기반(className 토큰 아님)', () => {
  it('display:flex → true', () => {
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'flex' } as CSSStyleDeclaration);
    expect(isRenderedAsFlex(document.createElement('div'))).toBe(true);
  });
  it('display:inline-flex → true', () => {
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'inline-flex' } as CSSStyleDeclaration);
    expect(isRenderedAsFlex(document.createElement('div'))).toBe(true);
  });
  it('display:block → false', () => {
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'block' } as CSSStyleDeclaration);
    expect(isRenderedAsFlex(document.createElement('div'))).toBe(false);
  });
  it('null 엘리먼트 → false', () => {
    expect(isRenderedAsFlex(null)).toBe(false);
  });
});

describe('isNodeFlexEnabled — 노드 파생(enable 컨트롤 역해석)', () => {
  it('className 에 flex 토큰 있으면 true', () => {
    expect(isNodeFlexEnabled({ props: { className: 'w-full flex gap-2' } }, ENABLE_CONTROL)).toBe(true);
  });
  it('flex 토큰 없으면 false', () => {
    expect(isNodeFlexEnabled({ props: { className: 'w-full max-w-md p-8' } }, ENABLE_CONTROL)).toBe(false);
  });
  it('enable 컨트롤/노드 미공급 → false', () => {
    expect(isNodeFlexEnabled(null, ENABLE_CONTROL)).toBe(false);
    expect(isNodeFlexEnabled({ props: { className: 'flex' } }, null)).toBe(false);
  });

  // 회귀 — flexEnable 이 on/off **options** 를 가질 때(실제 editor-spec 형태),
  // 해제(off=block) 후 className 에 off 토큰(block)이 남으면 역해석이 off 옵션에 매칭되어
  // value='block', matched=true 가 된다. 이를 "flex enabled"로 오인하면 해제 후에도 컨테이너
  // 컨트롤이 유지되고 "정렬 박스로 만들기" 버튼이 복귀하지 않는다. onValue 와 일치할 때만 enabled.
  describe('on/off options 컨트롤 — off 토큰은 enabled 아님(해제 토글 회귀)', () => {
    const ENABLE_ONOFF_CONTROL = {
      widget: 'segmented',
      group: 'flex-enable',
      onValue: 'flex',
      apply: { type: 'classToken', tokens: ['flex'] },
      options: [
        { value: 'flex', apply: { type: 'classToken', tokens: ['flex'] } },
        { value: 'block', apply: { type: 'classToken', tokens: ['block'] } },
      ],
    } as unknown as EditorControlSpec;

    it('on 토큰(flex) → true', () => {
      expect(isNodeFlexEnabled({ props: { className: 'w-full flex gap-2' } }, ENABLE_ONOFF_CONTROL)).toBe(true);
    });
    it('off 토큰(block) → false (해제 후 만들기 버튼 복귀)', () => {
      expect(isNodeFlexEnabled({ props: { className: 'w-full block p-8' } }, ENABLE_ONOFF_CONTROL)).toBe(false);
    });
    it('on/off 토큰 모두 없음 → false', () => {
      expect(isNodeFlexEnabled({ props: { className: 'w-full p-8' } }, ENABLE_ONOFF_CONTROL)).toBe(false);
    });
  });
});

describe('resolveFlexContainerMode', () => {
  it('container → 항상 컨테이너 컨트롤(해제 버튼 없음)', () => {
    expect(resolveFlexContainerMode('container', null)).toEqual({ showContainer: true, showEnableButton: false, showDisableButton: false });
  });
  it('item → 컨테이너 컨트롤 비노출', () => {
    expect(resolveFlexContainerMode('item', null)).toEqual({ showContainer: false, showEnableButton: false, showDisableButton: false });
  });
  it('auto + 노드 파생 flex(enable 컨트롤 있음) → 컨테이너 컨트롤 + 해제 버튼', () => {
    // 노드 파생 우선 — computed style 은 보지 않는다(stale 방지). el 은 block 이어도 무관.
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'block' } as CSSStyleDeclaration);
    expect(resolveFlexContainerMode('auto', document.createElement('div'), true, true)).toEqual({
      showContainer: true,
      showEnableButton: false,
      showDisableButton: true,
    });
  });
  it('auto + 노드 파생 비flex(enable 컨트롤 있음) → "정렬 박스로 만들기" 버튼만', () => {
    // el 이 flex 로 보여도(패치 직후 stale 가능) 노드 파생이 우선이므로 enable 버튼.
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'flex' } as CSSStyleDeclaration);
    expect(resolveFlexContainerMode('auto', document.createElement('div'), false, true)).toEqual({
      showContainer: false,
      showEnableButton: true,
      showDisableButton: false,
    });
  });
  it('auto + enable 컨트롤 없음 → computed style 폴백(flex)', () => {
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'flex' } as CSSStyleDeclaration);
    expect(resolveFlexContainerMode('auto', document.createElement('div'), false, false)).toEqual({
      showContainer: true,
      showEnableButton: false,
      showDisableButton: false,
    });
  });
  it('auto + enable 컨트롤 없음 → computed style 폴백(block) → 만들기 버튼', () => {
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'block' } as CSSStyleDeclaration);
    expect(resolveFlexContainerMode('auto', document.createElement('div'), false, false)).toEqual({
      showContainer: false,
      showEnableButton: true,
      showDisableButton: false,
    });
  });
});

describe('scope', () => {
  const ENABLE_ONOFF = {
    widget: 'segmented',
    group: 'flex-enable',
    onValue: 'flex',
    apply: { type: 'classToken', tokens: ['flex'] },
    options: [
      { value: 'flex', apply: { type: 'classToken', tokens: ['flex'] } },
      { value: 'block', apply: { type: 'classToken', tokens: ['block'] } },
    ],
  } as unknown as EditorControlSpec;

  const mobileScope = { colorScheme: 'base', breakpoint: 'mobile' } as const;
  const baseScope = { colorScheme: 'base', breakpoint: 'base' } as const;

  it('isNodeFlexEnabled — base flex 가 mobile scope 에서는 false(상속 미인정)', () => {
    const node = { name: 'Div', props: { className: 'flex' } };
    expect(isNodeFlexEnabled(node, ENABLE_ONOFF, baseScope)).toBe(true);
    expect(isNodeFlexEnabled(node, ENABLE_ONOFF, mobileScope)).toBe(false);
  });

  it('isNodeFlexEnabled — mobile scope 명시 flex 오버라이드면 true', () => {
    const node = {
      name: 'Div',
      props: { className: 'flex' },
      responsive: { mobile: { props: { className: 'flex' } } },
    };
    expect(isNodeFlexEnabled(node, ENABLE_ONOFF, mobileScope)).toBe(true);
  });

  it('resolveFlexContainerMode — scope≠base + enable 없음 → flex on/off 비노출(D8)', () => {
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'flex' } as CSSStyleDeclaration);
    expect(resolveFlexContainerMode('auto', document.createElement('div'), false, false, mobileScope)).toEqual({
      showContainer: false,
      showEnableButton: false,
      showDisableButton: false,
    });
  });

  it('resolveFlexContainerMode — scope≠base + 노드 파생 flex → 컨테이너+해제', () => {
    expect(resolveFlexContainerMode('auto', null, true, true, mobileScope)).toEqual({
      showContainer: true,
      showEnableButton: false,
      showDisableButton: true,
    });
  });

  it('resolveFlexContainerMode — computed 폴백은 base scope 에서만', () => {
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'flex' } as CSSStyleDeclaration);
    expect(
      resolveFlexContainerMode('auto', document.createElement('div'), false, false, baseScope).showContainer,
    ).toBe(true);
  });
});

describe('control key 규약', () => {
  it('컨테이너/아이템 컨트롤 키 상수가 안정적이다', () => {
    expect(Object.values(FLEX_CONTAINER_CONTROL_KEYS)).toEqual(['flexDirection', 'flexJustify', 'flexAlign', 'flexWrap', 'flexGap', 'flexEnable']);
    expect(Object.values(FLEX_ITEM_CONTROL_KEYS)).toEqual(['flexItemGrow', 'flexItemAlign', 'flexItemOrder']);
  });
});
