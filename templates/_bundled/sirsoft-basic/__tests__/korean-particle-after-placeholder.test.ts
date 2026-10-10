/**
 * @file korean-particle-after-placeholder.test.ts
 * @description 한국어 문구가 {{값}} 바로 뒤에 받침에 따라 갈리는 조사를 한 꼴로 붙이지 않는다 (7.1.0 전수 실측 #736 O-H4 조사 전수)
 *
 * 소셜 로그인 버튼 「{{provider}}로 로그인」 은 이름 끝 글자에 따라 「Facebook로」「Apple로」처럼 틀린 조사가 나온다.
 * 값 뒤에는 조사를 직접 붙이지 않고 「계정으로」처럼 고정 낱말을 사이에 둔다.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const koDir = path.resolve(here, '../lang/partial/ko');

describe('ko 조사', () => {
  /**
   * 사용자 템플릿 ko 문구에 값 바로 뒤 한 꼴 조사가 없다.
   *
   * @scenario surface=user_template_lang
   * @effects placeholder_is_not_followed_by_a_single_form_particle
   */
  it('{{값}} 바로 뒤에 받침 의존 조사를 한 꼴로 붙인 문구가 없다', () => {
    const offenders: string[] = [];
    const walk = (node: unknown, trail: string) => {
      if (typeof node === 'string') {
        if (/\{\{[^}]+\}\}(을|를|이|가|은|는|와|과|으로|로)(?![가-힣(])/u.test(node)) offenders.push(`${trail}: ${node}`);
        return;
      }
      if (node && typeof node === 'object') for (const [k, v] of Object.entries(node)) walk(v, trail ? `${trail}.${k}` : k);
    };

    for (const file of fs.readdirSync(koDir).filter((f) => f.endsWith('.json'))) {
      walk(JSON.parse(fs.readFileSync(path.join(koDir, file), 'utf-8')), file.replace(/\.json$/, ''));
    }

    expect(offenders).toEqual([]);
  });
});
