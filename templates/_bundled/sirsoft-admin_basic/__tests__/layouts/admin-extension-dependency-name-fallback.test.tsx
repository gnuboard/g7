/**
 * @file admin-extension-dependency-name-fallback.test.tsx
 * @description 의존 확장 이름이 빈 문자열이면 식별자를 보여 준다 (7.1.0 전수 실측 #736 O-F10)
 *
 * 7.1.0 CHANGELOG 는 「이름이 비면 식별자를 보여 줍니다」라고 약속했지만 화면은 `name ?? identifier` 로
 * null·undefined 만 걸렀다. 이름이 빈 문자열이면 카드에는 이름 없는 아이콘만, 상세·확인 창에는 빈 칸만 남았다.
 * 모듈·플러그인 카드는 대체 자체가 없었다.
 */

import { describe, it, expect } from 'vitest';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import templateTabAdmin from '../../layouts/partials/admin_template_list/_tab_admin.json';
import templateTabUser from '../../layouts/partials/admin_template_list/_tab_user.json';
import moduleInstall from '../../layouts/partials/admin_module_list/_modal_install.json';
import pluginInstall from '../../layouts/partials/admin_plugin_list/_modal_install.json';
import templateInstall from '../../layouts/partials/admin_template_list/_modal_install.json';
import moduleDetail from '../../layouts/partials/admin_module_list/_modal_detail.json';
import pluginDetail from '../../layouts/partials/admin_plugin_list/_modal_detail.json';
import templateDetail from '../../layouts/partials/admin_template_list/_modal_detail.json';
import moduleForceDeactivate from '../../layouts/partials/admin_module_list/_modal_force_deactivate.json';
import pluginForceDeactivate from '../../layouts/partials/admin_plugin_list/_modal_force_deactivate.json';
import moduleDeactivateWarning from '../../layouts/partials/admin_module_list/_modal_deactivate_warning.json';
import moduleForceActivate from '../../layouts/partials/admin_module_list/_modal_force_activate.json';
import pluginForceActivate from '../../layouts/partials/admin_plugin_list/_modal_force_activate.json';
import templateForceActivate from '../../layouts/partials/admin_template_list/_modal_force_activate.json';

/** 레이아웃 JSON 안의 모든 문자열을 모은다. */
function strings(tree: unknown): string[] {
  const out: string[] = [];
  const walk = (n: unknown): void => {
    if (typeof n === 'string') out.push(n);
    else if (Array.isArray(n)) n.forEach(walk);
    else if (n && typeof n === 'object') Object.values(n).forEach(walk);
  };
  walk(tree);
  return out;
}

/** 카드 데이터의 의존성 매핑 식(`(p.dependencies…).map(d => ({ … }))`)을 꺼내 실제로 계산한다. */
function cardDependencyNames(layout: unknown, dependencies: unknown): string[] {
  const source = strings(layout).find((s) => s.includes('p.dependencies'));
  expect(source, '카드 데이터에 의존성 매핑 식이 있어야 합니다').toBeDefined();
  const pattern = /\(p\.dependencies \?\? \[\]\)\.map\(d => \(\{[^}]*\}\)\)|\.\.\.\(\(p\.dependencies\?\.(modules|plugins) \?\? \[\]\)\.map\(d => \(\{[^}]*\}\)\)\)/g;
  const exprs = source!.match(pattern) ?? [];
  expect(exprs.length).toBeGreaterThan(0);
  const body = exprs.length === 1 && !exprs[0].startsWith('...') ? exprs[0] : `[${exprs.join(', ')}]`;
  // eslint-disable-next-line no-new-func
  const rows = new Function('p', `return ${body};`)({ dependencies }) as Array<{ name: string }>;
  return rows.map((r) => r.name);
}

describe('카드의 의존성 이름', () => {
  /**
   * @scenario surface=module_card
   * @effects blank_dependency_name_falls_back_to_identifier
   */
  it('모듈 카드: 이름이 빈 문자열이면 식별자를 보인다', () => {
    const names = cardDependencyNames(moduleList, [{ type: 'module', identifier: 'acme-core', name: '' }]);
    expect(names).toEqual(['acme-core']);
  });

  /**
   * @scenario surface=plugin_card
   * @effects blank_dependency_name_falls_back_to_identifier
   */
  it('플러그인 카드: 이름이 빈 문자열이면 식별자를 보인다', () => {
    const names = cardDependencyNames(pluginList, [{ type: 'plugin', identifier: 'acme-pay', name: '' }]);
    expect(names).toEqual(['acme-pay']);
  });

  /**
   * @scenario surface=template_card
   * @effects blank_dependency_name_falls_back_to_identifier
   */
  it('템플릿 카드(관리자·사용자 탭): 이름이 빈 문자열이면 식별자를 보이고, 이름이 있으면 이름을 보인다', () => {
    const deps = {
      modules: [{ identifier: 'acme-core', name: '' }, { identifier: 'acme-board', name: '게시판' }],
      plugins: [{ identifier: 'acme-pay', name: '' }],
    };
    expect(cardDependencyNames(templateTabAdmin, deps)).toEqual(['acme-core', '게시판', 'acme-pay']);
    expect(cardDependencyNames(templateTabUser, deps)).toEqual(['acme-core', '게시판', 'acme-pay']);
  });
});

describe('상세·확인 창의 의존 확장 이름', () => {
  const surfaces: Array<[string, unknown]> = [
    ['모듈 설치 확인', moduleInstall],
    ['플러그인 설치 확인', pluginInstall],
    ['템플릿 설치 확인', templateInstall],
    ['모듈 정보', moduleDetail],
    ['플러그인 정보', pluginDetail],
    ['템플릿 정보', templateDetail],
    ['모듈 강제 비활성화', moduleForceDeactivate],
    ['플러그인 강제 비활성화', pluginForceDeactivate],
    ['모듈 비활성화 경고', moduleDeactivateWarning],
    ['모듈 강제 활성화', moduleForceActivate],
    ['플러그인 강제 활성화', pluginForceActivate],
    ['템플릿 강제 활성화', templateForceActivate],
  ];

  /**
   * @scenario surface=confirm_and_detail_modals
   * @effects blank_dependency_name_falls_back_to_identifier
   */
  it.each(surfaces)('%s: 의존 확장 이름 칸은 빈 문자열이면 식별자로 대체한다', (_label, layout) => {
    const text = strings(layout).join('\n');
    // 대체 없는 이름 표시({{dep.name}})와 null 만 거르는 대체(name ?? identifier)가 없어야 한다
    expect(text).not.toMatch(/\{\{(dep|mod|plug|template|module|plugin)\.name\}\}/);
    expect(text).not.toMatch(/(dep|mod|plug|template|module|plugin)\.name \?\? \1\.identifier/);
    expect(text).toMatch(/(dep|mod|plug|template|module|plugin)\.name \|\| \1\.identifier/);
  });
});
