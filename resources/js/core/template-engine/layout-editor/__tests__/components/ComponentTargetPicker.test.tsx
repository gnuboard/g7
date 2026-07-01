/**
 * ComponentTargetPicker.test.tsx — 컴포넌트 영역 picker 위젯 + 캔버스 pick 핸드셰이크
 *
 *
 * 검증:
 *  - 직접 입력칸(항상 동작): ID 타이핑 → onChange.
 *  - 🎯 영역 선택: pick-request 이벤트 발사(모달 최소화) + picked 이벤트(같은 requestId) 수신
 *    시 onChange. cancelled 회신은 onChange 미발화. requestId 불일치 회신 무시.
 *
 * EditorCanvasOverlay 가 pick-request 를 수신해 노드 id 를 picked 로 회신하는 결선의 짝
 * 계약(이벤트명/detail 형태)을 잠근다.
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

const minimizeSpy = vi.fn();
const restoreSpy = vi.fn();
let mockStack: Array<{ id: string }> = [{ id: 'host-modal' }];
vi.mock('../../EditorModalContext', () => ({
  useEditorModal: () => ({
    stack: mockStack,
    minimize: minimizeSpy,
    restore: restoreSpy,
  }),
}));

import {
  ComponentTargetPicker,
  COMPONENT_TARGET_PICK_REQUEST_EVENT,
  COMPONENT_TARGET_PICKED_EVENT,
} from '../../components/property-controls/ComponentTargetPicker';
import type { EditorControlSpec } from '../../spec/specTypes';

const control: EditorControlSpec = { widget: 'component-target-picker', label: 'target' };
const t = (k: string) => k;

function renderPicker(value: unknown, onChange = vi.fn()) {
  render(<ComponentTargetPicker control={control} value={value} onChange={onChange} t={t} />);
  return onChange;
}

beforeEach(() => {
  cleanup();
  minimizeSpy.mockClear();
  restoreSpy.mockClear();
  mockStack = [{ id: 'host-modal' }];
});

describe('ComponentTargetPicker — 직접 입력 + 캔버스 pick 핸드셰이크', () => {
  it('직접 입력칸 타이핑 → onChange', () => {
    const onChange = renderPicker('');
    const input = screen.getByTestId('g7le-component-target-input') as HTMLInputElement;
    fireEvent.change(input, { target: { value: 'main-form' } });
    expect(onChange).toHaveBeenCalledWith('main-form');
  });

  it('빈 입력 → onChange(undefined)', () => {
    const onChange = renderPicker('x');
    const input = screen.getByTestId('g7le-component-target-input') as HTMLInputElement;
    fireEvent.change(input, { target: { value: '' } });
    expect(onChange).toHaveBeenCalledWith(undefined);
  });

  it('🎯 클릭 → pick-request 발사 + 호스트 모달 최소화', () => {
    let requestDetail: { requestId?: string } | null = null;
    const onReq = (e: Event): void => {
      requestDetail = (e as CustomEvent).detail;
    };
    window.addEventListener(COMPONENT_TARGET_PICK_REQUEST_EVENT, onReq);
    renderPicker('');
    fireEvent.click(screen.getByTestId('g7le-component-target-pick'));
    expect(requestDetail).not.toBeNull();
    expect(typeof requestDetail!.requestId).toBe('string');
    // 호스트 모달(스택 top) 최소화 호출.
    expect(minimizeSpy).toHaveBeenCalledWith('host-modal', expect.any(String));
    window.removeEventListener(COMPONENT_TARGET_PICK_REQUEST_EVENT, onReq);
  });

  it('picked 회신(같은 requestId) → onChange(id) + 모달 복원', () => {
    let requestId = '';
    const onReq = (e: Event): void => {
      requestId = (e as CustomEvent).detail?.requestId ?? '';
    };
    window.addEventListener(COMPONENT_TARGET_PICK_REQUEST_EVENT, onReq);
    const onChange = renderPicker('');
    fireEvent.click(screen.getByTestId('g7le-component-target-pick'));
    // 캔버스가 노드 id 회신.
    window.dispatchEvent(
      new CustomEvent(COMPONENT_TARGET_PICKED_EVENT, { detail: { requestId, id: 'picked-node' } }),
    );
    expect(onChange).toHaveBeenCalledWith('picked-node');
    expect(restoreSpy).toHaveBeenCalledWith('host-modal');
    window.removeEventListener(COMPONENT_TARGET_PICK_REQUEST_EVENT, onReq);
  });

  it('cancelled 회신 → onChange 미발화(복원만)', () => {
    let requestId = '';
    const onReq = (e: Event): void => {
      requestId = (e as CustomEvent).detail?.requestId ?? '';
    };
    window.addEventListener(COMPONENT_TARGET_PICK_REQUEST_EVENT, onReq);
    const onChange = renderPicker('');
    fireEvent.click(screen.getByTestId('g7le-component-target-pick'));
    window.dispatchEvent(
      new CustomEvent(COMPONENT_TARGET_PICKED_EVENT, { detail: { requestId, cancelled: true } }),
    );
    expect(onChange).not.toHaveBeenCalled();
    expect(restoreSpy).toHaveBeenCalledWith('host-modal');
    window.removeEventListener(COMPONENT_TARGET_PICK_REQUEST_EVENT, onReq);
  });

  it('다른 requestId 회신은 무시(onChange 미발화)', () => {
    const onReq = (): void => {};
    window.addEventListener(COMPONENT_TARGET_PICK_REQUEST_EVENT, onReq);
    const onChange = renderPicker('');
    fireEvent.click(screen.getByTestId('g7le-component-target-pick'));
    // 엉뚱한 requestId 회신.
    window.dispatchEvent(
      new CustomEvent(COMPONENT_TARGET_PICKED_EVENT, { detail: { requestId: 'other', id: 'x' } }),
    );
    expect(onChange).not.toHaveBeenCalled();
    window.removeEventListener(COMPONENT_TARGET_PICK_REQUEST_EVENT, onReq);
  });
});
