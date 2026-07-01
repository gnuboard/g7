/**
 * plugin_settings.json 레이아웃의 setState spread 회귀 차단.
 *
 * 회귀 배경 (케이스 05):
 *  setState 액션의 form 페이로드 내부에 `"...": "{{_local.form}}"` 스프레드 키를
 *  넣으면 ActionDispatcher 의 깊은 병합이 1차 키만 펼치므로 form 안의 `"..."` 가
 *  풀리지 않은 채 SSoT 파일에 직렬화됨.
 *
 * 본 테스트는 레이아웃 JSON 전체를 재귀 탐색해 모든 setState action params
 * 안에 `"..."` 키가 잔존하지 않음을 보장한다.
 */
import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

type AnyNode = Record<string, any>;

function walk(node: AnyNode, visit: (n: AnyNode) => void): void {
  if (node === null || typeof node !== 'object') return;
  visit(node);
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) {
      for (const item of value) walk(item as AnyNode, visit);
    } else if (value && typeof value === 'object') {
      walk(value as AnyNode, visit);
    }
  }
}

describe('plugin_settings.json — setState spread 회귀 차단', () => {
  const layoutPath = path.resolve(
    __dirname,
    '../../layouts/admin/plugin_settings.json',
  );

  const layout = JSON.parse(fs.readFileSync(layoutPath, 'utf-8'));

  it('모든 setState action.params 의 form 객체에 `"..."` 키가 없음', () => {
    const offenders: { path: string; keys: string[] }[] = [];

    walk(layout, (n) => {
      if (n && n.handler === 'setState' && n.params && typeof n.params === 'object') {
        const params = n.params as AnyNode;
        // 1차 key 와 nested form 모두 검사
        if ('...' in params) {
          offenders.push({ path: 'params', keys: Object.keys(params) });
        }
        if (params.form && typeof params.form === 'object' && '...' in params.form) {
          offenders.push({ path: 'params.form', keys: Object.keys(params.form) });
        }
      }
    });

    expect(offenders).toEqual([]);
  });

  it('setState target=local 의 form 객체는 변경 키만 명시 (깊은 병합 위임)', () => {
    const formPayloads: { keys: string[] }[] = [];

    walk(layout, (n) => {
      if (
        n &&
        n.handler === 'setState' &&
        n.params &&
        typeof n.params === 'object' &&
        (n.params as AnyNode).target === 'local' &&
        (n.params as AnyNode).form &&
        typeof (n.params as AnyNode).form === 'object'
      ) {
        formPayloads.push({ keys: Object.keys((n.params as AnyNode).form) });
      }
    });

    // 최소 1개 이상의 form payload 가 존재해야 함 (라디오 토글 등)
    expect(formPayloads.length).toBeGreaterThan(0);

    // 모든 form payload 가 1~3개의 좁은 키만 갖고 있어야 함 (전체 스냅샷 push 회귀 차단)
    for (const payload of formPayloads) {
      expect(payload.keys.length).toBeGreaterThan(0);
      expect(payload.keys.length).toBeLessThanOrEqual(3);
      expect(payload.keys).not.toContain('...');
    }
  });
});