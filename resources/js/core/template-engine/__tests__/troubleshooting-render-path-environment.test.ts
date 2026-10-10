/**
 * 회귀 테스트 — 셀 렌더 경로가 미리보기 렌더 환경 대신 호스트 값을 씀 (이슈 #141)
 *
 * 트러블슈팅 「표현식 렌더 경로 비대칭」 사례 8 의 증상 ↔ 코드 단언 앵커다. 같은 화면 안에서
 * DynamicRenderer 가 그리는 자식은 미리보기 폭·콘텐츠 언어를 따르는데, 컴포넌트가 셀 렌더 함수로
 * 직접 그리는 자식은 브라우저 창 폭·호스트 언어를 따랐다.
 *
 * 상세 회귀선:
 * - 렌더 환경 우선순위: `helpers/__tests__/RenderEnvironment.test.ts`
 * - 셀 렌더 함수: `helpers/__tests__/RenderHelpers.renderEnvironment.test.tsx`
 * - 두 렌더 경로 패리티(제보자 요청): `ResponsiveOverride.renderPathParity.test.tsx`
 * - G7Core 래퍼: `resources/js/core/__tests__/G7CoreGlobals.renderEnvironment.test.ts`
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { TranslationEngine } from '../TranslationEngine';
import { responsiveManager } from '../ResponsiveManager';
import { renderItemChildren } from '../helpers/RenderHelpers';

describe('[case:guide-render-path-env] [TS-RENDERPATH-8] 셀 렌더 경로가 미리보기 렌더 환경 대신 호스트 값을 씀', () => {
  let originalWidth: number;

  beforeEach(() => {
    originalWidth = responsiveManager.getWidth();
  });

  afterEach(() => {
    responsiveManager._setWidthForTesting(originalWidth);
  });

  /**
   * @scenario preview_device=mobile, window_width=1440, render_path=cell
   * @effects preview_cell_render_follows_preview_width
   */
  it('창 폭 1440 이어도 렌더 환경 폭 390 이면 셀이 portable 분기를 고른다', () => {
    responsiveManager._setWidthForTesting(1440);
    const nodes = renderItemChildren(
      [{ id: 'c', type: 'basic', name: 'Box', props: { tone: 'base' }, responsive: { portable: { props: { tone: 'portable' } } } } as any],
      { row: {} },
      { Box: (props: any) => props.tone },
      'k',
      { renderEnv: { responsiveWidth: 390 } },
    ) as any[];

    // 종전: 싱글톤 1440 으로 판정해 'base'
    expect(nodes[0].props.tone).toBe('portable');
  });

  /**
   * @scenario content_locale=en, host_locale=ko, render_path=cell_defer
   * @effects preview_cell_defer_translation_follows_content_locale
   */
  it('셀의 $t:defer: 는 렌더 환경의 콘텐츠 언어로 번역된다', () => {
    const translationEngine = new TranslationEngine();
    (translationEngine as any).translations.set('t:ko', { fee: '배송비' });
    (translationEngine as any).translations.set('t:en', { fee: 'Shipping fee' });

    const nodes = renderItemChildren(
      [{ id: 'c', type: 'basic', name: 'Box', text: '$t:defer:fee' } as any],
      { row: {} },
      { Box: (props: any) => props.children },
      'k',
      { translationEngine, renderEnv: { translationContext: { templateId: 't', locale: 'en' } } },
    ) as any[];

    // 종전: 기본 컨텍스트(ko, templateId 빈 값)로 번역해 키가 그대로 남거나 한국어
    expect(nodes[0].props.children).toBe('Shipping fee');
  });
});
