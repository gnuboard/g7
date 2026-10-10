// @vitest-environment jsdom
/**
 * @file pluginSettingsChannelSave.test.tsx
 * @description 마케팅 설정 화면 — 채널 저장 실패 처리와 채널 추가 창의 입력 검증
 *
 * 채널 저장이 422 로 실패하면 종전에는 이유와 무관하게 「삭제 불가 → 비활성 전환」 창을 열었고,
 * 그 창은 번역된 오류 문장을 채널 키로 대조해 어떤 채널도 되살리지 못했다(변경 표시가 남아
 * 저장 → 창 → 저장이 끝없이 반복됐다). 형식 오류의 실제 문구는 어디에도 보이지 않았다.
 *
 * 배포 JSON 의 액션을 그대로 엔진에 태워 확인한다 — 사본 표현식이 아니라 실제 레이아웃이 SSoT 다.
 */

import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { actionsOf, findById, flattenNodes } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import pluginSettings from '../../../layouts/admin/plugin_settings.json';


const SAVED_CHANNELS = [
  { key: 'email_subscription', label: { ko: '광고성 이메일 수신', en: 'Email' }, page_slug: '', enabled: true, is_system: true },
  { key: 'sms_subscription', label: { ko: '광고성 SMS', en: 'SMS' }, page_slug: '', enabled: true, is_system: false },
  { key: 'push_subscription', label: { ko: '앱 푸시', en: 'Push' }, page_slug: '', enabled: true, is_system: false },
];

const saveAction = actionsOf(findById(pluginSettings, 'save_button'))[0];
const deactivateModal = findById(pluginSettings, 'channel_deactivate_modal');
const convertAction = actionsOf(
  flattenNodes(deactivateModal).find((n) => n.name === 'Button' && JSON.stringify(actionsOf(n)).includes('channelDeactivation')),
)[0];
const editModal = findById(pluginSettings, 'channel_edit_modal') as any;

let utils: ReturnType<typeof createLayoutTest> | null = null;

/**
 * 테스트용 기본 컴포넌트를 등록한다.
 */
function registerBasics(): void {
  const registry: any = ComponentRegistry.getInstance();
  registry.registry = {};
  const tags: Record<string, string> = { Div: 'div', P: 'p', Span: 'span', Label: 'label', Button: 'button', Input: 'input', Ul: 'ul', Li: 'li' };
  for (const [name, tag] of Object.entries(tags)) {
    registry.registry[name] = {
      component: ({ text, children, ...props }: any) =>
        React.createElement(tag, props, tag === 'input' ? undefined : children || text),
      metadata: { name, type: 'basic' },
    };
  }
  registry.registry.Toggle = { component: () => null, metadata: { name: 'Toggle', type: 'composite' } };
  registry.registry.MultilingualInput = { component: () => null, metadata: { name: 'MultilingualInput', type: 'composite' } };
  registry.registry.Fragment = { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } };
}

/**
 * 채널 저장 엔드포인트만 주어진 422 본문으로 응답하도록 fetch 를 바꿔 끼운다.
 *
 * @param body 422 응답 본문
 */
function stubChannelSave422(body: Record<string, unknown>): void {
  globalThis.fetch = vi.fn(async (url: string) => {
    if (String(url).includes('/admin/channels')) {
      return new Response(JSON.stringify(body), { status: 422, headers: { 'Content-Type': 'application/json' } });
    }
    return new Response(JSON.stringify({ success: true, data: {} }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  }) as any;
}

/**
 * 저장본(_local.form.channels)과 편집 중 목록(_global.channels)을 갖춘 화면을 띄운다.
 *
 * @param currentChannels 편집 중인 채널 목록
 */
async function mountWith(currentChannels: any[]) {
  utils = createLayoutTest(
    { version: '1.0.0', layout_name: 'marketing_channel_save', components: [{ id: 'root', type: 'basic', name: 'Div' }] } as any,
    {
      initialState: {
        _local: { form: { channels: SAVED_CHANNELS }, hasChanges: true },
        _global: { channels: currentChannels },
      },
      routeParams: { identifier: 'sirsoft-marketing' },
    },
  );
  await utils.render();
  return utils;
}

beforeEach(() => {
  registerBasics();
  (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
});

afterEach(() => {
  utils?.cleanup();
  utils = null;
  (ComponentRegistry.getInstance() as any).registry = {};
  delete (window as any).__templateApp;
});

describe('채널 저장 실패 — 동의가 쌓인 채널 삭제', () => {
  /**
   * @scenario case=channel_has_consents
   * @effects deactivate_modal_only_for_blocked_channels, blocked_channel_restored_disabled
   */
  it('서버가 돌려준 키의 채널만 꺼진 채로 원래 자리에 되살린다', async () => {
    // SMS 를 지우고 저장 → 서버가 동의 회원이 있다며 거절
    const t = await mountWith([SAVED_CHANNELS[0], SAVED_CHANNELS[2]]);
    stubChannelSave422({
      success: false,
      message: '채널(sms_subscription)에 동의한 회원이 3명 있어 삭제할 수 없습니다.',
      errors: {
        channels: ['채널(sms_subscription)에 동의한 회원이 3명 있어 삭제할 수 없습니다.'],
        blocked_channel_keys: ['sms_subscription'],
      },
    });

    await t.triggerAction(saveAction);

    expect(t.getState()._global.modalStack).toEqual(['channel_deactivate_modal']);
    expect(t.getState()._global.channelDeactivation.keys).toEqual(['sms_subscription']);
    // 오류 목록에는 사람이 읽는 문장만 — 기계용 키 목록은 빠진다
    expect(t.getState()._local.errors).toEqual({
      channels: ['채널(sms_subscription)에 동의한 회원이 3명 있어 삭제할 수 없습니다.'],
    });
    expect(t.getState()._local.isSaving).toBe(false);

    // 창의 「비활성화로 전환」 — 창은 별도 컨텍스트라 부모 _local 을 읽지 못한다. 저장본을 비워 그 조건을 재현한다
    t.setState('form', undefined);
    await t.triggerAction({ ...convertAction });
    const channels = t.getState()._global.channels;
    expect(channels.map((ch: any) => ch.key)).toEqual(['email_subscription', 'sms_subscription', 'push_subscription']);
    expect(channels.find((ch: any) => ch.key === 'sms_subscription').enabled).toBe(false);
    expect(channels.find((ch: any) => ch.key === 'push_subscription').enabled).toBe(true);
    expect(t.getState()._global.channelDeactivation).toBeNull();
  });
});

describe('채널 저장 실패 — 형식·필수값 오류', () => {
  /**
   * @scenario case=field_validation
   * @effects field_errors_shown_without_deactivate_modal
   */
  it('비활성 전환 창을 열지 않고 실제 검증 문구를 오류 목록에 싣는다', async () => {
    const newChannel = { key: 'Bad-Key', label: { ko: '', en: '' }, page_slug: '', enabled: true, is_system: false };
    const t = await mountWith([...SAVED_CHANNELS, newChannel]);
    stubChannelSave422({
      success: false,
      message: 'channels.3.key 형식이 올바르지 않습니다.',
      errors: {
        'channels.3.key': ['channels.3.key 형식이 올바르지 않습니다.'],
        'channels.3.label': ['ko 번역은 필수입니다.'],
      },
    });

    await t.triggerAction(saveAction);

    expect(t.getState()._global.modalStack ?? []).toEqual([]);
    expect(t.getState()._global.channelDeactivation).toBeUndefined();
    expect(Object.keys(t.getState()._local.errors)).toEqual(['channels.3.key', 'channels.3.label']);
    expect((t.getState()._global.toasts ?? []).map((toast: any) => toast.type)).toEqual(['error']);
  });
});

describe('채널 추가 창 — 확정 전 입력 검증', () => {
  /**
   * 창의 버튼 줄을 주어진 편집 대상으로 렌더하고 확정 버튼을 돌려준다.
   *
   * @param editingChannel 편집 중인 채널
   * @param channels 현재 채널 목록
   */
  async function confirmButtonFor(editingChannel: Record<string, unknown>, channels: any[] = SAVED_CHANNELS) {
    utils = createLayoutTest(
      { version: '1.0.0', layout_name: 'marketing_channel_edit', components: editModal.children } as any,
      { initialState: { _global: { editingChannel, channels } }, initialData: { $locale: 'ko' }, locale: 'ko' },
    );
    const { container } = await utils.render();
    const confirm = [...container.querySelectorAll('button')].pop() as HTMLButtonElement;
    return { confirm, container };
  }

  /**
   * @scenario case=add_channel
   * @effects add_modal_blocks_invalid_input
   */
  it.each([
    ['형식이 틀린 키', { key: 'Bad-Key', label: { ko: '문자', en: '' } }],
    ['빈 키', { key: '', label: { ko: '문자', en: '' } }],
    ['이미 있는 키', { key: 'sms_subscription', label: { ko: '문자', en: '' } }],
  ])('%s 이면 확정 버튼이 잠긴다', async (_case, input) => {
    const { confirm } = await confirmButtonFor({ ...input, page_slug: '', enabled: true, is_system: false, _editing: false });
    expect(confirm.disabled).toBe(true);
  });

  /**
   * @scenario case=add_channel
   * @effects add_modal_blocks_invalid_input
   */
  it('형식·이름을 갖추면 확정 버튼이 열린다', async () => {
    const { confirm, container } = await confirmButtonFor({
      key: 'kakao_subscription', label: { ko: '카카오 알림', en: '' }, page_slug: '', enabled: true, is_system: false, _editing: false,
    });
    expect(confirm.disabled).toBe(false);
    expect(container.querySelectorAll('p.text-error-soft')).toHaveLength(0);
  });

  /**
   * @scenario case=add_channel
   * @effects add_modal_blocks_invalid_input
   */
  it('형식이 틀린 키에는 이유를 창 안에 보여 준다', async () => {
    const { container } = await confirmButtonFor({
      key: 'Bad-Key', label: { ko: '문자', en: '' }, page_slug: '', enabled: true, is_system: false, _editing: false,
    });
    const hints = [...container.querySelectorAll('p.text-error-soft')].map((p) => p.textContent);
    expect(hints.join(' ')).toContain('key_invalid');
  });
});

describe('채널 추가 창 — 항목명 안내는 입력칸을 떠나거나 확정을 누른 뒤에만', () => {
  /**
   * 편집 창을 주어진 편집 대상으로 렌더한다.
   *
   * @param editingChannel 편집 중인 채널
   */
  async function mountModal(editingChannel: Record<string, unknown>) {
    utils = createLayoutTest(
      { version: '1.0.0', layout_name: 'marketing_channel_edit', components: editModal.children } as any,
      { initialState: { _global: { editingChannel, channels: SAVED_CHANNELS } }, initialData: { $locale: 'ko' }, locale: 'ko' },
    );
    const { container } = await utils.render();
    return container;
  }

  /** 창 안의 빨간 안내 문구 */
  const hintsOf = (container: HTMLElement) => [...container.querySelectorAll('p.text-error-soft')].map((p) => p.textContent ?? '');

  const BLANK = { key: '', label: { ko: '', en: '' }, page_slug: '', enabled: true, is_system: false, _editing: false };

  /**
   * @scenario case=add_channel
   * @effects add_modal_label_hint_after_touch
   */
  it('창을 막 열었을 때(입력 전)는 항목명 안내가 없다', async () => {
    const container = await mountModal({ ...BLANK });
    expect(hintsOf(container).filter((t) => t.includes('label_required'))).toHaveLength(0);
  });

  /**
   * @scenario case=add_channel
   * @effects add_modal_label_hint_after_touch
   */
  it('항목명 입력칸을 떠나면(blur) 그때 안내를 세운다', async () => {
    await mountModal({ ...BLANK, key: 'kakao_subscription' });
    const field = findById(editModal, 'channel_label_field') as any;
    const blur = (field?.actions ?? []).find((a: any) => a.type === 'blur' || a.event === 'blur');
    expect(blur).toBeTruthy();
    await utils!.triggerAction(blur);
    expect(utils!.getState()._global.editingChannel._labelTouched).toBe(true);
  });

  /**
   * @scenario case=add_channel
   * @effects add_modal_label_hint_after_touch
   */
  it('안내 표시 조건은 항목명을 건드린 뒤 비어 있을 때다', async () => {
    const touched = await mountModal({ ...BLANK, key: 'kakao_subscription', _labelTouched: true });
    expect(hintsOf(touched).some((t) => t.includes('label_required'))).toBe(true);
  });

  /**
   * @scenario case=add_channel
   * @effects add_modal_label_hint_after_touch, add_modal_blocks_invalid_input
   */
  it('항목명이 비면 확정 버튼은 눌리되, 누르면 목록에 붙이지 않고 안내를 세운다', async () => {
    const container = await mountModal({ ...BLANK, key: 'kakao_subscription', label: { ko: '  ', en: 'Kakao' } });
    const confirm = [...container.querySelectorAll('button')].pop() as HTMLButtonElement;
    expect(confirm.disabled).toBe(false);

    const confirmNode = findById(editModal, 'channel_edit_confirm') as any;
    await utils!.triggerAction(actionsOf(confirmNode)[0]);

    const state = utils!.getState()._global;
    expect(state.editingChannel._labelTouched).toBe(true);
    expect(state.channels.map((ch: any) => ch.key)).toEqual(SAVED_CHANNELS.map((ch) => ch.key));
    expect(state.modalStack ?? []).toEqual([]);
  });
});
