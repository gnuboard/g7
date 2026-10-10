/**
 * @file admin-extension-install-language-pack-awaiting-host.test.tsx
 * @description 꺼진 확장의 언어팩은 꺼진 채 설치되고 확장을 켤 때 함께 켜진다는 것을 화면이 알린다 (Q-G6, 결정 2026-10-08)
 *
 * 설치 직후 확장은 비활성이라 설치 창에서 함께 고른 그 확장 자신의 언어팩이 「대상 비활성」으로 늘 실패했다(Q-G2 D-Q2-2).
 * 이제 서버는 그 팩을 꺼진 채 설치하고 응답 `language_packs_awaiting_host` 로 돌려준다 — 설치 창은 이것을 안내 토스트로
 * 알린다(실패 경고가 아니다). 언어팩 화면의 번들 설치 창도 대상이 꺼져 있으면 설치를 막지 않고 안내만 한다.
 */

import { describe, it, expect } from 'vitest';
import moduleInstall from '../../layouts/partials/admin_module_list/_modal_install.json';
import pluginInstall from '../../layouts/partials/admin_plugin_list/_modal_install.json';
import templateInstall from '../../layouts/partials/admin_template_list/_modal_install.json';
import bundledInstall from '../../layouts/partials/admin_language_pack_list/_modal_install_bundled.json';

type Json = Record<string, any>;

/** 설치 apiCall 의 onSuccess 액션 목록을 찾는다. */
function installSuccess(tree: unknown): Json[] {
  let found: Json[] | undefined;
  const walk = (n: unknown): void => {
    if (found) return;
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (obj.handler === 'apiCall' && typeof obj.target === 'string' && /\/install$/.test(obj.target) && Array.isArray(obj.onSuccess)) {
        found = obj.onSuccess;
        return;
      }
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  expect(found, '설치 apiCall 의 onSuccess 가 없습니다.').toBeDefined();
  return found!;
}

/** 바인딩 식을 평가한다. */
function evalBinding(expr: string, scope: Json): unknown {
  const m = /^\{\{([\s\S]*)\}\}$/.exec(expr.trim());
  const names = Object.keys(scope);
  // eslint-disable-next-line no-new-func
  return new Function(...names, `return (${m ? m[1] : JSON.stringify(expr)});`)(...names.map((k) => scope[k]));
}

/** 트리에서 조건에 맞는 노드를 모은다. */
function collect(tree: unknown, pred: (n: Json) => boolean): Json[] {
  const out: Json[] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (pred(obj)) out.push(obj);
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  return out;
}

describe('설치 창 — 꺼진 채 설치된 언어팩 안내', () => {
  /**
   * @scenario case=install_modal_awaiting_toast_shown
   * @effects install_modal_reports_awaiting_instead_of_failure
   */
  it.each([
    ['모듈', moduleInstall],
    ['플러그인', pluginInstall],
    ['템플릿', templateInstall],
  ])('%s: 꺼진 채 설치된 언어팩이 있으면 안내 토스트에 식별자를 싣고, 없으면 띄우지 않는다', (_label, tree) => {
    const toasts = installSuccess(tree).filter((a) => a.handler === 'toast' && a.params?.type === 'info'
      && String(a.params?.message ?? '').includes('$t:extensions.alerts.language_pack_awaiting_host'));
    expect(toasts.length, '꺼진 채 설치 안내 토스트가 없습니다.').toBe(1);
    const toast = toasts[0];

    const awaiting = { data: { language_pack_failures: [], language_packs_awaiting_host: ['g7-module-x-ja', 'g7-module-x-zh'] } };
    expect(evalBinding(toast.if, { response: awaiting })).toBe(true);
    expect(evalBinding(toast.if, { response: { data: { language_packs_awaiting_host: [] } } })).toBe(false);
    expect(evalBinding(toast.if, { response: { data: {} } })).toBe(false);

    const names = /names=(\{\{[\s\S]*?\}\})/.exec(toast.params.message)![1];
    expect(evalBinding(names, { response: awaiting })).toBe('g7-module-x-ja, g7-module-x-zh');

    // 같은 응답에서 실패 경고는 뜨지 않는다(실패 목록이 비어 있음).
    const warn = installSuccess(tree).find((a) => a.handler === 'toast' && a.params?.type === 'warning'
      && String(a.params?.message ?? '').includes('language_pack_install_failed'))!;
    expect(evalBinding(warn.if, { response: awaiting })).toBe(false);
  });
});

describe('언어팩 화면 번들 설치 창 — 꺼진 대상 안내', () => {
  /**
   * @scenario case=bundled_modal_inactive_host_notice
   * @effects language_pack_installed_off_for_inactive_host
   */
  it('대상이 꺼져 있으면(install_notice=target_inactive) 안내를 보이고 설치 버튼은 잠그지 않는다', () => {
    const notices = collect(bundledInstall, (n) => typeof n.if === 'string' && n.if.includes("install_notice === 'target_inactive'"));
    expect(notices.length, '꺼진 대상 안내가 없습니다.').toBe(1);
    expect(JSON.stringify(notices[0])).toContain('install_notice_target_inactive');

    const row = { install_blocked_reason: null, install_notice: 'target_inactive', scope: 'module', target_identifier: 'x', abilities: { can_install: true } };
    expect(evalBinding(notices[0].if, { _global: { selectedBundledLanguagePack: row } })).toBe(true);
    expect(evalBinding(notices[0].if, { _global: { selectedBundledLanguagePack: { ...row, install_notice: null } } })).toBe(false);

    // 설치 버튼 잠금 조건은 안내 사유를 보지 않는다.
    const buttons = collect(bundledInstall, (n) => typeof n.props?.disabled === 'string' && n.props.disabled.includes('install_blocked_reason'));
    expect(buttons.length).toBe(1);
    expect(evalBinding(buttons[0].props.disabled, { _global: { isLanguagePackInstalling: false, selectedBundledLanguagePack: row } })).toBe(false);

    // 예전 「먼저 활성화하세요」 차단 문구는 쓰지 않는다.
    expect(JSON.stringify(bundledInstall)).not.toContain('install_blocked_target_inactive');
  });
});
