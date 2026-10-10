/**
 * @file localized-values-and-particles.test.ts
 * @description 한국어·일본어 화면 문구가 영어 원문을 그대로 쓰거나 값 뒤에 틀린 조사를 붙이지 않는다 (7.1.0 전수 실측 #736 O-J3·O-H4)
 *
 * - 환경설정 「정보」 탭 데이터베이스 구성 타입이 ko·ja 에서도 「Write Only」 영어로 보였다(O-J3).
 * - 언어팩 업데이트 창 「최신 버전 {{latest}}로」 — 버전 번호는 끝 글자에 따라 「로/으로」가 갈려
 *   「1.2.3로」「1.2.0로」처럼 틀린 조사가 나온다(O-H4 조사 전수). 값 뒤에 조사를 직접 붙이지 않는다.
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

const read = (file: string) => JSON.parse(fs.readFileSync(file, 'utf-8'));
const ko = read(path.join(templateDir, 'lang/partial/ko/admin.json'));
const en = read(path.join(templateDir, 'lang/partial/en/admin.json'));
const ja = read(path.join(projectRoot, 'lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/admin.json'));

/** 점 경로로 값을 꺼낸다. */
const pick = (obj: any, dotted: string) => dotted.split('.').reduce((o, k) => (o == null ? o : o[k]), obj);

describe('정보 탭 데이터베이스 구성 타입', () => {
  /**
   * 정보 탭 「Write Only」 값은 ko·ja 에서 번역된다(7.1.0 잔여 관측 R-14).
   *
   * @scenario surface=admin_template_lang
   * @effects write_only_value_is_translated
   */
  it('ko·ja 는 영어 원문을 그대로 쓰지 않는다', () => {
    const key = 'settings.info.write_only';
    expect(pick(en, key)).toBe('Write Only');
    expect(pick(ko, key)).not.toBe(pick(en, key));
    expect(pick(ja, key)).not.toBe(pick(en, key));
  });
});

describe('값 뒤 조사', () => {
  /**
   * 관리자 템플릿 ko 문구에 값 바로 뒤 한 꼴 조사가 없다.
   *
   * @scenario surface=admin_template_lang
   * @effects placeholder_is_not_followed_by_a_single_form_particle
   */
  it('ko 문구는 {{값}} 바로 뒤에 받침 의존 조사를 한 꼴로 붙이지 않는다', () => {
    const offenders: string[] = [];
    const walk = (node: any, trail: string) => {
      if (typeof node === 'string') {
        if (/\{\{[^}]+\}\}(을|를|이|가|은|는|와|과|으로|로)(?![가-힣(])/u.test(node)) offenders.push(`${trail}: ${node}`);
        return;
      }
      if (node && typeof node === 'object') for (const [k, v] of Object.entries(node)) walk(v, trail ? `${trail}.${k}` : k);
    };
    walk(ko, '');
    expect(offenders).toEqual([]);
  });
});
