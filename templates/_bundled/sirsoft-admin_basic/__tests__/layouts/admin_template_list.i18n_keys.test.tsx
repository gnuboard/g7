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
      // @scenario screen=templates_user, icon_state=present
      // @effects icon_rendered_from_icon_url
      it(`${locale}.json 에 extensions $partial 항목 존재`, () => {
        const root = loadJson(path.join(TEMPLATE_LANG_ROOT, `${locale}.json`));
        expect(root.extensions).toBeDefined();
        expect(root.extensions.$partial).toBe(`partial/${locale}/extensions.json`);
      });

      // @scenario screen=templates_user, icon_state=present
      // @effects icon_rendered_from_icon_url
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

/**
 * 위 블록은 키를 손으로 적는다 — 그래서 확장 아이콘·보기 전환이 들여온 신규 키 11종이
 * 가드 없이 들어왔다(라운드 3 판정). 참조 목록을 레이아웃에서 파생해 같은 누락을 막는다.
 *
 * 레이아웃·컴포넌트가 실제로 부르는 `$t:extensions.*` 를 모아 ko·en 양쪽에서 정의를 확인한다.
 * 키가 하나 늘면 케이스도 같이 늘고, 정의를 지우면 red 가 된다.
 */
describe('admin 템플릿 frontend lang — extensions.* 참조 키를 저장소에서 파생해 대조', () => {
  const SOURCE_ROOTS = [
    path.resolve(__dirname, '../../layouts'),
    path.resolve(__dirname, '../../src'),
  ];

  /** 디렉터리를 재귀로 훑어 텍스트 파일 경로를 모은다. */
  function walk(dir: string, out: string[] = []): string[] {
    if (!fs.existsSync(dir)) return out;
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) walk(full, out);
      else if (/\.(g7\.html|json|tsx|ts)$/.test(entry.name)) out.push(full);
    }
    return out;
  }

  const referenced = new Set<string>();
  for (const root of SOURCE_ROOTS) {
    for (const file of walk(root)) {
      const text = fs.readFileSync(file, 'utf-8');
      for (const m of text.matchAll(/\$t:(?:defer:)?extensions\.([a-zA-Z0-9_.]+)/g)) {
        // 보간 뒤 파라미터(`|name=…`)는 정규식이 이미 잘라낸다. 끝의 점은 문장부호다.
        referenced.add(m[1].replace(/\.$/, ''));
      }
    }
  }

  const REFERENCED_KEYS = [...referenced].sort();

  // @scenario screen=templates_user, icon_state=present
  // @effects icon_rendered_from_icon_url
  it('참조 키 모집단이 비어 있지 않다', () => {
    // 0 이면 정규식이 깨졌거나 경로가 바뀐 것이다 — 그 상태로는 아래 케이스가 전부 통과한다.
    expect(REFERENCED_KEYS.length).toBeGreaterThanOrEqual(20);
  });

  for (const locale of ['ko', 'en']) {
    // @scenario screen=templates_user, icon_state=present
    // @effects icon_rendered_from_icon_url
    it.each(REFERENCED_KEYS)(`${locale}: extensions.%s 가 정의돼 있다`, (key) => {
      const data = loadJson(
        path.join(TEMPLATE_LANG_ROOT, 'partial', locale, 'extensions.json'),
      );
      const value = key.split('.').reduce<any>((node, part) => node?.[part], data);

      expect(value, `${locale} 에 extensions.${key} 정의가 없습니다`).toBeTypeOf('string');
    });
  }
});
