// e2e:allow 엔진 init 단계 교착 잠금 — 화면 검증은 7.1.1 출시 전 실측(비회원 클레임 신청 잘못된 유형 주소) 재실측이 한다.
/**
 * TemplateApp — init_actions 안의 showErrorPage 가 첫 렌더와 서로를 기다리던 교착 (7.1.1 출시 전 실측)
 *
 * `executeInitActions` 는 렌더 **전에** init 을 끝까지 기다린다. `showErrorPage` 는 `#main_content` 가 생길 때까지
 * 최대 60초 기다리는데, 그 컨테이너는 렌더가 끝나야 생긴다 — 서로를 기다려 화면이 빈 채로 남았다
 * (`/shop/guest/orders/NOPE0000/claims/apply/bogus`: 8초 동안 본문 빈 문자열). blocking 데이터 소스 오류 처리는
 * 같은 이유로 이미 비동기로 돈다(DataSourceManager 「데드락」 주석).
 *
 * 렌더 전 init 단계에서 부른 showErrorPage 는 init 체인을 막지 않고, 이 경로의 첫 렌더가 끝난 뒤 실행된다.
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

/** 약속이 주어진 시간 안에 끝나는지 */
async function settlesWithin(promise: Promise<unknown>, ms: number): Promise<boolean> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  const timeout = new Promise<false>((resolve) => {
    timer = setTimeout(() => resolve(false), ms);
  });
  const done = promise.then(() => true as const, () => true as const);
  const result = await Promise.race([done, timeout]);
  clearTimeout(timer);
  return result;
}

describe('init_actions 안의 showErrorPage — 렌더를 막지 않고 렌더 뒤에 실행', () => {
  let app: any;
  let renderError: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
    app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false });
    renderError = vi.fn().mockResolvedValue(true);
    app.getErrorPageHandler = () => ({ renderError });
    (window as any).__templateApp = app;
    (window as any).G7Core = { dispatch: vi.fn() };
  });

  afterEach(() => {
    delete (window as any).__templateApp;
    delete (window as any).G7Core;
    document.body.innerHTML = '';
  });

  // 레이아웃 sirsoft-basic shop/guest_claim_apply·mypage/claims/apply 의 유형 가드와 같은 모양
  const guardInit = [
    {
      handler: 'conditions',
      conditions: [{ if: '{{true}}', then: { handler: 'showErrorPage', params: { errorCode: 404, target: 'content' } } }],
    },
  ];

  /**
   * @scenario slot=init, container=absent, route=same
   * @effects init_show_error_page_does_not_block_render
   */
  it('컨테이너가 아직 없을 때 init 체인은 기다리지 않고 끝나고, 렌더 뒤 오류 화면을 그린다', async () => {
    const routeChangeId = app.currentRouteChangeId;

    // 종전: #main_content 를 60초 기다려 init 이 끝나지 않는다(렌더가 시작되지 못함)
    expect(await settlesWithin(app.executeInitActions(guardInit, {}), 500)).toBe(true);
    expect(renderError).not.toHaveBeenCalled();

    // 첫 렌더가 컨테이너를 만든 뒤 TemplateApp 이 미뤄 둔 동작을 실행한다
    const main = document.createElement('div');
    main.id = 'main_content';
    document.getElementById('app')!.appendChild(main);
    await app.flushAfterRenderTasks(routeChangeId);

    await vi.waitFor(() => expect(renderError).toHaveBeenCalledWith(404, 'main_content', expect.any(Function)));
  });

  /**
   * SPA 이동이면 앞 화면의 `#main_content` 가 아직 DOM 에 있다. 거기에 바로 그리면 곧이어 새 화면 렌더가 덮는다 —
   * 렌더 전 init 단계에서는 컨테이너가 있어도 이 경로의 렌더 뒤로 미룬다.
   *
   * @scenario slot=init, container=stale_previous_page, route=same
   * @effects init_show_error_page_waits_for_this_route_render
   */
  it('앞 화면의 컨테이너가 남아 있어도 이 경로의 렌더 뒤에 그린다', async () => {
    const stale = document.createElement('div');
    stale.id = 'main_content';
    document.getElementById('app')!.appendChild(stale);
    const routeChangeId = app.currentRouteChangeId;

    await app.executeInitActions(guardInit, {});
    expect(renderError).not.toHaveBeenCalled();

    await app.flushAfterRenderTasks(routeChangeId);
    await vi.waitFor(() => expect(renderError).toHaveBeenCalledTimes(1));
  });

  /**
   * 렌더 전에 다른 경로로 이동하면 미뤄 둔 오류 화면은 버린다 — 새 화면에 앞 화면의 오류가 덮이지 않는다.
   * 버린 뒤에도 다음 showErrorPage 가 「이미 실행 중」 으로 건너뛰어지지 않는다.
   *
   * @scenario slot=init, container=absent, route=superseded
   * @effects superseded_deferred_error_page_dropped
   */
  it('렌더 전에 경로가 바뀌면 미룬 오류 화면을 버리고, 다음 호출은 정상 실행된다', async () => {
    const firstId = app.currentRouteChangeId;
    await app.executeInitActions(guardInit, {});

    // 새 경로 전환이 시작됨 → 앞 경로의 미룬 동작 폐기
    app.currentRouteChangeId = firstId + 1;
    app.dropAfterRenderTasks();
    await app.flushAfterRenderTasks(firstId);
    expect(renderError).not.toHaveBeenCalled();

    // 렌더 뒤(init 단계 밖)의 호출은 종전대로 바로 실행된다
    const main = document.createElement('div');
    main.id = 'main_content';
    document.getElementById('app')!.appendChild(main);
    await realDispatcher.current.dispatchAction({ handler: 'showErrorPage', params: { errorCode: 403 } }, { data: {} });
    expect(renderError).toHaveBeenCalledWith(403, 'main_content', expect.any(Function));
  });
});
