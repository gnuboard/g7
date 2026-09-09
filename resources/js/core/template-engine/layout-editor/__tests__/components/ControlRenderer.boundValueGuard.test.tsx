/**
 * ControlRenderer.boundValueGuard.test.tsx — 데이터 연결 값 보호 **공용 게이트**
 *
 * 저장값이 `{{...}}`(또는 설정 참조)이면 위젯은 그 문자열을 해석하지 못해 **빈 컨트롤**로
 * 보이고, 운영자가 조작하는 순간 환경설정과의 연결이 소리 없이 끊긴다. 원문이 화면 어디에도
 * 남지 않아 되돌릴 수단조차 없다.
 *
 * 이 보호를 위젯마다 넣으면 한 곳이 빠져도 오류가 나지 않고 그 한 곳이 우회로가 된다
 * (공개 #135 후속 실측: 「이미지 관리」 진입 하나만 열려 있었다). 그래서 `ControlRenderer`
 * 한 곳에서 걸고, 본 테스트가 **위젯 종류와 무관하게** 걸리는 것을 잠근다 — 새 위젯을
 * 등록해도 자동 적용된다는 계약이다.
 *
 * @effects bound_value_protection_is_a_single_shared_gate_for_every_widget, restore_affordance_recovers_the_original_binding_after_replacement, bound_value_shows_expression_badge_and_locks_destructive_controls, replace_affordance_reopens_editing_without_emitting_a_change
 * @since engine-v1.66.0
 */

import React from 'react';
import { describe, it, expect, vi, afterEach, beforeAll } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ControlRenderer } from '../../components/property-controls/ControlRenderer';
import { registerCoreWidgets } from '../../spec/registerCoreWidgets';
import { LayoutEditorProvider } from '../../LayoutEditorContext';
import { EditorModalProvider } from '../../EditorModalContext';
import type { EditorControlSpec } from '../../spec/specTypes';
import type { EditorNode } from '../../utils/layoutTreeUtils';

// 레지스트리 디스패치를 타는 테스트라 코어 위젯이 등록돼 있어야 한다
// (미등록이면 ControlRenderer 가 "지원하지 않는 위젯" 폴백을 그린다).
beforeAll(() => registerCoreWidgets());

const t = (key: string): string => key;
const EXPR = '{{_global.settings?.general?.site_logo_url}}';

/** 스칼라를 prop 자리에 기록하는 평범한 컨트롤 — image 가 아니다 */
const numberCtrl: EditorControlSpec = {
  widget: 'number',
  apply: { type: 'propValue', propKey: 'maxVisibleBoards' },
} as never;

const selectCtrl: EditorControlSpec = {
  widget: 'select',
  options: [{ value: 'sm', label: 'S' }, { value: 'lg', label: 'L' }],
  apply: { type: 'propValue', propKey: 'size' },
} as never;

afterEach(() => cleanup());

describe('ControlRenderer — 데이터 연결 값 보호 공용 게이트', () => {
  it('number 위젯도 바인딩 값이면 원문 배지로 디그레이드된다 (image 전용이 아니다)', () => {
    const node: EditorNode = { type: 'basic', name: 'Header', props: { maxVisibleBoards: EXPR } };
    render(<ControlRenderer controlKey="hdrMax" control={numberCtrl} node={node} t={t} onPatch={vi.fn()} />);

    expect(screen.getByTestId('g7le-control-hdrMax-expression').textContent).toBe(EXPR);
    // 위젯 자체는 렌더되지 않는다 — 해석 못 하는 값을 흉내내면 거짓 컨트롤이다.
    expect(screen.queryByTestId('g7le-number-input')).toBeNull();
  });

  it('select 위젯도 동일하게 걸린다 (닫힌 집합이라 표현식을 표시할 수 없다)', () => {
    const node: EditorNode = { type: 'basic', name: 'Div', props: { size: EXPR } };
    render(<ControlRenderer controlKey="sz" control={selectCtrl} node={node} t={t} onPatch={vi.fn()} />);

    expect(screen.getByTestId('g7le-control-sz-expression').textContent).toBe(EXPR);
  });

  it('「직접 지정으로 바꾸기」 → 위젯이 열리고, 그 클릭만으로는 값을 바꾸지 않는다', () => {
    const onPatch = vi.fn();
    const node: EditorNode = { type: 'basic', name: 'Header', props: { maxVisibleBoards: EXPR } };
    render(<ControlRenderer controlKey="hdrMax" control={numberCtrl} node={node} t={t} onPatch={onPatch} />);

    fireEvent.click(screen.getByTestId('g7le-control-hdrMax-expression-replace'));

    expect(screen.queryByTestId('g7le-control-hdrMax-expression')).toBeNull();
    expect(screen.getByTestId('g7le-widget-number')).toBeInTheDocument();
    expect(onPatch).not.toHaveBeenCalled();
  });

  it('해제 뒤 「되돌리기」가 노출되고, 원문을 다시 기록한다 (편도 금지)', () => {
    const patches: EditorNode[] = [];

    // 실제 편집기처럼 패치가 노드로 되돌아오는 controlled 하네스 —
    // 고정 prop 으로는 "값을 바꾼 뒤" 상태를 재현할 수 없다.
    function Harness(): React.ReactElement {
      const [node, setNode] = React.useState<EditorNode>({
        type: 'basic',
        name: 'Header',
        props: { maxVisibleBoards: EXPR },
      });
      return (
        <ControlRenderer
          controlKey="hdrMax"
          control={numberCtrl}
          node={node}
          t={t}
          onPatch={(n) => {
            patches.push(n);
            setNode(n);
          }}
        />
      );
    }

    render(<Harness />);

    fireEvent.click(screen.getByTestId('g7le-control-hdrMax-expression-replace'));

    const input = screen.getByTestId('g7le-number-input');
    fireEvent.change(input, { target: { value: '7' } });
    fireEvent.blur(input, { target: { value: '7' } });
    expect(patches[patches.length - 1].props?.maxVisibleBoards).toBe(7);

    // 원문이 화면에서 사라진 뒤에도 되돌릴 수 있어야 한다.
    fireEvent.click(screen.getByTestId('g7le-control-hdrMax-expression-restore'));
    expect(patches[patches.length - 1].props?.maxVisibleBoards).toBe(EXPR);
    // 보호 상태로 복귀
    expect(screen.getByTestId('g7le-control-hdrMax-expression').textContent).toBe(EXPR);
  });

  it('바인딩이 아닌 평범한 값은 종전대로 위젯이 렌더된다 (회귀 가드)', () => {
    const node: EditorNode = { type: 'basic', name: 'Header', props: { maxVisibleBoards: 5 } };
    render(<ControlRenderer controlKey="hdrMax" control={numberCtrl} node={node} t={t} onPatch={vi.fn()} />);

    expect(screen.queryByTestId('g7le-control-hdrMax-expression')).toBeNull();
    expect((screen.getByTestId('g7le-number-input') as HTMLInputElement).value).toBe('5');
  });

  it('자체 처리 위젯(image)은 공용 게이트를 타지 않는다 — 위젯 자신의 배지를 쓴다', () => {
    const imageCtrl: EditorControlSpec = {
      widget: 'image',
      apply: { type: 'propValue', propKey: 'logo' },
    } as never;
    const node: EditorNode = { type: 'composite', name: 'Header', props: { logo: EXPR } };
    // ImagePickerControl 은 편집기 컨텍스트(첨부 목록·모달)를 요구한다.
    render(
      <LayoutEditorProvider templateIdentifier="sirsoft-basic" initialLocale="ko">
        <EditorModalProvider>
          <ControlRenderer controlKey="hdrLogo" control={imageCtrl} node={node} t={t} onPatch={vi.fn()} />
        </EditorModalProvider>
      </LayoutEditorProvider>,
    );

    // 공용 게이트의 testid 가 아니라 위젯 자신의 testid 로 뜬다.
    expect(screen.queryByTestId('g7le-control-hdrLogo-expression')).toBeNull();
    expect(screen.getByTestId('g7le-image-expression').textContent).toBe(EXPR);
  });
});
