/**
 * 인자 없는 `localized` 파이프는 렌더 문맥의 `$locale` 을 따른다 (engine-v1.81.0)
 *
 * 레이아웃 편집기 미리보기는 `$locale` 을 콘텐츠 언어로 두고(관리자 화면 언어와 다를 수 있다) 렌더한다.
 * 같은 작업(#148)의 첫 수정은 인자가 없으면 호스트 화면 언어(`__templateApp.getConfig().locale`)를 읽어,
 * 같은 미리보기 안에서 `$localized(x)` 는 콘텐츠 언어·`x | localized` 는 관리자 언어로 갈렸다
 * (트러블슈팅 guide 「렌더 경로 사례 8」 — 렌더 함수는 호스트 값이 아니라 렌더 환경을 읽는다).
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { DataBindingEngine } from '../DataBindingEngine';

describe('localized 파이프의 기본 언어 = 렌더 문맥 $locale', () => {
  let engine: DataBindingEngine;

  beforeEach(() => {
    engine = new DataBindingEngine();
    // 호스트(관리자) 화면 언어는 ko
    (window as any).__templateApp = { getConfig: () => ({ locale: 'ko' }), getLocale: () => 'ko' };
  });

  afterEach(() => {
    delete (window as any).__templateApp;
  });

  // @scenario surface=pipe_integration
  // @effects localized_pipe_follows_render_context_locale
  it('문맥 $locale(en)이 호스트 화면 언어(ko)보다 먼저다 — $localized 와 같은 언어', () => {
    const context = { product: { name: { ko: '상품명', en: 'Product' } }, $locale: 'en' };

    expect(engine.resolveBindings('{{product.name | localized}}', context)).toBe('Product');
    expect(engine.evaluateExpression('$localized(product.name)', context)).toBe('Product');
  });

  /** @effects localized_pipe_follows_render_context_locale */
  it('파이프 인자로 준 언어가 문맥 $locale 보다 먼저다', () => {
    const context = { product: { name: { ko: '상품명', en: 'Product' } }, $locale: 'en' };

    expect(engine.resolveBindings("{{product.name | localized('ko')}}", context)).toBe('상품명');
  });

  /** @effects localized_pipe_follows_render_context_locale */
  it('문맥에 $locale 이 없으면 종전대로 화면 언어', () => {
    const context = { product: { name: { ko: '상품명', en: 'Product' } } };

    expect(engine.resolveBindings('{{product.name | localized}}', context)).toBe('상품명');
  });
});
