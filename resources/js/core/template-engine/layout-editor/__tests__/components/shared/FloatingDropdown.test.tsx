// e2e:allow 공용 부유 드롭다운 RTL — 렌더/외부클릭·ESC 닫힘/anchor 클릭 무시. flip/clamp 수치 보정은
// jsdom 좌표 0 이라 라이브(Chrome MCP)로 검증. 여기선 닫힘 트리거·열림 분기를 검증.
/**
 * FloatingDropdown.test.tsx — 공용 부유 드롭다운 RTL
 *
 * 검증:
 *  ① open=false → 미렌더 / open=true → 패널 렌더(children)
 *  ② 외부 pointerdown → onClose
 *  ③ ESC → onClose
 *  ④ 앵커 내부 pointerdown → onClose 미발화(토글은 소비자 처리)
 *  ⑤ 패널 내부 pointerdown → onClose 미발화
 */

import React, { useRef } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { FloatingDropdown } from '../../../components/shared/FloatingDropdown';

beforeEach(() => cleanup());

/** 앵커 버튼 + 드롭다운을 함께 렌더하는 테스트 하네스 */
function Harness({ open, onClose }: { open: boolean; onClose: () => void }) {
  const anchorRef = useRef<HTMLButtonElement | null>(null);
  return (
    <div>
      <button ref={anchorRef} data-testid="anchor">토글</button>
      <button data-testid="outside">바깥</button>
      <FloatingDropdown anchorRef={anchorRef} open={open} onClose={onClose} testid="dropdown">
        <input data-testid="inside-input" />
      </FloatingDropdown>
    </div>
  );
}

describe('FloatingDropdown', () => {
  it('① open=false → 미렌더', () => {
    render(<Harness open={false} onClose={vi.fn()} />);
    expect(screen.queryByTestId('dropdown')).toBeNull();
  });

  it('① open=true → 패널 + children 렌더', () => {
    render(<Harness open onClose={vi.fn()} />);
    expect(screen.getByTestId('dropdown')).toBeInTheDocument();
    expect(screen.getByTestId('inside-input')).toBeInTheDocument();
  });

  it('② 외부 pointerdown → onClose', () => {
    const onClose = vi.fn();
    render(<Harness open onClose={onClose} />);
    fireEvent.pointerDown(screen.getByTestId('outside'));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('③ ESC → onClose', () => {
    const onClose = vi.fn();
    render(<Harness open onClose={onClose} />);
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('④ 앵커 내부 pointerdown → onClose 미발화(토글은 소비자 처리)', () => {
    const onClose = vi.fn();
    render(<Harness open onClose={onClose} />);
    fireEvent.pointerDown(screen.getByTestId('anchor'));
    expect(onClose).not.toHaveBeenCalled();
  });

  it('⑤ 패널 내부 pointerdown → onClose 미발화', () => {
    const onClose = vi.fn();
    render(<Harness open onClose={onClose} />);
    fireEvent.pointerDown(screen.getByTestId('inside-input'));
    expect(onClose).not.toHaveBeenCalled();
  });
});
