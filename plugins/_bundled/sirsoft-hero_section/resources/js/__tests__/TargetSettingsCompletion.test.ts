/** @scenario-manifest tests/scenarios/target-settings.yaml */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { finishTargetSettings, saveTargetSettings } from '../handlers';

const response = { message: 'saved', data: { position: 'board_hero', target: { key: '4' }, overrides: { effect: 'fade' } } };
const submitted = { effect: 'fade' };
let local: any;
let core: any;
const complete = (extra = {}) => finishTargetSettings({ params: { position: 'board_hero', target: '4', requestId: 'request-a', submitted, response, ...extra } });

describe('대상 설정 완료 응답 격리', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/admin/plugins/sirsoft-hero_section/banners?position=board_hero&target=4');
    local = { targetSaving: { key: 'board_hero|4', id: 'request-a' }, targetForm: { key: 'board_hero|4', overrides: submitted }, keep: '입력' };
    core = { state: { getLocal: () => local, setLocal: vi.fn((updates) => { local = { ...local, ...updates }; }) }, dataSource: { set: vi.fn() }, getActionDispatcher: () => ({ dispatchAction: dispatch }) };
    delete (globalThis as any).G7Config;
    dispatch.mockClear(); (window as any).G7Core = core;
  });
  const dispatch = vi.fn(async () => undefined);
  // @scenario surface=target_settings_late_response_isolated
  // @effects target_draft_preserved_after_late_response
  it('같은 대상 성공은 응답을 먼저 반영하고 제출했던 작업본만 비운다', async () => {
    expect(await complete()).toBe(true);
    expect(core.dataSource.set).toHaveBeenCalledWith('target_settings', response);
    expect(local).toMatchObject({ targetSaving: null, targetForm: null, keep: '입력' });
    expect(core.dataSource.set.mock.invocationCallOrder[0]).toBeLessThan(core.state.setLocal.mock.invocationCallOrder[0]);
    expect(dispatch).toHaveBeenCalledOnce();
  });
  // @scenario surface=target_settings_late_response_isolated
  // @effects target_draft_preserved_after_late_response
  it.each(['PUT', 'DELETE'])('%s A 응답은 B 작업본·데이터와 B 저장 잠금을 건드리지 않는다', async () => {
    window.history.replaceState({}, '', '?position=board_hero&target=2');
    local = { targetSaving: { key: 'board_hero|2', id: 'request-b' }, targetForm: { key: 'board_hero|2', overrides: { pagination: 'pill' } } };
    const before = structuredClone(local);
    expect(await complete()).toBe(false);
    expect(local).toEqual(before); expect(core.dataSource.set).not.toHaveBeenCalled(); expect(dispatch).not.toHaveBeenCalled();
  });
  // @scenario surface=target_settings_late_response_isolated
  // @effects target_draft_preserved_after_late_response
  it('저장 중 다른 대상에서 새 입력을 시작하면 이전 요청의 잠금만 풀고 입력은 보존한다', async () => {
    window.history.replaceState({}, '', '?position=board_hero&target=2');
    local.targetForm = { key: 'board_hero|2', overrides: { pagination: 'pill' } };
    await complete(); expect(local.targetSaving).toBeNull(); expect(local.targetForm.overrides.pagination).toBe('pill');
  });
  // @scenario surface=target_settings_late_response_isolated
  // @effects target_draft_preserved_after_late_response
  it('같은 대상에서 요청 뒤 추가로 고친 작업본은 서버 응답으로 지우지 않는다', async () => {
    local.targetForm.overrides = { effect: 'cube' };
    await complete(); expect(local.targetForm.overrides).toEqual({ effect: 'cube' }); expect(local.targetSaving).toBeNull();
  });
  // @scenario surface=target_settings_late_response_isolated
  // @effects target_draft_preserved_after_late_response
  it('현재 대상의 실패는 입력을 보존하고 잠금을 풀어 재시도를 허용한다', async () => {
    await complete({ response: undefined, error: { message: 'failed' } });
    expect(local.targetSaving).toBeNull(); expect(local.targetForm.overrides).toEqual(submitted); expect(core.dataSource.set).not.toHaveBeenCalled();
    expect(dispatch).toHaveBeenCalledWith(expect.objectContaining({ params: { type: 'error', message: 'failed' } }));
  });
  // @scenario surface=target_settings_late_response_isolated
  // @effects target_draft_preserved_after_late_response
  it('편집 화면으로 이동한 뒤의 응답은 목록 데이터나 토스트를 쓰지 않는다', async () => {
    window.history.replaceState({}, '', '/admin/plugins/sirsoft-hero_section/banners/1442/edit?position=board_hero&target=4');
    await complete(); expect(core.dataSource.set).not.toHaveBeenCalled(); expect(dispatch).not.toHaveBeenCalled();
  });
  // @scenario surface=target_settings_late_response_isolated
  it('하위 폴더 마운트에서도 현재 대상 저장 완료를 식별한다', async () => {
    (globalThis as any).G7Config = { basePath: '/g7' };
    window.history.replaceState({}, '', '/g7/admin/plugins/sirsoft-hero_section/banners?position=board_hero&target=4');
    expect(await complete()).toBe(true); expect(core.dataSource.set).toHaveBeenCalledWith('target_settings', response);
  });

  // @scenario surface=target_settings_late_response_isolated
  it('같은 대상에 재진입해 새 요청을 시작했으면 이전 요청 응답도 버린다', async () => {
    local.targetSaving = { key: 'board_hero|4', id: 'new-request' };
    const before = structuredClone(local);
    expect(await complete()).toBe(false); expect(local).toEqual(before); expect(core.dataSource.set).not.toHaveBeenCalled();
  });

  // @scenario surface=target_settings_late_response_isolated
  it('언어 전환으로 바뀐 디스패처에도 완료 처리기를 다시 등록한다', async () => {
    vi.resetModules();
    const first = { registerHandler: vi.fn() }, second = { registerHandler: vi.fn() };
    let dispatcher = first;
    (window as any).G7Core = { getActionDispatcher: () => dispatcher, getComponentRegistry: () => ({ registerExtensionComponent: vi.fn() }) };
    await import('../index');
    expect(first.registerHandler).toHaveBeenCalledWith('sirsoft-hero_section.saveTargetSettings', expect.any(Function), expect.any(Object));
    dispatcher = second; (window as any).__SirsoftHeroSection.initPlugin();
    expect(second.registerHandler).toHaveBeenCalledWith('sirsoft-hero_section.saveTargetSettings', expect.any(Function), expect.any(Object));
  });

  // @scenario surface=target_settings_late_response_isolated
  it('실제 요청 처리기는 await 전에 대상·제출값을 복사하고 B 입력을 보존하며 잠금을 푼다', async () => {
    local.targetSaving = null;
    let resolve!: (value: any) => void;
    core.api = { put: vi.fn(() => new Promise(r => { resolve = r; })) };
    const params = { position: 'board_hero', target: '4', method: 'PUT' as const, overrides: { effect: 'fade' } };
    const pending = saveTargetSettings({ params });
    window.history.replaceState({}, '', '?position=board_hero&target=2');
    params.target = '2'; params.overrides.effect = 'cube';
    local.targetForm = { key: 'board_hero|2', overrides: { pagination: 'pill' } };
    resolve(response); await pending;
    expect(local.targetSaving).toBeNull(); expect(local.targetForm.overrides).toEqual({ pagination: 'pill' });
    expect(core.api.put).toHaveBeenCalledWith('/api/plugins/sirsoft-hero_section/admin/position-settings/board_hero/targets/4', { overrides: { effect: 'fade' } });
    expect(core.dataSource.set).not.toHaveBeenCalled(); expect(dispatch).not.toHaveBeenCalled();
  });

  // @scenario surface=target_settings_failure_retry
  // @effects target_settings_retry_restores_controls
  it('실제 저장 요청 실패도 서버 메시지를 알리고 입력과 재시도 가능 상태를 남긴다', async () => {
    local.targetSaving = null;
    core.api = { put: vi.fn().mockRejectedValue({ response: { data: { message: '서버 저장 오류' } } }) };
    await saveTargetSettings({ params: { position: 'board_hero', target: '4', method: 'PUT', overrides: submitted } });
    expect(local.targetSaving).toBeNull(); expect(local.targetForm.overrides).toEqual(submitted);
    expect(dispatch).toHaveBeenCalledWith(expect.objectContaining({ params: { type: 'error', message: '서버 저장 오류' } }));
  });
  // @scenario surface=target_settings_late_response_isolated
  it('HTTP 로 연 관리자(crypto.randomUUID 없음)에서도 저장 요청을 보내고 잠금을 푼다', async () => {
    local.targetSaving = null;
    const original = Object.getOwnPropertyDescriptor(globalThis.crypto, 'randomUUID');
    Object.defineProperty(globalThis.crypto, 'randomUUID', { value: undefined, configurable: true });
    try {
      core.api = { put: vi.fn(async () => response) };
      expect(await saveTargetSettings({ params: { position: 'board_hero', target: '4', method: 'PUT', overrides: submitted } })).toBe(true);
      expect(core.api.put).toHaveBeenCalledOnce(); expect(local.targetSaving).toBeNull();
    } finally {
      if (original) Object.defineProperty(globalThis.crypto, 'randomUUID', original); else delete (globalThis.crypto as any).randomUUID;
    }
  });
  // @scenario surface=target_settings_late_response_isolated
  it('진행 중 저장 단추를 다시 눌러도 요청을 중복 전송하지 않는다', async () => {
    core.api = { put: vi.fn() };
    expect(await saveTargetSettings({ params: { position: 'board_hero', target: '4', method: 'PUT', overrides: submitted } })).toBe(false);
    expect(core.api.put).not.toHaveBeenCalled();
  });

});
