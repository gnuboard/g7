/**
 * errorHandlingSavePrune.test.ts — 저장 직렬화는 동작이 빈 오류 코드 항목을 지운다
 *
 * 설계 확정(2026-11-09, #869): [덮기]로 빈 동작(`{}`)을 시작한 뒤 동작을 고르지 않고 저장하면 그 코드 키를
 * 저장할 때 지운다. 빈 항목이 저장되면 런타임 findHandler 가 「처리됨」으로 보아 같은 맵 default, 데이터
 * 소스 onError, 템플릿 기본 처리를 가린다. 편집 중 [덮기]의 빈 동작 시작은 그대로다(ErrorHandlingRows 시험).
 * 페이지 설정(최상위 errorHandling)과 데이터 소스(data_sources[].errorHandling) 두 편집기 모두
 * stripInheritedFromLayoutContent 를 거쳐 저장되므로 그 단계에서 잰다.
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { stripInheritedFromLayoutContent } from '../../utils/layoutTreeUtils';
import { ErrorHandlingResolver } from '../../../../error/ErrorHandlingResolver';

function contentWith(original: Record<string, unknown>): Record<string, unknown> {
  return { components: [], ...original, __editor: { original: { ...original } } };
}

beforeEach(() => ErrorHandlingResolver.resetInstance());

describe('저장 직렬화 — 빈 오류 코드 항목 제거', () => {
  /**
   * @scenario subject=error_handling_save_prune
   * @effects save_prunes_empty_page_error_codes
   */
  it('페이지 설정: 빈 동작({} 또는 []) 코드만 지우고 동작이 있는 코드와 default 는 남긴다', () => {
    const saved = stripInheritedFromLayoutContent(
      contentWith({
        errorHandling: { '401': {}, '403': [], '500': { handler: 'toast' }, default: { handler: 'navigate' } },
      }),
    );
    expect(saved.errorHandling).toEqual({ '500': { handler: 'toast' }, default: { handler: 'navigate' } });
  });

  /**
   * @scenario subject=error_handling_save_prune
   * @effects save_prunes_empty_page_error_codes
   */
  it('페이지 설정: 모든 코드가 비면 errorHandling 키 자체를 싣지 않는다', () => {
    const saved = stripInheritedFromLayoutContent(contentWith({ errorHandling: { '401': {}, default: {} } }));
    expect('errorHandling' in saved).toBe(false);
  });

  /**
   * @scenario subject=error_handling_save_prune
   * @effects save_drops_empty_error_handling_map
   */
  it('코드를 모두 지워 빈 맵({})이 된 errorHandling 도 페이지와 데이터 소스 모두 키째 싣지 않는다', () => {
    const saved = stripInheritedFromLayoutContent(
      contentWith({
        errorHandling: {},
        data_sources: [{ id: 'products', type: 'api', errorHandling: {} }],
      }),
    );
    expect('errorHandling' in saved).toBe(false);
    expect((saved.data_sources as Array<Record<string, unknown>>)[0]).toEqual({ id: 'products', type: 'api' });
  });

  /**
   * @scenario subject=error_handling_save_prune
   * @effects save_prunes_empty_data_source_error_codes
   */
  it('데이터 소스: 소스마다 빈 동작 코드를 지우고, 다 비면 그 소스의 errorHandling 을 뺀다', () => {
    const saved = stripInheritedFromLayoutContent(
      contentWith({
        data_sources: [
          { id: 'products', type: 'api', errorHandling: { '401': {}, '500': { handler: 'navigate' } } },
          { id: 'orders', type: 'api', errorHandling: { '401': {} }, onError: { handler: 'toast' } },
          { id: 'plain', type: 'api' },
        ],
      }),
    );
    const ds = saved.data_sources as Array<Record<string, unknown>>;
    expect(ds[0].errorHandling).toEqual({ '500': { handler: 'navigate' } });
    expect('errorHandling' in ds[1]).toBe(false);
    expect(ds[1].onError).toEqual({ handler: 'toast' });
    expect(ds[2]).toEqual({ id: 'plain', type: 'api' });
  });

  /**
   * @scenario subject=error_handling_save_prune
   * @effects save_prune_leaves_editor_document_untouched
   */
  it('편집 중 문서(raw)는 바꾸지 않는다 — [덮기]로 시작한 빈 동작은 편집기에 그대로 남는다', () => {
    const content = contentWith({ errorHandling: { '401': {}, '500': { handler: 'toast' } } });
    stripInheritedFromLayoutContent(content);
    expect((content.errorHandling as Record<string, unknown>)['401']).toEqual({});
    const original = (content.__editor as { original: Record<string, unknown> }).original;
    expect((original.errorHandling as Record<string, unknown>)['401']).toEqual({});
  });
});

describe('저장 뒤 런타임 — 상위 대체 처리가 살아 있다', () => {
  /**
   * @scenario subject=error_handling_save_prune
   * @effects save_prune_keeps_layout_default_and_template_fallback
   */
  it('레이아웃: 지운 코드는 같은 맵 default 로, default 도 없으면 템플릿 처리로 간다', () => {
    const saved = stripInheritedFromLayoutContent(
      contentWith({ errorHandling: { '401': {}, default: { handler: 'toast' } } }),
    );
    const resolver = ErrorHandlingResolver.getInstance();
    resolver.setLayoutConfig(saved.errorHandling as never);
    const layoutLevel = resolver.resolve(401);
    expect(layoutLevel.level).toBe('layout');
    expect(layoutLevel.handler?.handler).toBe('toast');

    const savedNoDefault = stripInheritedFromLayoutContent(contentWith({ errorHandling: { '401': {} } }));
    resolver.setLayoutConfig((savedNoDefault.errorHandling ?? null) as never);
    resolver.setTemplateConfig({ '401': { handler: 'navigate', params: { path: '/login' } } } as never);
    const templateLevel = resolver.resolve(401);
    expect(templateLevel.level).toBe('template');
    expect(templateLevel.handler?.handler).toBe('navigate');
  });

  /**
   * @scenario subject=error_handling_save_prune
   * @effects save_prune_keeps_data_source_on_error
   */
  it('데이터 소스: 지운 코드는 그 소스의 onError 로 간다', () => {
    const saved = stripInheritedFromLayoutContent(
      contentWith({
        data_sources: [{ id: 'orders', type: 'api', errorHandling: { '401': {}, '500': { handler: 'navigate' } }, onError: { handler: 'toast' } }],
      }),
    );
    const entry = (saved.data_sources as Array<Record<string, unknown>>)[0];
    const result = ErrorHandlingResolver.getInstance().resolve(401, {
      errorHandling: entry.errorHandling as never,
      onError: entry.onError as never,
    });
    expect(result.handler?.handler).toBe('toast');
  });
});
