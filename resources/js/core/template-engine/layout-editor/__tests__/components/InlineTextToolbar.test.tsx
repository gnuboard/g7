/**
 * InlineTextToolbar.test.tsx — 인라인 편집 서식 툴바 RTL
 *
 *  - 스펙 기반 버튼 노출: componentCapabilities[name].styleControls 의 텍스트 서식만.
 *  - styleControls 미선언 컴포넌트 → 툴바 부재(null).
 *  - 목록·표·이미지 버튼 부재(서식 툴바는 props/style 변경만).
 *  - 클릭 → applyRecipe 와 동일 패치 호출(컨트롤 동일성 — 속성 모달과 같은 엔진).
 *  - 서식이 텍스트 컴포넌트 전체에 적용(substring 부분 서식 미지원 — 노드 props/className).
 */

import React from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { InlineTextToolbar } from '../../components/InlineTextToolbar';
import type { EditorSpec } from '../../spec/specTypes';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import type { OverlayBox } from '../../utils/overlayGeometry';

const t = (k: string) => k;
const box: OverlayBox = { left: 0, top: 60, width: 120, height: 24, scale: 1 };

const spec: EditorSpec = {
  controls: {
    fontWeight: {
      widget: 'segmented',
      group: 'font-weight',
      // 실제 번들 스펙과 동일한 3지 세그먼트(normal/semibold/bold) — 'B' 버튼은 옵션 순환이
      // 아니라 bold 전용 토글이어야 한다.
      options: [
        { value: 'font-normal', apply: { type: 'classToken', tokens: ['font-normal'] } },
        { value: 'font-semibold', apply: { type: 'classToken', tokens: ['font-semibold'] } },
        { value: 'font-bold', apply: { type: 'classToken', tokens: ['font-bold'] } },
      ],
    },
    textAlign: {
      widget: 'segmented',
      group: 'text-align',
      options: [
        { value: 'left', apply: { type: 'classToken', tokens: ['text-left'] } },
        { value: 'center', apply: { type: 'classToken', tokens: ['text-center'] } },
      ],
    },
    fontSize: { widget: 'dimension', apply: { type: 'styleProp', prop: 'fontSize' } },
    textColor: { widget: 'color', group: 'text-color', apply: { type: 'styleProp', prop: 'color' } },
  },
  componentCapabilities: {
    H1: { styleControls: ['fontWeight', 'textAlign', 'fontSize', 'textColor'] },
    Span: { styleControls: ['fontWeight'] },
    Icon: {}, // styleControls 미선언
  },
} as unknown as EditorSpec;

afterEach(() => cleanup());

describe('InlineTextToolbar', () => {
  it('styleControls 에 선언된 서식 버튼만 노출', () => {
    const node: EditorNode = { name: 'H1', text: '제목' };
    render(<InlineTextToolbar node={node} spec={spec} t={t} onApplyControl={vi.fn()} box={box} />);
    expect(screen.getByTestId('g7le-inline-format-fontWeight')).toBeTruthy();
    expect(screen.getByTestId('g7le-inline-format-textAlign')).toBeTruthy();
    expect(screen.getByTestId('g7le-inline-format-fontSize')).toBeTruthy();
    expect(screen.getByTestId('g7le-inline-format-textColor')).toBeTruthy();
  });

  it('Span 은 fontWeight 만 선언 → 굵기 버튼만, 나머지 부재', () => {
    const node: EditorNode = { name: 'Span', text: '글' };
    render(<InlineTextToolbar node={node} spec={spec} t={t} onApplyControl={vi.fn()} box={box} />);
    expect(screen.getByTestId('g7le-inline-format-fontWeight')).toBeTruthy();
    expect(screen.queryByTestId('g7le-inline-format-textAlign')).toBeNull();
    expect(screen.queryByTestId('g7le-inline-format-fontSize')).toBeNull();
  });

  it('styleControls 미선언 컴포넌트 → 툴바 자체 부재(null)', () => {
    const node: EditorNode = { name: 'Icon' };
    const { container } = render(
      <InlineTextToolbar node={node} spec={spec} t={t} onApplyControl={vi.fn()} box={box} />,
    );
    expect(container.querySelector('[data-testid="g7le-inline-toolbar"]')).toBeNull();
  });

  it('목록/표/이미지 같은 요소-추가 버튼은 노출하지 않는다', () => {
    const node: EditorNode = { name: 'H1', text: '제목' };
    render(<InlineTextToolbar node={node} spec={spec} t={t} onApplyControl={vi.fn()} box={box} />);
    expect(screen.queryByTestId('g7le-inline-format-list')).toBeNull();
    expect(screen.queryByTestId('g7le-inline-format-table')).toBeNull();
    expect(screen.queryByTestId('g7le-inline-format-image')).toBeNull();
  });

  it('굵기 버튼 클릭 → applyFn 을 fresh 노드에 적용 시 font-bold 토큰 (substring 아님)', () => {
    const node: EditorNode = { name: 'Span', text: '글' };
    const onApplyControl = vi.fn();
    render(<InlineTextToolbar node={node} spec={spec} t={t} onApplyControl={onApplyControl} box={box} />);
    fireEvent.click(screen.getByTestId('g7le-inline-format-fontWeight'));
    expect(onApplyControl).toHaveBeenCalledTimes(1);
    const [controlKey, applyFn] = onApplyControl.mock.calls[0];
    expect(controlKey).toBe('fontWeight');
    // applyFn 을 fresh 노드(미적용 상태)에 적용 → 첫 적용 옵션(bold) → font-bold 토큰.
    const patched = applyFn(node);
    expect((patched.props?.className as string) ?? '').toContain('font-bold');
  });

  it('연속 토글 — applyFn 이 매번 fresh 노드 기준 (켜기 → 끄기)', () => {
    const node: EditorNode = { name: 'Span', text: '글' };
    const onApplyControl = vi.fn();
    render(<InlineTextToolbar node={node} spec={spec} t={t} onApplyControl={onApplyControl} box={box} />);
    fireEvent.click(screen.getByTestId('g7le-inline-format-fontWeight'));
    const applyFn = onApplyControl.mock.calls[0][1] as (n: EditorNode) => EditorNode;
    // 1차: 미적용 → bold
    const bolded = applyFn(node);
    expect((bolded.props?.className as string) ?? '').toContain('font-bold');
    // 2차: 이미 bold 인 fresh 노드 → 다음 옵션(normal=clearGroup) → font-bold 제거
    const unbolded = applyFn(bolded);
    expect((unbolded.props?.className as string) ?? '').not.toContain('font-bold');
  });

  it('3지 세그먼트(normal/semibold/bold)에서 B 버튼은 bold 로 직행(semibold 거치지 않음) + 재클릭 해제', () => {
    // 미적용 노드에서 B 클릭 → font-bold (옵션 순환으로 font-normal 이 되면 안 됨).
    const node: EditorNode = { name: 'Span', text: '글' };
    const onApplyControl = vi.fn();
    render(<InlineTextToolbar node={node} spec={spec} t={t} onApplyControl={onApplyControl} box={box} />);
    fireEvent.click(screen.getByTestId('g7le-inline-format-fontWeight'));
    const applyFn = onApplyControl.mock.calls[0][1] as (n: EditorNode) => EditorNode;
    const bolded = applyFn(node);
    const boldedCls = (bolded.props?.className as string) ?? '';
    expect(boldedCls).toContain('font-bold');
    expect(boldedCls).not.toContain('font-normal');
    expect(boldedCls).not.toContain('font-semibold');
    // 이미 bold 인 노드에서 B 재클릭 → 해제(font-bold 제거).
    const unbolded = applyFn(bolded);
    expect((unbolded.props?.className as string) ?? '').not.toContain('font-bold');
  });

  it('이미 semibold 인 노드에서 B 클릭 → bold 로 전환(semibold 교체) — 옵션 순환 아님', () => {
    const node: EditorNode = { name: 'Span', text: '글', props: { className: 'font-semibold' } };
    const onApplyControl = vi.fn();
    render(<InlineTextToolbar node={node} spec={spec} t={t} onApplyControl={onApplyControl} box={box} />);
    fireEvent.click(screen.getByTestId('g7le-inline-format-fontWeight'));
    const applyFn = onApplyControl.mock.calls[0][1] as (n: EditorNode) => EditorNode;
    const patched = applyFn(node);
    const cls = (patched.props?.className as string) ?? '';
    expect(cls).toContain('font-bold');
    expect(cls).not.toContain('font-semibold');
  });

  it('정렬 버튼 클릭 → popover 열림(단순 토글/순환 아님), 옵션 선택 시 그 값 적용', () => {
    const node: EditorNode = { name: 'H1', text: '제목' };
    const onApplyControl = vi.fn();
    render(<InlineTextToolbar node={node} spec={spec} t={t} onApplyControl={onApplyControl} box={box} />);

    // 버튼 클릭 자체는 적용을 발화하지 않고 popover 만 연다(토글/순환 아님).
    fireEvent.click(screen.getByTestId('g7le-inline-format-textAlign'));
    expect(onApplyControl).not.toHaveBeenCalled();
    expect(screen.getByTestId('g7le-inline-popover-textAlign')).toBeInTheDocument();

    // popover 에서 center 옵션 선택 → 그 값으로 적용(fresh 노드 기준).
    fireEvent.click(screen.getByTestId('g7le-inline-option-textAlign-center'));
    expect(onApplyControl).toHaveBeenCalledTimes(1);
    const applyFn = onApplyControl.mock.calls[0][1] as (n: EditorNode) => EditorNode;
    const patched = applyFn(node);
    expect((patched.props?.className as string) ?? '').toContain('text-center');
  });

  it('크기 버튼(옵션 없는 dimension) 클릭 → popover 열림, 자유값 입력칸 + Enter 적용 (단순 토글 아님)', () => {
    const node: EditorNode = { name: 'H1', text: '제목' };
    const onApplyControl = vi.fn();
    render(<InlineTextToolbar node={node} spec={spec} t={t} onApplyControl={onApplyControl} box={box} />);

    fireEvent.click(screen.getByTestId('g7le-inline-format-fontSize'));
    expect(onApplyControl).not.toHaveBeenCalled();
    const popover = screen.getByTestId('g7le-inline-popover-fontSize');
    expect(popover).toBeInTheDocument();

    // fontSize 는 dimension(옵션 없음) → 자유값 입력칸으로 폴백. 값 입력 후 Enter → 적용.
    const input = screen.getByTestId('g7le-inline-freeinput-fontSize') as HTMLInputElement;
    fireEvent.change(input, { target: { value: '24px' } });
    fireEvent.keyDown(input, { key: 'Enter' });
    expect(onApplyControl).toHaveBeenCalledTimes(1);
    const applyFn = onApplyControl.mock.calls[0][1] as (n: EditorNode) => EditorNode;
    const patched = applyFn(node);
    expect((patched.props?.style as Record<string, unknown> | undefined)?.fontSize).toBe('24px');
  });

  it('선택형(정렬/크기/색) 옵션 재선택 시 해제(toggle off) — 같은 값 클릭', () => {
    // 이미 text-center 인 노드.
    const node: EditorNode = { name: 'H1', text: '제목', props: { className: 'text-center' } };
    const onApplyControl = vi.fn();
    render(<InlineTextToolbar node={node} spec={spec} t={t} onApplyControl={onApplyControl} box={box} />);

    fireEvent.click(screen.getByTestId('g7le-inline-format-textAlign'));
    fireEvent.click(screen.getByTestId('g7le-inline-option-textAlign-center'));
    const applyFn = onApplyControl.mock.calls[0][1] as (n: EditorNode) => EditorNode;
    const patched = applyFn(node);
    // 현재값(center)과 같은 값 선택 → 해제 → text-center 제거.
    expect((patched.props?.className as string) ?? '').not.toContain('text-center');
  });
});
