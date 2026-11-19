/**
 * @file coreUpdateCustomPreflight.test.ts
 * @description 코어 업데이트 확인 결과 창의 「개발자 패키지 충돌로 업데이트를 시작하지 않았습니다」 영역 계약 (#792 WP-K3)
 *
 * 잠그는 것: 영역이 확인 API 의 `custom_preflight` 가 있을 때만 보이는 것, 충돌 행이 `conflicts` 배열을 반복하는 것,
 * 충돌 종류(duplicate / constraint)에 따라 문구가 갈리는 것, 배포본 예약 자리 파일 목록, 쓰는 번역 키가 모든 로케일에 있는 것.
 */

import { describe, it, expect } from 'vitest';
import { evaluateSafeExpression } from '@core/template-engine/SafeExpressionEvaluator';
import { loadLayout, localeNames, loadLocale, langValue, nodeById, collectObjects, translationKeys } from './analyticsLayoutTestUtils';

const modal = loadLayout('partials/admin_settings/_modal_core_update_result.json');

describe('코어 업데이트 확인 결과 창 — custom 사전 검사 중단 영역', () => {
  /**
   * @scenario case=preflight_modal
   * @effects preflight_section_conditional
   */
  it('영역은 custom_preflight 기록이 있을 때만 보인다', () => {
    const section = nodeById(modal, 'custom_preflight_section');
    expect(section, '영역 노드').toBeTruthy();
    const cond = String(section?.if ?? '').replace(/^\{\{|\}\}$/g, '').replace(/\$parent\._local/g, 'parentLocal');

    expect(evaluateSafeExpression(cond, { parentLocal: { coreUpdateInfo: { custom_preflight: null } } })).toBe(false);
    expect(evaluateSafeExpression(cond, { parentLocal: { coreUpdateInfo: { custom_preflight: { conflicts: [] } } } })).toBe(true);
    expect(evaluateSafeExpression(cond, { parentLocal: {} })).toBe(false);

    // 「개발자 패키지 N개가 맞지 않습니다」 안내는 충돌이 있을 때만 — 예약 자리 파일만으로 멈췄으면 0개 문장을 쓰지 않는다
    const desc = collectObjects(section).find((o) => typeof o.text === 'string' && String(o.text).startsWith('$t:admin.settings.info.custom_preflight_desc'));
    const descCond = String(desc?.if ?? '').replace(/^\{\{|\}\}$/g, '').replace(/\$parent\._local/g, 'parentLocal');
    expect(evaluateSafeExpression(descCond, { parentLocal: { coreUpdateInfo: { custom_preflight: { conflicts: [], reserved_files: ['routes/custom.php'] } } } })).toBe(false);
    expect(evaluateSafeExpression(descCond, { parentLocal: { coreUpdateInfo: { custom_preflight: { conflicts: [{ package: 'a/b' }] } } } })).toBe(true);
  });

  /**
   * @scenario case=preflight_modal
   * @effects preflight_conflict_rows
   */
  it('충돌 행은 conflicts 배열을 반복하고 종류에 따라 문구가 갈린다', () => {
    const rows = collectObjects(modal).filter((o) => o.iteration?.item_var === 'conflict');
    expect(rows).toHaveLength(1);
    expect(rows[0].iteration.source).toContain('custom_preflight?.conflicts');

    const texts = collectObjects(rows[0]).filter((o) => typeof o.text === 'string' && String(o.text).startsWith('$t:admin.settings.info.custom_conflict_'));
    const duplicate = texts.find((o) => o.text === '$t:admin.settings.info.custom_conflict_duplicate');
    const constraint = texts.find((o) => String(o.text).startsWith('$t:admin.settings.info.custom_conflict_constraint|'));
    expect(duplicate?.if).toContain("conflict.type === 'duplicate'");
    expect(constraint?.if).toContain("conflict.type !== 'duplicate'");
    expect(constraint?.text).toContain('requires={{conflict.requires}}');
    expect(constraint?.text).toContain('constraint={{conflict.constraint}}');

    const files = collectObjects(modal).filter((o) => o.iteration?.item_var === 'reservedFile');
    expect(files).toHaveLength(1);
    expect(files[0].iteration.source).toContain('custom_preflight?.reserved_files');
  });

  /**
   * @scenario case=preflight_modal
   * @effects preflight_keys_all_locales
   */
  it('영역이 쓰는 번역 키가 모든 로케일에 있다', () => {
    const section = nodeById(modal, 'custom_preflight_section');
    const keys = translationKeys(section);
    expect(keys.length).toBeGreaterThan(10);
    for (const locale of localeNames()) {
      const tree = loadLocale(locale, 'admin');
      const missing = keys.filter((k) => typeof langValue(tree, k) !== 'string');
      expect(missing, `${locale} 누락 키`).toEqual([]);
    }
  });
});
