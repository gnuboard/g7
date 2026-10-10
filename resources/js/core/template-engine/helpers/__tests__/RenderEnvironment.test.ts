/**
 * RenderEnvironment — 렌더 환경 우선순위 (이슈 #141)
 *
 * 셀 렌더 함수·G7Core 래퍼·SlotContainer 가 값을 「명시 옵션 > 렌더 환경 > 호스트 전역값」
 * 순서로 고르는 규칙을 한 곳에서 잠근다.
 */

import { describe, it, expect } from 'vitest';
import { resolveRenderEnvironment } from '../RenderEnvironment';

describe('resolveRenderEnvironment — 필드별 우선순위', () => {
  /**
   * @scenario render_env=explicit_env_fallback
   * @effects preview_render_env_priority_explicit_over_env_over_host
   */
  it('명시 > env > fallback 순으로 필드마다 고른다', () => {
    const explicitDispatcher = { id: 'explicit' };
    const envDispatcher = { id: 'env' };
    const hostDispatcher = { id: 'host' };

    const resolved = resolveRenderEnvironment(
      { actionDispatcher: explicitDispatcher },
      { actionDispatcher: envDispatcher, responsiveWidth: 390 },
      { actionDispatcher: hostDispatcher, responsiveWidth: 1441, isEditMode: false },
    );

    expect(resolved.actionDispatcher).toBe(explicitDispatcher);
    expect(resolved.responsiveWidth).toBe(390);
    expect(resolved.isEditMode).toBe(false);
  });

  /**
   * @scenario render_env=explicit_undefined
   * @effects preview_render_env_priority_explicit_over_env_over_host
   */
  it('명시 값이 undefined 이면 건너뛰고 env 를 쓴다', () => {
    const resolved = resolveRenderEnvironment(
      { responsiveWidth: undefined, translationContext: undefined },
      { responsiveWidth: 390, translationContext: { templateId: 't', locale: 'en' } },
      { responsiveWidth: 1441, translationContext: { templateId: 't', locale: 'ko' } },
    );

    expect(resolved.responsiveWidth).toBe(390);
    expect(resolved.translationContext).toEqual({ templateId: 't', locale: 'en' });
  });

  /**
   * @scenario render_env=null_env
   * @effects preview_render_env_falls_back_to_host_outside_preview
   */
  it('env 가 null 이면 fallback 을 쓴다', () => {
    const resolved = resolveRenderEnvironment({}, null, { responsiveWidth: 1441 });
    expect(resolved.responsiveWidth).toBe(1441);
    expect(resolved.actionDispatcher).toBeUndefined();
  });

  /**
   * @scenario render_env=edit_mode_false_explicit
   * @effects preview_render_env_priority_explicit_over_env_over_host
   */
  it('명시 false 는 값으로 존중한다 (?? 의미 — falsy 를 건너뛰지 않는다)', () => {
    const resolved = resolveRenderEnvironment(
      { isEditMode: false, responsiveWidth: 0 },
      { isEditMode: true, responsiveWidth: 390 },
      {},
    );
    expect(resolved.isEditMode).toBe(false);
    expect(resolved.responsiveWidth).toBe(0);
  });
});
