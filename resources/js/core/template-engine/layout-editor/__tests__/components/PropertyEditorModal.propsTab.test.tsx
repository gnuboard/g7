/**
 * PropertyEditorModal.propsTab.test.tsx — 속성 탭 + 노드 에디터 디스패치
 *
 * 검증 매트릭스:
 *  - propControls 보유 → [속성] 탭 등장 + 탭 순서(설정→속성→스타일) + BASE_SCOPE 컨트롤 렌더
 *  - propControls 만 보유 → no-editable 탈출(안내 없음) + 속성 탭 기본 활성
 *  - capability.nodeEditor.kind 등록 핸들러 → [속성] 탭에 직접 렌더(kind-agnostic, 구조=CSS 아님)
 *  - nodeEditor.kind 미등록 → 디그레이드(렌더 0, 회귀 0)
 */

import React from 'react';
import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { PropertyEditorModal } from '../../components/PropertyEditorModal';
import { registerCoreWidgets } from '../../spec/registerCoreWidgets';
import {
  registerNodeEditor,
  clearNodeEditorRegistry,
} from '../../spec/nodeEditorRegistry';
import type { EditorSpec } from '../../spec/specTypes';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const t = (k: string, params?: Record<string, string | number>) =>
  params ? `${k}(${JSON.stringify(params)})` : k;

beforeAll(() => {
  registerCoreWidgets();
});
beforeEach(() => {
  clearNodeEditorRegistry();
});

const spec: EditorSpec = {
  controls: {
    iconName: {
      widget: 'text',
      label: '$t:editor.control.icon_name',
      apply: { type: 'propValue', propKey: 'name' },
    },
    imgSrc: {
      widget: 'text',
      label: '$t:editor.control.img_src',
      apply: { type: 'propValue', propKey: 'src' },
    },
    textColor: {
      widget: 'color',
      group: 'text-color',
      apply: { type: 'styleProp', prop: 'color' },
    },
  },
  componentCapabilities: {
    // 속성 prop 만 보유 — 스타일/설정/동작/고급 없음. no-editable 탈출만 확인.
    Icon: { propControls: ['iconName'] },
    // 속성 + 스타일 둘 다 보유 — 탭 순서(속성→스타일) 확인.
    Img: { propControls: ['imgSrc'], styleControls: ['textColor'] },
    // 노드 에디터 슬롯 — children kind(단계 2 빌트인). 테스트에서 더미 등록.
    Ul: { nodeEditor: { kind: 'children', params: { childComponent: 'Li' } } },
    // 미등록 kind — 디그레이드 확인.
    Mystery: { nodeEditor: { kind: 'no-such-kind' } },
  },
};

function renderModal(node: EditorNode) {
  render(
    <PropertyEditorModal
      node={node}
      spec={spec}
      manifest={null}
      t={t}
      onPatchNode={vi.fn()}
      onClose={vi.fn()}
      onDelete={vi.fn()}
    />,
  );
}

describe('PropertyEditorModal — 속성(props) 탭', () => {
  it('propControls 보유 → [속성] 탭 등장 + no-editable 탈출', () => {
    renderModal({ name: 'Icon' });
    expect(screen.getByTestId('g7le-property-tab-props')).toBeTruthy();
    expect(screen.queryByTestId('g7le-property-modal-no-editable')).toBeNull();
  });

  it('propControls 만 보유 → 속성 탭 기본 활성 + propValue 컨트롤 렌더(BASE_SCOPE)', () => {
    renderModal({ name: 'Icon' });
    const tab = screen.getByTestId('g7le-property-tab-props');
    expect(tab.getAttribute('data-active')).toBe('true');
    expect(screen.getByTestId('g7le-property-props-tab')).toBeTruthy();
    expect(screen.getByTestId('g7le-control-iconName')).toBeTruthy();
  });

  it('탭 순서: 속성 탭이 스타일 탭보다 앞(설정→속성→스타일)', () => {
    renderModal({ name: 'Img' });
    const bar = screen.getByRole('tablist');
    const order = Array.from(bar.querySelectorAll('[role="tab"]')).map((b) =>
      b.getAttribute('data-testid'),
    );
    const propsIdx = order.indexOf('g7le-property-tab-props');
    const styleIdx = order.indexOf('g7le-property-tab-style');
    expect(propsIdx).toBeGreaterThanOrEqual(0);
    expect(styleIdx).toBeGreaterThan(propsIdx);
  });
});

describe('PropertyEditorModal — nodeEditor kind-agnostic 디스패치', () => {
  it('등록된 kind → [속성] 탭에 노드 에디터 직접 렌더(구조 편집=CSS 아님)', () => {
    const DummyChildren = (): React.ReactElement => (
      <div data-testid="dummy-children-editor" />
    );
    registerNodeEditor('children', DummyChildren);
    renderModal({ name: 'Ul' });
    // 구조 에디터(nodeEditor)는 [속성] 탭에 둔다(스타일 탭=CSS 전용). 구조 에디터 보유는
    // 속성 탭을 기본 활성으로 띄운다 → 진입 즉시 더미 에디터가 렌더된다.
    expect(screen.getByTestId('g7le-property-tab-props')).toBeTruthy();
    expect(screen.getByTestId('dummy-children-editor')).toBeTruthy();
    expect(screen.queryByTestId('g7le-property-modal-no-editable')).toBeNull();
  });

  it('미등록 kind → 디그레이드(노드 에디터 렌더 0, 속성 탭은 코어 id 로 유지)', () => {
    renderModal({ name: 'Mystery' });
    // 등록 핸들러 없음 → hasNodeEditor=false → 노드 에디터 미렌더.
    expect(screen.queryByTestId('dummy-children-editor')).toBeNull();
    // 단, 코어 제공 속성(id)으로 속성 탭은 노출되어 no-editable 은 아니다.
    // 미등록 nodeEditor 의 디그레이드는 "그 에디터가 안 뜬다"는 의미이지, 컴포넌트 전체가
    // 편집 불가가 되는 것은 아니다(코어 id 는 항상 제공, coreProps:false 일 때만 차단).
    expect(screen.queryByTestId('g7le-property-modal-no-editable')).toBeNull();
    expect(screen.getByTestId('g7le-property-tab-props')).toBeTruthy();
  });
});

describe('PropertyEditorModal — 데이터 연결(dataProps) 영역', () => {
  const bindingSpec: EditorSpec = {
    controls: {
      // 구조/수치 prop — 정적 propControl(데이터 연결 비대상, 회귀 가드용).
      colCount: { widget: 'slider', apply: { type: 'propValue', propKey: 'columns' } },
    },
    componentCapabilities: {
      // 데이터형 prop(배열) 선언 → 데이터 연결 영역 노출.
      CardGrid: { dataProps: [{ propKey: 'data', shape: 'array', itemFields: ['name'] }] },
      // 구조/수치 prop 만(dataProps 없음) → 데이터 연결 영역 0(1차 결함 근절 회귀 가드).
      Spacer: { propControls: ['colCount'] },
    },
  };

  function renderBinding(node: EditorNode) {
    render(
      <PropertyEditorModal
        node={node}
        spec={bindingSpec}
        manifest={null}
        t={t}
        onPatchNode={vi.fn()}
        onClose={vi.fn()}
        onDelete={vi.fn()}
        bindingCandidates={[
          {
            expression: '{{products.data.data}}',
            source: 'data_source',
            sourceId: 'products',
            path: 'data.data',
            shape: 'array',
            preview: '[3]',
            itemFields: ['name'],
          },
        ]}
      />,
    );
  }

  it('dataProps 선언 컴포넌트 → [속성] 탭에 데이터 연결 영역 노출 + 기본 활성', () => {
    renderBinding({ name: 'CardGrid', props: {} });
    expect(screen.getByTestId('g7le-property-tab-props').getAttribute('data-active')).toBe('true');
    expect(screen.getByTestId('g7le-data-binding-section')).toBeTruthy();
    expect(screen.getByTestId('g7le-binding-row-data')).toBeTruthy();
  });

  it('회귀 가드: 구조/수치 prop 만 보유(dataProps 없음) → 데이터 연결 영역 0', () => {
    renderBinding({ name: 'Spacer', props: {} });
    // propControls(구조/수치)는 속성 탭에 있으나 데이터 연결 섹션은 렌더되지 않는다.
    expect(screen.queryByTestId('g7le-data-binding-section')).toBeNull();
    expect(screen.getByTestId('g7le-control-colCount')).toBeTruthy();
  });

  // iteration(반복) 노드 공용 데이터 연결. node.iteration 은 구조 키라
  // capability 무관 — capability 에 dataProps/nodeEditor 가 없는 순수 Div 라도 iteration 이
  // 있으면 "반복 데이터 연결" 영역이 [속성] 탭에 떠야 한다.
  it('iteration 노드(capability dataProps 무관) → [속성] 탭에 반복 데이터 연결 영역 노출 + no-editable 탈출', () => {
    renderBinding({ name: 'Spacer', iteration: { source: '', item_var: 'item' } });
    expect(screen.getByTestId('g7le-property-tab-props').getAttribute('data-active')).toBe('true');
    expect(screen.getByTestId('g7le-iteration-binding-section')).toBeTruthy();
    expect(screen.queryByTestId('g7le-property-modal-no-editable')).toBeNull();
  });

  it('iteration 없는 노드 → 반복 데이터 연결 영역 0(노드 구조 기반 게이트)', () => {
    renderBinding({ name: 'CardGrid', props: {} });
    expect(screen.queryByTestId('g7le-iteration-binding-section')).toBeNull();
  });
});
