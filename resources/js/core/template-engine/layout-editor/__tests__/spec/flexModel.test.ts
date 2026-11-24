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
  apply: { type: 'classToken', tokens: ['flex'] },
};

afterEach(() => vi.restoreAllMocks());

describe('getFlexEditorRole', () => {
  /** @effects flex_container_classToken_emits_per_spec_apply_type */
  it.each(['container', 'item', 'auto'])('%s 역할을 읽는다', (role) => {
    expect(getFlexEditorRole({ flexEditor: role as 'container' })).toBe(role);
  });
  /** @effects flex_container_classToken_emits_per_spec_apply_type */
  it('미선언/잘못된 값 → null', () => {
    expect(getFlexEditorRole({})).toBeNull();
    expect(getFlexEditorRole(null)).toBeNull();
    expect(getFlexEditorRole({ flexEditor: 'nope' as 'container' })).toBeNull();
  });
});

describe('isRenderedAsFlex — computed style 기반(className 토큰 아님)', () => {
  /** @effects flex_auto_detection_uses_computed_style_not_classname */
  it('display:flex → true', () => {
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'flex' } as CSSStyleDeclaration);
    expect(isRenderedAsFlex(document.createElement('div'))).toBe(true);
  });
  /** @effects flex_auto_detection_uses_computed_style_not_classname */
  it('display:inline-flex → true', () => {
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'inline-flex' } as CSSStyleDeclaration);
    expect(isRenderedAsFlex(document.createElement('div'))).toBe(true);
  });
  /** @effects flex_auto_detection_uses_computed_style_not_classname */
  it('display:block → false', () => {
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'block' } as CSSStyleDeclaration);
    expect(isRenderedAsFlex(document.createElement('div'))).toBe(false);
  });
  /** @effects flex_auto_detection_uses_computed_style_not_classname */
  it('null 엘리먼트 → false', () => {
    expect(isRenderedAsFlex(null)).toBe(false);
  });
});

describe('isNodeFlexEnabled — 노드 파생(enable 컨트롤 역해석)', () => {
  /** @effects flex_auto_detection_works_across_tailwind_bootstrap_native_css */
  it('className 에 flex 토큰 있으면 true', () => {
    expect(isNodeFlexEnabled({ props: { className: 'w-full flex gap-2' } }, ENABLE_CONTROL)).toBe(true);
  });
  /** @effects scope_flex_judgment_uses_node_derivation_not_computed_style */
  it('flex 토큰 없으면 false', () => {
    expect(isNodeFlexEnabled({ props: { className: 'w-full max-w-md p-8' } }, ENABLE_CONTROL)).toBe(false);
  });
  /** @effects scope_flex_judgment_uses_node_derivation_not_computed_style */
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

    /** @effects scope_flex_judgment_uses_node_derivation_not_computed_style */
    it('on 토큰(flex) → true', () => {
      expect(isNodeFlexEnabled({ props: { className: 'w-full flex gap-2' } }, ENABLE_ONOFF_CONTROL)).toBe(true);
    });
    /** @effects device_flex_disable_writes_explicit_off_token_breaking_base_inheritance */
    it('off 토큰(block) → false (해제 후 만들기 버튼 복귀)', () => {
      expect(isNodeFlexEnabled({ props: { className: 'w-full block p-8' } }, ENABLE_ONOFF_CONTROL)).toBe(false);
    });
    /** @effects scope_flex_judgment_uses_node_derivation_not_computed_style */
    it('on/off 토큰 모두 없음 → false', () => {
      expect(isNodeFlexEnabled({ props: { className: 'w-full p-8' } }, ENABLE_ONOFF_CONTROL)).toBe(false);
    });
  });
});

describe('resolveFlexContainerMode', () => {
  /** @effects flex_container_classToken_emits_per_spec_apply_type */
  it('container → 항상 컨테이너 컨트롤(해제 버튼 없음)', () => {
    expect(resolveFlexContainerMode('container', null)).toEqual({ showContainer: true, showEnableButton: false, showDisableButton: false });
  });
  /** @effects flex_item_align_self_emits_correct_recipe, flex_item_order_property_set */
  it('item → 컨테이너 컨트롤 비노출', () => {
    expect(resolveFlexContainerMode('item', null)).toEqual({ showContainer: false, showEnableButton: false, showDisableButton: false });
  });
  /** @effects scope_flex_judgment_uses_node_derivation_not_computed_style */
  it('auto + 노드 파생 flex(enable 컨트롤 있음) → 컨테이너 컨트롤 + 해제 버튼', () => {
    // 노드 파생 우선 — computed style 은 보지 않는다(stale 방지). el 은 block 이어도 무관.
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'block' } as CSSStyleDeclaration);
    expect(resolveFlexContainerMode('auto', document.createElement('div'), true, true)).toEqual({
      showContainer: true,
      showEnableButton: false,
      showDisableButton: true,
    });
  });
  /** @effects flex_make_box_button_when_not_rendered_as_flex */
  it('auto + 노드 파생 비flex(enable 컨트롤 있음) → "정렬 박스로 만들기" 버튼만', () => {
    // el 이 flex 로 보여도(패치 직후 stale 가능) 노드 파생이 우선이므로 enable 버튼.
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'flex' } as CSSStyleDeclaration);
    expect(resolveFlexContainerMode('auto', document.createElement('div'), false, true)).toEqual({
      showContainer: false,
      showEnableButton: true,
      showDisableButton: false,
    });
  });
  /** @effects flex_auto_detection_uses_computed_style_not_classname */
  it('auto + enable 컨트롤 없음 → computed style 폴백(flex)', () => {
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'flex' } as CSSStyleDeclaration);
    expect(resolveFlexContainerMode('auto', document.createElement('div'), false, false)).toEqual({
      showContainer: true,
      showEnableButton: false,
      showDisableButton: false,
    });
  });
  /** @effects flex_make_box_button_when_not_rendered_as_flex */
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

  /** @effects scope_flex_judgment_uses_node_derivation_not_computed_style */
  it('isNodeFlexEnabled — 구간 className 이 없으면 mobile scope 는 base flex 를 이어받아 true (engine-v1.99.1)', () => {
    // 종전(D8)에는 false 였다. classMerge 가 기본과 같은 토큰을 구간에 쓰지 않으므로 false 로 두면 공통이 flex 인 노드는
    // 디바이스 탭에서 영영 「해제」 단추를 보이지 못했다.
    const node = { name: 'Div', props: { className: 'flex' } };
    expect(isNodeFlexEnabled(node, ENABLE_ONOFF, baseScope)).toBe(true);
    expect(isNodeFlexEnabled(node, ENABLE_ONOFF, mobileScope)).toBe(true);
  });

  /** @effects device_flex_disable_writes_explicit_off_token_breaking_base_inheritance */
  it('isNodeFlexEnabled — 구간 실효값에 off 토큰이 있으면 base flex 여도 mobile scope 는 false', () => {
    const merged = { name: 'Div', props: { className: 'flex' }, responsive: { mobile: { classMerge: 'block' } } };
    expect(isNodeFlexEnabled(merged, ENABLE_ONOFF, mobileScope)).toBe(false);
    const replaced = { name: 'Div', props: { className: 'flex' }, responsive: { mobile: { props: { className: 'block' } } } };
    expect(isNodeFlexEnabled(replaced, ENABLE_ONOFF, mobileScope)).toBe(false);
  });

  /** @effects scope_flex_judgment_uses_node_derivation_not_computed_style */
  it('isNodeFlexEnabled — base 가 flex 가 아니고 구간 className 도 없으면 mobile scope 는 false', () => {
    const node = { name: 'Div', props: { className: 'p-4' } };
    expect(isNodeFlexEnabled(node, ENABLE_ONOFF, mobileScope)).toBe(false);
  });

  /** @effects device_flex_disable_writes_explicit_off_token_breaking_base_inheritance */
  it('isNodeFlexEnabled — mobile scope 명시 flex 오버라이드면 true', () => {
    const node = {
      name: 'Div',
      props: { className: 'flex' },
      responsive: { mobile: { props: { className: 'flex' } } },
    };
    expect(isNodeFlexEnabled(node, ENABLE_ONOFF, mobileScope)).toBe(true);
  });

  /** @effects no_enable_control_hides_flex_toggle_in_non_base_scope */
  it('resolveFlexContainerMode — scope≠base + enable 없음 → flex on/off 비노출(D8)', () => {
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'flex' } as CSSStyleDeclaration);
    expect(resolveFlexContainerMode('auto', document.createElement('div'), false, false, mobileScope)).toEqual({
      showContainer: false,
      showEnableButton: false,
      showDisableButton: false,
    });
  });

  /** @effects scope_flex_judgment_uses_node_derivation_not_computed_style */
  it('resolveFlexContainerMode — scope≠base + 노드 파생 flex → 컨테이너+해제', () => {
    expect(resolveFlexContainerMode('auto', null, true, true, mobileScope)).toEqual({
      showContainer: true,
      showEnableButton: false,
      showDisableButton: true,
    });
  });

  /** @effects scope_flex_judgment_uses_node_derivation_not_computed_style */
  it('resolveFlexContainerMode — computed 폴백은 base scope 에서만', () => {
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'flex' } as CSSStyleDeclaration);
    expect(
      resolveFlexContainerMode('auto', document.createElement('div'), false, false, baseScope).showContainer,
    ).toBe(true);
  });
});

describe('control key 규약', () => {
  /** @effects flex_container_classToken_emits_per_spec_apply_type */
  it('컨테이너/아이템 컨트롤 키 상수가 안정적이다', () => {
    expect(Object.values(FLEX_CONTAINER_CONTROL_KEYS)).toEqual(['flexDirection', 'flexJustify', 'flexAlign', 'flexWrap', 'flexGap', 'flexEnable']);
    expect(Object.values(FLEX_ITEM_CONTROL_KEYS)).toEqual(['flexItemGrow', 'flexItemAlign', 'flexItemOrder']);
  });
});
