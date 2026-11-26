/**
 * InsertionAffordances — 캔버스 밖으로 나가는 삽입 단추를 캔버스 안으로 접어 넣는다
 *
 * 결함: 맞춤 보기(창 1280)에서 너비 전체 노드(Header)를 고르면 「오른쪽에 넣기 +」(`g7le-insertion-right`)가
 * 박스 오른쪽 바깥 30px 에 놓여 캔버스 여백 24px 을 6px 넘었다. 캔버스 오른쪽 끝이 곧 창 끝이라 문서가
 * 6px 가로로 넘쳤다(선택 전 0). 위·왼쪽·아래 단추도 같은 이유로 캔버스 밖(헤더 밑, 창 밖)에 놓일 수 있다.
 *
 * 고침: 오버레이가 캔버스 상자(실제 크기에서는 캔버스 스크롤 내용 영역)를 frame 좌표 경계로 계산해 넘기고
 * (`resolveAffordanceBounds`), 단추가 경계를 넘을 때만 경계 안으로 접어 넣는다(`foldIntoBounds`). 넘지 않으면
 * 종전 배치(CSS 오프셋) 그대로다.
 *
 * 세 상태(맞춤·70%·실제 크기)의 경계를 jsdom 대역 사각형으로 잰다.
 */

import { describe, expect, it, afterEach, vi } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import { InsertionAffordances } from '../../components/InsertionAffordances';
import { buildPoints } from '../../hooks/useInsertionPoints';
import { foldIntoBounds, resolveAffordanceBounds } from '../../utils/overlayGeometry';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

function withTranslation(node: React.ReactElement): React.ReactElement {
  return (
    <TranslationProvider translationEngine={new TranslationEngine()} translationContext={{ templateId: 'test', locale: 'ko' }}>
      {node}
    </TranslationProvider>
  );
}

function rect(left: number, top: number, width: number, height: number): DOMRect {
  return { left, top, width, height, right: left + width, bottom: top + height, x: left, y: top, toJSON: () => ({}) } as DOMRect;
}

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

/** 캔버스 > 무대 > 래퍼 > frame 대역. frame 화면 사각형과 캔버스 사각형·스크롤을 준다. */
function mountCanvas(opts: {
  canvas: DOMRect;
  frame: DOMRect;
  scroller?: { scrollLeft: number; scrollTop: number; scrollWidth: number; scrollHeight: number };
}): HTMLElement {
  const canvas = document.createElement('div');
  canvas.setAttribute('data-testid', 'g7le-preview-canvas');
  const frame = document.createElement('div');
  canvas.appendChild(frame);
  document.body.appendChild(canvas);
  vi.spyOn(canvas, 'getBoundingClientRect').mockReturnValue(opts.canvas);
  vi.spyOn(frame, 'getBoundingClientRect').mockReturnValue(opts.frame);
  Object.defineProperty(canvas, 'clientWidth', { value: opts.canvas.width });
  Object.defineProperty(canvas, 'clientHeight', { value: opts.canvas.height });
  if (opts.scroller) {
    canvas.style.overflowX = 'auto';
    for (const [k, v] of Object.entries(opts.scroller)) Object.defineProperty(canvas, k, { value: v });
  }
  return frame;
}

describe('삽입 단추 접어 넣기 — 경계 계산(맞춤·70%·실제 크기)', () => {
  /** @effects editor_insertion_affordances_stay_inside_canvas */
  it('맞춤: 캔버스 1000 안 frame 952(여백 24) → 경계 [-24, 976]', () => {
    const frame = mountCanvas({ canvas: rect(280, 100, 1000, 800), frame: rect(304, 124, 952, 600) });
    expect(resolveAffordanceBounds(frame)).toEqual({ left: -24, top: -24, right: 976, bottom: 776 });
  });

  /** @effects editor_insertion_affordances_stay_inside_canvas */
  it('70%: 가운데 정렬로 양옆 여백이 넓다 → 경계도 넓다', () => {
    const frame = mountCanvas({ canvas: rect(280, 100, 1000, 800), frame: rect(332, 124, 896, 600) });
    expect(resolveAffordanceBounds(frame)).toEqual({ left: -52, top: -24, right: 948, bottom: 776 });
  });

  /** @effects editor_insertion_affordances_stay_inside_canvas */
  it('실제 크기: 캔버스가 스크롤 상자면 화면 사각형이 아니라 스크롤 내용 영역이 경계다', () => {
    // 캔버스 여백 64, 100px 오른쪽으로 스크롤 → frame 화면 left = 280 + 64 − 100 = 244
    const frame = mountCanvas({
      canvas: rect(280, 100, 1000, 800),
      frame: rect(244, 164, 1280, 600),
      scroller: { scrollLeft: 100, scrollTop: 0, scrollWidth: 1408, scrollHeight: 800 },
    });
    expect(resolveAffordanceBounds(frame)).toEqual({ left: -64, top: -64, right: 1344, bottom: 736 });
  });

  /** @effects editor_insertion_affordances_stay_inside_canvas */
  it('캔버스를 못 찾으면 경계 없음(null) — 종전 배치', () => {
    const frame = document.createElement('div');
    document.body.appendChild(frame);
    expect(resolveAffordanceBounds(frame)).toBeNull();
  });

  /** @effects editor_insertion_affordances_stay_inside_canvas */
  it('foldIntoBounds — 넘칠 때만 경계 안으로, 넘지 않으면 null', () => {
    const box = { left: 0, top: 0, width: 952, height: 80, scale: 1 };
    const bounds = { left: -24, top: -24, right: 976, bottom: 776 };
    // right: 952 + 6 = 958 → 958 + 24 = 982 > 976 → 952 로 접음
    expect(foldIntoBounds(box, { left: 958, top: 28 }, 24, bounds)).toEqual({ left: 952, top: 28 });
    // above: top −30 < −24 → −24
    expect(foldIntoBounds(box, { left: 464, top: -30 }, 24, bounds)).toEqual({ left: 464, top: -24 });
    // below: 80 + 6 → 안쪽이라 그대로(null)
    expect(foldIntoBounds(box, { left: 464, top: 86 }, 24, bounds)).toBeNull();
  });
});

describe('삽입 단추 접어 넣기 — 렌더', () => {
  const points = buildPoints('flex_row_single', [], 0);

  /** @effects editor_insertion_affordances_stay_inside_canvas */
  it('맞춤 상태 너비 전체 노드: 오른쪽·위 단추가 경계 안 숫자 좌표로 접히고, 넘지 않는 단추는 종전 CSS', () => {
    render(
      withTranslation(
        <InsertionAffordances
          selectedBox={{ left: 0, top: 0, width: 952, height: 80, scale: 1 }}
          points={points}
          onAddRequest={() => {}}
          bounds={{ left: -24, top: -24, right: 976, bottom: 776 }}
        />,
      ),
    );
    const right = screen.getByTestId('g7le-insertion-right');
    expect(right.style.left).toBe('952px');
    expect(right.style.right).toBe('');
    expect(screen.getByTestId('g7le-insertion-above').style.top).toBe('-24px');
    // 왼쪽: −30 < −24 → −24 로 접힘. 아래: 넘지 않아 종전 CSS(bottom −30px).
    expect(screen.getByTestId('g7le-insertion-left').style.left).toBe('-24px');
    expect(screen.getByTestId('g7le-insertion-below').style.bottom).toBe('-30px');
  });

  /** @effects editor_insertion_affordances_stay_inside_canvas */
  it('경계가 넉넉하면(70%, 실제 크기 여백 64) 모든 단추가 종전 CSS 그대로', () => {
    render(
      withTranslation(
        <InsertionAffordances
          selectedBox={{ left: 0, top: 0, width: 896, height: 80, scale: 1 }}
          points={points}
          onAddRequest={() => {}}
          bounds={{ left: -52, top: -64, right: 948, bottom: 776 }}
        />,
      ),
    );
    expect(screen.getByTestId('g7le-insertion-right').style.right).toBe('-30px');
    expect(screen.getByTestId('g7le-insertion-above').style.top).toBe('-30px');
    expect(screen.getByTestId('g7le-insertion-left').style.left).toBe('-30px');
  });
});
