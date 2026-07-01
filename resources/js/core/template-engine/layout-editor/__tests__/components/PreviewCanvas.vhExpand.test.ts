// e2e:allow VH_EXPAND 펼침은 실제 레이아웃 측정(computed 100vh)+캔버스 재렌더 의존이고, 캔버스는 dnd-kit/iframe 으로 Playwright 자동화 부적합(specTypes.ts/DataBindingSection.tsx 와 동일 정책). 순수 함수 단위(idempotent 재적용) + Chrome MCP T1~T7 매트릭스(재렌더 후 frameH 2206 유지 라이브 실측)로 검증.
/**
 * PreviewCanvas.vhExpand.test.ts — VH_EXPAND 풀스크린 펼침 + 재렌더 재적용 회귀
 *
 * 배경(근본 원인): admin 류 풀스크린 레이아웃의 루트(`h-screen overflow-hidden`, height=100vh)는
 * 편집기 캔버스에서 콘텐츠를 뷰포트 높이로 잘라 캔버스가 작아지고 잘린다. `expandVhLockedElements`
 * 가 그 요소의 height/overflow 를 무력화(height:auto, overflow:visible)해 콘텐츠가 흐르게 한다.
 *
 * 회귀(본 테스트가 잠그는 것): 테이블 추가 후 **인라인 편집 진입→이탈** 시 `bustTranslationCache`
 * 가 캔버스를 재렌더 → `h-screen overflow-hidden` 루트 요소가 재생성되며 VH_EXPAND 인라인 스타일이
 * 사라진다. 그런데 VH_EXPAND useEffect 의 dep(`components/scriptsReady/deviceWidth/dataContext`)는
 * 인라인 편집 이탈로 변하지 않아 재실행되지 않았고, 100vh 클립이 복귀해 캔버스가 수축/잘렸다.
 * 펼침 로직을 순수 함수로 추출해 (1) 펼침이 올바른지 (2) 재렌더(스타일 소실) 후 재호출하면 다시
 * 펼쳐지는지를 가드한다. (PreviewCanvas 는 이 함수를 rAF effect + frame MutationObserver 양쪽에서
 * 호출해 재렌더 시 자동 재적용한다.)
 *
 * jsdom 은 레이아웃을 계산하지 않으므로, computed height 를 모킹해 vh 고정 요소를 시뮬레이션한다.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { expandVhLockedElements } from '../../components/PreviewCanvas';

const VIEWPORT_H = 900;

/** getComputedStyle 을 요소별 지정 스타일로 모킹 (jsdom 레이아웃 부재 보완) */
function mockComputedStyle(styleMap: Map<Element, Partial<CSSStyleDeclaration>>): () => void {
  const orig = window.getComputedStyle;
  window.getComputedStyle = ((el: Element) => {
    const s = styleMap.get(el) ?? {};
    return {
      height: s.height ?? 'auto',
      minHeight: s.minHeight ?? 'auto',
      overflow: s.overflow ?? 'visible',
      overflowY: s.overflowY ?? 'visible',
    } as CSSStyleDeclaration;
  }) as typeof window.getComputedStyle;
  return () => { window.getComputedStyle = orig; };
}

describe('expandVhLockedElements — 풀스크린 vh 펼침', () => {
  let frame: HTMLElement;
  let restore: (() => void) | null = null;

  beforeEach(() => {
    frame = document.createElement('div');
    document.body.appendChild(frame);
  });
  afterEach(() => {
    frame.remove();
    if (restore) restore();
    restore = null;
    vi.restoreAllMocks();
  });

  it('h-screen + overflow:hidden(콘텐츠 가둠) 루트를 height:auto/overflow:visible 로 무력화한다', () => {
    const root = document.createElement('div'); // h-screen overflow-hidden
    frame.appendChild(root);
    restore = mockComputedStyle(new Map([
      [root, { height: `${VIEWPORT_H}px`, overflowY: 'hidden' }],
    ]));

    expandVhLockedElements(frame, VIEWPORT_H);

    expect(root.style.height).toBe('auto');
    expect(root.style.overflow).toBe('visible');
    expect(root.getAttribute('data-g7le-vh-expanded')).toBe('1');
  });

  it('min-height:100vh 센터링 컨테이너(overflow:visible)는 건드리지 않는다 (위지윅↔유저 동일성 보존)', () => {
    const centered = document.createElement('div'); // min-h-screen flex items-center, overflow visible
    frame.appendChild(centered);
    restore = mockComputedStyle(new Map([
      [centered, { minHeight: `${VIEWPORT_H}px`, overflowY: 'visible' }],
    ]));

    expandVhLockedElements(frame, VIEWPORT_H);

    expect(centered.style.height).toBe('');
    expect(centered.style.minHeight).toBe('');
    expect(centered.getAttribute('data-g7le-vh-expanded')).toBeNull();
  });

  it('재렌더로 인라인 스타일이 소실된 뒤 재호출하면 다시 펼친다 (인라인 편집 이탈 수축 회귀 가드)', () => {
    const root = document.createElement('div');
    frame.appendChild(root);
    restore = mockComputedStyle(new Map([
      [root, { height: `${VIEWPORT_H}px`, overflowY: 'hidden' }],
    ]));

    // 1차 펼침
    expandVhLockedElements(frame, VIEWPORT_H);
    expect(root.getAttribute('data-g7le-vh-expanded')).toBe('1');

    // 재렌더 시뮬레이션 — DynamicRenderer 가 요소를 재생성해 인라인 스타일/attr 소실.
    root.removeAttribute('data-g7le-vh-expanded');
    root.style.removeProperty('height');
    root.style.removeProperty('overflow');
    expect(root.getAttribute('data-g7le-vh-expanded')).toBeNull();

    // 재호출(MutationObserver 트리거 모사) → 다시 펼쳐져야 한다.
    expandVhLockedElements(frame, VIEWPORT_H);
    expect(root.style.height).toBe('auto');
    expect(root.style.overflow).toBe('visible');
    expect(root.getAttribute('data-g7le-vh-expanded')).toBe('1');
  });

  it('viewportHeight 0/누락 시 아무 것도 하지 않는다 (안전 가드)', () => {
    const root = document.createElement('div');
    frame.appendChild(root);
    restore = mockComputedStyle(new Map([[root, { height: `${VIEWPORT_H}px`, overflowY: 'hidden' }]]));
    expandVhLockedElements(frame, 0);
    expect(root.getAttribute('data-g7le-vh-expanded')).toBeNull();
  });
});
