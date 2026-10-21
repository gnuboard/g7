// e2e:allow 엔진 경로 전환 경합 잠금 — 화면 검증은 엔진 재실측(잘못된 클레임 주소에서 404 전 다른 메뉴로 이동)이 한다.
/**
 * 늦게 도착한 showErrorPage 가 이미 이동한 다음 화면을 덮던 경합 (7.1.1 출시 전 실측)
 *
 * 잘못된 클레임 주소에서 404 가 뜨기 전(~0.5초) 다른 메뉴로 SPA 이동하면, 앞 화면이 부른 showErrorPage 가 컨테이너 대기·
 * 오류 레이아웃 적재를 마친 뒤 새 화면 위에 404 를 그렸다(주소 `/mypage/mileage`, 본문 404). showErrorPage 는 부른 시점의
 * 경로 세대(`TemplateApp.getRouteChangeId`, 데이터 소스 오류 처리는 그 fetch 를 시작한 세대)를 잡아 두고, 그리기 직전에
 * 세대가 바뀌었으면 그리지 않는다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const { realDispatcher } = vi.hoisted(() => ({ realDispatcher: { current: null as any } }));

vi.mock('../template-engine', async () => {
  const { ActionDispatcher } = await vi.importActual<any>('../template-engine/ActionDispatcher');
  realDispatcher.current = new ActionDispatcher({ navigate: () => {} });
  return {
    initTemplateEngine: vi.fn().mockResolvedValue(undefined),
    renderTemplate: vi.fn().mockResolvedValue(undefined),
    destroyTemplate: vi.fn(),
    releaseTemplateRoot: vi.fn(),
    getActionDispatcher: () => realDispatcher.current,
    getState: () => ({ actionDispatcher: realDispatcher.current, reactRoot: null, currentLayoutJson: null }),
  };
});

import { TemplateApp } from '../TemplateApp';

const tick = (ms = 20) => new Promise((resolve) => setTimeout(resolve, ms));

describe('늦은 showErrorPage 는 바뀐 경로를 덮지 않는다', () => {
  let app: any;
  let renderError: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
    app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false });
    renderError = vi.fn(async (_code: number, _id: string, shouldRender?: () => boolean) => (shouldRender ? shouldRender() : true));
    app.getErrorPageHandler = () => ({ renderError });
    (window as any).__templateApp = app;
    (window as any).G7Core = { dispatch: vi.fn() };
  });

  afterEach(() => {
    delete (window as any).__templateApp;
    delete (window as any).G7Core;
    document.body.innerHTML = '';
  });

  const addContainer = () => {
    const main = document.createElement('div');
    main.id = 'main_content';
    document.getElementById('app')!.appendChild(main);
  };

  /**
   * @scenario origin=action, timing=before_container
   * @effects stale_error_page_not_drawn_over_next_route
   */
  it('컨테이너를 기다리는 동안 경로가 바뀌면 그리지 않는다', async () => {
    const pending = realDispatcher.current.dispatchAction({ handler: 'showErrorPage', params: { errorCode: 404 } }, { data: {} });
    await tick();
    app.currentRouteChangeId += 1; // 사용자가 다른 메뉴로 이동
    addContainer(); // 새 화면 렌더
    await pending;

    // 종전: 새 화면의 컨테이너가 생기자 앞 화면의 404 를 그렸다
    expect(renderError).not.toHaveBeenCalled();
  });

  /**
   * @scenario origin=action, timing=during_render
   * @effects stale_error_page_not_drawn_over_next_route
   */
  it('오류 레이아웃을 적재하는 동안 경로가 바뀌면 그리기 직전 판정이 거짓이다', async () => {
    addContainer();
    renderError.mockImplementationOnce(async (_c: number, _i: string, shouldRender?: () => boolean) => {
      app.currentRouteChangeId += 1; // 적재 중 이동
      return shouldRender ? shouldRender() : true;
    });
    await realDispatcher.current.dispatchAction({ handler: 'showErrorPage', params: { errorCode: 404 } }, { data: {} });

    expect(renderError).toHaveBeenCalledTimes(1);
    expect(typeof renderError.mock.calls[0][2]).toBe('function');
    expect(await renderError.mock.results[0].value).toBe(false);
  });

  /**
   * 데이터 소스 오류 처리는 그 fetch 를 시작한 경로 세대(`__routeGeneration`)로 판정한다 — 앞 화면 fetch 가 이동 뒤에 실패해도
   * 새 화면을 덮지 않는다.
   *
   * @scenario origin=data_source, timing=late_fetch
   * @effects stale_error_page_not_drawn_over_next_route
   */
  it('앞 경로에서 시작한 데이터 소스의 오류 처리는 이동 뒤에 와도 그리지 않는다', async () => {
    addContainer();
    const origin = app.currentRouteChangeId;
    app.currentRouteChangeId += 1;
    await realDispatcher.current.dispatchAction(
      { handler: 'showErrorPage', params: { errorCode: 404 } },
      { data: { __routeGeneration: origin } },
    );

    expect(renderError).not.toHaveBeenCalled();
  });

  /**
   * @scenario origin=action, timing=same_route
   * @effects same_route_error_page_drawn
   */
  it('같은 경로에서는 종전대로 그리고, 그 뒤 호출도 막히지 않는다', async () => {
    const pending = realDispatcher.current.dispatchAction({ handler: 'showErrorPage', params: { errorCode: 404 } }, { data: {} });
    app.currentRouteChangeId += 1;
    addContainer();
    await pending; // 버려짐

    await realDispatcher.current.dispatchAction({ handler: 'showErrorPage', params: { errorCode: 403 } }, { data: {} });
    expect(renderError).toHaveBeenCalledTimes(1);
    expect(renderError.mock.calls[0][0]).toBe(403);
    expect(await renderError.mock.results[0].value).toBe(true);
  });
});
