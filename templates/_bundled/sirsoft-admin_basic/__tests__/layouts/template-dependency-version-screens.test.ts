/**
 * @file template-dependency-version-screens.test.ts
 * @description 의존 확장 미충족 사유를 관리자 화면이 그린다 — 템플릿 강제 활성화 창·설치 창 3종·목록 카드 매핑·503 화면 (코어 7.1.4, #152)
 *
 * 템플릿 활성화가 버전 제약까지 보게 되면서 409 항목에 `status: 'version_mismatch'`(설치·요구 버전 포함)와 `template_type` 이 온다.
 * 1) 강제 활성화 창은 버전 미달 배지와, 강제로 켜면 그 템플릿 화면이 503 이 된다는 타입별 경고를 보인다(관리자면 복구 명령).
 * 2) 설치 창 3종은 설치·활성이지만 버전이 모자란 의존에 「버전 미달 (설치 → 요구)」 배지를 단다.
 * 3) 목록 카드 매핑은 의존 항목의 충족 여부·사유를 카드 컴포넌트에 넘긴다.
 * 4) 503 화면은 항목마다 사유(미설치·꺼짐·버전 미달)를 보이고 제목이 「활성화되지 않았다」로 단정하지 않는다.
 */

import { describe, it, expect } from 'vitest';
import templateForceActivate from '../../layouts/partials/admin_template_list/_modal_force_activate.json';
import templateInstall from '../../layouts/partials/admin_template_list/_modal_install.json';
import moduleInstall from '../../layouts/partials/admin_module_list/_modal_install.json';
import pluginInstall from '../../layouts/partials/admin_plugin_list/_modal_install.json';
import tabUser from '../../layouts/partials/admin_template_list/_tab_user.json';
import tabAdmin from '../../layouts/partials/admin_template_list/_tab_admin.json';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import error503 from '../../layouts/errors/503.json';
import koAdmin from '../../lang/partial/ko/admin.json';
import enAdmin from '../../lang/partial/en/admin.json';
import koErrors from '../../lang/partial/ko/errors.json';
import enErrors from '../../lang/partial/en/errors.json';

type LayoutNode = Record<string, unknown>;

/** 레이아웃 JSON 안의 모든 노드를 모은다. */
function nodes(tree: unknown): LayoutNode[] {
  const out: LayoutNode[] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) n.forEach(walk);
    else if (n && typeof n === 'object') {
      out.push(n as LayoutNode);
      Object.values(n).forEach(walk);
    }
  };
  walk(tree);
  return out;
}

/** `if` 식이 정확히 같은 노드 하나를 찾아 직렬화한다. */
function nodeWithIf(tree: unknown, condition: string): string {
  const hit = nodes(tree).find((n) => n.if === condition);
  expect(hit, `조건 ${condition} 를 가진 노드가 있어야 합니다`).toBeDefined();
  return JSON.stringify(hit);
}

/** ExtensionCardGrid 의 items 식. */
function cardItems(layout: unknown): string {
  const grid = nodes(layout).find((n) => n.name === 'ExtensionCardGrid') as { props?: { items?: string } } | undefined;
  expect(typeof grid?.props?.items).toBe('string');
  return grid!.props!.items!;
}

describe('V1 — 템플릿 강제 활성화 창', () => {
  // @scenario surface=force_modal_badge
  // @effects force_modal_shows_version_mismatch
  it('모듈·플러그인 의존 모두 버전 미달 배지에 설치·요구 버전을 보인다', () => {
    const moduleBadge = nodeWithIf(templateForceActivate, "{{module.status === 'version_mismatch'}}");
    expect(moduleBadge).toContain('$t:admin.modules.status.version_mismatch');
    expect(moduleBadge).toContain('{{module.installed_version}} → {{module.required_version}}');
    const pluginBadge = nodeWithIf(templateForceActivate, "{{plugin.status === 'version_mismatch'}}");
    expect(pluginBadge).toContain('$t:admin.plugins.status.version_mismatch');
    expect(pluginBadge).toContain('{{plugin.installed_version}} → {{plugin.required_version}}');
  });

  // @scenario surface=force_modal_warning
  // @effects force_modal_type_specific_503_warning
  it('사용자·관리자 503 경고가 타입으로 갈리고 관리자 경고는 복구 명령에 현재 관리자 템플릿을 넣는다', () => {
    const typeExpr = "(_global.activateWarningData?.template_type ?? _global.selectedTemplate?.type)";
    expect(nodeWithIf(templateForceActivate, `{{${typeExpr} !== 'admin'}}`)).toContain('$t:admin.templates.force_activate.warning_user_503');
    const admin = nodeWithIf(templateForceActivate, `{{${typeExpr} === 'admin'}}`);
    expect(admin).toContain('$t:admin.templates.force_activate.warning_admin_503|identifier={{_global.activateWarningData?.current_active_identifier');
  });
});

describe('V2 — 설치 창 3종의 버전 미달 배지', () => {
  const cases = [
    { name: '템플릿', layout: templateInstall, ns: 'templates' },
    { name: '모듈', layout: moduleInstall, ns: 'modules' },
    { name: '플러그인', layout: pluginInstall, ns: 'plugins' },
  ];

  // @scenario surface=install_modal_badge
  // @effects install_modal_shows_version_mismatch
  it.each(cases)('$name 설치 창: 설치됐지만 판이 모자란 의존에 배지를 단다', ({ layout, ns }) => {
    const badge = nodeWithIf(layout, "{{dep.is_installed && !dep.is_met && (dep.is_active || dep.blocked_reason === 'version_mismatch')}}");
    expect(badge).toContain(`$t:admin.${ns}.modals.dependency_unmet_version ({{dep.installed_version}} → {{dep.required_version}})`);
  });
});

describe('V4 — 목록 카드 의존 매핑', () => {
  const lists = [
    { name: '사용자 템플릿', layout: tabUser },
    { name: '관리자 템플릿', layout: tabAdmin },
    { name: '모듈', layout: moduleList },
    { name: '플러그인', layout: pluginList },
  ];

  // @scenario surface=card_mapping
  // @effects card_mapping_passes_unmet_reason
  it.each(lists)('$name 목록: 의존 항목에 unmet·reason 을 넘긴다', ({ layout }) => {
    const items = cardItems(layout);
    expect(items).toContain('unmet: d?.is_met === false');
    expect(items).toContain("$t('admin.plugins.dependency_unmet.version_mismatch', {installed: d.installed_version, required: d.required_version})");
    expect(items).toContain("$t('admin.plugins.dependency_unmet.inactive')");
    expect(items).toContain("$t('admin.plugins.dependency_unmet.not_installed')");
  });
});

describe('V6 — 관리자 503 화면 사유', () => {
  // @scenario surface=admin_503
  // @effects error_503_shows_reason_per_item
  it('모듈·플러그인 항목마다 사유 3종을 status 로 고른다', () => {
    for (const v of ['mod', 'plug']) {
      expect(nodeWithIf(error503, `{{${v}.status === 'version_mismatch'}}`)).toContain(`$t:errors.503.reason_version_mismatch|installed={{${v}.installed_version}}|required={{${v}.required_version}}`);
      expect(nodeWithIf(error503, `{{${v}.status === 'inactive'}}`)).toContain('$t:errors.503.reason_inactive');
      expect(nodeWithIf(error503, `{{${v}.status === 'not_installed'}}`)).toContain('$t:errors.503.reason_not_installed');
    }
  });
});

describe('번역 키 ko·en', () => {
  // @scenario surface=lang_keys
  // @effects screen_keys_translated
  it('새 키가 ko·en 에 있고 503 제목이 「활성화되지 않았다」로 단정하지 않는다', () => {
    for (const [admin, errors] of [[koAdmin, koErrors], [enAdmin, enErrors]] as Array<[Record<string, any>, Record<string, any>]>) {
      expect(typeof admin.templates.force_activate.warning_user_503).toBe('string');
      expect(admin.templates.force_activate.warning_admin_503).toContain('{{identifier}}');
      expect(admin.plugins.dependency_unmet.version_mismatch).toContain('{{installed}}');
      expect(typeof admin.plugins.dependency_unmet.inactive).toBe('string');
      expect(typeof admin.plugins.dependency_unmet.not_installed).toBe('string');
      for (const key of ['reason_not_installed', 'reason_inactive', 'reason_version_mismatch']) {
        expect(typeof errors['503'][key]).toBe('string');
      }
    }
    expect(koErrors['503'].unmet_dependencies).not.toContain('활성화되지 않았습니다');
    expect(enErrors['503'].unmet_dependencies).not.toContain('not activated');
  });
});
