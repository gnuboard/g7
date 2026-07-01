// e2e:allow 페이지 설정 폼 공용 부품(RTL) — ToggleSwitch/DisabledFieldset 단위, Chrome MCP 매트릭스(세션 D)로 통합 검증.
/**
 * FormPrimitives.test.tsx — 폼 공용 부품 RTL
 *
 * 검증:
 *  ① ToggleSwitch — role=switch + aria-checked + 클릭 토글, disabled 시 클릭 무시(D-L)
 *  ② DisabledFieldset — disabled 시 본문 항상 렌더 + data-disabled=true + pointerEvents 차단(D-M)
 */

import React from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ToggleSwitch, DisabledFieldset, OverlaySourceField } from '../../../components/page-settings/FormPrimitives';

afterEach(() => cleanup());

describe('ToggleSwitch (D-L)', () => {
  it('role=switch + aria-checked 반영 + 클릭 시 반대값으로 onChange', () => {
    const onChange = vi.fn();
    render(<ToggleSwitch checked={false} onChange={onChange} testid="sw" label="라벨" />);
    const sw = screen.getByTestId('sw');
    expect(sw).toHaveAttribute('role', 'switch');
    expect(sw).toHaveAttribute('aria-checked', 'false');
    fireEvent.click(sw);
    expect(onChange).toHaveBeenCalledWith(true);
  });

  it('checked=true → aria-checked=true, 클릭 시 false', () => {
    const onChange = vi.fn();
    render(<ToggleSwitch checked onChange={onChange} testid="sw" />);
    expect(screen.getByTestId('sw')).toHaveAttribute('aria-checked', 'true');
    fireEvent.click(screen.getByTestId('sw'));
    expect(onChange).toHaveBeenCalledWith(false);
  });

  it('disabled → 버튼 disabled, 클릭 무시', () => {
    const onChange = vi.fn();
    render(<ToggleSwitch checked={false} onChange={onChange} testid="sw" disabled />);
    const sw = screen.getByTestId('sw') as HTMLButtonElement;
    expect(sw.disabled).toBe(true);
    fireEvent.click(sw);
    expect(onChange).not.toHaveBeenCalled();
  });
});

describe('DisabledFieldset (D-M)', () => {
  it('disabled=false → 본문 렌더 + data-disabled=false + pointerEvents 미차단', () => {
    render(
      <DisabledFieldset disabled={false} testid="fs">
        <span data-testid="inner">x</span>
      </DisabledFieldset>,
    );
    const fs = screen.getByTestId('fs');
    expect(screen.getByTestId('inner')).toBeInTheDocument();
    expect(fs).toHaveAttribute('data-disabled', 'false');
    expect(fs.style.pointerEvents).not.toBe('none');
  });

  it('disabled=true → 본문 숨기지 않고 항상 렌더 + data-disabled=true + 회색/클릭 차단(숨김 금지)', () => {
    render(
      <DisabledFieldset disabled testid="fs">
        <span data-testid="inner">x</span>
      </DisabledFieldset>,
    );
    const fs = screen.getByTestId('fs');
    // 핵심 — 본문은 DOM 에 존재(숨김 금지).
    expect(screen.getByTestId('inner')).toBeInTheDocument();
    expect(fs).toHaveAttribute('data-disabled', 'true');
    expect(fs).toHaveAttribute('aria-disabled', 'true');
    expect(fs.style.pointerEvents).toBe('none');
    expect(Number(fs.style.opacity)).toBeLessThan(1);
  });
});

describe('OverlaySourceField (#4 — 칸 안에 녹이기)', () => {
  it('비었음(filled=false) + 출처 있음 → 출처 칩, 되돌리기 부재', () => {
    render(
      <OverlaySourceField filled={false} sourceLabel="코어" onRevert={vi.fn()} testid="of">
        <input data-testid="inp" />
      </OverlaySourceField>,
    );
    expect(screen.getByTestId('inp')).toBeInTheDocument();
    expect(screen.getByTestId('of-source')).toHaveTextContent('코어');
    expect(screen.queryByTestId('of-revert')).not.toBeInTheDocument();
  });

  it('값 있음(filled=true) → 되돌리기 버튼, 출처 칩 부재, 클릭 시 onRevert', () => {
    const onRevert = vi.fn();
    render(
      <OverlaySourceField filled sourceLabel="코어" onRevert={onRevert} revertLabel="되돌리기" testid="of">
        <input data-testid="inp" />
      </OverlaySourceField>,
    );
    expect(screen.queryByTestId('of-source')).not.toBeInTheDocument();
    const revert = screen.getByTestId('of-revert');
    fireEvent.click(revert);
    expect(onRevert).toHaveBeenCalled();
  });

  it('출처 정보 없음(sourceLabel 부재) + 비었음 → 칩/버튼 모두 미표시', () => {
    render(
      <OverlaySourceField filled={false} testid="of">
        <input data-testid="inp" />
      </OverlaySourceField>,
    );
    expect(screen.queryByTestId('of-source')).not.toBeInTheDocument();
    expect(screen.queryByTestId('of-revert')).not.toBeInTheDocument();
  });
});
