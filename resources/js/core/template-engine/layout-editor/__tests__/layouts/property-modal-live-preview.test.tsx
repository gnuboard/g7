/**
 * property-modal-live-preview.test.tsx — 속성 모달 ↔ 캔버스 라이브 미리보기
 *
 * 속성 편집 모달의 스타일 컨트롤을 조작하면 그 패치(applyRecipe 결과)가 캔버스에서
 * 실제로 렌더되는 노드의 className/style 로 즉시 반영되는지(라이브 미리보기) 검증한다.
 *
 *  의 ControlRenderer 단위 테스트(컨트롤→onPatch)와 달리, 본 테스트는
 * "패치된 노드를 실제 DynamicRenderer 가 렌더하면 DOM 에 그 스타일이 적용된다"는
 * 모달→캔버스 통합 라운드트립을 다룬다("모달 컨트롤 조작 → 캔버스 노드
 * className/style 즉시 변경"). DynamicRenderer 는 실제 API(componentDef + 엔진 주입)로
 * 렌더하며(editor-attrs-passthrough.test.tsx 패턴), 컨트롤 패치 결과를 componentDef.props 로
 * 흘려 재렌더가 캔버스에 반영되는지 확인한다.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, fireEvent, cleanup, act } from '@testing-library/react';
import DynamicRenderer from '../../../DynamicRenderer';
import { ComponentRegistry } from '../../../ComponentRegistry';
import { DataBindingEngine } from '../../../DataBindingEngine';
import { TranslationEngine } from '../../../TranslationEngine';
import { ActionDispatcher } from '../../../ActionDispatcher';
import { ControlRenderer } from '../../components/property-controls/ControlRenderer';
import { registerCoreWidgets } from '../../spec/registerCoreWidgets';
import { applyRecipe } from '../../spec/recipeEngine';
import { collectAdvancedValues } from '../../utils/advancedValueUtils';
import type { EditorControlSpec } from '../../spec/specTypes';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const t = (k: string) => k;

beforeEach(() => registerCoreWidgets());
afterEach(() => cleanup());

const textAlign: EditorControlSpec = {
  widget: 'segmented',
  group: 'text-align',
  options: [
    { value: 'left', apply: { type: 'classToken', tokens: ['text-left'] } },
    { value: 'center', apply: { type: 'classToken', tokens: ['text-center'] } },
  ],
};

const textColor: EditorControlSpec = { widget: 'color', apply: { type: 'styleProp', prop: 'color' } };

// editor-spec 의 backgroundImage 컨트롤과 동일 형태(다중 background 속성, values 미선언).
const bgImage: EditorControlSpec = {
  widget: 'image',
  group: 'bg-image',
  apply: {
    type: 'styleProp',
    props: ['backgroundImage', 'backgroundSize', 'backgroundRepeat', 'backgroundPosition'],
  },
};

/** 캔버스용 격리 레지스트리 — H1/P/Div 를 props 패스스루하는 basic 컴포넌트로 등록 */
function makeRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.createIsolatedInstance();
  const Passthrough = (tag: string) => (props: Record<string, unknown>) =>
    React.createElement(tag, props, (props.children as React.ReactNode) ?? (props.text as React.ReactNode));
  (registry as unknown as { registry: Record<string, unknown> }).registry.H1 = {
    component: Passthrough('h1'),
    metadata: { name: 'H1', type: 'basic' },
  };
  (registry as unknown as { registry: Record<string, unknown> }).registry.P = {
    component: Passthrough('p'),
    metadata: { name: 'P', type: 'basic' },
  };
  (registry as unknown as { registry: Record<string, unknown> }).registry.Div = {
    component: Passthrough('div'),
    metadata: { name: 'Div', type: 'basic' },
  };
  return registry;
}

/**
 * 모달 컨트롤 + 캔버스를 한 화면에 묶은 하네스.
 * 컨트롤 onPatch → 상위 node state 갱신 → componentDef.props 갱신 → DynamicRenderer 재렌더.
 */
function ModalCanvasHarness({ control, controlKey, initial, tag }: {
  control: EditorControlSpec;
  controlKey: string;
  initial: EditorNode;
  tag: 'H1' | 'P' | 'Div';
}): React.ReactElement {
  const [node, setNode] = React.useState<EditorNode>(initial);
  const registry = React.useMemo(makeRegistry, []);
  const bindingEngine = React.useMemo(() => new DataBindingEngine(), []);
  const translationEngine = React.useMemo(() => new TranslationEngine(), []);
  const actionDispatcher = React.useMemo(() => new ActionDispatcher({}), []);

  const componentDef = {
    name: tag,
    type: 'basic',
    props: { ...(node.props ?? {}), text: node.text ?? '미리보기' },
  };

  return (
    <div>
      <div data-testid="harness-modal">
        <ControlRenderer controlKey={controlKey} control={control} node={node} t={t} onPatch={setNode} />
      </div>
      <div data-testid="harness-canvas">
        {React.createElement(DynamicRenderer, {
          componentDef,
          dataContext: {},
          registry,
          bindingEngine,
          translationEngine,
          actionDispatcher,
          isEditMode: false,
          isRootRenderer: true,
          componentPath: '0',
        } as never)}
      </div>
    </div>
  );
}

// fontWeight — 옵션은 일부지만 groupTokens 로 패밀리 전체 선언.
const fontWeightWithFamily: EditorControlSpec = {
  widget: 'segmented',
  group: 'font-weight',
  groupTokens: ['font-thin', 'font-light', 'font-normal', 'font-medium', 'font-semibold', 'font-bold'],
  options: [
    { value: 'font-normal', apply: { type: 'classToken', tokens: ['font-normal'] } },
    { value: 'font-bold', apply: { type: 'classToken', tokens: ['font-bold'] } },
  ],
} as unknown as EditorControlSpec;

describe('속성 모달 → 캔버스 라이브 미리보기 ', () => {
  it('속성 모달 굵기 컨트롤도 groupTokens 로 옵션 밖 기본 토큰(font-medium) 교체 — 툴바와 동일 엔진', () => {
    // 속성 모달은 ControlRenderer → applyRecipe 를 호출(서식 툴바와 같은 엔진). groupTokens 가
    // 엔진 레벨이므로 모달에도 자동 적용됨을 캔버스 라이브 반영으로 검증.
    const { getByTestId } = render(
      <ModalCanvasHarness
        controlKey="fontWeight"
        control={fontWeightWithFamily}
        initial={{ name: 'P', text: '본문', props: { className: 'font-medium' } }}
        tag="P"
      />,
    );
    act(() => {
      fireEvent.click(getByTestId('g7le-segment-font-bold'));
    });
    const canvas = getByTestId('harness-canvas');
    const el = canvas.querySelector('[class]') as HTMLElement | null;
    const cls = el?.className ?? '';
    expect(cls).toContain('font-bold');
    expect(cls).not.toContain('font-medium'); // 옵션 밖 기본 토큰도 교체됨(모달 경로)
  });

  it('정렬(segmented) 조작 → 캔버스 노드 className 즉시 변경', () => {
    const { getByTestId } = render(
      <ModalCanvasHarness controlKey="textAlign" control={textAlign} initial={{ name: 'H1', text: '제목' }} tag="H1" />,
    );
    act(() => {
      fireEvent.click(getByTestId('g7le-segment-center'));
    });
    const canvas = getByTestId('harness-canvas');
    expect(canvas.querySelector('.text-center')).toBeTruthy();
  });

  it('글자 색상(color) 조작 → 캔버스 노드 inline style color 즉시 변경', () => {
    const { getByTestId } = render(
      <ModalCanvasHarness controlKey="textColor" control={textColor} initial={{ name: 'P', text: '본문' }} tag="P" />,
    );
    act(() => {
      fireEvent.blur(getByTestId('g7le-color-hex'), { target: { value: '#1a1a1a' } });
    });
    const canvas = getByTestId('harness-canvas');
    const styled = canvas.querySelector('[style*="color"]') as HTMLElement | null;
    expect(styled).toBeTruthy();
    // React DOM 은 inline style 의 hex 를 rgb() 로 정규화해 렌더한다 (#1a1a1a → rgb(26,26,26))
    expect((styled!.getAttribute('style') ?? '').replace(/\s/g, '')).toContain('color:rgb(26,26,26)');
  });

  it('정렬 변경 시 기존 group 토큰(text-left)이 교체되고 비-group 토큰(font-bold)은 보존된다', () => {
    const { getByTestId } = render(
      <ModalCanvasHarness
        controlKey="textAlign"
        control={textAlign}
        initial={{ name: 'H1', text: '제목', props: { className: 'text-left font-bold' } }}
        tag="H1"
      />,
    );
    act(() => {
      fireEvent.click(getByTestId('g7le-segment-center'));
    });
    const el = getByTestId('harness-canvas').querySelector('.text-center') as HTMLElement | null;
    expect(el).toBeTruthy();
    expect(el!.className).toContain('font-bold');
    expect(el!.className).not.toContain('text-left');
  });

  it('배경 이미지(image) 조작 → 캔버스 노드 inline background-image url(...) 즉시 반영', () => {
    // 본 결함을 통합 레이어에서 재현: image 위젯 onChange 객체값을 applyRecipe 가
    // 패치하면 DynamicRenderer 캔버스 DOM 에 실제 `background-image: url(...)` 가 찍혀야
    // 한다. (ImagePickerControl 위젯 본체는 LayoutEditor/Modal Provider 의존이 있어 본
    // 통합 하네스에서 직접 마운트하지 않고, 위젯 onChange 가 만드는 객체값을 그대로 주입.)
    const registry = makeRegistry();
    const bindingEngine = new DataBindingEngine();
    const translationEngine = new TranslationEngine();
    const actionDispatcher = new ActionDispatcher({});
    const base: EditorNode = { name: 'Div', text: '영역', props: { className: 'p-4' } };
    // ImagePickerControl.onChange(fill 모드) 가 만드는 값 객체
    const patched = applyRecipe(base, bgImage, {
      url: 'https://example.com/api/templates/sirsoft-basic/layout-attachments/hero.jpg',
      size: 'cover',
      repeat: 'no-repeat',
      position: 'center',
    });
    const componentDef = {
      name: 'Div',
      type: 'basic',
      props: { ...(patched.props ?? {}), text: patched.text ?? '영역' },
    };
    const { container } = render(
      React.createElement(DynamicRenderer, {
        componentDef,
        dataContext: {},
        registry,
        bindingEngine,
        translationEngine,
        actionDispatcher,
        isEditMode: false,
        isRootRenderer: true,
        componentPath: '0',
      } as never),
    );
    const styled = container.querySelector('[style*="background-image"]') as HTMLElement | null;
    expect(styled).toBeTruthy();
    const styleAttr = styled!.getAttribute('style') ?? '';
    // 브라우저(jsdom)는 url() 내부에 따옴표를 추가 정규화한다 — url(...) 함수 형태 + 도메인 확인.
    expect(styleAttr).toMatch(
      /background-image:\s*url\(["']?https:\/\/example\.com\/api\/templates\/sirsoft-basic\/layout-attachments\/hero\.jpg["']?\)/,
    );
    // 기본 모드(fill) — cover / no-repeat / center
    expect(styleAttr).toContain('background-size: cover');
    expect(styleAttr).toContain('background-repeat: no-repeat');
    expect(styleAttr).toMatch(/background-position:\s*center/);
  });

  it('역해석 불가한 고급 값(파이프 표현식·개발자 속성)은 패치 후에도 보존된다 (무손실)', () => {
    // 모달이 텍스트색을 패치해도 data_binding·파이프 표현식 같은 고급값은 손실되지 않는다 (순수 로직)
    const node: EditorNode = {
      name: 'P',
      props: { caption: '{{user.name | truncate}}', data_binding: { source: 'x' } },
    };
    const patched = applyRecipe(node, textColor, '#333333');
    expect((patched.props?.style as Record<string, unknown>).color).toBe('#333333');
    const keys = collectAdvancedValues(patched).map((a) => a.key);
    expect(keys).toContain('props.data_binding');
    expect(keys).toContain('props.caption');
  });
});
