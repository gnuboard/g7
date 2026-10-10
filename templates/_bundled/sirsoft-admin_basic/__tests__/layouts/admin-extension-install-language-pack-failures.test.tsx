/**
 * @file admin-extension-install-language-pack-failures.test.tsx
 * @description 설치 창에서 함께 고른 언어팩이 설치되지 못하면 알린다 (Q-G2 Hello 설치 실측)
 *
 * 설치 응답은 함께 고른 번들 언어팩 중 실패한 것을 `language_pack_failures`(식별자·사유)로 돌려준다.
 * 화면은 이 값을 읽지 않고 「설치되었습니다」만 띄워, 체크해 둔 언어팩이 설치되지 않은 것을 알 수 없었다
 * (실측: Hello 모듈 설치 — ja 언어팩이 「대상 모듈이 비활성」 사유로 실패, 화면은 성공 토스트 1건).
 */

import { describe, it, expect } from 'vitest';
import moduleInstall from '../../layouts/partials/admin_module_list/_modal_install.json';
import pluginInstall from '../../layouts/partials/admin_plugin_list/_modal_install.json';
import templateInstall from '../../layouts/partials/admin_template_list/_modal_install.json';

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
function evalBinding(expr: string, response: Json): unknown {
  const m = /^\{\{([\s\S]*)\}\}$/.exec(expr.trim());
  // eslint-disable-next-line no-new-func
  return new Function('response', `return (${m ? m[1] : JSON.stringify(expr)});`)(response);
}

describe('설치 창 — 함께 고른 언어팩 실패 알림', () => {
  /**
   * @scenario case=failure_toast_surfaced
   * @effects install_language_pack_failures_surfaced
   */
  it.each([
    ['모듈', moduleInstall],
    ['플러그인', pluginInstall],
    ['템플릿', templateInstall],
  ])('%s: 실패가 있으면 경고 토스트에 식별자와 사유를 싣고, 없으면 띄우지 않는다', (_label, tree) => {
    const toasts = installSuccess(tree).filter((a) => a.handler === 'toast' && a.params?.type === 'warning'
      && String(a.params?.message ?? '').includes('$t:extensions.alerts.language_pack_install_failed'));
    expect(toasts.length, '언어팩 실패 경고 토스트가 없습니다.').toBe(1);
    const toast = toasts[0];

    const failed = { data: { language_pack_failures: [{ identifier: 'g7-module-x-ja', reason: '대상 모듈이 비활성입니다.' }] } };
    expect(evalBinding(toast.if, failed)).toBe(true);
    expect(evalBinding(toast.if, { data: { language_pack_failures: [] } })).toBe(false);
    expect(evalBinding(toast.if, { data: {} })).toBe(false);

    const names = /names=(\{\{[\s\S]*?\}\})/.exec(toast.params.message)![1];
    const reason = /reason=(\{\{[\s\S]*?\}\})/.exec(toast.params.message)![1];
    expect(evalBinding(names, failed)).toBe('g7-module-x-ja');
    expect(evalBinding(reason, failed)).toBe('대상 모듈이 비활성입니다.');
  });

  /**
   * @scenario case=met_dependency_badge_shown
   * @effects met_dependency_language_pack_selectable
   */
  it.each([
    ['모듈', moduleInstall],
    ['플러그인', pluginInstall],
    ['템플릿', templateInstall],
  ])('%s: 충족된 의존 확장의 언어팩(depends_on_extension 없음)도 「종속」 배지로 어느 확장 것인지 보인다', (_label, tree) => {
    const badges: Json[] = [];
    const walk = (n: unknown): void => {
      if (Array.isArray(n)) return n.forEach(walk);
      if (n && typeof n === 'object') {
        const obj = n as Json;
        if (typeof obj.text === 'string' && obj.text.includes('cascade_lp_depends_on')) badges.push(obj);
        if (typeof obj.props?.text === 'string' && obj.props.text.includes('cascade_lp_depends_on')) badges.push(obj);
        Object.values(obj).forEach(walk);
      }
    };
    walk(tree);
    expect(badges.length, '종속 배지가 없습니다.').toBe(1);
    const cond = badges[0].if as string;
    const evalIf = (lp: Json): unknown => {
      const m = /^\{\{([\s\S]*)\}\}$/.exec(cond.trim())!;
      // eslint-disable-next-line no-new-func
      return new Function('lp', '_global', `return (${m[1]});`)(lp, { installPreview: { target: { identifier: 'self-ext' } } });
    };
    expect(Boolean(evalIf({ depends_on_extension: null, target_identifier: 'dep-ext' })), '충족 의존 확장 언어팩').toBe(true);
    expect(Boolean(evalIf({ depends_on_extension: 'dep-ext', target_identifier: 'dep-ext' })), '미충족 의존 확장 언어팩').toBe(true);
    expect(Boolean(evalIf({ depends_on_extension: null, target_identifier: 'self-ext' })), '자기 언어팩').toBe(false);
  });
});
