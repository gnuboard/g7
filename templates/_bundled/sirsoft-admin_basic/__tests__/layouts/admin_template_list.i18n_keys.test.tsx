/**
 * @file admin_template_list.i18n_keys.test.tsx
 * @description 회귀: admin 템플릿의 frontend lang 파일에 extensions.* 키 존재 검증
 *
 * 이슈: 레이아웃에서 $t:extensions.banner.title 등을 참조하지만 frontend lang 파티션
 * (templates/_bundled/sirsoft-admin_basic/lang/) 에는 extensions 네임스페이스가 부재 →
 * 화면에 raw key 가 노출됨 (백엔드 lang/{ko,en}/extensions.php 는 PHP 전용,
 * frontend 에서 참조 불가).
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const TEMPLATE_LANG_ROOT = path.resolve(
  __dirname,
  '../../lang',
);

function loadJson(file: string): any {
  return JSON.parse(fs.readFileSync(file, 'utf-8'));
}

describe('admin 템플릿 frontend lang — extensions.* 키 회귀', () => {
  for (const locale of ['ko', 'en']) {
    describe(`locale=${locale}`, () => {
      it(`${locale}.json 에 extensions $partial 항목 존재`, () => {
        const root = loadJson(path.join(TEMPLATE_LANG_ROOT, `${locale}.json`));
        expect(root.extensions).toBeDefined();
        expect(root.extensions.$partial).toBe(`partial/${locale}/extensions.json`);
      });

      it(`partial/${locale}/extensions.json 파일 존재 + 레이아웃 참조 키 모두 정의`, () => {
        const file = path.join(TEMPLATE_LANG_ROOT, 'partial', locale, 'extensions.json');
        expect(fs.existsSync(file)).toBe(true);

        const data = loadJson(file);

        // 레이아웃이 참조하는 키 목록 (grep 으로 추출)
        expect(data.banner?.title).toBeTypeOf('string');
        expect(data.banner?.item_required).toBeTypeOf('string');
        expect(data.banner?.guide_link).toBeTypeOf('string');
        expect(data.banner?.dismiss).toBeTypeOf('string');

        expect(data.badges?.incompatible).toBeTypeOf('string');
        expect(data.badges?.incompatible_tooltip).toBeTypeOf('string');
        expect(data.badges?.incompatible_sr).toBeTypeOf('string');

        expect(data.update_modal?.compat_warning_title).toBeTypeOf('string');
        expect(data.update_modal?.compat_warning_message).toBeTypeOf('string');
        expect(data.update_modal?.compat_guide_link).toBeTypeOf('string');
        expect(data.update_modal?.force_label).toBeTypeOf('string');

        expect(data.alerts?.recover_action).toBeTypeOf('string');
        expect(data.alerts?.dismiss_action).toBeTypeOf('string');
        expect(data.alerts?.recovered_success).toBeTypeOf('string');

        expect(data.types?.module).toBeTypeOf('string');
        expect(data.types?.plugin).toBeTypeOf('string');
        expect(data.types?.template).toBeTypeOf('string');
      });
    });
  }
});
