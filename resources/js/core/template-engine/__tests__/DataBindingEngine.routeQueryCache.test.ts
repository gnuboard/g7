import { describe, it, expect, beforeEach } from 'vitest';
import { DataBindingEngine } from '../DataBindingEngine';

/**
 * 화면 전환 뒤 `{{route.*}}`·`{{query.*}}` 가 직전 화면 값으로 남던 결함의 회귀 잠금.
 *
 * 정적 경로 영구 캐시(키 = 경로 글자, 30초 만료)가 `route`·`query` 루트도 담아,
 * 글 보기(`/board/notice/226`) → SPA 로 다른 게시판 목록(`/board/opinion`) 이동 시
 * `<a href="/board/{{route.slug}}/write">` 가 `/board/notice/write` 로 그려졌다.
 * 화면 전환은 컨텍스트 객체를 새로 만들 뿐 캐시를 비우지 않으므로, 시험도 같은 엔진
 * 인스턴스에 새 컨텍스트를 넘겨 전환을 흉내 낸다.
 */
describe('[case:cache-24] DataBindingEngine — route·query 는 화면 전환 뒤 새 값으로 평가된다', () => {
  let engine: DataBindingEngine;

  beforeEach(() => {
    engine = new DataBindingEngine();
    engine.startRenderCycle();
  });

  /**
   * @scenario surface=binding
   * @effects bindings_interpolate_into_strings
   */
  it('resolveBindings: route.slug 가 전환 뒤 새 slug 로 보간된다', () => {
    const before = engine.resolveBindings('/board/{{route.slug}}/write', { route: { slug: 'notice', id: '226' } });
    expect(before).toBe('/board/notice/write');

    engine.startRenderCycle();
    const after = engine.resolveBindings('/board/{{route.slug}}/write', { route: { slug: 'opinion' } });
    expect(after).toBe('/board/opinion/write');
  });

  /**
   * @scenario surface=binding
   * @effects path_resolves_nested_values
   */
  it('resolve: route.id 가 전환 뒤 새 id 를 돌려준다', () => {
    expect(engine.resolve('route.id', { route: { id: '1' } })).toBe('1');
    engine.startRenderCycle();
    expect(engine.resolve('route.id', { route: { id: '2' } })).toBe('2');
  });

  /**
   * @scenario surface=binding
   * @effects bindings_interpolate_into_strings
   */
  it('evaluatePipeExpression: 파이프 기본식 query.page 가 전환 뒤 새 값이다', () => {
    expect(engine.evaluatePipeExpression('query.tab | default("all")', { query: { tab: 'info' } })).toBe('info');
    engine.startRenderCycle();
    expect(engine.evaluatePipeExpression('query.tab | default("all")', { query: { tab: 'orders' } })).toBe('orders');
  });

  /**
   * @scenario surface=binding
   * @effects cache_reuses_evaluated_results
   */
  it('route·query 는 영구 캐시에 남지 않고, 이름이 비슷한 데이터소스(routes)는 종전처럼 캐시된다', () => {
    engine.resolve('route.slug', { route: { slug: 'notice' } });
    engine.resolve('query.page', { query: { page: '2' } });
    expect(engine.getCacheStats().size).toBe(0);

    engine.resolve('routes.total', { routes: { total: 3 } });
    expect(engine.getCacheStats().size).toBe(1);
  });

  /**
   * @scenario surface=binding
   * @effects cache_reuses_evaluated_results
   */
  it('같은 렌더 사이클 안에서는 route 값을 재사용한다(렌더 사이클 캐시)', () => {
    const ctx = { route: { slug: 'notice' } };
    expect(engine.resolve('route.slug', ctx)).toBe('notice');
    ctx.route.slug = 'mutated';
    expect(engine.resolve('route.slug', ctx)).toBe('notice');
    engine.startRenderCycle();
    expect(engine.resolve('route.slug', ctx)).toBe('mutated');
  });
});
