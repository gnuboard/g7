/**
 * @file pluginSettingsSticky.test.tsx
 * @description sirsoft-pay_nhnkcp 플러그인 환경설정 화면 하단 저장 버튼 sticky 고정 테스트
 *
 * 플러그인 환경설정(plugin_settings.json)의 하단 저장/취소 버튼 영역이
 * 긴 콘텐츠를 스크롤하는 동안에도 화면 하단에 고정되도록 sticky 클래스가
 * 적용되어 있는지 검증한다.
 *
 * @scenario-manifest tests/scenarios/plugin-settings-sticky.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import pluginSettingsLayout from '../../../layouts/admin/plugin_settings.json';

const EXT_CSS = fs.readFileSync(path.resolve(__dirname, '../../../css/g7-extension.generated.css'), 'utf8');

/** 레이아웃 트리에서 주어진 id 의 노드를 찾는다. */
function findById(node: unknown, id: string): Record<string, unknown> | undefined {
  if (!node || typeof node !== 'object') {
    return undefined;
  }
  const value = node as Record<string, unknown>;
  if (value.id === id) {
    return value;
  }
  for (const child of Object.values(value)) {
    const found = findById(child, id);
    if (found) {
      return found;
    }
  }
  return undefined;
}

function classNameOf(node: Record<string, unknown> | undefined): string {
  const props = (node?.props ?? {}) as Record<string, unknown>;
  return typeof props.className === 'string' ? props.className : '';
}

function collectClassNames(node: unknown, classNames: string[] = []): string[] {
  if (!node || typeof node !== 'object') {
    return classNames;
  }
  const value = node as Record<string, unknown>;
  const props = (value.props ?? {}) as Record<string, unknown>;
  if (typeof props.className === 'string') {
    classNames.push(props.className);
  }
  for (const child of Object.values(value)) {
    collectClassNames(child, classNames);
  }

  return classNames;
}

function collectIds(node: unknown, ids: string[] = []): string[] {
  if (!node || typeof node !== 'object') {
    return ids;
  }
  const value = node as Record<string, unknown>;
  if (typeof value.id === 'string') {
    ids.push(value.id);
  }
  for (const child of Object.values(value)) {
    collectIds(child, ids);
  }

  return ids;
}

describe('plugin_settings 하단 버튼 sticky 고정', () => {
  /**
   * @scenario plugin=sirsoft-pay_nhnkcp, element=vbank_noti_url
   *
   * @effects vbank_noti_url_guide_shown
   */
  it('가상계좌 입금통보 URL 등록 경로를 설정 화면에 노출해야 한다', () => {
    const section = findById(pluginSettingsLayout, 'vbank_notify_section');
    expect(section).toBeDefined();

    const serialized = JSON.stringify(section);
    expect(serialized).toContain('sirsoft-pay_nhnkcp.settings.section_vbank_notify');
    expect(serialized).toContain('sirsoft-pay_nhnkcp.settings.vbank_notify_hint');
    expect(serialized).toContain('sirsoft-pay_nhnkcp.settings.vbank_notify_path');
  });

  /**
   * @scenario plugin=sirsoft-pay_nhnkcp, element=cancel_server_ip
   *
   * @effects cancel_server_ip_guide_shown
   */
  it('결제취소 서버 IP 등록 안내를 설정 화면에 노출해야 한다', () => {
    const notice = findById(pluginSettingsLayout, 'cancel_server_ip_notice');
    expect(notice).toBeDefined();

    const className = classNameOf(notice);
    expect(className).toContain('border-amber-200');
    expect(className).toContain('dark:border-amber-700');

    const serialized = JSON.stringify(notice);
    expect(serialized).toContain('sirsoft-pay_nhnkcp.settings.cancel_server_ip_title');
    expect(serialized).toContain('sirsoft-pay_nhnkcp.settings.cancel_server_ip_body');
    expect(serialized).toContain('sirsoft-pay_nhnkcp.settings.cancel_server_ip_path');
    expect(serialized).toContain('sirsoft-pay_nhnkcp.settings.cancel_server_ip_effect');
    expect(serialized).toContain('sirsoft-pay_nhnkcp.settings.cancel_server_ip_value_label');
    expect(serialized).toContain('health.data.summary.cancel_server_ip.address');
    expect(serialized).toContain('sirsoft-pay_nhnkcp.copyToClipboard');
    expect(serialized).toContain('https://partner.kcp.co.kr/');
    expect(serialized).not.toContain('https://admin8.kcp.co.kr');

    const ids = collectIds(pluginSettingsLayout);
    expect(ids.indexOf('cancel_server_ip_notice')).toBeGreaterThan(-1);
    expect(ids.indexOf('vbank_notify_section')).toBeGreaterThan(-1);
    expect(ids.indexOf('cancel_server_ip_notice')).toBeLessThan(ids.indexOf('vbank_notify_section'));
  });

  /**
   * @scenario plugin=sirsoft-pay_nhnkcp, element=footer
   *
   * @effects footer_buttons_stick_to_bottom
   */
  it('footer_buttons 에 sticky bottom 고정 클래스가 존재해야 한다', () => {
    const footer = findById(pluginSettingsLayout, 'footer_buttons');
    expect(footer).toBeDefined();

    const className = classNameOf(footer);

    // 명제는 "푸터가 하단에 sticky 로 붙고 위로 겹친다" 다. 그 명제를 유틸리티 클래스 나열로 단언하면
    // 축약 클래스(`.sticky-footer-buttons` = `@apply sticky bottom-0 z-10 …`)를 쓰는 소스에서 red 가
    // 된다 — 축약 클래스가 이 저장소의 표준 표기이므로 둘 중 어느 표기든 통과해야 한다.
    const isShorthand = /sticky-footer-buttons(-between)?/.test(className);
    if (!isShorthand) {
      expect(className).toContain('sticky');
      expect(className).toContain('bottom-0');
      expect(className).toContain('z-10');
    }
    expect(isShorthand || className.includes('sticky')).toBe(true);
    expect(className).toContain('border-gray-200');
  });

  /**
   * @scenario plugin=sirsoft-pay_nhnkcp, element=health_card
   *
   * @effects health_card_uses_admin_card_tone
   */
  it('health_check_card 가 기본 검정 border 대신 관리자 카드 톤을 명시해야 한다', () => {
    const card = findById(pluginSettingsLayout, 'health_check_card');
    expect(card).toBeDefined();

    const className = classNameOf(card);
    expect(className).toContain('shadow-sm');
    expect(className).toContain('border-gray-200');
    expect(className).toContain('dark:border-gray-700');
  });

  /**
   * @scenario plugin=sirsoft-pay_nhnkcp, element=health_card_divider
   *
   * @effects health_card_dividers_use_card_tone
   */
  it('health_check_card 내부 구분선도 기본 검정 border 로 떨어지지 않아야 한다', () => {
    const card = findById(pluginSettingsLayout, 'health_check_card');
    expect(card).toBeDefined();

    const classNames = collectClassNames(card);
    expect(classNames).not.toContain('flex items-center justify-between p-4 border-b dark:border-gray-700');
    expect(classNames).not.toContain('grid grid-cols-1 sm:grid-cols-3 gap-3 p-4 border-b dark:border-gray-700');
  });

  /**
   * @scenario plugin=sirsoft-pay_nhnkcp, element=root
   *
   * @effects settings_root_layout_by_width
   */
  it('활성 테마의 신규 시맨틱 CSS 없이도 설정 화면 기본 레이아웃이 적용되어야 한다', () => {
    const root = findById(pluginSettingsLayout, 'plugin_settings_content');
    expect(root).toBeDefined();

    const rootClassName = classNameOf(root);
    expect(rootClassName).toContain('p-4');
    // 화면 폭 분기는 엔진 responsive 로 쓴다 — 640~1023px 은 p-6, 1024px 이상은 p-8
    const responsive = (root?.responsive ?? {}) as Record<string, Record<string, unknown>>;
    // 640~1023px 구간은 플러그인 자기 의미 클래스(본문 상자)를 쓰고, 그 정의가 p-6(24px) 여백을 준다
    expect(classNameOf(responsive['640-1023']).split(' ')).toContain('pay-nhnkcp-admin-page-content');
    expect(EXT_CSS).toMatch(/\.pay-nhnkcp-admin-page-content\s*\{[^}]*padding:\s*calc\(\.25rem \* 6\)/);
    // 커스텀 구간(640-1023)과 프리셋을 한 노드에 섞지 않는다 — 1024px 이상도 커스텀 구간 1024- 로 쓴다
    expect(classNameOf(responsive['1024-']).split(' ')).toContain('paynhnkcp:p-8');
    expect(responsive.desktop).toBeUndefined();
    expect(rootClassName).toContain('min-h-screen');
    expect(rootClassName).toContain('bg-gray-50');
    expect(rootClassName).toContain('dark:bg-gray-900');

    const classNames = collectClassNames(pluginSettingsLayout);
    for (const className of classNames) {
      expect(className).not.toContain('admin-page-content-responsive');
      expect(className).not.toContain('flex-between');
      expect(className).not.toContain('sticky-footer-buttons');
      expect(className).not.toContain('row-stack');
    }
  });
});
