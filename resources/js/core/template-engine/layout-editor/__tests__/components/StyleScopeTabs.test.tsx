/**
 * StyleScopeTabs.test.tsx — 색 모드 × 디바이스 세부탭
 *
 * - 2 색 모드 + 4 고정 디바이스 탭 렌더, 클릭 시 scope 변경
 * - showColorScheme=false (표시조건 탭) → 색 모드 줄 숨김
 * - 노드 responsive["600-900"] 존재 시 동적 커스텀 탭 렌더
 * - [+ 커스텀 크기] 로 min-max 키 생성, min>max 거부
 * - 활성 커스텀 scope 는 노드에 흔적 없어도 탭 유지(미편집 prune 전 보호)
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { StyleScopeTabs } from '../../components/property-controls/StyleScopeTabs';
import { BASE_SCOPE, type StyleScope } from '../../spec/styleScope';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const t = (k: string) => k;

afterEach(() => cleanup());

function renderTabs(
  opts: {
    scope?: StyleScope;
    node?: EditorNode;
    showColorScheme?: boolean;
    onClearScope?: () => void;
  } = {},
) {
  const onChange = vi.fn();
  render(
    <StyleScopeTabs
      scope={opts.scope ?? BASE_SCOPE}
      onChange={onChange}
      node={opts.node ?? { name: 'Div' }}
      t={t}
      showColorScheme={opts.showColorScheme}
      onClearScope={opts.onClearScope}
    />,
  );
  return { onChange };
}

describe('StyleScopeTabs — 고정 탭 렌더', () => {
  // @scenario area=scope_tabs
  // @effects scope_tabs_render_scheme_and_device_rows
  it('색 모드 2탭 + 디바이스 4탭', () => {
    renderTabs();
    expect(screen.getByTestId('g7le-style-scheme-base')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-style-scheme-dark')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-style-bp-base')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-style-bp-desktop')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-style-bp-tablet')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-style-bp-mobile')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-style-bp-add-custom')).toBeInTheDocument();
  });

  // @scenario area=scope_tabs
  // @effects scope_tabs_hide_scheme_row_when_disabled
  it('showColorScheme=false 면 색 모드 줄 숨김', () => {
    renderTabs({ showColorScheme: false });
    expect(screen.queryByTestId('g7le-style-scheme-base')).toBeNull();
    expect(screen.getByTestId('g7le-style-bp-base')).toBeInTheDocument();
  });

  // 칩 버튼이 아니라 서브탭(탭) UI 구조여야 한다.
  // @scenario area=scope_tabs
  // @effects scope_tabs_use_tablist_semantics
  it('서브탭 시맨틱 — tablist/tab role + 활성 탭 aria-selected', () => {
    renderTabs({ scope: { colorScheme: 'dark', breakpoint: 'tablet' } });
    // 색 모드/디바이스 두 줄 모두 tablist
    expect(screen.getByTestId('g7le-style-scope-scheme')).toHaveAttribute('role', 'tablist');
    expect(screen.getByTestId('g7le-style-scope-device')).toHaveAttribute('role', 'tablist');
    // 각 탭은 role=tab
    expect(screen.getByTestId('g7le-style-scheme-dark')).toHaveAttribute('role', 'tab');
    expect(screen.getByTestId('g7le-style-bp-tablet')).toHaveAttribute('role', 'tab');
    // 활성 탭만 aria-selected=true
    expect(screen.getByTestId('g7le-style-scheme-dark')).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByTestId('g7le-style-scheme-base')).toHaveAttribute('aria-selected', 'false');
    expect(screen.getByTestId('g7le-style-bp-tablet')).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByTestId('g7le-style-bp-base')).toHaveAttribute('aria-selected', 'false');
  });
});

describe('StyleScopeTabs — 클릭 시 scope 변경', () => {
  // @scenario area=scope_tabs
  // @effects scope_tabs_click_reports_scope
  it('다크 탭 클릭 → colorScheme:dark', () => {
    const { onChange } = renderTabs();
    fireEvent.click(screen.getByTestId('g7le-style-scheme-dark'));
    expect(onChange).toHaveBeenCalledWith({ colorScheme: 'dark', breakpoint: 'base' });
  });

  // @scenario area=scope_tabs
  // @effects scope_tabs_click_reports_scope
  it('태블릿 탭 클릭 → breakpoint:tablet', () => {
    const { onChange } = renderTabs();
    fireEvent.click(screen.getByTestId('g7le-style-bp-tablet'));
    expect(onChange).toHaveBeenCalledWith({ colorScheme: 'base', breakpoint: 'tablet' });
  });
});

describe('StyleScopeTabs — 커스텀 범위 탭', () => {
  // @scenario area=scope_tabs
  // @effects scope_tabs_render_custom_range_from_node
  it('노드 responsive["600-900"] 존재 시 동적 탭 렌더', () => {
    renderTabs({
      node: { name: 'Div', responsive: { '600-900': { props: { className: 'x' } } } },
    });
    expect(screen.getByTestId('g7le-style-bp-custom-600-900')).toBeInTheDocument();
  });

  // @scenario area=scope_tabs
  // @effects scope_tabs_custom_range_add_validates
  it('[+ 커스텀 크기] → min-max 키 생성', () => {
    const { onChange } = renderTabs();
    fireEvent.click(screen.getByTestId('g7le-style-bp-add-custom'));
    fireEvent.change(screen.getByTestId('g7le-style-custom-min'), { target: { value: '600' } });
    fireEvent.change(screen.getByTestId('g7le-style-custom-max'), { target: { value: '900' } });
    fireEvent.click(screen.getByTestId('g7le-style-custom-confirm'));
    expect(onChange).toHaveBeenCalledWith({ colorScheme: 'base', breakpoint: '600-900' });
  });

  // @scenario area=scope_tabs
  // @effects scope_tabs_custom_range_add_validates
  it('min>max 는 거부(에러 표시, onChange 미호출)', () => {
    const { onChange } = renderTabs();
    fireEvent.click(screen.getByTestId('g7le-style-bp-add-custom'));
    fireEvent.change(screen.getByTestId('g7le-style-custom-min'), { target: { value: '900' } });
    fireEvent.change(screen.getByTestId('g7le-style-custom-max'), { target: { value: '600' } });
    fireEvent.click(screen.getByTestId('g7le-style-custom-confirm'));
    expect(screen.getByTestId('g7le-style-custom-error')).toBeInTheDocument();
    expect(onChange).not.toHaveBeenCalled();
  });

  // @scenario area=scope_tabs
  // @effects scope_tabs_keep_active_custom_tab
  it('활성 커스텀 scope 는 노드에 흔적 없어도 탭 유지', () => {
    renderTabs({
      scope: { colorScheme: 'base', breakpoint: '600-900' },
      node: { name: 'Div' }, // responsive 없음(미편집)
    });
    expect(screen.getByTestId('g7le-style-bp-custom-600-900')).toBeInTheDocument();
  });

  // @scenario area=scope_tabs
  // @effects scope_tabs_custom_scope_priority_note
  it('커스텀 활성 시 우선순위 안내 노출', () => {
    renderTabs({
      scope: { colorScheme: 'base', breakpoint: '600-900' },
      node: { name: 'Div' },
    });
    expect(screen.getByTestId('g7le-style-custom-priority-note')).toBeInTheDocument();
  });
});

describe('StyleScopeTabs — 표시점(●)', () => {
  // @scenario area=scope_tabs
  // @effects override_dot_shown_only_when_scope_differs_from_base_not_for_seed_only
  it('base 와 다른 override 가진 디바이스 탭에 표시점', () => {
    renderTabs({
      node: {
        name: 'Div',
        props: { className: 'bg-white' },
        responsive: { tablet: { props: { className: 'bg-white text-center' } } },
      },
    });
    expect(screen.getByTestId('g7le-style-bp-tablet-dot')).toBeInTheDocument();
    // 미설정 mobile 은 점 없음
    expect(screen.queryByTestId('g7le-style-bp-mobile-dot')).toBeNull();
  });

  // @scenario area=scope_tabs
  // @effects override_dot_shown_only_when_scope_differs_from_base_not_for_seed_only
  it('시드만(base 동일) 디바이스는 표시점 없음', () => {
    renderTabs({
      node: {
        name: 'Div',
        props: { className: 'bg-white rounded' },
        responsive: { tablet: { props: { className: 'rounded bg-white' } } },
      },
    });
    expect(screen.queryByTestId('g7le-style-bp-tablet-dot')).toBeNull();
  });

  // @scenario area=scope_tabs
  // @effects override_dot_shown_only_when_scope_differs_from_base_not_for_seed_only
  it('다크 토큰 보유 시 다크 탭에 표시점', () => {
    renderTabs({
      node: { name: 'Div', props: { className: 'bg-white dark:bg-slate-800' } },
    });
    expect(screen.getByTestId('g7le-style-scheme-dark-dot')).toBeInTheDocument();
  });
});

describe('StyleScopeTabs — 기본값으로 초기화', () => {
  // @scenario area=scope_tabs
  // @effects scope_tabs_reset_needs_override_and_handler
  it('override 있고 onClearScope 공급 시 초기화 버튼 노출 + 클릭 콜백', () => {
    const onClearScope = vi.fn();
    renderTabs({
      scope: { colorScheme: 'base', breakpoint: 'tablet' },
      node: {
        name: 'Div',
        props: { className: 'bg-white' },
        responsive: { tablet: { props: { className: 'bg-white text-center' } } },
      },
      onClearScope,
    });
    const btn = screen.getByTestId('g7le-style-scope-reset');
    expect(btn).toBeInTheDocument();
    fireEvent.click(btn);
    expect(onClearScope).toHaveBeenCalled();
  });

  // @scenario area=scope_tabs
  // @effects scope_tabs_reset_needs_override_and_handler
  it('override 없으면 초기화 버튼 비노출', () => {
    renderTabs({
      scope: { colorScheme: 'base', breakpoint: 'tablet' },
      node: { name: 'Div', props: { className: 'bg-white' } }, // tablet override 없음
      onClearScope: vi.fn(),
    });
    expect(screen.queryByTestId('g7le-style-scope-reset')).toBeNull();
  });

  // @scenario area=scope_tabs
  // @effects scope_tabs_reset_needs_override_and_handler
  it('onClearScope 미공급 시 버튼 비노출', () => {
    renderTabs({
      scope: { colorScheme: 'base', breakpoint: 'tablet' },
      node: {
        name: 'Div',
        props: { className: 'bg-white' },
        responsive: { tablet: { props: { className: 'bg-white text-center' } } },
      },
      // onClearScope 미공급
    });
    expect(screen.queryByTestId('g7le-style-scope-reset')).toBeNull();
  });
});
