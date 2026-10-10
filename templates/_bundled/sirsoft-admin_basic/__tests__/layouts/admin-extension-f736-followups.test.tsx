/**
 * @file admin-extension-f736-followups.test.tsx
 * @description 확장 관리 화면 7.1.0 전수 실측(#736 F 영역)에서 찾은 결함의 회귀 잠금
 *
 * - 정보 창(모듈·플러그인·템플릿)의 라이선스 열기가 `<p>` 라 키보드로 열 수 없고 접근성 트리에 글자로만 보였다.
 * - 언어팩 수동 설치 미리보기 서랍만 아이콘 규격 검사 결과를 그리지 않았다(서버 응답에는 `icon` 이 있다).
 */

import { describe, it, expect } from 'vitest';
import moduleDetail from '../../layouts/partials/admin_module_list/_modal_detail.json';
import pluginDetail from '../../layouts/partials/admin_plugin_list/_modal_detail.json';
import templateDetail from '../../layouts/partials/admin_template_list/_modal_detail.json';
import languagePackPreview from '../../layouts/partials/admin_language_pack_list/_drawer_manifest_preview.json';
import modulePreview from '../../layouts/partials/admin_module_list/_drawer_manifest_preview.json';

type Json = Record<string, any>;

/** 조건을 만족하는 노드를 모두 모은다. */
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

/** 노드 자신의 동작(actions) 안에 라이선스 조회 요청이 있는가 — 자식 노드는 보지 않는다. */
function ownsLicenseCall(n: Json): boolean {
  return Array.isArray(n.actions) && JSON.stringify(n.actions).includes('/license');
}

/** 정보 창 레이아웃의 라이선스 열기가 버튼 하나인지 검사한다. */
function expectLicenseButton(layout: unknown): void {
  const triggers = collect(layout, (n) => typeof n.name === 'string' && ownsLicenseCall(n));
  expect(triggers.length).toBe(1);
  expect(triggers[0].name).toBe('Button');
  expect(triggers[0].props?.type).toBe('button');
}

describe('정보 창 라이선스 열기', () => {
  /**
   * @scenario surface=module_detail
   * @effects license_trigger_is_button
   */
  it('모듈 정보 창의 라이선스 열기는 버튼이다(키보드로 열 수 있다)', () => {
    expectLicenseButton(moduleDetail);
  });

  /**
   * @scenario surface=plugin_detail
   * @effects license_trigger_is_button
   */
  it('플러그인 정보 창의 라이선스 열기는 버튼이다(키보드로 열 수 있다)', () => {
    expectLicenseButton(pluginDetail);
  });

  /**
   * @scenario surface=template_detail
   * @effects license_trigger_is_button
   */
  it('템플릿 정보 창의 라이선스 열기는 버튼이다(키보드로 열 수 있다)', () => {
    expectLicenseButton(templateDetail);
  });
});

describe('언어팩 미리보기 서랍의 아이콘 검사 결과', () => {
  /**
   * @scenario surface=language_pack_preview
   * @effects language_pack_preview_shows_icon_check
   */
  it('언어팩 서랍도 모듈 서랍처럼 아이콘 경고 목록을 그린다', () => {
    const text = JSON.stringify(languagePackPreview);
    expect(text).toContain('languagePackPreviewResult?.icon?.warnings');
    expect(text).toContain('$t:extensions.manifest_preview.icon_invalid');
    expect(text).toContain('$t:extensions.manifest_preview.icon_present');
    expect(text).toContain('$t:extensions.manifest_preview.icon_missing');
    // 대조군: 모듈 서랍은 이미 같은 블록을 갖고 있다
    expect(JSON.stringify(modulePreview)).toContain('modulePreviewResult?.icon?.warnings');
  });
});
