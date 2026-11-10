import { describe, it, expect } from 'vitest';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import moduleDeactivateIntegrations from '../../layouts/partials/admin_module_list/_modal_deactivate_integrations.json';
import pluginDeactivateIntegrations from '../../layouts/partials/admin_plugin_list/_modal_deactivate_integrations.json';
import templateActivateModal from '../../layouts/partials/admin_template_list/_modal_activate.json';
import languagePackContent from '../../layouts/partials/admin_language_pack_list/_content.json';

/**
 * 확장·언어팩 켜기/끄기와 템플릿 활성화 — 「다른 작업 진행 중」 409 를 의존 경고·칸 충돌 409 와 가른다 (#161 D2·W3)
 *
 * 코어 7.1.5 부터 설치·업데이트·활성화·비활성화·제거가 같은 작업 잠금을 쓴다. 같은 대상에 다른 작업이 진행 중이면 서버가 409 에
 * `errors.operation_in_progress: true` 를 싣는다. 이 화면들의 409 는 원래 「의존 확장이 있음(강제 창)」·「같은 자리에 다른 언어팩
 * (칸 충돌 창)」 계약이라, 그대로 두면 빈 강제 창이 열린다. 진행 중 409 는 창을 열지 않고 서버 문구를 안내 토스트로 보인다.
 */

type Json = Record<string, any>;

/** 노드 트리에서 409 처리 액션 목록을 모두 찾는다(sequence 는 펼친다). */
function find409Handlers(node: unknown, out: Json[][] = []): Json[][] {
  if (Array.isArray(node)) {
    node.forEach((child) => find409Handlers(child, out));
    return out;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    const handler = obj.errorHandling?.['409'];
    if (handler) {
      out.push(flatten(handler));
    }
    Object.values(obj).forEach((value) => find409Handlers(value, out));
  }
  return out;
}

/** sequence·parallel 을 펼쳐 실행될 액션 목록으로 만든다. */
function flatten(action: Json | Json[]): Json[] {
  if (Array.isArray(action)) return action.flatMap(flatten);
  if (action.handler === 'sequence' || action.handler === 'parallel') {
    return (action.actions ?? action.params?.actions ?? []).flatMap(flatten);
  }
  return [action];
}

/** 액션의 `if` 를 주어진 error 로 평가한다(없으면 항상 실행). */
function runs(action: Json, error: Json): boolean {
  if (action.if === undefined) return true;
  const expr = String(action.if).replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return Boolean(new Function('error', '_global', `return (${expr});`)(error, {}));
}

const IN_PROGRESS = {
  status: 409,
  message: '"sirsoft-page"에 다른 작업(설치·업데이트·활성화 등)이 진행 중입니다. 끝난 뒤 다시 시도하세요.',
  errors: { operation_in_progress: true },
};

const cases = [
  { name: '모듈 목록 토글', layout: moduleList, legacy: { status: 409, message: 'dep', errors: { dependent_templates: [{ identifier: 't' }] } }, modal: /module_force_(de)?activate_modal/ },
  { name: '플러그인 목록 토글', layout: pluginList, legacy: { status: 409, message: 'dep', errors: { dependent_modules: [{ identifier: 'm' }] } }, modal: /plugin_force_(de)?activate_modal/ },
  { name: '모듈 연동 비활성 창', layout: moduleDeactivateIntegrations, legacy: { status: 409, message: 'dep', errors: { dependent_templates: [] } }, modal: /module_force_deactivate_modal/ },
  { name: '플러그인 연동 비활성 창', layout: pluginDeactivateIntegrations, legacy: { status: 409, message: 'dep', errors: { dependent_templates: [] } }, modal: /plugin_force_deactivate_modal/ },
  { name: '템플릿 활성화 창', layout: templateActivateModal, legacy: { status: 409, message: 'dep', errors: { missing_modules: [{ identifier: 'm' }] } }, modal: /template_force_activate_modal/ },
  { name: '언어팩 토글', layout: languagePackContent, legacy: { status: 409, message: 'slot', errors: { current: { id: 1 }, target: { id: 2 } } }, modal: /language_pack_slot_conflict_modal/ },
];

const opensModal = (actions: Json[], pattern: RegExp) =>
  actions.some((a) => a.handler === 'openModal' && pattern.test(String(a.target ?? a.params?.target ?? '')));

describe('확장·언어팩 생명주기 409 — 「다른 작업 진행 중」과 의존 경고·칸 충돌을 가른다 (#161)', () => {
  /**
   * @scenario case=modal_409_discriminator
   * @effects lifecycle_409_in_progress_shows_info_toast_without_force_modal
   */
  it.each(cases)('$name: 진행 중 409 는 창을 열지 않고 서버 문구 안내 토스트만 띄운다', ({ layout, modal }) => {
    const handlers = find409Handlers(layout);
    expect(handlers.length).toBeGreaterThan(0);

    for (const actions of handlers) {
      const active = actions.filter((a) => runs(a, IN_PROGRESS));
      expect(opensModal(active, modal), '진행 중 409 에 강제·충돌 창이 열립니다').toBe(false);
      expect(active.some((a) => a.handler === 'openModal'), '진행 중 409 에 다른 창이 열립니다').toBe(false);

      const toast = active.find((a) => a.handler === 'toast');
      expect(toast?.params?.type).toBe('info');
      expect(toast?.params?.message).toBe('{{error.message}}');
    }
  });

  /**
   * @scenario case=modal_409_discriminator
   * @effects lifecycle_409_legacy_contract_preserved
   */
  it.each(cases)('$name: 의존 경고·칸 충돌 409 는 종전대로 창을 연다 (안내 토스트 없음)', ({ layout, legacy, modal }) => {
    for (const actions of find409Handlers(layout)) {
      const active = actions.filter((a) => runs(a, legacy));
      expect(opensModal(active, modal), '종전 409 계약의 창이 열리지 않습니다').toBe(true);
      expect(active.some((a) => a.handler === 'toast' && a.params?.message === '{{error.message}}')).toBe(false);
    }
  });

  /**
   * @scenario case=modal_409_discriminator
   * @effects lifecycle_409_in_progress_shows_info_toast_without_force_modal
   */
  it('템플릿 활성화 창: 진행 중 409 도 진행 표시를 끄고 창을 닫는다', () => {
    const [actions] = find409Handlers(templateActivateModal);
    const active = actions.filter((a) => runs(a, IN_PROGRESS));

    const state = active.find((a) => a.handler === 'setState' && 'isTemplateActivating' in (a.params ?? {}));
    expect(state?.params?.isTemplateActivating).toBe(false);
    expect(active.some((a) => a.handler === 'closeModal')).toBe(true);
  });

  /**
   * @scenario case=modal_409_discriminator
   * @effects lifecycle_409_in_progress_shows_info_toast_without_force_modal
   */
  it.each([
    { name: '모듈 목록 토글', layout: moduleList, ds: 'modules' },
    { name: '플러그인 목록 토글', layout: pluginList, ds: 'plugins' },
    { name: '언어팩 토글', layout: languagePackContent, ds: 'language_packs' },
  ])('$name: 진행 중 409 도 스위치 재동기 신호와 목록 새로 받기를 유지한다', ({ layout, ds }) => {
    const [actions] = find409Handlers(layout);
    const active = actions.filter((a) => runs(a, IN_PROGRESS));

    expect(active.some((a) => a.handler === 'setState' && 'extensionToggleResync' in (a.params ?? {}))).toBe(true);
    expect(active.some((a) => a.handler === 'refetchDataSource' && a.params?.dataSourceId === ds)).toBe(true);
  });

  /**
   * @scenario case=modal_409_discriminator
   * @effects lifecycle_409_in_progress_toast_before_refetch
   */
  it.each([
    { name: '모듈 목록 토글', layout: moduleList },
    { name: '플러그인 목록 토글', layout: pluginList },
  ])('$name: 진행 중 안내 토스트·재동기 신호는 목록 다시 받기보다 먼저다 — 목록 응답을 기다리지 않고 바로 보인다', ({ layout }) => {
    const [actions] = find409Handlers(layout);
    const active = actions.filter((a) => runs(a, IN_PROGRESS));
    const toastAt = active.findIndex((a) => a.handler === 'toast');
    const resyncAt = active.findIndex((a) => a.handler === 'setState' && 'extensionToggleResync' in (a.params ?? {}));
    const refetchAt = active.findIndex((a) => a.handler === 'refetchDataSource');

    expect(toastAt).toBeGreaterThanOrEqual(0);
    expect(toastAt).toBeLessThan(refetchAt);
    expect(resyncAt).toBeLessThan(refetchAt);
  });
});
