/**
 * @file mypage-required-info.test.ts
 * @description 추가 정보 입력 화면(/mypage/required-info)의 구조, 데이터소스, 저장과 그 뒤 갈래, 목적지 검증
 *
 * 필수 항목이 비어 있는 회원이 로그인 뒤 거치는 화면이다. 비어 있는 필수 칸만 받으므로 비밀번호 재확인 없이 바로 입력을 받고,
 * 저장하면 원래 가려던 곳(주소의 redirect)으로 보낸다. redirect 는 사이트 안 경로만 받는다.
 *
 * 식은 실제 앱과 같은 평가기(DataBindingEngine, ComputedEvaluator)로 풀고, 저장 흐름은 실제 ActionDispatcher 로 돌린다.
 *
 * @vitest-environment jsdom
 * @scenario-manifest tests/scenarios/mypage-required-info.yaml
 */

import { describe, it, expect, beforeEach, afterEach, vi, type Mock } from 'vitest';
import { evaluateComputedDefinitions } from '@core/template-engine/helpers/ComputedEvaluator';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';
import { ErrorHandlingResolver } from '@core/error/ErrorHandlingResolver';

import requiredInfoLayout from '../../layouts/mypage/required-info.json';
import routes from '../../routes.json';
import koMember from '../../lang/partial/ko/member.json';
import enMember from '../../lang/partial/en/member.json';

type Node = Record<string, any>;

const layout = requiredInfoLayout as Node;

/** 배열이든 객체 하나든 배열로 맞춘다(컴파일러가 원소 하나짜리 배열을 객체로 접는다) */
function list<T = Node>(value: unknown): T[] {
  if (value === undefined || value === null) return [];
  return (Array.isArray(value) ? value : [value]) as T[];
}

/** 트리를 평탄화한다 */
function collect(input: unknown, out: Node[] = []): Node[] {
  for (const node of list<Node>(input)) {
    if (!node || typeof node !== 'object') continue;
    out.push(node);
    collect(node.children, out);
    if (node.slots) Object.values(node.slots).forEach((slot) => collect(slot, out));
  }
  return out;
}

/** 식({{ }})을 실제 엔진의 평가기로 푼다 */
function engineEval(expression: string, context: Record<string, unknown>): any {
  const body = expression.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  return new DataBindingEngine().evaluateExpression(body, context as any, { skipCache: true });
}

/** 레이아웃의 계산값 전부를 엔진의 계산값 평가기로 푼다. 풀지 못한 식이 있으면 실패다 */
function engineComputed(context: Record<string, unknown>): Record<string, any> {
  const failures: string[] = [];
  const values = evaluateComputedDefinitions(layout.computed, context, new DataBindingEngine(), {
    previous: {},
    onError: 'undefined',
    failureLog: (name: string, error: unknown) => failures.push(`${name}: ${String(error)}`),
  } as any);
  expect(failures).toEqual([]);
  return values;
}

const nodes = collect(layout.slots?.content ?? layout.components);
const byId = (id: string): Node => {
  const found = nodes.find((node) => node.id === id);
  expect(found, `노드 ${id} 가 없다`).toBeDefined();
  return found as Node;
};
const dataSource = list<Node>(layout.data_sources).find((source) => source.id === 'profileFields') as Node;
const form = byId('required_info_form');
const submit = list<Node>(form.actions).find((action) => action.type === 'submit') as Node;
const apiCall = list<Node>(submit.actions).find((action) => action.handler === 'apiCall') as Node;
const onSuccess = list<Node>(apiCall.onSuccess);
const branches = list<Node>(onSuccess.find((action) => action.handler === 'conditions')?.conditions);

const sampleFields = [
  { key: 'nickname', name: 'nickname', type: 'text', required: true },
  { key: 'job', name: 'custom_fields.job', type: 'choice', required: true },
  { key: 'address', name: 'address', type: 'address', required: true, parts: { zipcode: 'zipcode', address: 'address', address_detail: 'address_detail' } },
];

describe('추가 정보 입력 화면', () => {
  describe('화면 구조', () => {
    /**
     * @scenario case=structure
     * @effects route_is_auth_required_and_points_to_layout, screen_has_stable_dom_ids
     */
    it('인증이 필요한 라우트이고, 실측이 겨냥할 노드 id 가 모두 있다', () => {
      const route = (routes as Node).routes.find((item: Node) => item.path === '/mypage/required-info');
      expect(route).toMatchObject({ layout: 'mypage/required-info', auth_required: true });
      expect(layout.layout_name).toBe('mypage/required-info');
      expect(layout.extends).toBe('_user_base');

      for (const id of [
        'required_info_card',
        'required_info_title',
        'required_info_loading',
        'required_info_notice',
        'required_info_orphan_errors',
        'required_info_form',
        'required_info_fields',
        'required_info_save',
        'required_info_logout',
        'required_info_done',
      ]) {
        byId(id);
      }
    });

    /**
     * @scenario case=structure
     * @effects notice_shows_remaining_count, narrow_screen_has_own_layout
     */
    it('안내 문구가 남은 개수를 싣고, 좁은 화면용 배치가 따로 있다', () => {
      const notice = collect(byId('required_info_notice')).find((node) => typeof node.text === 'string' && node.text.includes('member.required_info.notice'))!;
      expect(notice.text).toContain('|count={{(profileFields?.data?.fields ?? []).length}}');
      expect((koMember as Node).required_info.notice).toContain('{{count}}');
      // 영어는 복수 명사를 쓰므로 단수형이 따로 있다
      expect((enMember as Node).required_info.notice_one).toContain('{{count}} item)');
      expect((enMember as Node).required_info.remaining_one).toBeDefined();

      const card = byId('required_info_card');
      expect(card.responsive?.portable?.props?.className ?? '').toContain('p-5');
      expect(card.props.className).toContain('max-w-lg');
      // 저장 단추와 로그아웃은 터치 대상 높이(44px 이상)를 갖는다
      expect(byId('required_info_save').props.className).toContain('py-3');
      expect(byId('required_info_logout').props.className).toContain('min-h-11');
    });

    /**
     * @scenario case=no_reauth
     * @effects form_opens_without_password_reauth
     */
    it('재확인 구획 없이 바로 입력을 받는다', () => {
      const raw = JSON.stringify(layout);
      expect(raw).not.toContain('_password_verify_section');
      expect(raw).not.toContain('isPasswordVerified');
      expect(raw).not.toContain('/api/me/verify-password');
    });
  });

  describe('데이터소스와 공용 조각', () => {
    /**
     * @scenario case=data_source
     * @effects required_fields_fetched_with_context_required_under_shared_id
     */
    it('id 가 profileFields 이고 내 정보 항목 API 를 context=required 로 부른다(blocking 이 아니다)', () => {
      expect(dataSource.endpoint).toBe('/api/me/fields');
      expect(dataSource.params).toEqual({ context: 'required' });
      expect(dataSource.method).toBe('GET');
      expect(dataSource.auto_fetch).toBe(true);
      expect(dataSource.auth_required).toBe(true);
      expect(dataSource.loading_strategy).not.toBe('blocking');
      expect(dataSource.cache).toBe(false);
      // 공용 조각의 파일 갈래가 다시 받는 id 와 같아야 한다
      expect(list<Node>(layout.data_sources).map((source) => source.id)).toEqual(['profileFields']);
    });

    /**
     * @scenario case=data_source
     * @effects fields_drawn_by_shared_partial_with_agreements
     */
    it('받은 항목 전부를 묶음 하나로 공용 조각에 넘기고 약관 묶음 항목도 함께 그린다', () => {
      const loop = collect(byId('required_info_fields')).find((node) => node.iteration?.item_var === 'memberFieldGroup')!;
      expect(loop.iteration.source).toBe('{{$computed.requiredInfoGroups ?? []}}');
      expect(list<Node>(loop.children).some((node) => node.partial === 'partials/member/_member_fields.json')).toBe(true);

      const values = engineComputed({ profileFields: { data: { fields: sampleFields } }, _local: {}, query: {} });
      expect(values.requiredInfoGroups).toEqual([{ key: 'required', includeAgreements: true, fields: sampleFields }]);
      expect(values.memberFieldCtx).toEqual({ formKey: 'requiredInfoForm', errors: {}, disabled: false });
      expect(form.dataKey ?? form.props?.dataKey ?? form.props?.['data-key']).toBe('requiredInfoForm');

      const empty = engineComputed({ profileFields: undefined, _local: {}, query: {} });
      expect(empty.requiredInfoGroups).toEqual([{ key: 'required', includeAgreements: true, fields: [] }]);
    });
  });

  describe('상태', () => {
    /**
     * @scenario case=loading
     * @effects loading_indicator_until_fields_arrive
     */
    it('항목을 받기 전에는 진행 표시만 보이고 폼은 없다', () => {
      const loading = byId('required_info_loading');
      const formWrap = nodes.find((node) => list<Node>(node.children).some((child) => child.id === 'required_info_form'))!;

      expect(engineEval(loading.if, { profileFields: undefined })).toBe(true);
      expect(engineEval(formWrap.if, { profileFields: undefined })).toBe(false);

      expect(engineEval(loading.if, { profileFields: { data: { fields: sampleFields } } })).toBe(false);
      expect(engineEval(formWrap.if, { profileFields: { data: { fields: sampleFields } } })).toBe(true);
    });

    /**
     * @scenario case=saving
     * @effects save_button_disabled_and_labelled_while_saving
     */
    it('저장 중에는 저장 단추와 로그아웃이 잠기고 문구가 바뀐다', () => {
      const save = byId('required_info_save');
      expect(save.props.type).toBe('submit');
      expect(engineEval(save.props.disabled, { _local: { isSavingRequiredInfo: true } })).toBe(true);
      expect(engineEval(save.props.disabled, { _local: {} })).toBe(false);
      expect(engineEval(byId('required_info_logout').props.disabled, { _local: { isSavingRequiredInfo: true } })).toBe(true);

      const labels = collect(save).filter((node) => typeof node.text === 'string');
      const idle = labels.find((node) => node.text === '$t:member.required_info.save')!;
      const busy = labels.find((node) => node.text === '$t:member.required_info.saving')!;
      expect(engineEval(idle.if, { _local: {} })).toBe(true);
      expect(engineEval(busy.if, { _local: {} })).toBe(false);
      expect(engineEval(busy.if, { _local: { isSavingRequiredInfo: true } })).toBe(true);

      const values = engineComputed({ profileFields: { data: { fields: sampleFields } }, _local: { isSavingRequiredInfo: true }, query: {} });
      expect(values.memberFieldCtx.disabled).toBe(true);
    });

    /**
     * @scenario case=field_errors
     * @effects field_errors_go_under_fields_and_only_orphans_in_top_box
     */
    it('칸에 속한 오류는 조각 문맥으로 가고, 어느 칸에도 속하지 않는 오류만 위쪽 상자에 모인다', () => {
      const errors = {
        nickname: ['닉네임을 입력해 주세요'],
        'custom_fields.job': ['직업을 골라 주세요'],
        zipcode: ['우편번호를 입력해 주세요'],
        message: '저장하지 못했습니다',
      };
      const values = engineComputed({ profileFields: { data: { fields: sampleFields } }, _local: { requiredInfoErrors: errors }, query: {} });
      expect(values.memberFieldCtx.errors).toEqual(errors);
      expect(values.requiredInfoOrphanErrors).toEqual(['저장하지 못했습니다']);

      const box = byId('required_info_orphan_errors');
      expect(box.if).toBe('{{($computed.requiredInfoOrphanErrors ?? []).length > 0}}');
      expect(engineComputed({ profileFields: { data: { fields: sampleFields } }, _local: {}, query: {} }).requiredInfoOrphanErrors).toEqual([]);
    });

    /**
     * @scenario case=zero_pending_entry
     * @effects zero_pending_entry_navigates_to_target_immediately
     */
    it('남은 항목 0개로 들어오면 그려지는 순간 목적지로 보낸다(받기 전과 저장 중에는 아니다)', () => {
      const done = byId('required_info_done');
      expect(engineEval(done.if, { profileFields: { data: { fields: [] } }, _local: {} })).toBe(true);
      expect(engineEval(done.if, { profileFields: undefined, _local: {} })).toBe(false);
      expect(engineEval(done.if, { profileFields: { data: { fields: sampleFields } }, _local: {} })).toBe(false);
      expect(engineEval(done.if, { profileFields: { data: { fields: [] } }, _local: { isSavingRequiredInfo: true } })).toBe(false);

      const trigger = collect(done).find((node) => node.lifecycle?.onMount)!;
      const mounted = list<Node>(trigger.lifecycle.onMount);
      expect(mounted).toHaveLength(1);
      const mount = mounted[0];
      expect(mount.handler).toBe('navigate');
      expect(mount.params.path).toBe("{{$computed.requiredInfoTarget ?? '/'}}");
      expect(mount.params.fallbackPath).toBe('/');
    });
  });

  describe('목적지(redirect) 검증', () => {
    const target = (query: Record<string, unknown>) => engineComputed({ profileFields: undefined, _local: {}, query }).requiredInfoTarget;

    /**
     * @scenario case=redirect_validation
     * @effects in_site_redirect_is_used
     */
    it('사이트 안 경로는 그대로 쓴다(쿼리 포함)', () => {
      expect(target({ redirect: '/board/free' })).toBe('/board/free');
      expect(target({ redirect: '/shop/checkout?step=2&from=cart' })).toBe('/shop/checkout?step=2&from=cart');
      expect(target({ redirect: '/mypage/profile/edit?verification_token=abc' })).toBe('/mypage/profile/edit?verification_token=abc');
    });

    /**
     * @scenario case=redirect_validation
     * @effects off_site_or_self_redirect_falls_back_to_home
     */
    it('다른 사이트, 실행 스킴, 이 화면 자신, 글자가 아닌 값은 홈으로 바꾼다', () => {
      expect(target({})).toBe('/');
      expect(target({ redirect: '' })).toBe('/');
      expect(target({ redirect: 'https://evil.example/steal' })).toBe('/');
      expect(target({ redirect: '//evil.example/steal' })).toBe('/');
      expect(target({ redirect: '/\\evil.example/steal' })).toBe('/');
      expect(target({ redirect: 'javascript:alert(1)' })).toBe('/');
      expect(target({ redirect: 'board/free' })).toBe('/');
      expect(target({ redirect: ['/a', '/b'] })).toBe('/');
      expect(target({ redirect: '/mypage/required-info' })).toBe('/');
      expect(target({ redirect: '/mypage/required-info?redirect=%2Fboard' })).toBe('/');
    });
  });

  describe('저장', () => {
    let dispatcher: ActionDispatcher;
    let navigate: Mock;
    let refetch: Mock;
    let localWrites: Record<string, any>[];
    let globalState: Record<string, any>;

    beforeEach(() => {
      ErrorHandlingResolver.resetInstance();
      navigate = vi.fn();
      refetch = vi.fn(async () => undefined);
      localWrites = [];
      globalState = { toasts: [] };
      dispatcher = new ActionDispatcher({ navigate });
      dispatcher.setGlobalStateUpdater((updates: any) => {
        const next = typeof updates === 'function' ? updates(globalState) : updates;
        globalState = { ...globalState, ...next };
      });
      (window as any).G7Core = {
        state: { get: () => globalState, getLocal: () => ({}), setLocal: vi.fn() },
        dataSource: { refetch },
      };
      (window as any).__templateApp = { getRouter: () => ({ match: () => ({ route: {} }) }) };
    });

    afterEach(() => {
      vi.restoreAllMocks();
      delete (window as any).__templateApp;
      delete (window as any).G7Core;
    });

    /** 저장 시퀀스를 실제 디스패처로 돌린다 */
    async function runSubmit(response: Response, query: Record<string, unknown> = {}): Promise<Mock> {
      const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(response) as unknown as Mock;
      const local = { requiredInfoForm: { nickname: '길동이', custom_fields: { job: 'worker' } } };
      const computed = engineComputed({ profileFields: { data: { fields: sampleFields } }, _local: local, query });
      await dispatcher.dispatchAction({ ...submit, type: 'click' } as any, {
        data: { _local: local, query, $computed: computed, _computed: computed },
        // 실제 화면에서는 폼이 든 컴포넌트의 로컬 상태가 문맥에 실린다 — 시퀀스가 단계마다 이 값을 기준으로 _local 을 다시 세운다
        state: local,
        setState: ((updates: any) => {
          localWrites.push(updates);
        }) as any,
      });
      return fetchSpy;
    }

    const lastLocal = (key: string) => [...localWrites].reverse().find((write) => key in write)?.[key];
    const toastTypes = () => (globalState.toasts ?? []).map((toast: Node) => toast.type);

    /**
     * @scenario case=save_request
     * @effects save_sends_form_paths_with_put_to_required_fields
     */
    it('폼 값을 항목의 폼 경로 그대로 PUT /api/me/required-fields 로 보낸다', async () => {
      expect(apiCall.target).toBe('/api/me/required-fields');
      expect(apiCall.params.method).toBe('PUT');
      expect(apiCall.auth_required).toBe(true);
      expect(engineEval(apiCall.params.body, { _local: { requiredInfoForm: { mobile: '010', custom_fields: { job: 'a' } } } })).toEqual({ mobile: '010', custom_fields: { job: 'a' } });
      expect(engineEval(apiCall.params.body, { _local: {} })).toEqual({});

      const fetchSpy = await runSubmit(new Response(JSON.stringify({ success: true, data: { user: {}, pending_required_fields: [] } }), { status: 200 }));
      // 쓰기 요청 앞에 CSRF 쿠키 요청이 먼저 나갈 수 있으므로 주소로 고른다
      const saveCalls = fetchSpy.mock.calls.filter((call) => String(call[0]).includes('/api/me/required-fields'));
      expect(saveCalls).toHaveLength(1);
      const init = saveCalls[0][1];
      expect(init.method).toBe('PUT');
      expect(JSON.parse(init.body)).toEqual({ nickname: '길동이', custom_fields: { job: 'worker' } });
    });

    /**
     * @scenario case=save_success_done
     * @effects save_success_refetches_current_user_then_navigates_to_target
     */
    it('남은 항목이 없으면 로그인 사용자를 다시 받고 목적지로 보낸다', async () => {
      await runSubmit(
        new Response(JSON.stringify({ success: true, data: { user: {}, pending_required_fields: [] } }), { status: 200 }),
        { redirect: '/board/free?page=2' },
      );

      expect(refetch.mock.calls.map((call) => call[0])).toEqual(['current_user']);
      expect(navigate).toHaveBeenCalledTimes(1);
      expect(navigate.mock.calls[0][0]).toBe('/board/free?page=2');
      expect(toastTypes()).toEqual(['success']);
      // 성공 처리의 첫 동작이 로그인 사용자 다시 받기다
      expect(onSuccess[0]).toMatchObject({ handler: 'refetchDataSource', params: { dataSourceId: 'current_user' } });
    });

    /**
     * @scenario case=save_success_done
     * @effects save_success_without_redirect_goes_home
     */
    it('redirect 가 없거나 사이트 밖이면 홈으로 보낸다', async () => {
      await runSubmit(
        new Response(JSON.stringify({ success: true, data: { user: {}, pending_required_fields: [] } }), { status: 200 }),
        { redirect: 'https://evil.example/' },
      );
      expect(navigate.mock.calls.map((call) => call[0])).toEqual(['/']);
    });

    /**
     * @scenario case=save_success_remaining
     * @effects save_with_remaining_keys_stays_and_refetches_fields
     */
    it('남은 키가 있으면 화면에 남아 항목을 다시 받는다', async () => {
      await runSubmit(
        new Response(JSON.stringify({ success: true, data: { user: {}, pending_required_fields: ['t490_file'] } }), { status: 200 }),
        { redirect: '/board/free' },
      );

      expect(navigate).not.toHaveBeenCalled();
      expect(refetch.mock.calls.map((call) => call[0])).toEqual(['current_user', 'profileFields']);
      expect(lastLocal('isSavingRequiredInfo')).toBe(false);
      expect(toastTypes()).toEqual(['info']);

      expect(branches).toHaveLength(2);
      expect(engineEval(branches[0].if, { response: { data: { pending_required_fields: [] } } })).toBe(true);
      expect(engineEval(branches[0].if, { response: { data: { pending_required_fields: ['job'] } } })).toBe(false);
      expect(branches[1].if).toBeUndefined();
    });

    /**
     * @scenario case=save_failed
     * @effects save_failure_puts_errors_in_state_and_stays
     */
    it('검증 실패(422)면 오류를 상태에 싣고 이동하지 않는다', async () => {
      await runSubmit(
        new Response(JSON.stringify({ success: false, message: '입력값을 확인해 주세요.', errors: { 'custom_fields.job': ['직업을 골라 주세요'] } }), { status: 422 }),
        { redirect: '/board/free' },
      );

      expect(navigate).not.toHaveBeenCalled();
      expect(refetch).not.toHaveBeenCalled();
      expect(lastLocal('isSavingRequiredInfo')).toBe(false);
      expect(lastLocal('requiredInfoErrors')).toEqual({ 'custom_fields.job': ['직업을 골라 주세요'] });
      expect(toastTypes()).toEqual(['error']);
    });
  });

  describe('로그아웃', () => {
    /**
     * @scenario case=logout
     * @effects logout_button_outside_form_calls_logout
     */
    it('로그아웃 단추는 폼 밖에 있고 logout 액션을 부른다', () => {
      const logout = byId('required_info_logout');
      expect(logout.props.type).toBe('button');
      expect(list<Node>(logout.actions).map((action) => action.handler)).toEqual(['logout']);
      expect(collect(form).some((node) => node.id === 'required_info_logout')).toBe(false);
      expect(typeof logout.text === 'string' ? logout.text : JSON.stringify(logout)).toContain('$t:member.required_info.logout');
    });
  });

  describe('다국어', () => {
    /**
     * @scenario case=structure
     * @effects all_screen_texts_have_ko_and_en
     */
    it('화면이 쓰는 문구 키가 ko, en 에 모두 있다', () => {
      const used = new Set<string>();
      const scan = (value: unknown) => {
        if (typeof value === 'string') {
          for (const match of value.matchAll(/\$t:member\.required_info\.([a-z_.]+)/g)) used.add(match[1]);
        } else if (value && typeof value === 'object') {
          Object.values(value).forEach(scan);
        }
      };
      scan(layout);
      expect(used.size).toBeGreaterThanOrEqual(7);
      const read = (source: Node, path: string) => path.split('.').reduce((acc: any, key) => acc?.[key], source.required_info);
      for (const key of used) {
        expect(read(koMember as Node, key), `ko member.required_info.${key}`).toEqual(expect.any(String));
        expect(read(enMember as Node, key), `en member.required_info.${key}`).toEqual(expect.any(String));
      }
    });
  });
});
