/**
 * @file admin-base-header-lang-currency.test.tsx
 * @description 관리자 언어·통화·배송국가 선택기 배치 회귀 테스트
 *
 * 배경 (구 정본): 4표면(유저·관리자 × 데스크톱·모바일) 모두 언어+통화가 헤더 같은 줄에 노출.
 *
 * 반전 사유 (현 정본): 모바일 헤더 우측 그룹은 `flex-shrink-0` 이라 좁아져도 폭을 유지한 채
 * 화면 밖으로 밀려난다. theme(36) + noti(36) + lang(79) + 통화(112) + gap = **420px 를 요구**해
 * 390px 에서 30px, 320px 에서 100px 이 잘렸다(통화 셀렉터 단독이 클립 전량의 원인 — 통화만
 * 숨기면 clip 0). 따라서 언어·통화·배송국가를 모두 모바일 드로어(`admin_drawer_prefs`)로 옮겨
 * 사용자 템플릿(`_user_base` 의 `mobile_drawer_prefs`)과 동일한 가로 칩 목록으로 통일했다.
 *
 * → 모바일 헤더에는 theme·notification 만 남는다. 이 테스트를 "패리티 회복"이라는 이유로
 *   되돌리면 폰 뷰포트 클립이 재발한다. 데스크톱(≥1024px)은 기존 헤더 배치를 유지한다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');

function loadJson(relPath: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(baseDir, relPath), 'utf8'));
}

function find(node: any, id: string): any {
  if (!node || typeof node !== 'object') return null;
  if (Array.isArray(node)) { for (const n of node) { const r = find(n, id); if (r) return r; } return null; }
  if (node.id === id) return node;
  for (const k of ['children', 'components']) { if (node[k]) { const r = find(node[k], id); if (r) return r; } }
  return null;
}

/**
 * 술어를 만족하는 첫 노드를 재귀 탐색합니다 (위치 인덱스/직계 자식 가정 회피).
 *
 * @param node 탐색 시작 노드/배열
 * @param pred 노드 술어
 * @return 찾은 노드 (없으면 null)
 */
function walk(node: any, pred: (n: any) => boolean): any {
  if (!node || typeof node !== 'object') return null;
  if (Array.isArray(node)) { for (const n of node) { const r = walk(n, pred); if (r) return r; } return null; }
  if (pred(node)) return node;
  for (const k of ['children', 'components']) { if (node[k]) { const r = walk(node[k], pred); if (r) return r; } }
  return null;
}

describe('관리자 헤더 언어·통화 배치', () => {
  const adminBase = loadJson('layouts/_admin_base.json');

  it('데스크톱 언어 선택기는 showCode:true 로 현재 로케일 코드를 표시한다', () => {
    const node = find(adminBase.components, 'language_selector_desktop');
    expect(node).toBeTruthy();
    expect(node.name).toBe('LanguageSelector');
    expect(node.props?.showCode).toBe(true);
  });

  it('데스크톱 헤더는 통화 슬롯을 계속 보유한다 (데스크톱 회귀 방지)', () => {
    const node = find(adminBase.components, 'header_currency_slot_desktop');
    expect(node).toBeTruthy();
    expect(node.name).toBe('SlotContainer');
    expect(node.props?.slotId).toBe('header_currency');
  });

  it('모바일 헤더 우측에는 언어·통화가 없다 (420px 요구 → 폰에서 클립되던 원인 제거)', () => {
    const mobileRight = find(adminBase.components, 'mobile_header_right');
    expect(mobileRight).toBeTruthy();
    const ids = (mobileRight.children ?? []).map((c: any) => c.id);
    expect(ids).not.toContain('language_selector_mobile');
    expect(ids).not.toContain('header_currency_slot_mobile');
    // 남아야 하는 것: 테마 토글 + 알림
    expect(ids).toContain('theme_toggle_mobile');
    expect(ids).toContain('notification_center_mobile');
  });

  it('모바일 드로어 선호설정 블록은 portable 에서만 렌더된다', () => {
    const prefs = find(adminBase.components, 'admin_drawer_prefs');
    expect(prefs).toBeTruthy();
    // 데스크톱에서는 미렌더 (드로어에 통화가 중복 노출되면 회귀)
    expect(prefs.if).toBe('{{false}}');
    expect(prefs.responsive?.portable?.if).toBe('{{true}}');
  });

  it('드로어 언어는 가로 칩이며 iteration 은 Button 자신에 걸린다', () => {
    const lang = find(adminBase.components, 'admin_drawer_language');
    expect(lang).toBeTruthy();
    expect(lang.if).toBe('{{$locales.length > 1}}');

    // 정적 flex-wrap 래퍼 + iteration 을 가진 Button 자식.
    // 래퍼는 아코디언 펼침 영역 안에 있으므로 직계 자식이 아니라 재귀로 찾는다.
    const wrapper = walk(lang,
      (c: any) => typeof c.props?.className === 'string' && c.props.className.includes('flex-wrap'),
    );
    expect(wrapper).toBeTruthy();

    const chip = (wrapper.children ?? [])[0];
    expect(chip?.name).toBe('Button');
    // 엔진은 iteration 을 가진 노드를 복제한다. flex 컨테이너에 걸면 컨테이너가 복제되어
    // 칩이 세로로 쌓이므로, 반드시 리프(Button)에 건다.
    expect(chip?.iteration?.item_var).toBe('loc');
    expect(wrapper.iteration).toBeUndefined();
  });

  it('드로어 언어 칩은 엔진 빌트인 setLocale 을 action.target 으로 호출한다', () => {
    const lang = find(adminBase.components, 'admin_drawer_language');
    const wrapper = walk(lang,
      (c: any) => typeof c.props?.className === 'string' && c.props.className.includes('flex-wrap'),
    );
    const chip = (wrapper.children ?? [])[0];
    const action = (chip.actions ?? [])[0];

    expect(action.handler).toBe('setLocale');
    // 엔진 빌트인(ActionDispatcher)은 action.target 만 읽는다. params.locale 로 주면 무시된다.
    expect(action.target).toBe('{{loc}}');
    expect(action.params).toBeUndefined();
  });

  /**
   * 접기/펼치기 — 언어·통화·배송국가를 전부 펼쳐 두면 드로어가 세로로 길어져 정작 메뉴가
   * 스크롤 밖으로 밀린다. 언어와 통화/배송국가는 각각 독립 아코디언이며 기본 접힘이고,
   * 접힘 상태에서도 트리거에 현재 선택값을 요약 표기한다.
   *
   * 언어 = 템플릿 소유(`_global.mobileLanguageOpen`),
   * 통화/배송국가 = 이커머스 주입 조각 소유(`_local.showCurrencyDropdown`) → 서로 독립.
   */
  it('드로어 언어 섹션은 기본 접힘 + 트리거에 현재 언어를 요약 표기한다', () => {
    const lang = find(adminBase.components, 'admin_drawer_language');
    const toggle = find(lang, 'admin_drawer_language_toggle');
    expect(toggle?.name).toBe('Button');
    expect(toggle.props['aria-expanded']).toBe('{{_global.mobileLanguageOpen ?? false}}');

    const act = toggle.actions[0];
    expect(act.handler).toBe('setState');
    expect(act.params.target).toBe('global');
    expect(act.params.mobileLanguageOpen).toBe('{{!_global.mobileLanguageOpen}}');

    // 접힘 상태 요약 = 현재 언어명 + chevron
    const summary = JSON.stringify(toggle.children);
    expect(summary).toContain('localeNames?.[$locale]');
    expect(summary).toContain('chevron-down');
    expect(summary).toContain("{{_global.mobileLanguageOpen ? 'rotate-180' : ''}}");

    // 펼침 영역: falsy(기본) 분기가 닫힘(max-h-0)이어야 기본 접힘이 성립한다
    const body = walk(lang, (n: any) =>
      typeof n.props?.className === 'string' &&
      n.props.className.includes('overflow-hidden') &&
      n.props.className.includes('mobileLanguageOpen'),
    );
    expect(body).toBeTruthy();
    expect(body.props.className).toContain("'max-h-screen opacity-100 mt-2' : 'max-h-0 opacity-0 mt-0'");
  });

  it('언어 토글은 통화 플래그를 건드리지 않는다 (독립 개폐)', () => {
    const toggle = find(adminBase.components, 'admin_drawer_language_toggle');
    expect(toggle).toBeTruthy();
    expect(JSON.stringify(toggle)).not.toContain('showCurrencyDropdown');
  });

  it('드로어 통화 슬롯은 데스크톱과 동일한 header_currency 슬롯을 공유하고 고유 id 를 갖는다', () => {
    const node = find(adminBase.components, 'admin_drawer_currency_wrap');
    expect(node).toBeTruthy();
    expect(node.name).toBe('SlotContainer');
    expect(node.props?.slotId).toBe('header_currency');
    // SlotContainer 는 주입 root id 를 `{id}__{containerId}` 로 스코프하므로 id 가 필수다.
    expect(node.id).toBe('admin_drawer_currency_wrap');
    // 헤더의 `flex items-center` 를 복사하면 주입 루트가 콘텐츠 폭으로 줄어든다.
    expect(node.props?.className).toBe('block');
  });
});
