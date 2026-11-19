/**
 * @file admin-settings-private-attachment.test.ts
 * @description 환경설정 > 드라이버 「비공개 첨부 저장소」 카드 계약 (#916)
 *
 * 잠그는 것: 카드가 공개 자산 카드 뒤에 있고 select 가 `drivers.private_attachment_disk` 로 바인딩되며 선택지는 서버 카탈로그
 * (`available_drivers.private_attachment`)를 그대로 쓴다. S3 접속 블록은 비공개 저장소가 s3 일 때도 열린다. 공개 자산과 같은 디스크일
 * 때만 경고가 보이고(「기본」·「사용 안 함」은 비교하지 않는다), `.env` 고정 주체(`_meta.private_attachment.pins`)가 있을 때만 안내가 보인다.
 * 라벨·안내 키가 모든 로케일에 있다.
 */

import { describe, it, expect } from 'vitest';
import { loadLayout, localeNames, loadLocale, langValue, nodeById, collectObjects, translationKeys } from './analyticsLayoutTestUtils';

const tab = loadLayout('partials/admin_settings/_tab_drivers.json');

/**
 * 노드의 `if` 표현식을 주어진 `_local` 로 평가합니다.
 *
 * @param node 레이아웃 노드
 * @param local `_local` 값
 * @returns 평가 결과
 */
function evalIf(node: Record<string, any> | undefined, local: Record<string, any>): boolean {
  const expr = String(node?.if ?? '').replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return Boolean(new Function('_local', `return (${expr});`)(local));
}

/**
 * 드라이버 값으로 `_local` 을 만듭니다.
 *
 * @param drivers drivers 값
 * @param meta `_meta` 값
 * @returns `_local`
 */
function local(drivers: Record<string, string>, meta: Record<string, any> = {}): Record<string, any> {
  return { form: { drivers, _meta: meta } };
}

describe('환경설정 드라이버 탭 — 비공개 첨부 저장소', () => {
  /**
   * @scenario case=private_attachment_card
   * @effects private_attachment_select_bound_to_catalog
   */
  it('카드가 공개 자산 카드 뒤에 있고 select 가 카탈로그 선택지로 drivers.private_attachment_disk 에 바인딩된다', () => {
    const card = nodeById(tab, 'card_private_attachment_settings');
    expect(card, '카드 노드').toBeTruthy();

    const select = collectObjects(card).find((o) => o.name === 'Select');
    expect(select?.props?.name).toBe('drivers.private_attachment_disk');
    expect(String(select?.props?.options ?? '')).toContain('available_drivers?.private_attachment');
    expect(String(select?.props?.error ?? '')).toContain("_local.errors?.['drivers.private_attachment_disk']");

    const ids = collectObjects(tab).map((o) => o.id).filter(Boolean);
    expect(ids.indexOf('card_private_attachment_settings')).toBeGreaterThan(ids.indexOf('card_public_asset_settings'));
    expect(ids.indexOf('card_private_attachment_settings')).toBeLessThan(ids.indexOf('card_file_delegation_settings'));
  });

  /**
   * @scenario case=private_attachment_card
   * @effects s3_block_opens_for_private_attachment
   */
  it('S3 접속 블록은 스토리지·공개 자산·비공개 첨부 저장소 중 하나라도 s3 면 열린다', () => {
    const block = nodeById(tab, 's3_settings');
    expect(evalIf(block, local({ storage_driver: 'local', public_asset_disk: 'none', private_attachment_disk: 's3' }))).toBe(true);
    expect(evalIf(block, local({ storage_driver: 'local', public_asset_disk: 'none', private_attachment_disk: 'default' }))).toBe(false);
    expect(evalIf(block, local({ storage_driver: 's3', public_asset_disk: 'none', private_attachment_disk: 'default' }))).toBe(true);
  });

  /**
   * @scenario case=private_attachment_card
   * @effects same_disk_warning_only_when_equal
   */
  it('공개 자산과 같은 디스크일 때만 경고가 보인다', () => {
    const warning = nodeById(tab, 'private_attachment_same_disk_warning');
    expect(evalIf(warning, local({ private_attachment_disk: 's3', public_asset_disk: 's3' }))).toBe(true);
    expect(evalIf(warning, local({ private_attachment_disk: 'fx-cdn', public_asset_disk: 'fx-cdn' }))).toBe(true);
    expect(evalIf(warning, local({ private_attachment_disk: 's3', public_asset_disk: 'public' }))).toBe(false);
    expect(evalIf(warning, local({ private_attachment_disk: 'default', public_asset_disk: 'none' }))).toBe(false);
    expect(evalIf(warning, local({ private_attachment_disk: '', public_asset_disk: '' }))).toBe(false);
    expect(evalIf(warning, local({}))).toBe(false);
  });

  /**
   * @scenario case=private_attachment_card
   * @effects env_pins_notice_only_when_pinned
   */
  it('.env 고정 주체가 있을 때만 안내가 보이고, 옛 코어(_meta 없음)에서도 깨지지 않는다', () => {
    const pins = nodeById(tab, 'private_attachment_pins');
    expect(evalIf(pins, local({}, { private_attachment: { pins: [{ owner: 'sirsoft-board', env: 'SIRSOFT_BOARD_ATTACHMENT_DISK', disk: 's3' }] } }))).toBe(true);
    expect(evalIf(pins, local({}, { private_attachment: { pins: [] } }))).toBe(false);
    expect(evalIf(pins, local({}, {}))).toBe(false);
    expect(evalIf(pins, { form: {} })).toBe(false);
  });

  /**
   * @scenario case=private_attachment_card
   * @effects private_attachment_keys_all_locales
   */
  it('라벨·안내 키가 모든 로케일에 있다', () => {
    const keys = translationKeys(nodeById(tab, 'card_private_attachment_settings'));
    expect(keys).toEqual(expect.arrayContaining([
      'admin.settings.drivers.private_attachment.title',
      'admin.settings.drivers.private_attachment.desc',
      'admin.settings.drivers.private_attachment.disk',
      'admin.settings.drivers.private_attachment.same_disk_warning',
      'admin.settings.drivers.private_attachment.pins_title',
      'admin.settings.drivers.private_attachment.pin_core',
      'admin.settings.drivers.private_attachment.pins_hint',
    ]));
    for (const locale of localeNames()) {
      const tree = loadLocale(locale, 'admin');
      expect(keys.filter((k) => typeof langValue(tree, k) !== 'string'), `${locale} 누락 키`).toEqual([]);
    }
  });
});
