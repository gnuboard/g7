/**
 * Hello 사용자 템플릿 — 503 화면의 미충족 의존 목록.
 *
 * 이 템플릿은 의존을 선언할 수 있는데 503 화면이 서버가 넘긴 미충족 목록(`_global.errorData.modules/plugins`)을 그리지 않아,
 * 방문자·운영자가 왜 화면이 멈췄는지 알 수 없었다(코어 7.1.4, #152). sirsoft-basic 과 같은 목록·사유 블록을 둔다.
 *
 * @scenario-manifest tests/scenarios/error-503-dependency-list.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const root = path.resolve(__dirname, '../..');
const layout = JSON.parse(fs.readFileSync(path.join(root, 'layouts', 'errors', '503.json'), 'utf8'));
const ko = JSON.parse(fs.readFileSync(path.join(root, 'lang', 'ko.json'), 'utf8'));
const en = JSON.parse(fs.readFileSync(path.join(root, 'lang', 'en.json'), 'utf8'));

/** 레이아웃 JSON 안의 모든 노드를 모은다. */
function nodes(tree: unknown): Array<Record<string, unknown>> {
  const out: Array<Record<string, unknown>> = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) n.forEach(walk);
    else if (n && typeof n === 'object') {
      out.push(n as Record<string, unknown>);
      Object.values(n).forEach(walk);
    }
  };
  walk(tree);
  return out;
}

describe('[case:error-503-dependency-list] Hello 503 화면 의존 목록', () => {
  /**
   * @scenario surface=list
   * @effects error_503_lists_unmet_dependencies
   */
  it('모듈·플러그인 목록을 errorData 로 반복하고 항목마다 사유 3종을 status 로 고른다', () => {
    const all = nodes(layout);
    const iterated = all.map((n) => (n.iteration as { source?: string } | undefined)?.source ?? '').join('\n');
    expect(iterated).toContain('_global.errorData?.modules');
    expect(iterated).toContain('_global.errorData?.plugins');

    for (const v of ['mod', 'plug']) {
      for (const [status, key] of [['version_mismatch', 'reason_version_mismatch'], ['inactive', 'reason_inactive'], ['not_installed', 'reason_not_installed']]) {
        const hit = all.find((n) => n.if === `{{${v}.status === '${status}'}}`);
        expect(hit, `${v} ${status}`).toBeDefined();
        expect(JSON.stringify(hit)).toContain(`$t:error.503.${key}`);
      }
    }
  });

  /**
   * @scenario surface=lang_keys
   * @effects error_503_lists_unmet_dependencies
   */
  it('목록·사유 키가 ko·en 에 있다', () => {
    for (const lang of [ko, en]) {
      for (const key of ['unmet_dependencies', 'required_modules', 'required_plugins', 'contact_admin', 'reason_not_installed', 'reason_inactive', 'reason_version_mismatch']) {
        expect(typeof lang.error['503'][key], key).toBe('string');
      }
    }
  });
});
