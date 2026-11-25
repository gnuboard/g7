/**
 * @file admin-menu-icon-field.test.tsx
 * @description 메뉴 관리 레이아웃 — 아이콘을 구조형 값(icon_spec)으로 편집·저장·표시한다 (#381)
 *
 * 굽기 산출(JSON)을 읽어
 *   ① 폼 판의 아이콘 칸이 MenuIconField(name=icon_spec)이고 서버 오류를 icon_spec·icon 순으로 보인다
 *   ② 저장(submit) apiCall 본문이 icon_spec 을 싣고 종전 icon 문자열을 싣지 않는다
 *   ③ 보기 판의 [수정] 전환이 선택 메뉴의 icon_spec 을 폼으로 옮긴다 (icon 키 없음)
 *   ④ 새 메뉴 초기값(initLocal · [메뉴 추가])이 둘 다 `{type:'class', value:'fas fa-home'}` 이다
 *   ⑤ 보기 판이 MenuIcon 으로 선택 메뉴의 icon_spec(없으면 icon)을 그린다
 *   ⑥ 두 컴포넌트가 템플릿 매니페스트(components.json)에 composite 로 등록돼 있다
 * 를 잰다.
 *
 * @scenario-manifest tests/scenarios/menu-icon-rendering.yaml
 * @vitest-environment jsdom
 */

import { describe, expect, it } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(__dirname, '../..');
const LAYOUTS = path.join(ROOT, 'layouts');

type Node = Record<string, any>;

/**
 * 레이아웃 JSON 을 읽는다.
 *
 * @param rel layouts 아래 상대 경로
 * @returns JSON
 */
function read(rel: string): Node {
  return JSON.parse(fs.readFileSync(path.join(LAYOUTS, rel), 'utf8'));
}

/**
 * 조건에 맞는 노드를 모두 찾는다.
 *
 * @param node 시작 노드
 * @param match 조건
 * @param acc 누적
 * @returns 노드 목록
 */
function findAll(node: unknown, match: (n: Node) => boolean, acc: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((child) => findAll(child, match, acc));

    return acc;
  }
  if (!node || typeof node !== 'object') return acc;
  if (match(node as Node)) acc.push(node as Node);
  Object.values(node as Node).forEach((value) => findAll(value, match, acc));

  return acc;
}

const DEFAULT_ICON_SPEC = { type: 'class', value: 'fas fa-home' };

const form = read('partials/admin_menu_list/_panel_form.json');
const view = read('partials/admin_menu_list/_panel_view.json');
const list = read('admin_menu_list.json');

describe('메뉴 관리 — 아이콘 구조형 값(icon_spec) (#381)', () => {
  /**
   * @scenario surface=form_field, icon_type=class
   * @effects layout_form_posts_icon_spec, form_emits_icon_spec_event
   */
  it('폼 판의 아이콘 칸은 MenuIconField(name=icon_spec)이고 서버 오류를 icon_spec → icon 순으로 읽는다', () => {
    const fields = findAll(form, (n) => n.name === 'MenuIconField');

    expect(fields).toHaveLength(1);
    expect(fields[0].type).toBe('composite');
    expect(fields[0].id).toBe('form_icon_select');
    expect(fields[0].props.name).toBe('icon_spec');
    expect(fields[0].props.error).toBe("{{_global.formErrors?.icon_spec?.[0] ?? _global.formErrors?.icon?.[0] ?? ''}}");
    // 종전 IconSelect(name=icon) 은 폼에 남지 않는다
    expect(findAll(form, (n) => n.name === 'IconSelect')).toEqual([]);
  });

  /**
   * @scenario surface=form_field, icon_type=class
   * @effects layout_form_posts_icon_spec
   */
  it('저장 apiCall 본문은 icon_spec 을 싣고 icon 을 싣지 않는다', () => {
    const calls = findAll(form, (n) => n.handler === 'apiCall' && n.params?.body && 'slug' in n.params.body);

    expect(calls.length).toBeGreaterThanOrEqual(1);
    for (const call of calls) {
      expect(call.params.body.icon_spec).toBe('{{_global.formData.icon_spec}}');
      expect(call.params.body).not.toHaveProperty('icon');
    }
  });

  /**
   * @scenario surface=view_panel, icon_type=class
   * @effects layout_edit_switch_copies_icon_spec
   */
  it('보기 판의 [수정] 전환은 선택 메뉴의 icon_spec 을 폼으로 옮긴다', () => {
    const switches = findAll(view, (n) => n.handler === 'setState' && n.params?.panelMode === 'edit' && n.params?.formData);

    expect(switches).toHaveLength(1);
    expect(switches[0].params.formData.icon_spec).toBe('{{_global.selectedMenu?.icon_spec ?? null}}');
    expect(switches[0].params.formData).not.toHaveProperty('icon');
  });

  /**
   * @scenario surface=form_field, icon_type=class
   * @effects layout_form_posts_icon_spec, form_initial_tab_matches_value
   */
  it('새 메뉴 초기값은 initLocal 과 [메뉴 추가] 모두 {type:class, value:fas fa-home} 이다', () => {
    expect(list.initLocal.formData.icon_spec).toEqual(DEFAULT_ICON_SPEC);
    expect(list.initLocal.formData).not.toHaveProperty('icon');

    const resets = findAll(list, (n) => n.handler === 'setState' && n.params?.panelMode === 'create' && n.params?.formData);
    expect(resets.length).toBeGreaterThanOrEqual(1);
    for (const reset of resets) {
      expect(reset.params.formData.icon_spec).toEqual(DEFAULT_ICON_SPEC);
      expect(reset.params.formData).not.toHaveProperty('icon');
    }
  });

  /**
   * 6차 O-6b: 업로드가 끝나기 전 저장하면 올린 이미지가 조용히 버려졌다 — 아이콘 칸이 켜는 전역 업로드 중 표시로 저장 단추를 잠근다.
   *
   * @scenario surface=form_field, icon_type=image
   * @effects upload_in_progress_blocks_save
   */
  it('저장 단추는 저장 중·아이콘 업로드 중에 잠긴다', () => {
    const submits = findAll(form, (n) => n.name === 'Button' && n.props?.type === 'submit' && n.props?.form === 'menu_form');

    expect(submits).toHaveLength(1);
    expect(submits[0].props.disabled).toBe('{{_global.isSaving || _global.menuIconUploading}}');
  });

  /**
   * @scenario surface=view_panel, icon_type=legacy_string
   * @effects layout_view_panel_renders_menu_icon, legacy_icon_string_still_renders
   */
  it('보기 판은 MenuIcon 으로 선택 메뉴의 icon_spec 을, 없으면 icon 을 그린다', () => {
    const icons = findAll(view, (n) => n.name === 'MenuIcon');

    expect(icons).toHaveLength(1);
    expect(icons[0].type).toBe('composite');
    expect(icons[0].props).toMatchObject({
      spec: '{{_global.selectedMenu?.icon_spec ?? null}}',
      icon: '{{_global.selectedMenu?.icon ?? null}}',
      fallbackIcon: 'file',
    });
  });

  /**
   * @scenario surface=view_panel, icon_type=svg
   * @effects layout_view_panel_renders_menu_icon
   */
  it('보기 판의 아이콘 라벨은 SVG·업로드 이미지를 문구 키로, 사이트·패키지 이미지는 경로로 보인다', () => {
    const labels = findAll(view, (n) => typeof n.text === 'string' && n.text.includes('icon_spec?.type'));

    expect(labels).toHaveLength(1);
    expect(labels[0].text).toContain("'$t:admin.menus.icon_field.type_svg'");
    expect(labels[0].text).toContain("'$t:admin.menus.icon_field.uploaded_image'");
    expect(labels[0].text).toContain('_global.selectedMenu?.icon_spec?.path');
  });

  /**
   * @scenario surface=form_field, icon_type=image
   * @effects layout_form_posts_icon_spec
   */
  it('MenuIcon·MenuIconField 가 components.json 에 composite 로 등록돼 있다', () => {
    const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'components.json'), 'utf8'));
    const entries = findAll(manifest, (n) => n.name === 'MenuIcon' || n.name === 'MenuIconField');
    const byName = Object.fromEntries(entries.map((e) => [e.name, e]));

    expect(byName.MenuIcon?.path).toBe('src/components/composite/MenuIcon.tsx');
    expect(byName.MenuIconField?.path).toBe('src/components/composite/MenuIconField.tsx');
  });
});
