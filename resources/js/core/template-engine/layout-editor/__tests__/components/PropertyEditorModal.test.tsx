/**
 * PropertyEditorModal.test.tsx — 속성 편집 모달 렌더링
 *
 * 검증 매트릭스:
 *  - 탭 가용성: 집합 컴포넌트(settings) → 설정 탭, styleControls → 스타일 탭, advanced → 고급 탭
 *  - 편집 가능 속성 없음 → 안내
 *  - 고급 값 존재 → 상단 배지
 *  - 스타일 컨트롤 조작 → onPatchNode (applyRecipe 경유)
 *  - 설정 폼 변경 → props 패치
 *  - 고급 탭 permissions TagInput + 보존 목록
 *
 * widgetRegistry 가 등록돼 있어야 ControlRenderer 가 위젯을 디스패치하므로 beforeAll 에서 등록.
 */

import React from 'react';
import { describe, it, expect, vi, beforeAll } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import { PropertyEditorModal } from '../../components/PropertyEditorModal';
import { registerCoreWidgets } from '../../spec/registerCoreWidgets';
import type { EditorSpec } from '../../spec/specTypes';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import type { ComponentManifest } from '../../components/ComponentPalette';

const t = (k: string, params?: Record<string, string | number>) =>
  params ? `${k}(${JSON.stringify(params)})` : k;

beforeAll(() => {
  registerCoreWidgets();
});

const spec: EditorSpec = {
  controls: {
    textAlign: {
      widget: 'segmented',
      group: 'text-align',
      options: [
        { value: 'left', apply: { type: 'classToken', tokens: ['text-left'] } },
        { value: 'center', apply: { type: 'classToken', tokens: ['text-center'] } },
      ],
    },
    textColor: { widget: 'color', group: 'text-color', apply: { type: 'styleProp', prop: 'color' } },
  },
  componentCapabilities: {
    H1: { styleControls: ['textAlign', 'textColor'] },
    Div: { styleControls: ['textColor'], advanced: ['permissions', 'comment'] },
    // 빈 capability 라도 코어 제공 속성(요소 id)으로 속성 탭이 노출된다.
    Plain: {},
    // 코어 속성까지 opt-out 한 컴포넌트만 no-editable (coreProps:false).
    PlainOptOut: { coreProps: false },
  },
};

const manifest: ComponentManifest = {
  components: {
    composite: [
      {
        name: 'RecentPosts',
        type: 'composite',
        settings: {
          groups: [
            {
              label: '$t:editor.component.recent_posts',
              fields: [
                { key: 'count', label: '표시 개수', type: 'number', default: 6, min: 1, max: 20 },
                {
                  key: 'listType',
                  label: '유형',
                  type: 'select',
                  default: 'recent',
                  options: [
                    { value: 'recent', label: '최신글' },
                    { value: 'popular', label: '인기글' },
                  ],
                },
              ],
            },
          ],
        },
      },
    ],
  },
};

function renderModal(node: EditorNode, onPatchNode = vi.fn(), onDelete = vi.fn()) {
  render(
    <PropertyEditorModal
      node={node}
      spec={spec}
      manifest={manifest}
      t={t}
      onPatchNode={onPatchNode}
      onClose={vi.fn()}
      onDelete={onDelete}
      permissionCandidates={[{ value: 'core.users.view', label: '회원 조회' }]}
    />,
  );
  return { onPatchNode, onDelete };
}

describe('PropertyEditorModal — 탭 가용성', () => {
  it('styleControls 보유 컴포넌트 → 스타일 탭 + 컨트롤 렌더', () => {
    renderModal({ name: 'H1' });
    expect(screen.getByTestId('g7le-property-tab-style')).toBeTruthy();
    expect(screen.getByTestId('g7le-control-textAlign')).toBeTruthy();
    expect(screen.getByTestId('g7le-control-textColor')).toBeTruthy();
    // advanced 미선언 → 고급 탭 없음
    expect(screen.queryByTestId('g7le-property-tab-advanced')).toBeNull();
  });

  it('advanced 선언 컴포넌트 → 고급 탭 존재', () => {
    renderModal({ name: 'Div' });
    expect(screen.getByTestId('g7le-property-tab-advanced')).toBeTruthy();
  });

  it('집합 컴포넌트(settings) → 설정 탭 기본 활성', () => {
    renderModal({ name: 'RecentPosts' });
    const tab = screen.getByTestId('g7le-property-tab-settings');
    expect(tab.getAttribute('data-active')).toBe('true');
    expect(screen.getByTestId('g7le-composite-settings')).toBeTruthy();
  });

  it('빈 capability 컴포넌트 → 코어 제공 속성(id)으로 속성 탭 노출 (no-editable 아님)', () => {
    renderModal({ name: 'Plain' });
    expect(screen.queryByTestId('g7le-property-modal-no-editable')).toBeNull();
    expect(screen.getByTestId('g7le-property-tab-props')).toBeTruthy();
    expect(screen.getByTestId('g7le-control-core-id')).toBeTruthy();
  });

  it('coreProps:false 로 opt-out 한 컴포넌트만 no-editable 안내', () => {
    renderModal({ name: 'PlainOptOut' });
    expect(screen.getByTestId('g7le-property-modal-no-editable')).toBeTruthy();
    expect(screen.queryByTestId('g7le-property-tab-props')).toBeNull();
  });

  it('initialTab="translation" → 진입 즉시 번역 탭 활성', () => {
    render(
      <PropertyEditorModal
        node={{ name: 'H1', text: '$t:custom.home.1' }}
        spec={spec}
        manifest={manifest}
        t={t}
        onPatchNode={vi.fn()}
        onClose={vi.fn()}
        onDelete={vi.fn()}
        templateIdentifier="sirsoft-basic"
        initialTab="translation"
      />,
    );
    const tab = screen.getByTestId('g7le-property-tab-translation');
    expect(tab.getAttribute('data-active')).toBe('true');
    // 스타일 탭이 있으면 비활성(initialTab 우선).
    const style = screen.queryByTestId('g7le-property-tab-style');
    if (style) expect(style.getAttribute('data-active')).toBe('false');
  });

  it('initialTab 이 가용 목록에 없으면 무시 → 기본 규칙(설정/스타일) 적용', () => {
    // settings 보유 컴포넌트인데 initialTab="advanced"(미선언) → settings 가 활성.
    render(
      <PropertyEditorModal
        node={{ name: 'RecentPosts' }}
        spec={spec}
        manifest={manifest}
        t={t}
        onPatchNode={vi.fn()}
        onClose={vi.fn()}
        onDelete={vi.fn()}
        initialTab="advanced"
      />,
    );
    expect(screen.getByTestId('g7le-property-tab-settings').getAttribute('data-active')).toBe('true');
  });
});

describe('PropertyEditorModal — 고급 값 배지 / 무손실 보존', () => {
  it('복잡 표현식/개발자 속성 보유 → 상단 배지', () => {
    renderModal({ name: 'Div', props: { data_binding: { source: 'x' } } });
    expect(screen.getByTestId('g7le-property-modal-advanced-badge')).toBeTruthy();
  });

  it('고급 값 없으면 배지 미표시', () => {
    renderModal({ name: 'H1', text: '제목', props: { className: 'text-xl' } });
    expect(screen.queryByTestId('g7le-property-modal-advanced-badge')).toBeNull();
  });
});

describe('PropertyEditorModal — 컨트롤 조작 → 패치', () => {
  it('스타일 탭 segmented 클릭 → onPatchNode(applyRecipe 결과)', () => {
    const { onPatchNode } = renderModal({ name: 'H1' });
    fireEvent.click(screen.getByTestId('g7le-segment-center'));
    expect(onPatchNode).toHaveBeenCalled();
    const patched = onPatchNode.mock.calls.at(-1)![0] as EditorNode;
    expect(patched.props?.className).toBe('text-center');
  });

  it('설정 탭 number 필드 변경 → props 패치', () => {
    const { onPatchNode } = renderModal({ name: 'RecentPosts' });
    const input = screen.getByTestId('g7le-setting-input-count');
    fireEvent.change(input, { target: { value: '10' } });
    expect(onPatchNode).toHaveBeenCalled();
    const patched = onPatchNode.mock.calls.at(-1)![0] as EditorNode;
    expect(patched.props?.count).toBe(10);
  });
});

describe('PropertyEditorModal — 고급 탭', () => {
  it('permissions TagInput + 보존 목록 렌더', () => {
    renderModal({ name: 'Div', props: { data_binding: { source: 'x' } } });
    fireEvent.click(screen.getByTestId('g7le-property-tab-advanced'));
    expect(screen.getByTestId('g7le-advanced-permissions')).toBeTruthy();
    // data_binding 은 보존 목록에 (props.data_binding 키)
    expect(screen.getByTestId('g7le-advanced-preserved')).toBeTruthy();
    expect(within(screen.getByTestId('g7le-advanced-preserved')).getByText(/data_binding/)).toBeTruthy();
  });

  it('permissions 후보에서 칩 추가 → onPatchNode(permissions 배열)', () => {
    const { onPatchNode } = renderModal({ name: 'Div' });
    fireEvent.click(screen.getByTestId('g7le-property-tab-advanced'));
    fireEvent.click(screen.getByTestId('g7le-tag-add'));
    fireEvent.click(screen.getByTestId('g7le-tag-candidate-core.users.view'));
    expect(onPatchNode).toHaveBeenCalled();
    const patched = onPatchNode.mock.calls.at(-1)![0] as EditorNode;
    expect(patched.permissions).toEqual(['core.users.view']);
  });
});

describe('PropertyEditorModal — 삭제 버튼', () => {
  it('삭제 클릭 → onDelete', () => {
    const { onDelete } = renderModal({ name: 'H1' });
    fireEvent.click(screen.getByTestId('g7le-property-modal-delete'));
    expect(onDelete).toHaveBeenCalled();
  });
});

// 스타일/표시조건 탭의 색 모드 × 디바이스 세부탭 + scope 스냅샷
describe('PropertyEditorModal — scope 세부탭', () => {
  function renderWithScope(
    node: EditorNode,
    scope?: { colorScheme: 'base' | 'dark'; breakpoint: string },
    onScopeChange = vi.fn(),
  ) {
    render(
      <PropertyEditorModal
        node={node}
        spec={spec}
        manifest={manifest}
        t={t}
        onPatchNode={vi.fn()}
        onClose={vi.fn()}
        onDelete={vi.fn()}
        scope={scope}
        onScopeChange={onScopeChange}
      />,
    );
    return { onScopeChange };
  }

  it('스타일 탭 상단에 색 모드 + 디바이스 세부탭 렌더', () => {
    renderWithScope({ name: 'H1' });
    expect(screen.getByTestId('g7le-style-scope-tabs')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-style-scheme-base')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-style-bp-tablet')).toBeInTheDocument();
  });

  it('표시조건 탭 세부탭은 디바이스만(색 모드 줄 숨김, D9)', () => {
    renderWithScope({ name: 'Div' }); // Div 는 styleControls + advanced → visibility 가용
    fireEvent.click(screen.getByTestId('g7le-property-tab-visibility'));
    expect(screen.getByTestId('g7le-style-scope-device')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-style-scope-scheme')).toBeNull();
  });

  it('초기 scope prop = mobile 이면 모바일 세부탭 활성(스냅샷, D1/D2)', () => {
    renderWithScope({ name: 'H1' }, { colorScheme: 'base', breakpoint: 'mobile' });
    expect(screen.getByTestId('g7le-style-bp-mobile').getAttribute('data-active')).toBe('true');
    expect(screen.getByTestId('g7le-style-bp-base').getAttribute('data-active')).toBe('false');
  });

  it('세부탭 클릭 → onScopeChange 콜백(상위 스냅샷 갱신)', () => {
    const { onScopeChange } = renderWithScope({ name: 'H1' });
    fireEvent.click(screen.getByTestId('g7le-style-bp-tablet'));
    expect(onScopeChange).toHaveBeenCalledWith({ colorScheme: 'base', breakpoint: 'tablet' });
  });

  it('tablet override 노드 + tablet scope → "기본값으로 초기화" 클릭 시 onPatchNode(브랜치 제거)', () => {
    const onPatchNode = vi.fn();
    render(
      <PropertyEditorModal
        node={{
          name: 'H1',
          props: { className: 'text-left' },
          responsive: { tablet: { props: { className: 'text-left text-center' } } },
        }}
        spec={spec}
        manifest={manifest}
        t={t}
        onPatchNode={onPatchNode}
        onClose={vi.fn()}
        onDelete={vi.fn()}
        scope={{ colorScheme: 'base', breakpoint: 'tablet' }}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-style-scope-reset'));
    const patched = onPatchNode.mock.calls.at(-1)![0] as EditorNode;
    expect(patched.responsive).toBeUndefined();
    expect(patched.props?.className).toBe('text-left'); // base 불변
  });
});
