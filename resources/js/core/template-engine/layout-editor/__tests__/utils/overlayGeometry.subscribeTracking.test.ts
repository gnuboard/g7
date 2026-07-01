/**
 * overlayGeometry.subscribeTracking.test.ts — subscribeOverlayTracking 구독 동작
 *
 * 배경(근본 원인): 게시판 목록/인기글 등 **iteration 데이터 영역**이 데이터소스 fetch 로
 * 캔버스 DOM 에 뒤늦게 렌더될 때, `DndCanvasLayer.recomputeEntries` 가 트리거되지 않아
 * 이전 레이아웃의 드래그 핸들(stale)이 남아 카드/리스트 위를 z-index 로 덮고 클릭을
 * 가로챘다(→ 카드 클릭해도 선택 안 됨, 빈 공간만 컨테이너 선택). `recomputeEntries` 는
 * scroll/resize/ResizeObserver 로만 구독돼 있었고, **frame 내부 DOM 변경(childList/subtree)을
 * 감지할 MutationObserver 가 빠져** 있어(주석은 "MutationObserver 자리 구성"이라 의도했으나
 * 미구현) 데이터 렌더 후 재계산이 누락됐다.
 *
 * 본 테스트는 subscribeOverlayTracking 이:
 *  1. frame 내부 DOM 변경(childList/subtree) 시 callback 을 호출한다(신규 — 수정 전 fail).
 *  2. 기존 window scroll/resize 구독을 유지한다(회귀 가드).
 *  3. 구독 해제 시 MutationObserver 도 끊는다(누수 가드).
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { subscribeOverlayTracking } from '../../utils/overlayGeometry';

describe('subscribeOverlayTracking — DOM 변경 트리거', () => {
  let frame: HTMLElement;

  beforeEach(() => {
    frame = document.createElement('div');
    document.body.appendChild(frame);
  });

  afterEach(() => {
    frame.remove();
    vi.restoreAllMocks();
  });

  it('frame 내부에 자식이 추가되면 callback 을 호출한다 (데이터 렌더 후 recompute)', async () => {
    const cb = vi.fn();
    const unsub = subscribeOverlayTracking(cb, frame);

    // 데이터소스 fetch 완료 → DynamicRenderer 가 iteration 카드를 frame 안에 렌더하는 상황 모사.
    const card = document.createElement('div');
    card.setAttribute('data-editor-path', '2.children.1.children.0.iteration.0');
    frame.appendChild(card);

    // MutationObserver(마이크로태스크) → rAF debounce → callback. 두 틱 모두 대기.
    await new Promise((r) => requestAnimationFrame(() => r(null)));
    await new Promise((r) => setTimeout(r, 0));

    expect(cb).toHaveBeenCalled();
    unsub();
  });

  it('frame 내부 깊은(subtree) 변경도 callback 을 호출한다', async () => {
    const wrapper = document.createElement('div');
    frame.appendChild(wrapper);
    const cb = vi.fn();
    const unsub = subscribeOverlayTracking(cb, frame);

    // 깊은 자손 추가(subtree) — iteration 인스턴스 내부 노드 렌더 모사.
    const deep = document.createElement('span');
    wrapper.appendChild(deep);
    await new Promise((r) => requestAnimationFrame(() => r(null)));
    await new Promise((r) => setTimeout(r, 0));

    expect(cb).toHaveBeenCalled();
    unsub();
  });

  it('window scroll/resize 구독을 유지한다 (회귀 가드)', () => {
    const cb = vi.fn();
    const unsub = subscribeOverlayTracking(cb, frame);

    window.dispatchEvent(new Event('resize'));
    expect(cb).toHaveBeenCalledTimes(1);

    window.dispatchEvent(new Event('scroll'));
    expect(cb).toHaveBeenCalledTimes(2);

    unsub();
  });

  it('구독 해제 후에는 DOM 변경에도 callback 을 호출하지 않는다 (누수 가드)', async () => {
    const cb = vi.fn();
    const unsub = subscribeOverlayTracking(cb, frame);
    unsub();

    const card = document.createElement('div');
    frame.appendChild(card);
    window.dispatchEvent(new Event('resize'));
    await new Promise((r) => setTimeout(r, 0));

    expect(cb).not.toHaveBeenCalled();
  });

  it('frameEl 이 null 이어도 scroll/resize 구독은 동작한다 (안전 디그레이드)', () => {
    const cb = vi.fn();
    const unsub = subscribeOverlayTracking(cb, null);
    window.dispatchEvent(new Event('resize'));
    expect(cb).toHaveBeenCalledTimes(1);
    unsub();
  });
});
