/**
 * @file changelog-item-plain-text.test.ts
 * @description 변경 기록 항목의 인라인 마크다운 표기 (7.2.1 출시 전 실측 W5-002)
 *
 * CHANGELOG 항목은 `**강조**`·`` `코드` `` 표기를 쓴다. 업데이트 창·상세 창·코어 변경 기록 창은 항목을 글자로 찍어
 * 별표·백틱이 그대로 보였다(쇼핑몰 1.4.1 「**업데이트 안내**」 경고). 이 화면들은 표기 기호를 빼고 글자만 보인다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';

const layoutsDir = path.resolve(__dirname, '../../layouts/partials');
const files = [
  'admin_module_list/_modal_update.json',
  'admin_plugin_list/_modal_update.json',
  'admin_template_list/_modal_update.json',
  'admin_module_list/_modal_detail.json',
  'admin_plugin_list/_modal_detail.json',
  'admin_template_list/_modal_detail.json',
  'admin_language_pack_list/_modal_detail.json',
  'admin_settings/_modal_core_changelog.json',
];

function collect(node: any, match: (n: any) => boolean, out: any[] = []): any[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => collect(n, match, out));
    return out;
  }
  if (match(node)) out.push(node);
  Object.values(node).forEach((v) => collect(v, match, out));
  return out;
}

describe('변경 기록 항목 글자 (W5-002)', () => {
  // @scenario case=changelog_item_plain_text
  // @effects changelog_item_markdown_markers_hidden
  it.each(files)('%s 의 항목 글자는 ** 와 백틱을 빼고 보인다', (file) => {
    const layout = JSON.parse(fs.readFileSync(path.join(layoutsDir, file), 'utf-8'));
    const texts = collect(layout, (n) => typeof n.text === 'string' && n.text.includes('changeItem'));
    expect(texts.length, `${file}: 항목 글자 노드가 없습니다`).toBeGreaterThan(0);
    for (const node of texts) {
      const rendered = String(node.text).replace(/\{\{([\s\S]+?)\}\}/g, (_m, expr) =>
        String(dataBindingEngine.evaluateExpression(expr, { changeItem: '**업데이트 안내**: `AU-` 로 시작' } as any) ?? ''),
      );
      expect(rendered).toContain('업데이트 안내: AU- 로 시작');
      expect(rendered).not.toMatch(/\*\*|`/);
    }
  });
});
