/**
 * recipeEngine.scope.test.ts — scope(색 모드 × 디바이스) 적용
 *
 * 검증 매트릭스:
 *  (1) scope 미지정 = 오늘과 동일 (회귀 — BASE_SCOPE 기본값)
 *  (2) tablet apply → responsive.tablet.props, base 불변
 *  (3) prune: 마지막 tablet 오버라이드 제거 시 responsive.tablet → responsive 삭제
 *  (4) 다크 classToken 공존: 라이트 + dark: 토큰 동일 className, 한쪽 편집이 다른쪽 보존
 *  (5) 다크 styleProp → apply no-op, reverse darkReadonly:true
 *  (6) 다크 + tablet 합성: responsive.tablet.props.className 에 dark: 토큰
 *  (7) 커스텀 범위 키 "600-900" scope apply → responsive["600-900"].props, reverse 동일 키
 *  (D6) scope≠base 오버라이드 없으면 baseFallback 제공
 */

import { describe, it, expect } from 'vitest';
import { applyRecipe, reverseResolve } from '../../spec/recipeEngine';
import { applyResponsiveClassMerge } from '../../../helpers/StyleHelpers';
import type { StyleScope } from '../../spec/styleScope';
import type { EditorControlSpec } from '../../spec/specTypes';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const bgColor: EditorControlSpec = {
  widget: 'segmented',
  group: 'bg-color',
  options: [
    { value: 'white', apply: { type: 'classToken', tokens: ['bg-white'] } },
    { value: 'slate', apply: { type: 'classToken', tokens: ['bg-slate-800'] } },
  ],
};

const textColor: EditorControlSpec = {
  widget: 'color',
  group: 'text-color',
  apply: { type: 'styleProp', prop: 'color' },
};

const tablet: StyleScope = { colorScheme: 'base', breakpoint: 'tablet' };
const dark: StyleScope = { colorScheme: 'dark', breakpoint: 'base' };
const darkTablet: StyleScope = { colorScheme: 'dark', breakpoint: 'tablet' };
const custom: StyleScope = { colorScheme: 'base', breakpoint: '600-900' };

describe('(1) scope 미지정 = 오늘과 동일 (회귀)', () => {
  /**
   * @scenario color_scheme=light,device=base,apply_type=classToken,edit_target=style,scope_override_state=no_override_inherits_base
   * @effects base_scope_writes_node_props_byte_identical_to_pre_scope
   */
  it('base classToken apply 는 node.props.className 에 직접', () => {
    const node: EditorNode = { name: 'Div' };
    const next = applyRecipe(node, bgColor, 'white');
    expect(next.props?.className).toBe('bg-white');
    expect(next.responsive).toBeUndefined();
  });

  /**
   * @effects reverse_resolve_reads_value_from_selected_scope_container
   */
  it('reverseResolve 미지정 = base 값', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'bg-white' } };
    expect(reverseResolve(node, bgColor).value).toBe('white');
  });
});

describe('(2) tablet apply → responsive.tablet.props, base 불변', () => {
  /**
   * @scenario color_scheme=light,device=tablet,apply_type=classToken,edit_target=style,scope_override_state=no_override_inherits_base
   * @effects device_scope_writes_responsive_breakpoint_props_base_unchanged
   */
  it('responsive.tablet.props.className 에 기록', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'bg-white' } };
    const next = applyRecipe(node, bgColor, 'slate', tablet);
    expect(next.props?.className).toBe('bg-white'); // base 불변
    expect(next.responsive?.tablet?.props?.className).toBe('bg-slate-800');
  });

  /**
   * @scenario color_scheme=light,device=tablet,apply_type=classToken,edit_target=style,scope_override_state=has_scope_override
   * @effects reverse_resolve_reads_value_from_selected_scope_container
   */
  it('reverse 는 tablet scope 에서 그 값', () => {
    const node: EditorNode = {
      name: 'Div',
      props: { className: 'bg-white' },
      responsive: { tablet: { props: { className: 'bg-slate-800' } } },
    };
    const r = reverseResolve(node, bgColor, tablet);
    expect(r.value).toBe('slate');
    expect(r.scopedValue).toBe('slate');
  });
});

describe('(2-B) B안 className 시드 — 디바이스 scope 가 base 다른 group 토큰 보존', () => {
  // textAlign — bgColor 와 다른 group(text-align). base 에 bg-white 가 있을 때
  // tablet 에서 textAlign 만 바꿔도 bg-white 가 보존되어야 한다(얕은 머지 대응).
  const textAlign: EditorControlSpec = {
    widget: 'segmented',
    group: 'text-align',
    options: [
      { value: 'left', apply: { type: 'classToken', tokens: ['text-left'] } },
      { value: 'center', apply: { type: 'classToken', tokens: ['text-center'] } },
    ],
  };

  /**
   * @scenario color_scheme=light,device=tablet,apply_type=classToken,edit_target=style,scope_override_state=no_override_inherits_base
   * @effects device_scope_class_edit_writes_only_changed_tokens_as_class_merge, device_scope_keeps_base_tokens_via_class_merge
   */
  it('tablet 에서 textAlign 적용 시 바뀐 토큰만 classMerge 에 쓰고 base 토큰은 실효값에 보존 (engine-v1.98.0)', () => {
    const node: EditorNode = {
      name: 'Div',
      props: { className: 'w-full bg-white rounded-lg' },
    };
    const next = applyRecipe(node, textAlign, 'center', tablet);
    // 종전 B안 시드는 base className 전체를 responsive.tablet.props.className 에 복사했다 — 이제 바뀐 토큰만 쓴다
    expect(next.responsive?.tablet?.classMerge).toBe('text-center');
    expect(next.responsive?.tablet?.props).toBeUndefined();
    // 그 폭의 실효 className — base 토큰 전부 + 새 토큰
    const cls = applyResponsiveClassMerge('w-full bg-white rounded-lg', next.responsive?.tablet?.classMerge).split(/\s+/);
    expect(cls).toEqual(['w-full', 'bg-white', 'rounded-lg', 'text-center']);
    // base 불변
    expect(next.props?.className).toBe('w-full bg-white rounded-lg');
  });

  // spacing 위젯(tokenTemplate {value} + groupPrefixes) — 태블릿 scope 에서 여백 변경 시
  // responsive.tablet.props.className 에 새 p- 토큰이 들어가고 표시점이 떠야 한다.
  const paddingAll: EditorControlSpec = {
    widget: 'spacing',
    group: 'padding',
    apply: { type: 'classToken', tokenTemplate: '{value}' },
    groupPrefixes: ['p-', 'px-', 'py-', 'pt-', 'pr-', 'pb-', 'pl-'],
  } as unknown as EditorControlSpec;

  /**
   * @scenario color_scheme=light,device=tablet,apply_type=classToken,edit_target=style,scope_override_state=no_override_inherits_base
   * @effects device_scope_class_edit_writes_only_changed_tokens_as_class_merge
   */
  it('tablet 에서 spacing(p-8→p-2) 변경 시 responsive.tablet 에 p-2 기록 + base p-8 교체', () => {
    const node: EditorNode = {
      name: 'Div',
      props: { className: 'w-full p-8 bg-white' },
    };
    const next = applyRecipe(node, paddingAll, 'p-2', tablet);
    expect(next.responsive?.tablet?.classMerge).toBe('p-2'); // 바뀐 토큰만
    const cls = applyResponsiveClassMerge('w-full p-8 bg-white', next.responsive?.tablet?.classMerge).split(/\s+/);
    expect(cls).toContain('p-2');     // 새 여백
    expect(cls).not.toContain('p-8'); // base p-8 은 같은 축이라 엔진 병합에서 교체됨
    expect(cls).toContain('w-full');  // 무관 base 토큰 보존
    expect(cls).toContain('bg-white');
    expect(next.props?.className).toBe('w-full p-8 bg-white'); // base 불변
  });

  /**
   * @scenario color_scheme=light,device=tablet,apply_type=classToken,edit_target=style,scope_override_state=no_override_inherits_base
   * @effects device_scope_class_edit_writes_only_changed_tokens_as_class_merge
   */
  it('base 토큰을 지우기만 하는 편집은 덧붙이기로 나타낼 수 없어 종전처럼 props.className 전체를 쓴다', () => {
    const node: EditorNode = {
      name: 'Div',
      props: { className: 'w-full text-center bg-white' },
    };
    const next = applyRecipe(node, textAlign, '', tablet);
    expect(next.responsive?.tablet?.classMerge).toBeUndefined();
    expect(next.responsive?.tablet?.props?.className).toBe('w-full bg-white');
  });

  /**
   * @scenario color_scheme=light,device=tablet,apply_type=classToken,edit_target=style,scope_override_state=has_scope_override
   * @effects device_class_merge_follows_later_base_changes_full_replace_branch_stays_copy
   */
  it('이미 props.className 을 가진 구간(통째 교체)은 그 값을 기준으로 고치고 같은 자리에 쓴다', () => {
    const node: EditorNode = {
      name: 'Div',
      props: { className: 'w-full bg-white text-left' },
      responsive: { tablet: { props: { className: 'w-full bg-white text-left rounded-lg' } } },
    };
    const next = applyRecipe(node, textAlign, 'center', tablet);
    expect(next.responsive?.tablet?.classMerge).toBeUndefined();
    expect((next.responsive?.tablet?.props?.className as string).split(/\s+/).sort()).toEqual(
      ['bg-white', 'rounded-lg', 'text-center', 'w-full'].sort()
    );
  });

  /**
   * @scenario color_scheme=light,device=tablet,apply_type=classToken,edit_target=style,scope_override_state=has_scope_override
   * @effects device_scope_class_edit_writes_only_changed_tokens_as_class_merge
   */
  it('classMerge 구간을 다시 편집하면 실효값 기준으로 고치고 diff 만 남긴다', () => {
    const node: EditorNode = {
      name: 'Div',
      props: { className: 'w-full p-8 text-left' },
      responsive: { tablet: { classMerge: 'p-2' } },
    };
    const next = applyRecipe(node, textAlign, 'center', tablet);
    expect(next.responsive?.tablet?.classMerge?.split(/\s+/).sort()).toEqual(['p-2', 'text-center']);
    expect(next.responsive?.tablet?.props).toBeUndefined();
  });

  /**
   * @effects seeded_base_tokens_render_as_placeholder_only_user_changes_are_solid
   */
  it('시드된 base 토큰은 reverse 에서 placeholder(scopedValue undefined, baseFallback 제공)', () => {
    // tablet 에 base 와 동일한 bg-white 만 시드된 상태 — backgroundColor 는 사용자가
    // 안 바꿨으므로 placeholder 여야 한다(시드값=base값).
    const node: EditorNode = {
      name: 'Div',
      props: { className: 'bg-white text-left' },
      responsive: { tablet: { props: { className: 'bg-white text-center' } } },
    };
    // bgColor(=bg-white) 는 tablet 시드값=base값 → placeholder
    const bg = reverseResolve(node, bgColor, tablet);
    expect(bg.scopedValue).toBeUndefined();
    expect(bg.baseFallback).toBe('white');
    // textAlign 은 tablet(center) != base(left) → 진짜 override
    const ta = reverseResolve(node, textAlign, tablet);
    expect(ta.scopedValue).toBe('center');
  });
});

describe('(3) prune', () => {
  /**
   * @scenario color_scheme=light,device=tablet,apply_type=classToken,edit_target=style,scope_override_state=has_scope_override
   * @effects empty_override_prunes_responsive_branch_keeping_code_authored_keys
   */
  it('마지막 tablet 오버라이드 제거 → responsive 삭제', () => {
    const node: EditorNode = {
      name: 'Div',
      props: { className: 'bg-white' },
      responsive: { tablet: { props: { className: 'bg-slate-800' } } },
    };
    // 같은 group off — value=undefined 로 group 토큰 제거
    const next = applyRecipe(node, bgColor, undefined, tablet);
    expect(next.responsive).toBeUndefined();
    expect(next.props?.className).toBe('bg-white');
  });
});

describe('(4) 다크 classToken 공존', () => {
  /**
   * @scenario color_scheme=dark,device=base,apply_type=classToken,edit_target=style,scope_override_state=no_override_inherits_base
   * @effects dark_classToken_coexists_with_light_token_in_one_className
   */
  it('라이트 + dark: 토큰이 한 className 에 공존', () => {
    let node: EditorNode = { name: 'Div' };
    node = applyRecipe(node, bgColor, 'white'); // 라이트
    node = applyRecipe(node, bgColor, 'slate', dark); // 다크
    const tokens = (node.props?.className as string).split(' ');
    expect(tokens).toContain('bg-white');
    expect(tokens).toContain('dark:bg-slate-800');
  });

  /**
   * @effects dark_edit_preserves_light_token_and_vice_versa
   */
  it('다크 편집이 라이트 토큰 보존', () => {
    let node: EditorNode = { name: 'Div', props: { className: 'bg-white' } };
    node = applyRecipe(node, bgColor, 'slate', dark);
    expect((node.props?.className as string)).toContain('bg-white');
    // 다시 다크 변경 — 라이트 보존
    node = applyRecipe(node, bgColor, 'white', dark);
    expect((node.props?.className as string)).toContain('bg-white');
    expect((node.props?.className as string)).toContain('dark:bg-white');
  });

  /**
   * @effects reverse_resolve_reads_value_from_selected_scope_container, dark_classToken_coexists_with_light_token_in_one_className
   */
  it('reverse 가 라이트/다크 독립 해석', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'bg-white dark:bg-slate-800' } };
    expect(reverseResolve(node, bgColor).value).toBe('white'); // base
    expect(reverseResolve(node, bgColor, dark).value).toBe('slate'); // dark
  });
});

describe('(5) 다크 styleProp → no-op + darkReadonly', () => {
  /**
   * @effects dark_inline_control_readonly_value_never_overwritten
   */
  it('apply no-op — 원본 반환', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'x' } };
    const next = applyRecipe(node, textColor, '#fff', dark);
    expect(next).toBe(node); // short-circuit 원본
  });

  /**
   * @effects dark_inline_control_readonly_value_never_overwritten
   */
  it('reverse darkReadonly:true', () => {
    const node: EditorNode = { name: 'Div', props: { style: { color: 'red' } } };
    const r = reverseResolve(node, textColor, dark);
    expect(r.darkReadonly).toBe(true);
    expect(r.matched).toBe(false);
  });
});

describe('(6) 다크 + tablet 합성', () => {
  /**
   * @scenario color_scheme=dark,device=tablet,apply_type=classToken,edit_target=style,scope_override_state=no_override_inherits_base
   * @effects dark_and_device_compose_dark_prefix_in_responsive_props, device_scope_class_edit_writes_only_changed_tokens_as_class_merge
   */
  it('responsive.tablet.classMerge 에 dark: 토큰 (engine-v1.98.0 — 바뀐 토큰만)', () => {
    const node: EditorNode = { name: 'Div' };
    const next = applyRecipe(node, bgColor, 'slate', darkTablet);
    expect(next.responsive?.tablet?.classMerge).toBe('dark:bg-slate-800');
    expect(next.responsive?.tablet?.props).toBeUndefined();
  });

  /**
   * @scenario color_scheme=dark,device=tablet,apply_type=classToken,edit_target=style,scope_override_state=has_scope_override
   * @effects dark_and_device_compose_dark_prefix_in_responsive_props
   */
  it('reverse 다크+tablet (classMerge 구간)', () => {
    const node: EditorNode = {
      name: 'Div',
      props: { className: 'bg-white dark:bg-gray-800' },
      responsive: { tablet: { classMerge: 'dark:bg-slate-800' } },
    };
    expect(reverseResolve(node, bgColor, darkTablet).value).toBe('slate');
  });

  /**
   * @scenario color_scheme=dark,device=tablet,apply_type=classToken,edit_target=style,scope_override_state=has_scope_override
   * @effects dark_and_device_compose_dark_prefix_in_responsive_props
   */
  it('reverse 다크+tablet', () => {
    const node: EditorNode = {
      name: 'Div',
      responsive: { tablet: { props: { className: 'dark:bg-slate-800' } } },
    };
    expect(reverseResolve(node, bgColor, darkTablet).value).toBe('slate');
  });
});

describe('(7) 커스텀 범위 키', () => {
  /**
   * @scenario color_scheme=light,device=custom_range,apply_type=classToken,edit_target=style,scope_override_state=no_override_inherits_base
   * @effects custom_range_scope_writes_responsive_range_key, device_scope_class_edit_writes_only_changed_tokens_as_class_merge
   */
  it('apply → responsive["600-900"].classMerge (engine-v1.98.0 — 바뀐 토큰만)', () => {
    const node: EditorNode = { name: 'Div' };
    const next = applyRecipe(node, bgColor, 'slate', custom);
    expect(next.responsive?.['600-900']?.classMerge).toBe('bg-slate-800');
    expect(next.responsive?.['600-900']?.props).toBeUndefined();
  });

  /**
   * @effects reverse_resolve_reads_value_from_selected_scope_container
   */
  it('reverse 동일 키에서만 읽음', () => {
    const node: EditorNode = {
      name: 'Div',
      responsive: { '600-900': { props: { className: 'bg-slate-800' } } },
    };
    expect(reverseResolve(node, bgColor, custom).value).toBe('slate');
    // tablet scope 는 그 키를 못 읽음 (오버라이드 없음 → base fallback)
    expect(reverseResolve(node, bgColor, tablet).scopedValue).toBeUndefined();
  });

  /**
   * @scenario color_scheme=light,device=custom_range,apply_type=classToken,edit_target=style,scope_override_state=has_scope_override
   * @effects empty_override_prunes_responsive_branch_keeping_code_authored_keys
   */
  it('prune 동작', () => {
    const node: EditorNode = {
      name: 'Div',
      responsive: { '600-900': { props: { className: 'bg-slate-800' } } },
    };
    const next = applyRecipe(node, bgColor, undefined, custom);
    expect(next.responsive).toBeUndefined();
  });
});

describe('(D6) baseFallback', () => {
  /**
   * @scenario color_scheme=light,device=tablet,apply_type=classToken,edit_target=style,scope_override_state=no_override_inherits_base
   * @effects scope_without_override_shows_base_inherited_value_as_placeholder
   */
  it('scope 오버라이드 없으면 base 상속값을 baseFallback 으로', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'bg-white' } };
    const r = reverseResolve(node, bgColor, tablet);
    expect(r.scopedValue).toBeUndefined(); // tablet 오버라이드 없음
    expect(r.baseFallback).toBe('white'); // base 상속
    expect(r.value).toBe('white'); // 표시값 = fallback
  });

  /**
   * @scenario color_scheme=light,device=tablet,apply_type=classToken,edit_target=style,scope_override_state=has_scope_override
   * @effects scope_without_override_shows_base_inherited_value_as_placeholder
   */
  it('scope 오버라이드 있으면 baseFallback 없음', () => {
    const node: EditorNode = {
      name: 'Div',
      props: { className: 'bg-white' },
      responsive: { tablet: { props: { className: 'bg-slate-800' } } },
    };
    const r = reverseResolve(node, bgColor, tablet);
    expect(r.scopedValue).toBe('slate');
    expect(r.baseFallback).toBeUndefined();
  });
});

describe('입력 노드 불변', () => {
  /**
   * @effects scoped_edit_never_mutates_input_node
   */
  it('applyRecipe scope 는 원본 변경 안 함', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'bg-white' } };
    const snapshot = JSON.stringify(node);
    applyRecipe(node, bgColor, 'slate', tablet);
    expect(JSON.stringify(node)).toBe(snapshot);
  });
});

// 색 컨트롤 classToken 마이그레이션 (프리셋 토큰 + 자유 HEX tokenTemplate)
const textColorToken: EditorControlSpec = {
  widget: 'color',
  group: 'text-color',
  apply: { type: 'classToken', tokenTemplate: 'text-[{value}]' },
  options: [
    { value: 'text-gray-900', apply: { type: 'classToken', tokens: ['text-gray-900'] } },
    { value: 'text-blue-600', apply: { type: 'classToken', tokens: ['text-blue-600'] } },
  ],
} as unknown as EditorControlSpec;

describe(' 색 classToken — 프리셋/자유색/group 배타성', () => {
  const base: StyleScope = { colorScheme: 'base', breakpoint: 'base' };

  /**
   * @effects color_preset_token_replaces_same_group_keeping_other_text_tokens
   */
  it('프리셋 토큰 적용 → className 에 토큰, 기존 fontSize(text-xl) 보존', () => {
    const node: EditorNode = { name: 'P', props: { className: 'text-xl text-center' } };
    const next = applyRecipe(node, textColorToken, 'text-gray-900', base);
    const cls = (next.props!.className as string).split(/\s+/);
    expect(cls).toContain('text-gray-900');
    expect(cls).toContain('text-xl'); // fontSize 미간섭
    expect(cls).toContain('text-center'); // textAlign 미간섭
  });

  /**
   * @effects color_preset_token_replaces_same_group_keeping_other_text_tokens
   */
  it('프리셋 A → B 전환 시 A 제거, fontSize 보존', () => {
    const node: EditorNode = { name: 'P', props: { className: 'text-xl text-gray-900' } };
    const next = applyRecipe(node, textColorToken, 'text-blue-600', base);
    const cls = (next.props!.className as string).split(/\s+/);
    expect(cls).toContain('text-blue-600');
    expect(cls).not.toContain('text-gray-900');
    expect(cls).toContain('text-xl');
  });

  /**
   * @effects free_hex_color_writes_arbitrary_value_class_token
   */
  it('자유 HEX → text-[#hex] 임의값 토큰 (control-level tokenTemplate)', () => {
    const node: EditorNode = { name: 'P', props: { className: 'text-xl' } };
    const next = applyRecipe(node, textColorToken, '#3a7bd5', base);
    const cls = (next.props!.className as string).split(/\s+/);
    expect(cls).toContain('text-[#3a7bd5]');
    expect(cls).toContain('text-xl');
  });

  /**
   * @effects color_class_token_reverse_resolves_preset_and_hex
   */
  it('reverseResolve — 프리셋 토큰 역해석', () => {
    const node: EditorNode = { name: 'P', props: { className: 'text-xl text-blue-600' } };
    expect(reverseResolve(node, textColorToken, base).value).toBe('text-blue-600');
  });

  /**
   * @effects color_class_token_reverse_resolves_preset_and_hex
   */
  it('reverseResolve — 자유 HEX 토큰 역해석', () => {
    const node: EditorNode = { name: 'P', props: { className: 'text-[#3a7bd5]' } };
    expect(reverseResolve(node, textColorToken, base).value).toBe('#3a7bd5');
  });

  /**
   * @effects dark_classToken_coexists_with_light_token_in_one_className
   */
  it('다크 scope — 프리셋 토큰에 dark: prefix, base 라이트 토큰 공존', () => {
    const node: EditorNode = { name: 'P', props: { className: 'text-gray-900' } };
    const next = applyRecipe(node, textColorToken, 'text-blue-600', dark);
    const cls = (next.props!.className as string).split(/\s+/);
    expect(cls).toContain('text-gray-900'); // 라이트 보존
    expect(cls).toContain('dark:text-blue-600'); // 다크 프리셋
  });
});
