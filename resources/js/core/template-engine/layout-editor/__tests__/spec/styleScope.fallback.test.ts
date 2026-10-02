/**
 * resolveBranchSeparationMode — 폭 미전달 폴백 (이슈 #141 D6)
 *
 * 폭을 넘겨받지 못한 판정은 브라우저 창 폭이 아니라 결정적인 값으로 폴백해야 한다.
 * 레이아웃 편집기 미리보기는 같은 문서의 div 라서 창 폭이 미리보기 기기 폭과 무관하다.
 * 유일한 호출부(EditorCanvasOverlay)는 늘 폭을 넘기므로 화면 동작은 바뀌지 않는다.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { responsiveManager } from '../../../ResponsiveManager';
import { resolveBranchSeparationMode } from '../../spec/styleScope';
import type { EditorNode } from '../../utils/layoutTreeUtils';

describe('resolveBranchSeparationMode — 폭 미전달 폴백은 창 폭을 쓰지 않는다', () => {
  const plain: EditorNode = { name: 'Div', children: [{ name: 'A' }] };
  let original: number;

  beforeEach(() => {
    original = responsiveManager.getWidth();
    responsiveManager._setWidthForTesting(500);
  });

  afterEach(() => {
    responsiveManager._setWidthForTesting(original);
  });

  /**
   * @scenario preview_device=custom, custom_width=absent, window_width=500
   * @effects preview_style_scope_fallback_ignores_window_width
   */
  it('custom 인데 폭이 없으면 창 폭(500) 대신 기본 사용자 지정 폭 1024 로 판정', () => {
    expect(resolveBranchSeparationMode('custom', plain)).toEqual({ mode: 'separate', key: '1024-1024' });
  });

  /**
   * @scenario preview_device=unparseable_key, custom_width=absent, window_width=500
   * @effects preview_style_scope_fallback_ignores_window_width
   */
  it('파싱할 수 없는 기기 키 + 폭 없음 → 창 폭 대신 기기 기본 폭(데스크톱 1280)으로 판정', () => {
    expect(resolveBranchSeparationMode('weird-key' as any, plain)).toEqual({ mode: 'separate', key: '1280-1280' });
  });

  /**
   * @scenario preview_device=custom, custom_width=767
   * @effects preview_style_scope_fallback_ignores_window_width
   */
  it('폭을 넘기면 그 폭을 쓴다 (호출부 동작 불변)', () => {
    expect(resolveBranchSeparationMode('custom', plain, 767)).toEqual({ mode: 'separate', key: '767-767' });
  });
});
