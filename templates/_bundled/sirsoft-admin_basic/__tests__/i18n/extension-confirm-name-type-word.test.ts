/**
 * @file extension-confirm-name-type-word.test.ts
 * @description 확장 확인 창 문구가 이름 뒤에 종류 단어를 다시 붙이지 않는다 (7.1.0 전수 실측 #736 O-F6)
 *
 * 확인 문구가 「{{name}} 모듈을 설치하시겠습니까?」처럼 이름 뒤에 종류 단어를 붙여, 이름에 이미 종류가 들어 있는
 * 확장(「Hello 모듈」)은 「Hello 모듈 모듈을 설치하시겠습니까?」로 보였다. 창 제목이 이미 「모듈 설치 확인」처럼
 * 종류를 말하므로 문구는 이름을 따옴표로 묶어 종류 단어 없이 쓴다. ko·en 은 템플릿 언어 파일, ja 는 동봉 언어팩.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const templateDir = path.resolve(here, '../..');
const projectRoot = (() => {
  let dir = templateDir;
  while (dir !== path.dirname(dir)) {
    if (fs.existsSync(path.join(dir, 'artisan'))) return dir;
    dir = path.dirname(dir);
  }
  throw new Error('artisan 을 찾지 못했습니다');
})();

const files: Record<string, string> = {
  ko: path.join(templateDir, 'lang/partial/ko/admin.json'),
  en: path.join(templateDir, 'lang/partial/en/admin.json'),
  ja: path.join(projectRoot, 'lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/admin.json'),
};

const sections = ['modules', 'plugins', 'templates'] as const;
const keys = ['install_confirm', 'uninstall_confirm', 'update_confirm', 'refresh_layouts_confirm', 'activate_confirm', 'deactivate_confirm'];

/** 이름 자리 바로 뒤(또는 영어는 앞뒤)에 붙은 종류 단어 */
const typeWordAfterName: Record<string, RegExp> = {
  ko: /\{\{name\}\}[」"']?\s*(모듈|플러그인|템플릿)/,
  en: /\{\{name\}\}["']?\s+(module|plugin|template)\b/i,
  ja: /\{\{name\}\}[」"']?\s*(モジュール|プラグイン|テンプレート)/,
};

/** 섹션 안의 modals 문구를 모은다(섹션 구조가 바뀌어도 키 이름으로 찾는다). */
function confirmStrings(locale: string): Array<[string, string]> {
  const json = JSON.parse(fs.readFileSync(files[locale], 'utf-8'));
  const out: Array<[string, string]> = [];
  for (const section of sections) {
    const modals = json?.[section]?.modals ?? {};
    for (const key of keys) {
      if (typeof modals[key] === 'string') out.push([`${section}.modals.${key}`, modals[key]]);
    }
  }
  return out;
}

/** 한 로케일의 확인 문구 전부가 종류 단어 중복 없이 이름 자리를 갖는지 검사한다. */
function expectNoDuplicatedTypeWord(locale: string): void {
  const entries = confirmStrings(locale);
  expect(entries.length).toBeGreaterThanOrEqual(14);
  const offenders = entries.filter(([, text]) => typeWordAfterName[locale].test(text));
  expect(offenders).toEqual([]);
  for (const [, text] of entries) expect(text).toContain('{{name}}');
}

describe('확장 확인 창 문구는 이름 뒤에 종류 단어를 다시 붙이지 않는다(「Hello 모듈 모듈을」 방지)', () => {
  /**
   * @scenario locale=ko
   * @effects confirm_message_has_no_duplicated_type_word
   */
  it('ko', () => expectNoDuplicatedTypeWord('ko'));

  /**
   * @scenario locale=en
   * @effects confirm_message_has_no_duplicated_type_word
   */
  it('en', () => expectNoDuplicatedTypeWord('en'));

  /**
   * @scenario locale=ja
   * @effects confirm_message_has_no_duplicated_type_word
   */
  it('ja', () => expectNoDuplicatedTypeWord('ja'));
});

/**
 * 업데이트 창 코어 호환 경고(#736 O-F9) — `{{required}}` 에는 「>=7.2.0」 같은 제약식이 그대로 들어오므로
 * 「이상」·「or higher」·「以上」을 덧붙이면 「>=7.2.0 이상」처럼 겹친다. 종류 단어 뒤 띄어 쓴 조사(「모듈 은(는)」)도 없앤다.
 */
const extensionFiles: Record<string, string> = {
  ko: path.join(templateDir, 'lang/partial/ko/extensions.json'),
  en: path.join(templateDir, 'lang/partial/en/extensions.json'),
  ja: path.join(projectRoot, 'lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/extensions.json'),
};

/** 한 로케일의 코어 호환 경고 문구가 제약식과 겹치는 말을 붙이지 않는지 검사한다. */
function expectCompatWarningReadsWithConstraint(locale: string): void {
  const json = JSON.parse(fs.readFileSync(extensionFiles[locale], 'utf-8'));
  const text: string = json?.update_modal?.compat_warning_message;
  expect(typeof text).toBe('string');
  expect(text).toContain('{{required}}');
  expect(text).toContain('{{installed}}');
  expect(text).not.toMatch(/\{\{required\}\}\s*(이상|or higher|以上)/);
  expect(text).not.toMatch(/\{\{type\}\}\s+은\(는\)/);
}

describe('업데이트 창 코어 호환 경고는 제약식과 겹치는 말을 붙이지 않는다(「>=7.2.0 이상」 방지)', () => {
  /**
   * @scenario locale=ko
   * @effects compat_warning_has_no_duplicated_operator_word
   */
  it('ko', () => expectCompatWarningReadsWithConstraint('ko'));

  /**
   * @scenario locale=en
   * @effects compat_warning_has_no_duplicated_operator_word
   */
  it('en', () => expectCompatWarningReadsWithConstraint('en'));

  /**
   * @scenario locale=ja
   * @effects compat_warning_has_no_duplicated_operator_word
   */
  it('ja', () => expectCompatWarningReadsWithConstraint('ja'));
});
