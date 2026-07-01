/**
 * styleScope.test.ts — 색 모드 × 디바이스 StyleScope 위치 결정 계층
 *
 * 검증 매트릭스:
 *  - getScopedProps: base / preset / 커스텀 범위 컨테이너 읽기
 *  - withScopedProps: 클론 불변 + base/responsive 쓰기 + prune(빈 props/branch/responsive)
 *  - withScopedProps: 코드 작성 children/text/if/iteration 남은 브랜치 보존
 *  - getScopedIf / setScopedIf: base / responsive[bp].if + prune
 *  - dark prefix 헬퍼: has/strip/add(멱등)
 *  - isDarkEditable: classToken 만 true
 *  - isValidScopeBreakpoint: base/preset/범위 유효성, min>max 거부
 *  - deviceToBreakpoint: preset 동명, custom 폭 매칭, 미매칭 base
 */

import { describe, it, expect } from 'vitest';
import {
  BASE_SCOPE,
  DARK_PREFIX,
  addDarkPrefix,
  clearScopeOverride,
  deviceToBreakpoint,
  getScopedIf,
  getEffectiveScopedIf,
  getScopedProps,
  hasDarkPrefix,
  hasScopeOverride,
  isDarkEditable,
  isPresetBreakpoint,
  isValidScopeBreakpoint,
  resolveBranchSeparationMode,
  setScopedIf,
  stripDarkPrefix,
  withScopedProps,
  type StyleScope,
} from '../../spec/styleScope';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const tabletScope: StyleScope = { colorScheme: 'base', breakpoint: 'tablet' };
const customScope: StyleScope = { colorScheme: 'base', breakpoint: '600-900' };

describe('getScopedProps', () => {
  it('base scope 는 node.props 를 반환한다', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'bg-white' } };
    expect(getScopedProps(node, BASE_SCOPE)).toEqual({ className: 'bg-white' });
  });

  it('preset scope 는 responsive[bp].props 를 반환한다', () => {
    const node: EditorNode = {
      name: 'Div',
      props: { className: 'bg-white' },
      responsive: { tablet: { props: { className: 'bg-slate-100' } } },
    };
    expect(getScopedProps(node, tabletScope)).toEqual({ className: 'bg-slate-100' });
  });

  it('오버라이드 없는 scope 는 빈 객체를 반환한다', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'bg-white' } };
    expect(getScopedProps(node, tabletScope)).toEqual({});
    expect(getScopedProps(node, customScope)).toEqual({});
  });

  it('커스텀 범위 키 컨테이너를 직접 읽는다', () => {
    const node: EditorNode = {
      name: 'Div',
      responsive: { '600-900': { props: { className: 'p-2' } } },
    };
    expect(getScopedProps(node, customScope)).toEqual({ className: 'p-2' });
  });
});

describe('withScopedProps — base scope', () => {
  it('node.props 를 변형하고 입력은 불변', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'bg-white' } };
    const next = withScopedProps(node, BASE_SCOPE, (props) => {
      props.className = 'bg-black';
    });
    expect(next.props).toEqual({ className: 'bg-black' });
    expect(node.props).toEqual({ className: 'bg-white' }); // 불변
  });

  it('빈 style 은 prune 된다', () => {
    const node: EditorNode = { name: 'Div', props: { style: { color: 'red' } } };
    const next = withScopedProps(node, BASE_SCOPE, (_props, style) => {
      delete style.color;
    });
    expect(next.props?.style).toBeUndefined();
  });
});

describe('withScopedProps — responsive scope', () => {
  it('responsive[bp].props 에 쓰고 base 는 불변', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'bg-white' } };
    const next = withScopedProps(node, tabletScope, (props) => {
      props.className = 'bg-slate-100';
    });
    expect(next.props).toEqual({ className: 'bg-white' }); // base 불변
    expect(next.responsive?.tablet?.props).toEqual({ className: 'bg-slate-100' });
  });

  it('커스텀 범위 키에 쓴다', () => {
    const node: EditorNode = { name: 'Div' };
    const next = withScopedProps(node, customScope, (props) => {
      props.className = 'p-4';
    });
    expect(next.responsive?.['600-900']?.props).toEqual({ className: 'p-4' });
  });

  it('마지막 오버라이드 제거 시 responsive 까지 prune', () => {
    const node: EditorNode = {
      name: 'Div',
      props: { className: 'bg-white' },
      responsive: { tablet: { props: { className: 'bg-slate-100' } } },
    };
    const next = withScopedProps(node, tabletScope, (props) => {
      delete props.className;
    });
    expect(next.responsive).toBeUndefined();
  });

  it('빈 props 면 브랜치에서 props 만 제거하고 코드 작성 키(if)는 보존', () => {
    const node: EditorNode = {
      name: 'Div',
      responsive: { tablet: { props: { className: 'x' }, if: '{{show}}' } },
    };
    const next = withScopedProps(node, tabletScope, (props) => {
      delete props.className;
    });
    expect(next.responsive?.tablet?.props).toBeUndefined();
    expect(next.responsive?.tablet?.if).toBe('{{show}}'); // 보존
  });

  it('다른 브랜치(desktop)는 tablet 편집에 영향받지 않는다', () => {
    const node: EditorNode = {
      name: 'Div',
      responsive: {
        desktop: { props: { className: 'a' } },
        tablet: { props: { className: 'b' } },
      },
    };
    const next = withScopedProps(node, tabletScope, (props) => {
      props.className = 'c';
    });
    expect(next.responsive?.desktop?.props).toEqual({ className: 'a' });
    expect(next.responsive?.tablet?.props).toEqual({ className: 'c' });
  });
});

describe('getScopedIf / setScopedIf', () => {
  it('base if 읽기/쓰기', () => {
    const node: EditorNode = { name: 'Div', if: '{{a}}' };
    expect(getScopedIf(node, BASE_SCOPE)).toBe('{{a}}');
    const next = setScopedIf(node, BASE_SCOPE, '{{b}}');
    expect(next.if).toBe('{{b}}');
  });

  it('base if 빈 식 → prune', () => {
    const node: EditorNode = { name: 'Div', if: '{{a}}' };
    const next = setScopedIf(node, BASE_SCOPE, '');
    expect(next.if).toBeUndefined();
  });

  it('responsive if 쓰기 → responsive[bp].if', () => {
    const node: EditorNode = { name: 'Div' };
    const next = setScopedIf(node, tabletScope, '{{mobile}}');
    expect(next.responsive?.tablet?.if).toBe('{{mobile}}');
    expect(node.responsive).toBeUndefined(); // 불변
  });

  it('responsive if clear → 브랜치 prune', () => {
    const node: EditorNode = {
      name: 'Div',
      responsive: { tablet: { if: '{{x}}' } },
    };
    const next = setScopedIf(node, tabletScope, '');
    expect(next.responsive).toBeUndefined();
  });

  it('responsive if clear 시 같은 브랜치 props 보존', () => {
    const node: EditorNode = {
      name: 'Div',
      responsive: { tablet: { props: { className: 'a' }, if: '{{x}}' } },
    };
    const next = setScopedIf(node, tabletScope, '');
    expect(next.responsive?.tablet?.if).toBeUndefined();
    expect(next.responsive?.tablet?.props).toEqual({ className: 'a' });
  });
});

// getEffectiveScopedIf: 디바이스 탭에서 base if 상속 폴백(Bug1 회귀).
// base 에 정의된 표시조건이 디바이스 세부탭(PC/태블릿/모바일)에서 빈 빌더로 가려지던 결함.
describe('getEffectiveScopedIf — 디바이스 탭 base 상속 폴백', () => {
  it('base scope → node.if 그대로', () => {
    const node: EditorNode = { name: 'Div', if: '{{_local.success}}' };
    expect(getEffectiveScopedIf(node, BASE_SCOPE)).toBe('{{_local.success}}');
  });

  it('디바이스 override 없음 → base node.if 로 폴백 (핵심 결함)', () => {
    const node: EditorNode = { name: 'Div', if: '{{(banner?.data?.items?.length ?? 0) > 0}}' };
    // 디바이스 탭(tablet)에 자체 if 가 없으면 base if 가 유효 조건으로 노출돼야 한다.
    expect(getEffectiveScopedIf(node, tabletScope)).toBe('{{(banner?.data?.items?.length ?? 0) > 0}}');
    // getScopedIf(자체값)은 여전히 undefined (override 표시점 판정용 — 폴백 안 함).
    expect(getScopedIf(node, tabletScope)).toBeUndefined();
  });

  it('디바이스 override 있음 → 그 값 (base 무시)', () => {
    const node: EditorNode = {
      name: 'Div',
      if: '{{base}}',
      responsive: { tablet: { if: '{{tabletOnly}}' } },
    };
    expect(getEffectiveScopedIf(node, tabletScope)).toBe('{{tabletOnly}}');
  });

  it('디바이스 override 가 빈 문자열(명시적 조건 없음) → 폴백 안 함', () => {
    const node: EditorNode = {
      name: 'Div',
      if: '{{base}}',
      responsive: { tablet: { if: '' } },
    };
    // 'if' 키가 존재(빈 문자열)하면 "이 디바이스는 조건 없음" 의도 → base 로 폴백하지 않음.
    expect(getEffectiveScopedIf(node, tabletScope)).toBe('');
  });

  it('base 도 디바이스도 if 없음 → undefined', () => {
    const node: EditorNode = { name: 'Div' };
    expect(getEffectiveScopedIf(node, tabletScope)).toBeUndefined();
  });

  it('responsive 브랜치는 있으나 if 키 부재(props 만) → base 폴백', () => {
    const node: EditorNode = {
      name: 'Div',
      if: '{{base}}',
      responsive: { tablet: { props: { className: 'a' } } },
    };
    expect(getEffectiveScopedIf(node, tabletScope)).toBe('{{base}}');
  });
});

describe('dark prefix 헬퍼', () => {
  it('hasDarkPrefix', () => {
    expect(hasDarkPrefix('dark:bg-x')).toBe(true);
    expect(hasDarkPrefix('bg-x')).toBe(false);
  });

  it('stripDarkPrefix', () => {
    expect(stripDarkPrefix('dark:bg-x')).toBe('bg-x');
    expect(stripDarkPrefix('bg-x')).toBe('bg-x');
  });

  it('addDarkPrefix 멱등', () => {
    expect(addDarkPrefix('bg-x')).toBe('dark:bg-x');
    expect(addDarkPrefix('dark:bg-x')).toBe('dark:bg-x');
  });

  it('DARK_PREFIX 상수', () => {
    expect(DARK_PREFIX).toBe('dark:');
  });
});

describe('isDarkEditable', () => {
  it('classToken 만 true', () => {
    expect(isDarkEditable({ type: 'classToken', tokens: ['bg-x'] })).toBe(true);
    expect(isDarkEditable({ type: 'styleProp', prop: 'color' })).toBe(false);
    expect(isDarkEditable({ type: 'cssVar', varName: '--x' })).toBe(false);
    expect(isDarkEditable({ type: 'propValue', propKey: 'size' })).toBe(false);
    expect(isDarkEditable(undefined)).toBe(false);
  });
});

describe('isValidScopeBreakpoint / isPresetBreakpoint', () => {
  it('base 와 preset 은 유효', () => {
    expect(isValidScopeBreakpoint('base')).toBe(true);
    expect(isValidScopeBreakpoint('tablet')).toBe(true);
    expect(isValidScopeBreakpoint('desktop')).toBe(true);
  });

  it('유효한 범위 문자열은 통과, min>max 거부', () => {
    expect(isValidScopeBreakpoint('600-900')).toBe(true);
    expect(isValidScopeBreakpoint('-599')).toBe(true);
    expect(isValidScopeBreakpoint('1200-')).toBe(true);
    expect(isValidScopeBreakpoint('900-600')).toBe(false); // min>max
    expect(isValidScopeBreakpoint('abc')).toBe(false);
  });

  it('isPresetBreakpoint', () => {
    expect(isPresetBreakpoint('mobile')).toBe(true);
    expect(isPresetBreakpoint('600-900')).toBe(false);
    expect(isPresetBreakpoint('base')).toBe(false);
  });
});

describe('deviceToBreakpoint', () => {
  const node: EditorNode = {
    name: 'Div',
    responsive: { '600-900': { props: { className: 'x' } } },
  };

  it('preset 디바이스는 동명 키', () => {
    expect(deviceToBreakpoint('desktop', node)).toBe('desktop');
    expect(deviceToBreakpoint('tablet', node)).toBe('tablet');
    expect(deviceToBreakpoint('mobile', node)).toBe('mobile');
  });

  it('custom 폭이 노드 커스텀 범위에 매칭되면 그 키', () => {
    expect(deviceToBreakpoint('custom', node, 700)).toBe('600-900');
  });

  it('custom 폭 미매칭이면 base', () => {
    expect(deviceToBreakpoint('custom', node, 1500)).toBe('base');
  });

  it('custom 폭 없으면 base', () => {
    expect(deviceToBreakpoint('custom', node)).toBe('base');
  });

  // portable·동적 커스텀 디바이스 키는 동명 breakpoint 로 그대로 결선.
  it('portable 디바이스는 동명 키(portable)', () => {
    expect(deviceToBreakpoint('portable', node)).toBe('portable');
  });

  it('동적 커스텀 디바이스 키("600-900")는 그대로 반환', () => {
    expect(deviceToBreakpoint('600-900', node)).toBe('600-900');
  });
});

describe('clearScopeOverride — 기본값으로 초기화', () => {
  it('디바이스 scope: responsive[bp].props 제거 → 기본값 상속 복귀', () => {
    const node: EditorNode = {
      name: 'Div',
      props: { className: 'bg-white' },
      responsive: { tablet: { props: { className: 'bg-white text-center' } } },
    };
    const next = clearScopeOverride(node, tabletScope);
    expect(next.responsive).toBeUndefined();
    expect(next.props?.className).toBe('bg-white'); // base 불변
  });

  it('디바이스 scope: 코드 작성 if 남은 브랜치는 보존(props 만 제거)', () => {
    const node: EditorNode = {
      name: 'Div',
      responsive: { tablet: { props: { className: 'x' }, if: '{{a}}' } },
    };
    const next = clearScopeOverride(node, tabletScope);
    expect(next.responsive?.tablet?.props).toBeUndefined();
    expect(next.responsive?.tablet?.if).toBe('{{a}}');
  });

  it('다크 base scope: className 의 dark: 토큰만 제거(라이트 보존)', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'bg-white dark:bg-slate-800 text-center' } };
    const next = clearScopeOverride(node, { colorScheme: 'dark', breakpoint: 'base' });
    const tokens = (next.props?.className as string).split(/\s+/);
    expect(tokens).toContain('bg-white');
    expect(tokens).toContain('text-center');
    expect(tokens).not.toContain('dark:bg-slate-800');
  });

  it('라이트 × 기본값 초기화는 no-op', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'bg-white' } };
    expect(clearScopeOverride(node, BASE_SCOPE)).toBe(node);
  });
});

describe('hasScopeOverride — 표시점(●) 판정', () => {
  it('기본값 탭은 표시점 없음', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'bg-white' } };
    expect(hasScopeOverride(node, BASE_SCOPE)).toBe(false);
  });

  it('시드만 있는 디바이스(base 동일 className)는 표시점 없음', () => {
    const node: EditorNode = {
      name: 'Div',
      props: { className: 'bg-white rounded' },
      responsive: { tablet: { props: { className: 'rounded bg-white' } } }, // 순서만 다름 = 시드
    };
    expect(hasScopeOverride(node, tabletScope)).toBe(false);
  });

  it('base 와 다른 토큰이 있는 디바이스는 표시점 있음', () => {
    const node: EditorNode = {
      name: 'Div',
      props: { className: 'bg-white' },
      responsive: { tablet: { props: { className: 'bg-white text-center' } } },
    };
    expect(hasScopeOverride(node, tabletScope)).toBe(true);
  });

  it('디바이스 if 만 있어도 표시점 있음', () => {
    const node: EditorNode = {
      name: 'Div',
      responsive: { tablet: { if: '{{a}}' } },
    };
    expect(hasScopeOverride(node, tabletScope)).toBe(true);
  });

  it('다크 scope: className 에 dark: 토큰 있으면 표시점', () => {
    const node: EditorNode = { name: 'Div', props: { className: 'bg-white dark:bg-slate-800' } };
    expect(hasScopeOverride(node, { colorScheme: 'dark', breakpoint: 'base' })).toBe(true);
    const node2: EditorNode = { name: 'Div', props: { className: 'bg-white' } };
    expect(hasScopeOverride(node2, { colorScheme: 'dark', breakpoint: 'base' })).toBe(false);
  });
});

// 디바이스 전용 분리 포함관계 판정.
// 규칙: 현재 디바이스 폭 범위 vs 노드 분기 키 범위들.
//  - 정확히 같은 분기 → merge(해제)
//  - 더 넓은 포괄 분기(portable ⊇ mobile 등) → none(편집만, 버튼 없음)
//  - 둘 다 없음 → separate(현재 디바이스 전용 생성)
describe('resolveBranchSeparationMode — 디바이스 분리 포함관계 판정', () => {
  const branched = (resp: Record<string, unknown>): EditorNode => ({
    name: 'Div',
    children: [{ name: 'A' }],
    responsive: resp as EditorNode['responsive'],
  });
  const plain: EditorNode = { name: 'Div', children: [{ name: 'A' }] };

  it('responsive 미지정 → 어떤 디바이스에서도 separate(현재 디바이스 키)', () => {
    expect(resolveBranchSeparationMode('mobile', plain)).toEqual({ mode: 'separate', key: 'mobile' });
    expect(resolveBranchSeparationMode('tablet', plain)).toEqual({ mode: 'separate', key: 'tablet' });
    expect(resolveBranchSeparationMode('desktop', plain)).toEqual({ mode: 'separate', key: 'desktop' });
  });

  // 포괄 분기(portable ⊇ mobile)는 더는 버튼을 숨기지 않고(none 폐지),
  // 현재 디바이스 전용 생성(separate) + sourceKey=포괄 분기(복제 원본)로 노출한다.
  it('portable(0~1023) 분기를 mobile 보기 → separate(mobile, sourceKey=portable)', () => {
    expect(resolveBranchSeparationMode('mobile', branched({ portable: { children: [] } }))).toEqual({
      mode: 'separate',
      key: 'mobile',
      sourceKey: 'portable',
    });
  });

  it('portable 분기를 tablet 보기 → separate(tablet, sourceKey=portable)', () => {
    expect(resolveBranchSeparationMode('tablet', branched({ portable: { children: [] } }))).toEqual({
      mode: 'separate',
      key: 'tablet',
      sourceKey: 'portable',
    });
  });

  it('portable 분기를 desktop 보기 → separate(desktop, portable 은 desktop 비포함)', () => {
    expect(resolveBranchSeparationMode('desktop', branched({ portable: { children: [] } }))).toEqual({
      mode: 'separate',
      key: 'desktop',
    });
  });

  it('mobile 분기를 mobile 보기 → merge(정확히 같은 범위, 해제)', () => {
    expect(resolveBranchSeparationMode('mobile', branched({ mobile: { children: [] } }))).toEqual({
      mode: 'merge',
      key: 'mobile',
    });
  });

  it('mobile 분기를 tablet 보기 → separate(tablet, mobile 은 tablet 비포함)', () => {
    expect(resolveBranchSeparationMode('tablet', branched({ mobile: { children: [] } }))).toEqual({
      mode: 'separate',
      key: 'tablet',
    });
  });

  it('커스텀 "0-767"(=mobile 범위) 분기를 mobile 보기 → merge(정확히 같은 범위)', () => {
    expect(resolveBranchSeparationMode('mobile', branched({ '0-767': { children: [] } }))).toEqual({
      mode: 'merge',
      key: '0-767',
    });
  });

  it('커스텀 "0-767" 분기를 tablet 보기 → separate(tablet, 비겹침)', () => {
    expect(resolveBranchSeparationMode('tablet', branched({ '0-767': { children: [] } })).mode).toBe('separate');
  });

  it('desktop 분기를 desktop 보기 → merge', () => {
    expect(resolveBranchSeparationMode('desktop', branched({ desktop: { children: [] } }))).toEqual({
      mode: 'merge',
      key: 'desktop',
    });
  });

  it('custom 폭(500px) 보기 + portable 분기 → separate(500-500, sourceKey=portable 포괄)', () => {
    expect(resolveBranchSeparationMode('custom', branched({ portable: { children: [] } }), 500)).toEqual({
      mode: 'separate',
      key: '500-500',
      sourceKey: 'portable',
    });
  });

  it('custom 폭(1400px) 보기 + portable 분기 → separate(1400-1400 키)', () => {
    const d = resolveBranchSeparationMode('custom', branched({ portable: { children: [] } }), 1400);
    expect(d.mode).toBe('separate');
    expect(d.key).toBe('1400-1400');
  });

  // ──: 판정 기준 = children 유무 (props-only 분기는 분리로 치지 않음) ──────────
  it('props-only mobile 분기를 mobile 보기 → separate (children 없으므로 분리 아님)', () => {
    // 결함 회귀: 과거 키 존재만으로 판정 시 merge 오판 → children 기준으로 separate 여야 함.
    expect(
      resolveBranchSeparationMode('mobile', branched({ mobile: { props: { className: 'p-2' } } })),
    ).toEqual({ mode: 'separate', key: 'mobile' });
  });

  it('props-only portable 분기를 mobile 보기 → separate (포괄이라도 children 없으면 무시)', () => {
    expect(
      resolveBranchSeparationMode(
        'mobile',
        branched({ portable: { props: { className: 'flex' } } }),
      ).mode,
    ).toBe('separate');
  });

  it('같은 키에 props + children 둘 다 → children 있으므로 merge (props/children 독립)', () => {
    expect(
      resolveBranchSeparationMode(
        'mobile',
        branched({ mobile: { props: { className: 'p-2' }, children: [{ name: 'B' }] } }),
      ),
    ).toEqual({ mode: 'merge', key: 'mobile' });
  });

  it('props-only mobile + children portable 공존 → separate(mobile, sourceKey=portable)', () => {
    // props-only mobile 은 무시되고, children 을 가진 portable 이 mobile 을 포괄 →
    // 현재 디바이스(mobile) 전용 생성 + 복제 원본은 포괄 분기 portable.
    expect(
      resolveBranchSeparationMode(
        'mobile',
        branched({
          mobile: { props: { className: 'p-2' } },
          portable: { children: [{ name: 'B' }] },
        }),
      ),
    ).toEqual({ mode: 'separate', key: 'mobile', sourceKey: 'portable' });
  });

  it('동적 커스텀 디바이스 키("600-900") 보기 + 동명 children 분기 → merge', () => {
    expect(
      resolveBranchSeparationMode('600-900', branched({ '600-900': { children: [] } })),
    ).toEqual({ mode: 'merge', key: '600-900' });
  });

  it('동적 커스텀 디바이스 키("600-900") 보기 + 분기 없음 → separate(600-900)', () => {
    expect(resolveBranchSeparationMode('600-900', plain)).toEqual({
      mode: 'separate',
      key: '600-900',
    });
  });
});
