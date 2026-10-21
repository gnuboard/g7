/**
 * navigate 대체 경로(`params.fallbackPath`) 테스트
 *
 * 같은 탭 이동은 거부된 주소로 이동하지 않는다(engine-v1.80.1). 로그인 완료처럼 반드시 어딘가로 가야 하는 자리는
 * `fallbackPath` 로 안전한 착지점을 적는다 — path 가 비었거나 거부되면 fallbackPath, 그것도 거부면 무동작.
 * 기존 `params.fallback`(미등록 라우트 핸들러)과는 다른 인자다.
 *
 * @since engine-v1.80.1
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';
import { resolveNavigatePathWithFallback } from '../../support/navPolicy';
import { CORE_ACTION_RECIPES } from '../layout-editor/spec/coreActionRecipes';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn(),
      logout: vi.fn(),
    })),
  },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: vi.fn(),
  })),
}));

describe('navigate fallbackPath (engine-v1.80.1)', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn<(path: string, options?: unknown) => void>>;
  let mockLocationAssign: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    mockNavigate = vi.fn<(path: string, options?: unknown) => void>();
    dispatcher = new ActionDispatcher({ navigate: mockNavigate });
    Logger.getInstance().setDebug(false);

    // 모든 경로가 등록 라우트 — 받아들여진 경로는 SPA 이동(mockNavigate)으로 관측한다
    (window as any).__templateApp = {
      getRouter: () => ({ match: () => ({}), getRoutes: () => [{}] }),
    };

    mockLocationAssign = vi.fn();
    delete (window as any).location;
    (window as any).location = {
      assign: mockLocationAssign,
      href: 'http://localhost/login',
      pathname: '/login',
      search: '',
      hash: '',
      origin: 'http://localhost',
    };
  });

  afterEach(() => {
    delete (window as any).__templateApp;
    vi.clearAllMocks();
  });

  const navigate = (params: Record<string, unknown>) =>
    dispatcher.dispatchAction({ type: 'click', handler: 'navigate', params } as any);

  /**
   * @scenario path_kind=same_origin_path, fallback_path=valid
   *
   * @effects accepted_path_ignores_fallback_path
   */
  it('받아들여지는 path 면 fallbackPath 를 읽지 않는다', async () => {
    await navigate({ path: '/board/free', fallbackPath: '/' });

    expect(mockNavigate).toHaveBeenCalledTimes(1);
    expect(mockNavigate.mock.calls[0][0]).toBe('/board/free');
  });

  /**
   * @scenario path_kind=same_origin_full_url, fallback_path=valid
   *
   * @effects accepted_path_ignores_fallback_path
   */
  it('같은 출처 전체 주소 path 는 그 경로로 가고 fallbackPath 를 읽지 않는다', async () => {
    await navigate({ path: 'http://localhost/mypage?tab=2', fallbackPath: '/' });

    expect(mockNavigate.mock.calls[0][0]).toBe('/mypage?tab=2');
  });

  /**
   * @scenario path_kind=script_scheme, fallback_path=valid
   *
   * @effects rejected_path_uses_fallback_path
   */
  it('실행 스킴 path 는 fallbackPath 로 간다 (변형 포함)', async () => {
    for (const path of ['javascript:alert(1)', ' JaVa\tScRiPt:alert(1)', '\x01javascript:alert(1)', 'data:text/html,x']) {
      mockNavigate.mockClear();
      await navigate({ path, fallbackPath: '/' });
      expect(mockNavigate).toHaveBeenCalledTimes(1);
      expect(mockNavigate.mock.calls[0][0]).toBe('/');
    }
    expect(mockLocationAssign).not.toHaveBeenCalled();
  });

  /**
   * @scenario path_kind=offsite, fallback_path=valid
   *
   * @effects rejected_path_uses_fallback_path
   */
  it('다른 출처·//host·백슬래시 우회 path 는 fallbackPath 로 간다', async () => {
    for (const path of ['https://example.com/x', '//example.com/x', '///example.com', '/\\example.com/x', '\x01//example.com', 'http://localhost:8080/x', 'mailto:a@b.c']) {
      mockNavigate.mockClear();
      await navigate({ path, fallbackPath: '/mypage' });
      expect(mockNavigate).toHaveBeenCalledTimes(1);
      expect(mockNavigate.mock.calls[0][0]).toBe('/mypage');
    }
  });

  /**
   * 같은 탭 최종 판정(sanitizeNavPath)이 거부하는 상대 경로·쿼리만 있는 값은 fallbackPath 로 간다 —
   * fallbackPath 를 쓸지 정하는 판정과 실제 이동을 거부하는 판정이 갈리면 「통과했는데 이동 0」 이 된다.
   *
   * @scenario path_kind=relative, fallback_path=valid
   *
   * @effects rejected_path_uses_fallback_path
   */
  it('상대 경로(admin/users·./x·../x·?q=1) path 는 fallbackPath 로 간다', async () => {
    for (const path of ['admin/users', './x', '../x', '?q=1', 'board']) {
      mockNavigate.mockClear();
      await navigate({ path, fallbackPath: '/admin' });
      expect(mockNavigate).toHaveBeenCalledTimes(1);
      expect(mockNavigate.mock.calls[0][0]).toBe('/admin');
    }
  });

  /**
   * @scenario path_kind=empty, fallback_path=valid
   *
   * @effects empty_path_uses_fallback_path
   */
  it('비어 있는 path(빈 ?redirect=·미해석 값) 는 fallbackPath 로 간다', async () => {
    for (const path of ['', '   ', undefined, null]) {
      mockNavigate.mockClear();
      await navigate({ path, fallbackPath: '/' });
      expect(mockNavigate).toHaveBeenCalledTimes(1);
      expect(mockNavigate.mock.calls[0][0]).toBe('/');
    }
  });

  // @scenario path_kind=offsite, fallback_path=rejected
  // @scenario path_kind=empty, fallback_path=rejected
  // @scenario path_kind=relative, fallback_path=rejected
  /**
   * @scenario path_kind=script_scheme, fallback_path=rejected
   *
   * @effects rejected_fallback_path_is_no_op
   */
  it('fallbackPath 도 거부·빈 값이면 이동하지 않는다', async () => {
    for (const [path, fallbackPath] of [
      ['javascript:alert(1)', 'javascript:alert(2)'],
      ['//example.com', 'https://example.com/'],
      ['', '//example.com'],
      ['', ''],
      ['admin/users', 'admin'],
    ]) {
      await navigate({ path, fallbackPath });
    }

    expect(mockNavigate).not.toHaveBeenCalled();
    expect(mockLocationAssign).not.toHaveBeenCalled();
  });

  // @scenario path_kind=offsite, fallback_path=absent
  // @scenario path_kind=empty, fallback_path=absent
  // @scenario path_kind=same_origin_path, fallback_path=absent
  // @scenario path_kind=relative, fallback_path=absent
  /**
   * @scenario path_kind=script_scheme, fallback_path=absent
   *
   * @effects absent_fallback_path_keeps_no_op
   */
  it('fallbackPath 가 없으면 종전 그대로 — 거부·빈 주소는 이동하지 않고, 정상 경로는 그대로 간다', async () => {
    // 미등록 라우트 폴백(openWindow _self)이 거부 판정을 하도록 라우트 미매칭으로 둔다
    (window as any).__templateApp = { getRouter: () => ({ match: () => null, getRoutes: () => [{}] }) };

    await navigate({ path: 'javascript:alert(1)' });
    await navigate({ path: '//example.com/x' });
    await navigate({ path: '' });
    await navigate({ path: 'admin/users' });
    expect(mockNavigate).not.toHaveBeenCalled();
    expect(mockLocationAssign).not.toHaveBeenCalled();

    await navigate({ path: '/outside' });
    expect(mockLocationAssign).toHaveBeenCalledWith('/outside');
  });

  /**
   * @effects rejected_path_uses_fallback_path
   */
  it('판정 함수: path 우선, 거부·빈 값이면 fallbackPath, 둘 다 거부면 null', () => {
    expect(resolveNavigatePathWithFallback('/a', '/')).toBe('/a');
    // 상대 경로는 같은 탭 최종 판정(sanitizeNavPath)이 거부하는 값 — fallbackPath 판정도 같은 함수라 대체 경로로 간다
    expect(resolveNavigatePathWithFallback('board/free', '/')).toBe('/');
    expect(resolveNavigatePathWithFallback('board/free', 'board')).toBeNull();
    expect(resolveNavigatePathWithFallback('http://localhost/a?b=1#c', '/')).toBe('/a?b=1#c');
    expect(resolveNavigatePathWithFallback('javascript:x', '/home')).toBe('/home');
    expect(resolveNavigatePathWithFallback('//evil', 'http://localhost/home')).toBe('/home');
    expect(resolveNavigatePathWithFallback(undefined, '/')).toBe('/');
    expect(resolveNavigatePathWithFallback('//evil', '/\\evil')).toBeNull();
    expect(resolveNavigatePathWithFallback('//evil', undefined)).toBeNull();
  });

  /**
   * @effects editor_recipe_keeps_fallback_path
   */
  it('레이아웃 편집기 「다른 페이지로 이동」 폼이 fallbackPath 를 칸·저장 틀에 둔다 (폼 저장이 값을 지우지 않게)', () => {
    const recipe = CORE_ACTION_RECIPES.navigate;
    expect(recipe.params?.some((p: { key: string }) => p.key === 'fallbackPath')).toBe(true);
    expect((recipe.build as any).params.fallbackPath).toBe('{{fallbackPath}}');
  });
});
