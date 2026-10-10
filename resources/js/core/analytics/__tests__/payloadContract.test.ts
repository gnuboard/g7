// e2e:allow 단위 계약 테스트 파일만 더했다 — 제품 코드·화면 변경 없음(트래커 동작은 analytics-collect-e2e.spec.ts 가 잰다)
/**
 * 비콘 계약 — 트래커가 싣는 키·길이 상한이 서버 수집 검증 규칙과 같은지.
 *
 * 두 쪽은 따로 적혀 있다. 트래커에 키를 더하고 서버 규칙을 빠뜨리면 그 값은 검증을 지나며 조용히
 * 사라지고, 서버 상한을 줄이고 트래커를 두면 긴 필드 하나 때문에 비콘 전체가 버려진다. 두 원본을
 * 파일에서 직접 읽어 대조한다.
 *
 * @scenario source=beacon, exclusion=none
 * @effects beacon_keys_and_limits_match_the_server_rules
 */
import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const ROOT = path.resolve(__dirname, '../../../../..');
const tracker = fs.readFileSync(path.join(ROOT, 'resources/js/core/analytics/Tracker.ts'), 'utf8');
const request = fs.readFileSync(path.join(ROOT, 'app/Http/Requests/Public/Analytics/CollectRequest.php'), 'utf8');

/** `export interface BeaconPayload { … }` 의 키와 필수 여부 */
function beaconKeys(): Map<string, boolean> {
  const body = /export interface BeaconPayload \{([\s\S]*?)\n\}/.exec(tracker)?.[1] ?? '';
  const keys = new Map<string, boolean>();
  for (const m of body.matchAll(/^\s{2}([a-z]+)(\??):/gm)) keys.set(m[1], m[2] !== '?');
  return keys;
}

/** `CollectRequest::rules()` 의 최상위 키와 규칙 문자열 */
function serverRules(): Map<string, string> {
  const rules = new Map<string, string>();
  for (const m of request.matchAll(/^\s{12}'([a-z]+)' => \[([^\n]*)\],$/gm)) rules.set(m[1], m[2]);
  return rules;
}

describe('비콘 계약', () => {
  // @scenario source=beacon, exclusion=none
  // @effects beacon_keys_and_limits_match_the_server_rules
  it('트래커 페이로드 키와 서버 규칙 키가 같고, 필수 여부도 같다', () => {
    const keys = beaconKeys();
    const rules = serverRules();
    expect(keys.size, '트래커 페이로드 키').toBeGreaterThanOrEqual(10);
    expect(rules.size, '서버 규칙 키').toBeGreaterThanOrEqual(10);

    expect([...keys.keys()].sort()).toEqual([...rules.keys()].sort());
    for (const [key, required] of keys) {
      expect((rules.get(key) ?? '').includes("'required'"), `${key} 의 필수 여부`).toBe(required);
    }
  });

  // @scenario source=beacon, exclusion=none
  // @effects beacon_keys_and_limits_match_the_server_rules
  it('트래커가 맞추는 길이 상한이 서버 규칙의 max 와 같다', () => {
    const limits = /const SERVER_LIMITS = \{([\s\S]*?)\}/.exec(tracker)?.[1] ?? '';
    const client = Object.fromEntries([...limits.matchAll(/(\w+): (\d+)/g)].map((m) => [m[1], Number(m[2])]));
    expect(Object.keys(client).length, '트래커 상한 표').toBeGreaterThanOrEqual(5);

    const max = (key: string): number => Number(/'max:(\d+)'/.exec(serverRules().get(key) ?? '')?.[1]);
    for (const key of ['url', 'title', 'ref', 'target', 'name', 'dims']) {
      expect(client[key], `${key} 상한`).toBe(max(key));
    }
    const dimValueMax = Number(/'dims\.\*' => \[[^\]]*'max:(\d+)'/.exec(request)?.[1]);
    expect(client.dimValue, '차원 값 상한').toBe(dimValueMax);
  });
});
