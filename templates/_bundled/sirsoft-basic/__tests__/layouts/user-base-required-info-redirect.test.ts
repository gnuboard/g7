/**
 * @file user-base-required-info-redirect.test.ts
 * @description 공통 사용자 레이아웃의 추가 정보 입력 유도, 유도 제외 화면, 쓰기 거부 안내 창과 오류 처리 우선순위
 *
 * 필수 항목이 비어 있는 회원(본인 응답의 pending_required_fields 에 키가 있다)이 유도 제외 화면이 아닌 곳에 있으면
 * 추가 정보 입력 화면으로 한 번 바꿔 보낸다. 서버가 쓰기를 거부하면(403, error_code 가 required_fields_pending)
 * 템플릿 수준 오류 처리가 안내 창을 열고, 다른 403 과 데이터소스, 레이아웃, 액션이 스스로 정한 처리는 그대로 둔다.
 *
 * 식은 실제 앱과 같은 평가기로 풀고, 오류 처리는 실제 ErrorHandlingResolver 와 ActionDispatcher 로 돌린다.
 *
 * @vitest-environment jsdom
 * @scenario-manifest tests/scenarios/user-base-required-info-redirect.yaml
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, beforeEach, afterEach, vi, type Mock } from 'vitest';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';
import { ErrorHandlingResolver, getErrorHandlingResolver } from '@core/error/ErrorHandlingResolver';
import { DEFAULT_ERROR_HANDLING } from '@core/types/ErrorHandling';

import userBase from '../../layouts/_user_base.json';
import pendingModal from '../../layouts/partials/_modal_required_info_pending.json';
import templateManifest from '../../template.json';
import states from '../../editor-spec/states.json';
import koMember from '../../lang/partial/ko/member.json';
import enMember from '../../lang/partial/en/member.json';
import koEditor from '../../lang/partial/ko/editor.json';
import enEditor from '../../lang/partial/en/editor.json';

type Node = Record<string, any>;

const base = userBase as Node;
const modal = pendingModal as Node;
const manifest = templateManifest as Node;

/** 배열이든 객체 하나든 배열로 맞춘다 */
function list<T = Node>(value: unknown): T[] {
  if (value === undefined || value === null) return [];
  return (Array.isArray(value) ? value : [value]) as T[];
}

/** 식({{ }})을 실제 엔진의 평가기로 푼다 */
function engineEval(expression: string, context: Record<string, unknown>): any {
  const body = expression.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  return new DataBindingEngine().evaluateExpression(body, context as any, { skipCache: true });
}

const initActions = list<Node>(base.init_actions ?? base.initActions);
const gateInit = initActions.find((action) => action.handler === 'setState' && action.params?.requiredInfoGate !== undefined) as Node;
const currentUser = list<Node>(base.data_sources).find((source) => source.id === 'current_user') as Node;
const guide = list<Node>(currentUser.onSuccess).find((action) => action.handler === 'switch' && action.cases?.guide) as Node;

/** 화면에 들어올 때 init 이 적는 기준값 */
const gateFor = (routePath: string, query: Record<string, unknown> = {}) =>
  engineEval(gateInit.params.requiredInfoGate, { route: { path: routePath }, query });

/**
 * current_user 응답을 받은 뒤 유도할지.
 *
 * 데이터소스 성공 처리의 if 는 응답만 든 문맥으로 풀린다(전역 상태가 없다). 그래서 남은 항목 유무는 if(응답만),
 * 제외 여부는 switch 의 값(인자 풀이 경로라 최신 전역 상태가 실린다)으로 갈라 두었고, 여기서도 그 문맥대로 푼다.
 */
const guides = (body: unknown, gate: unknown) =>
  engineEval(guide.if, { response: { data: body, sourceId: 'current_user' } }) === true &&
  engineEval(guide.params.value, { response: { data: body, sourceId: 'current_user' }, _global: { requiredInfoGate: gate } }) === 'guide';

/** 유도할 때 가는 주소 */
const guideUrl = (gate: unknown) => engineEval(guide.cases.guide.params.url, { _global: { requiredInfoGate: gate } });

const pendingBody = { success: true, data: { uuid: 'u-1', pending_required_fields: ['nickname', 'job'] } };
const completeBody = { success: true, data: { uuid: 'u-1', pending_required_fields: [] } };

const EXEMPT_PATHS = [
  '/mypage/required-info',
  '/page/terms',
  '/page/privacy',
  '/identity/challenge',
  '/identity/verify',
  '/mypage/change-password',
  '/mypage/profile',
  '/login',
  '/register',
  '/forgot-password',
  '/reset-password',
];

const GUIDED_PATHS = ['/', '/board/free', '/board/free/12', '/board/free/write', '/mypage/profile/edit', '/mypage/orders', '/shop/checkout', '/search', '/users/7'];

describe('공통 사용자 레이아웃 — 추가 정보 입력 유도', () => {
  describe('유도 판정', () => {
    /**
     * @scenario case=guide_pending_member
     * @effects pending_member_is_replaced_to_required_info_with_origin
     */
    it('필수 항목이 비어 있는 회원을 원래 주소를 redirect 로 실어 추가 정보 입력 화면으로 바꿔 보낸다', () => {
      expect(gateInit).toBeDefined();
      expect(gateInit.params.target).toBe('global');
      expect(guide).toBeDefined();
      expect(Object.keys(guide.cases)).toEqual(['guide']);
      expect(guide.cases.guide.handler).toBe('redirect');
      expect(guide.cases.guide.params.replace).toBe(true);
      // 성공 처리의 if 는 전역 상태를 읽지 못한다 — if 에 _global 을 쓰면 늘 거짓이 되어 유도가 죽는다
      expect(guide.if).not.toContain('_global');
      expect(guide.if).toContain('pending_required_fields');

      const gate = gateFor('/board/free', { page: '2', q: '공지 사항' });
      expect(gate).toEqual({ from: '/board/free?page=2&q=%EA%B3%B5%EC%A7%80%20%EC%82%AC%ED%95%AD', exempt: false });
      expect(guides(pendingBody, gate)).toBe(true);
      expect(guideUrl(gate)).toBe('/mypage/required-info?redirect=' + encodeURIComponent('/board/free?page=2&q=%EA%B3%B5%EC%A7%80%20%EC%82%AC%ED%95%AD'));

      // 응답 몸통이 한 겹 풀려 와도 같은 판정이다
      expect(guides(pendingBody.data, gate)).toBe(true);
      // 배열 쿼리는 값마다 한 쌍으로 싣는다
      expect(gateFor('/search', { 'type[]': ['post', 'page'] }).from).toBe('/search?type%5B%5D=post&type%5B%5D=page');
      for (const routePath of GUIDED_PATHS) {
        expect(guides(pendingBody, gateFor(routePath)), routePath).toBe(true);
      }
    });

    /**
     * @scenario case=no_guide_when_complete
     * @effects complete_member_and_guest_are_not_guided
     */
    it('남은 항목이 없는 회원과 기준값이 없는 화면에서는 보내지 않는다', () => {
      const gate = gateFor('/board/free');
      expect(guides(completeBody, gate)).toBe(false);
      expect(guides({ success: true, data: { uuid: 'u-1' } }, gate)).toBe(false);
      expect(guides({ data: [] }, gate)).toBe(false);
      // init 이 아직 기준값을 적지 못했으면(주소를 모르면) 보내지 않는다
      expect(guides(pendingBody, undefined)).toBe(false);
      expect(guides(pendingBody, { from: '', exempt: false })).toBe(false);
      expect(gateFor('').from).toBe('');
    });

    /**
     * @scenario case=exempt_paths
     * @effects exempt_screens_stay_open
     */
    it('제외 화면(이 화면 자신, 문서, 본인인증, 비밀번호 변경, 탈퇴 창이 있는 내 정보 보기, 로그인 전 화면)에서는 보내지 않는다', () => {
      for (const routePath of EXEMPT_PATHS) {
        const gate = gateFor(routePath);
        expect(gate.exempt, routePath).toBe(true);
        expect(guides(pendingBody, gate), routePath).toBe(false);
      }
    });

    /**
     * @scenario case=profile_edit_not_exempt
     * @effects profile_edit_with_verification_token_is_guided
     */
    it('재확인을 마치고 verification_token 을 들고 돌아온 내 정보 수정은 제외가 아니다', () => {
      const gate = gateFor('/mypage/profile/edit', { verification_token: 'tok-1' });
      expect(gate).toEqual({ from: '/mypage/profile/edit?verification_token=tok-1', exempt: false });
      expect(guides(pendingBody, gate)).toBe(true);
    });

    /**
     * @scenario case=guide_once
     * @effects landing_screen_does_not_guide_again
     */
    it('옮겨 간 추가 정보 입력 화면에서는 다시 옮기지 않는다(유도 1회)', () => {
      const first = gateFor('/board/free', { page: '2' });
      const landing = new URL(guideUrl(first), 'https://site.example');
      expect(landing.pathname).toBe('/mypage/required-info');
      expect(landing.searchParams.get('redirect')).toBe('/board/free?page=2');

      const landingGate = gateFor(landing.pathname, Object.fromEntries(landing.searchParams.entries()));
      expect(landingGate.exempt).toBe(true);
      expect(guides(pendingBody, landingGate)).toBe(false);
      // 유도 액션은 current_user 성공 처리에 하나뿐이다
      expect(list<Node>(currentUser.onSuccess).filter((action) => JSON.stringify(action).includes('/mypage/required-info'))).toHaveLength(1);
    });

    /**
     * @scenario case=guide_dispatch
     * @effects guide_runs_as_replace_redirect_through_dispatcher
     */
    it('실제 디스패처에서 조건이 참일 때만 이력을 남기지 않는 이동(redirect replace)이 한 번 실행된다', async () => {
      const dispatcher = new ActionDispatcher({ navigate: vi.fn() });
      const redirect = vi.spyOn(dispatcher as any, 'handleRedirect').mockResolvedValue(undefined);
      let globalState: Record<string, any> = { requiredInfoGate: gateFor('/board/free') };
      (window as any).G7Core = { state: { get: () => globalState } };

      await dispatcher.dispatchAction({ ...guide, type: 'click' } as any, { data: { response: { data: pendingBody, sourceId: 'current_user' } } });
      expect(redirect).toHaveBeenCalledTimes(1);
      expect(redirect.mock.calls[0][0]).toBe('/mypage/required-info?redirect=%2Fboard%2Ffree');
      expect((redirect.mock.calls[0][1] as Node).replace).toBe(true);

      redirect.mockClear();
      globalState = { requiredInfoGate: gateFor('/mypage/required-info', { redirect: '/board/free' }) };
      await dispatcher.dispatchAction({ ...guide, type: 'click' } as any, { data: { response: { data: pendingBody, sourceId: 'current_user' } } });
      await dispatcher.dispatchAction({ ...guide, type: 'click' } as any, { data: { response: { data: completeBody, sourceId: 'current_user' } } });
      expect(redirect).not.toHaveBeenCalled();

      vi.restoreAllMocks();
      delete (window as any).G7Core;
    });
  });

  describe('쓰기 거부 안내 창과 오류 처리 우선순위', () => {
    let dispatcher: ActionDispatcher;
    let navigate: Mock;
    let globalState: Record<string, any>;

    beforeEach(() => {
      ErrorHandlingResolver.resetInstance();
      navigate = vi.fn();
      globalState = { toasts: [], modalStack: [], requiredInfoGate: { from: '/board/free/write', exempt: false } };
      dispatcher = new ActionDispatcher({ navigate });
      dispatcher.setGlobalStateUpdater((updates: any) => {
        const next = typeof updates === 'function' ? updates(globalState) : updates;
        globalState = { ...globalState, ...next };
      });
      (window as any).G7Core = { state: { get: () => globalState, getLocal: () => ({}), setLocal: vi.fn() } };
      (window as any).__templateApp = { getRouter: () => ({ match: () => ({ route: {} }) }) };
      // 실제 앱은 template.json 의 errorHandling 을 템플릿 수준 설정으로 등록한다
      getErrorHandlingResolver().setTemplateConfig(manifest.errorHandling);
    });

    afterEach(() => {
      vi.restoreAllMocks();
      ErrorHandlingResolver.resetInstance();
      delete (window as any).__templateApp;
      delete (window as any).G7Core;
    });

    const rejected = (status: number, body: Record<string, unknown>) =>
      vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify(body), { status }));

    const pendingRejection = {
      success: false,
      message: '추가 정보를 입력해야 이용할 수 있습니다.',
      error_code: 'required_fields_pending',
      data: { pending_required_fields: ['job'], fields: [{ key: 'job', label: '직업' }] },
    };

    const write = (extra: Record<string, unknown> = {}) =>
      dispatcher.dispatchAction({ type: 'click', handler: 'apiCall', target: '/api/modules/sirsoft-board/boards/free/posts', params: { method: 'POST', body: { title: 't' } }, ...extra } as any, {});

    const toasts = () => list<Node>(globalState.toasts);

    /**
     * @scenario case=template_level_handler
     * @effects template_declares_403_handler_only_for_required_fields_pending
     */
    it('template.json 이 403 에만 템플릿 수준 오류 처리를 두고, required_fields_pending 이 아니면 종전 기본 처리(오류 토스트)와 같다', () => {
      expect(Object.keys(manifest.errorHandling)).toEqual(['403']);
      const handler = manifest.errorHandling['403'];
      expect(handler.handler).toBe('conditions');
      expect(handler.conditions).toHaveLength(2);
      expect(handler.conditions[0].if).toBe("{{error.error_code === 'required_fields_pending'}}");
      expect(handler.conditions[0].then).toEqual({ handler: 'openModal', target: 'required_info_pending_modal' });
      // 나머지 403 은 시스템 기본 처리와 글자까지 같다 — 다른 403 의 동작이 바뀌지 않는다
      expect(handler.conditions[1].if).toBeUndefined();
      expect(handler.conditions[1].then).toEqual(DEFAULT_ERROR_HANDLING[403]);
      // 레이아웃 수준 오류 처리는 두지 않았다(템플릿 수준이 닿는다)
      expect(base.errorHandling).toBeUndefined();
    });

    /**
     * @scenario case=priority
     * @effects data_source_and_layout_and_action_handlers_win_over_template
     */
    it('우선순위는 데이터소스(액션) > 레이아웃 > 템플릿이다', () => {
      const resolver = getErrorHandlingResolver();

      // 아무 처리도 정하지 않은 요청 → 템플릿 수준
      expect(resolver.resolve(403, {}).level).toBe('template');
      // 템플릿이 정하지 않은 코드는 시스템 기본값으로 간다
      expect(resolver.resolve(404, {}).level).toBe('system');
      expect(resolver.resolve(422, {}).level).toBe('system');

      // 데이터소스가 스스로 정한 403 처리(알림 배지와 목록의 삼킴)가 이긴다
      const sources = list<Node>(base.data_sources);
      const withOwn403 = sources.filter((source) => source.errorHandling?.['403']);
      expect(withOwn403.map((source) => source.id).sort()).toEqual(['notification_unread_count', 'notifications']);
      for (const source of withOwn403) {
        const resolved = resolver.resolve(403, { errorHandling: source.errorHandling });
        expect(resolved.level, source.id).toBe('action');
        expect(resolved.handler?.handler, source.id).toBe('suppress');
      }
      // current_user 의 401 삼킴도 그대로다
      const own401 = resolver.resolve(401, { errorHandling: currentUser.errorHandling });
      expect(own401.level).toBe('action');
      expect(own401.handler?.handler).toBe('suppress');

      // 액션의 onError 도 템플릿보다 먼저다
      expect(resolver.resolve(403, { onError: { handler: 'toast', params: { type: 'error', message: '{{error.message}}' } } as any }).level).toBe('action');

      // 레이아웃 수준이 템플릿보다 먼저다
      resolver.setLayoutConfig({ 403: { handler: 'showErrorPage', params: { target: 'content' } } } as any);
      const layoutLevel = resolver.resolve(403, {});
      expect(layoutLevel.level).toBe('layout');
      expect(layoutLevel.handler?.handler).toBe('showErrorPage');
      // 그때도 데이터소스가 정한 처리가 가장 먼저다
      expect(resolver.resolve(403, { errorHandling: withOwn403[0].errorHandling }).level).toBe('action');
      resolver.clearLayoutConfig();
      expect(resolver.resolve(403, {}).level).toBe('template');
    });

    /**
     * @scenario case=write_blocked_modal
     * @effects blocked_write_opens_modal_without_navigating
     */
    it('쓰기가 required_fields_pending 으로 거부되면 안내 창을 열고 바로 이동하지 않는다', async () => {
      rejected(403, pendingRejection);
      await write();

      expect(globalState.modalStack).toEqual(['required_info_pending_modal']);
      expect(navigate).not.toHaveBeenCalled();
      expect(toasts()).toEqual([]);
    });

    /**
     * @scenario case=other_403
     * @effects other_403_keeps_error_toast
     */
    it('다른 403 은 종전대로 오류 토스트만 뜬다', async () => {
      rejected(403, { success: false, message: '권한이 없습니다.' });
      await write();
      expect(globalState.modalStack).toEqual([]);
      expect(toasts().map((toast) => [toast.type, toast.message])).toEqual([['error', '권한이 없습니다.']]);

      globalState = { ...globalState, toasts: [] };
      rejected(403, { success: false, message: '차단된 계정입니다.', error_code: 'account_blocked' });
      await write();
      expect(globalState.modalStack).toEqual([]);
      expect(toasts().map((toast) => toast.message)).toEqual(['차단된 계정입니다.']);
    });

    /**
     * @scenario case=action_own_handler
     * @effects action_with_own_handler_keeps_its_behaviour
     */
    it('스스로 실패 처리를 정한 요청은 그 처리가 그대로 돌고 안내 창은 열리지 않는다', async () => {
      rejected(403, pendingRejection);
      await write({ onError: [{ handler: 'toast', params: { type: 'error', message: '{{error.message}}' } }] });
      expect(globalState.modalStack).toEqual([]);
      expect(toasts().map((toast) => toast.message)).toEqual(['추가 정보를 입력해야 이용할 수 있습니다.']);

      globalState = { ...globalState, toasts: [] };
      rejected(403, pendingRejection);
      await write({ errorHandling: { 403: { handler: 'suppress' } } });
      expect(globalState.modalStack).toEqual([]);
      expect(toasts()).toEqual([]);
    });

    /**
     * @scenario case=modal
     * @effects modal_has_close_and_go_buttons_and_lives_in_base_layout
     */
    it('안내 창은 상속원에 있고 닫기와 입력하러 가기 두 단추를 갖는다', () => {
      expect(list<Node>(base.modals).some((item) => item.partial === 'partials/_modal_required_info_pending.json')).toBe(true);
      expect(modal.id).toBe('required_info_pending_modal');
      expect(modal.name).toBe('Modal');
      expect(modal.props.title).toBe('$t:member.required_info.pending_modal.title');
      expect(list<Node>(modal.children).find((node) => node.id === 'required_info_pending_message')?.text).toBe('$t:member.required_info.pending_modal.message');

      const footer = list<Node>(modal.props.footer);
      expect(footer.map((node) => node.id)).toEqual(['required_info_pending_close', 'required_info_pending_go']);
      expect(footer.map((node) => node.text)).toEqual(['$t:member.required_info.pending_modal.close', '$t:member.required_info.pending_modal.go']);
      expect(list<Node>(footer[0].actions)[0]).toMatchObject({ handler: 'closeModal', target: 'required_info_pending_modal' });
      // 닫기는 단추, 입력하러 가기는 화면 이동이라 주소를 가진 링크다. 둘 다 터치 대상 높이(44px)를 갖는다
      expect(footer[0].name).toBe('Button');
      expect(footer[0].props.type).toBe('button');
      expect(footer[1].name).toBe('A');
      expect(engineEval(footer[1].props.href, { _global: { requiredInfoGate: { from: '/board/free' } } })).toBe('/mypage/required-info?redirect=%2Fboard%2Ffree');
      for (const button of footer) {
        expect(button.props.className).toContain('min-h-11');
      }

      for (const source of [koMember as Node, enMember as Node]) {
        expect(Object.keys(source.required_info.pending_modal).sort()).toEqual(['close', 'editor_label', 'go', 'message', 'title']);
      }
      expect((koMember as Node).required_info.pending_modal.title).toBe('추가 정보가 필요합니다');
      expect((koMember as Node).required_info.pending_modal.close).toBe('닫기');
      expect((koMember as Node).required_info.pending_modal.go).toBe('입력하러 가기');
    });

    /**
     * @scenario case=modal
     * @effects go_button_closes_modal_and_navigates_with_current_address
     */
    it('입력하러 가기는 창을 닫고 지금 주소를 돌아올 곳으로 실어 추가 정보 입력 화면으로 보낸다', async () => {
      const go = list<Node>(modal.props.footer)[1];
      const action = list<Node>(go.actions)[0];
      const move = list<Node>(action.actions).find((item) => item.handler === 'navigate')!;
      expect(list<Node>(action.actions).map((item) => item.handler)).toEqual(['closeModal', 'navigate']);
      expect(engineEval(move.params.path, { _global: { requiredInfoGate: { from: '/board/free/12?page=3' } } })).toBe('/mypage/required-info?redirect=%2Fboard%2Ffree%2F12%3Fpage%3D3');
      expect(engineEval(move.params.path, { _global: {} })).toBe('/mypage/required-info');

      rejected(403, pendingRejection);
      await write();
      expect(globalState.modalStack).toEqual(['required_info_pending_modal']);

      await dispatcher.dispatchAction({ ...action, type: 'click' } as any, { data: {} });
      expect(globalState.modalStack).toEqual([]);
      expect(navigate).toHaveBeenCalledTimes(1);
      expect(navigate.mock.calls[0][0]).toBe('/mypage/required-info?redirect=%2Fboard%2Ffree%2Fwrite');
    });

    /**
     * 다른 창 위에서 뜬 안내 창의 「입력하러 가기」는 밑의 창까지 모두 닫고 이동한다 (#490).
     *
     * 종전에는 맨 위(안내 창)만 닫혀, 장바구니 「옵션 변경」 창이 창 스택에 남아 추가 정보를 입력하고 돌아오면 다시 열려 있었다.
     *
     * @scenario case=modal_over_modal
     * @effects go_button_closes_every_open_modal
     */
    it('창 위에서 뜬 안내 창의 입력하러 가기는 밑의 창까지 모두 닫는다', async () => {
      const go = list<Node>(modal.props.footer)[1];
      const action = list<Node>(go.actions)[0];
      expect(list<Node>(action.actions)[0]).toMatchObject({ handler: 'closeModal', params: { all: true } });

      globalState = { ...globalState, modalStack: ['cart_option_modal', 'required_info_pending_modal'], activeModal: 'required_info_pending_modal' };
      (window as any).__g7LayoutContextStack = [
        { state: {}, setState: vi.fn(), dataContext: {} },
        { state: {}, setState: vi.fn(), dataContext: {} },
      ];

      // 창 안의 단추처럼 전역 사본을 실은 문맥으로 누른다 — 사본이 있어야 엔진이 최신 창 스택을 읽는다
      await dispatcher.dispatchAction({ ...action, type: 'click' } as any, { data: { _global: { ...globalState } } });

      expect(globalState.modalStack).toEqual([]);
      expect(globalState.activeModal).toBeNull();
      expect((window as any).__g7LayoutContextStack).toEqual([]);
      expect(navigate).toHaveBeenCalledTimes(1);
      delete (window as any).__g7LayoutContextStack;
    });
  });

  describe('편집기 상태 표본', () => {
    /**
     * @scenario case=editor_states
     * @effects editor_has_state_samples_for_screen_and_modal
     */
    it('추가 정보 입력 화면과 안내 창의 상태 표본이 있다', () => {
      const groups = (states as Node).groups as Node[];
      const screen = groups.find((group) => group.scope?.kind === 'route' && group.scope?.match === '/mypage/required-info')!;
      expect(screen.items.map((item: Node) => item.id)).toEqual(['default', 'validation_failed', 'saving']);
      expect(screen.items.filter((item: Node) => item.default === true)).toHaveLength(1);
      expect(Object.keys(screen.items[1].formErrors)).toEqual(['_local.requiredInfoErrors.nickname', '_local.requiredInfoErrors.mobile']);
      expect(screen.items[2].initialState.local.isSavingRequiredInfo).toBe(true);

      const dialog = groups.find((group) => group.scope?.kind === 'modal' && group.scope?.match === 'required_info_pending_modal')!;
      expect(dialog.items[0].initialState.global.requiredInfoGate).toEqual({ from: '/board/free', exempt: false });

      // 상태 라벨은 편집기 문구 묶음(`editor.state.*`)에서 풀린다(코어 계약 `stateLabelKeyResolution`) — 화면 문구(`member.*`)가 아니다
      for (const item of screen.items as Node[]) {
        expect(String(item.label), item.label).toMatch(/^\$t:editor\.state\./);
        const key = String(item.label).replace('$t:editor.', '');
        const read = (source: Node) => key.split('.').reduce((acc: any, part) => acc?.[part], source);
        expect(read(koEditor as Node), item.label).toEqual(expect.any(String));
        expect(read(enEditor as Node), item.label).toEqual(expect.any(String));
      }
    });
  });

  describe('관리자 템플릿', () => {
    const adminRoot = path.resolve(__dirname, '../../../sirsoft-admin_basic');
    const hasAdmin = fs.existsSync(path.join(adminRoot, 'layouts/_admin_base.json'));

    /**
     * @scenario case=admin_untouched
     * @effects admin_template_has_no_guide_logic
     */
    it.skipIf(!hasAdmin)('관리자 템플릿에는 유도 로직과 안내 창 처리가 없다', () => {
      const adminBase = fs.readFileSync(path.join(adminRoot, 'layouts/_admin_base.json'), 'utf-8');
      const adminManifest = fs.readFileSync(path.join(adminRoot, 'template.json'), 'utf-8');
      const adminRoutes = fs.readFileSync(path.join(adminRoot, 'routes.json'), 'utf-8');
      for (const raw of [adminBase, adminManifest, adminRoutes]) {
        expect(raw).not.toContain('required-info');
        expect(raw).not.toContain('requiredInfoGate');
        expect(raw).not.toContain('required_fields_pending');
        expect(raw).not.toContain('required_info_pending_modal');
      }
    });
  });
});
