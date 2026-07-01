/**
 * InsertionAffordances 컴포넌트 테스트
 *
 * - 4방향 버튼 렌더 (활성/비활성)
 * - 비활성 + 버튼 클릭은 onAddRequest 미호출
 * - 활성 + 버튼 클릭은 해당 point 가 onAddRequest 로 전달
 * - selectedBox 가 null 이면 미렌더
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { InsertionAffordances } from '../../components/InsertionAffordances';
import { buildPoints } from '../../hooks/useInsertionPoints';
import { OVERLAY_AFFORDANCE } from '../../utils/overlayZIndex';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

function withTranslation(node: React.ReactElement): React.ReactElement {
  const engine = new TranslationEngine();
  return (
    <TranslationProvider
      translationEngine={engine}
      translationContext={{ templateId: 'test', locale: 'ko' }}
    >
      {node}
    </TranslationProvider>
  );
}

const selectedBox = { left: 10, top: 20, width: 100, height: 50, scale: 1 };

describe('InsertionAffordances — block 부모', () => {
  it('상/하 활성, 좌/우 비활성', () => {
    const onAdd = vi.fn();
    render(
      withTranslation(
        <InsertionAffordances
          selectedBox={selectedBox}
          points={buildPoints('block', [], 0)}
          onAddRequest={onAdd}
        />
      )
    );
    expect(screen.getByTestId('g7le-insertion-above').getAttribute('data-disabled')).toBe('false');
    expect(screen.getByTestId('g7le-insertion-below').getAttribute('data-disabled')).toBe('false');
    expect(screen.getByTestId('g7le-insertion-left').getAttribute('data-disabled')).toBe('true');
    expect(screen.getByTestId('g7le-insertion-right').getAttribute('data-disabled')).toBe('true');

    fireEvent.click(screen.getByTestId('g7le-insertion-above'));
    expect(onAdd).toHaveBeenCalledTimes(1);
    expect(onAdd.mock.calls[0][0].direction).toBe('above');

    onAdd.mockClear();
    fireEvent.click(screen.getByTestId('g7le-insertion-left'));
    expect(onAdd).not.toHaveBeenCalled(); // 비활성 클릭은 콜백 무호출
  });
});

describe('InsertionAffordances — flex_row_single', () => {
  it('좌/우 활성, 상/하 비활성', () => {
    render(
      withTranslation(
        <InsertionAffordances
          selectedBox={selectedBox}
          points={buildPoints('flex_row_single', [], 1)}
          onAddRequest={vi.fn()}
        />
      )
    );
    expect(screen.getByTestId('g7le-insertion-above').getAttribute('data-disabled')).toBe('true');
    expect(screen.getByTestId('g7le-insertion-below').getAttribute('data-disabled')).toBe('true');
    expect(screen.getByTestId('g7le-insertion-left').getAttribute('data-disabled')).toBe('false');
    expect(screen.getByTestId('g7le-insertion-right').getAttribute('data-disabled')).toBe('false');
  });
});

describe('InsertionAffordances — 작은 박스 십자 배치', () => {
  const smallBox = { left: 200, top: 20, width: 6, height: 20, scale: 1 };

  it('작은 박스(44px 미만)는 + 버튼이 박스 중심 기준 십자로 벌어짐 (data-placement=outside)', () => {
    render(
      withTranslation(
        <InsertionAffordances
          selectedBox={smallBox}
          points={buildPoints('block', [], 0)}
          onAddRequest={vi.fn()}
        />
      )
    );
    const above = screen.getByTestId('g7le-insertion-above');
    const below = screen.getByTestId('g7le-insertion-below');
    const left = screen.getByTestId('g7le-insertion-left');
    const right = screen.getByTestId('g7le-insertion-right');
    expect(above.getAttribute('data-placement')).toBe('outside');
    // above/below 는 가로 중앙 동일 left, 세로로 분리(top 다름)
    expect(above.style.left).toBe(below.style.left);
    expect(above.style.top).not.toBe(below.style.top);
    // left/right 는 세로 중앙 동일 top, 가로로 분리(left 다름)
    expect(left.style.top).toBe(right.style.top);
    expect(left.style.left).not.toBe(right.style.left);
  });

  it('큰 박스는 기존 inside 배치 유지 (data-placement=inside)', () => {
    render(
      withTranslation(
        <InsertionAffordances
          selectedBox={selectedBox}
          points={buildPoints('block', [], 0)}
          onAddRequest={vi.fn()}
        />
      )
    );
    expect(screen.getByTestId('g7le-insertion-above').getAttribute('data-placement')).toBe('inside');
  });

  it('큰 박스(inside)에서 + 버튼이 박스 변 바깥 30px 여백으로 밀려 핸들/코너와 겹치지 않음', () => {
    render(
      withTranslation(
        <InsertionAffordances
          selectedBox={selectedBox}
          points={buildPoints('block', [], 0)}
          onAddRequest={vi.fn()}
        />
      )
    );
    // 종전 -12 는 24px 버튼이 변에 절반 걸쳐 모서리 리사이즈 핸들(±-4)과 겹쳤다.
    // INSERTION_GAP=30 으로 박스 바깥으로 완전히 밀어낸다.
    expect(screen.getByTestId('g7le-insertion-above').style.top).toBe('-30px');
    expect(screen.getByTestId('g7le-insertion-below').style.bottom).toBe('-30px');
    expect(screen.getByTestId('g7le-insertion-left').style.left).toBe('-30px');
    expect(screen.getByTestId('g7le-insertion-right').style.right).toBe('-30px');
  });

  it('십자 배치에서도 활성/비활성 + 클릭 콜백은 동일 동작', () => {
    const onAdd = vi.fn();
    render(
      withTranslation(
        <InsertionAffordances
          selectedBox={smallBox}
          points={buildPoints('block', [], 0)}
          onAddRequest={onAdd}
        />
      )
    );
    fireEvent.click(screen.getByTestId('g7le-insertion-above'));
    expect(onAdd).toHaveBeenCalledTimes(1);
    expect(onAdd.mock.calls[0][0].direction).toBe('above');
  });
});

describe('InsertionAffordances — z-index 회귀', () => {
  it('+ 버튼은 드래그 핸들 위 어포던스 밴드(z-index) 로 렌더 — 클릭 가로채기 차단', () => {
    render(
      withTranslation(
        <InsertionAffordances
          selectedBox={selectedBox}
          points={buildPoints('block', [], 0)}
          onAddRequest={vi.fn()}
        />
      )
    );
    // S5b 드래그 핸들이 z-index 미지정(≈0) 버튼 위로 올라와 +/ⓘ 클릭이 이동 포인터에
    // 가로채이던 회귀. 모든 + 버튼은 OVERLAY_AFFORDANCE z-index 로 핸들 위에 와야 한다.
    for (const dir of ['above', 'below', 'left', 'right']) {
      const btn = screen.getByTestId(`g7le-insertion-${dir}`);
      expect(btn.style.zIndex).toBe(String(OVERLAY_AFFORDANCE));
    }
  });
});

describe('InsertionAffordances — selectedBox null', () => {
  it('null 이면 렌더 자체를 안 함', () => {
    const { container } = render(
      withTranslation(
        <InsertionAffordances
          selectedBox={null}
          points={buildPoints('block', [], 0)}
          onAddRequest={vi.fn()}
        />
      )
    );
    expect(container.querySelector('[data-testid="g7le-insertion-affordances"]')).toBeNull();
  });
});
