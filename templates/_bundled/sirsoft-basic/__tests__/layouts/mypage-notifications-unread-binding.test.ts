/**
 * 마이페이지 알림 화면의 미읽음 건수 바인딩 회귀 (#492 6차 브라우저 실측)
 *
 * "읽지 않은 알림 N건" 라벨이 목록 데이터소스(`userNotifications`)를 참조하고 있어
 * 항상 0 으로 표시됐다. 목록 응답에는 `unread_count` 가 없고, 그 값은 별도 데이터소스
 * `notification_unread_count`(`/api/user/notifications/unread-count`) 가 가져온다.
 *
 * 같은 화면의 헤더 벨은 올바른 데이터소스를 써서 정확한 값을 보여주고 있었으므로,
 * 두 표시가 어긋나는 것이 결함의 신호였다(실측: 벨 7 · API 7 · 페이지 라벨 0).
 *
 * 브라우저로 잡으려면 미읽음이 1건 이상인 계정이 필요해 환경 의존이 크다.
 * 바인딩 경로 자체는 레이아웃 선언이므로 여기서 정적으로 고정한다.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const templateRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/**
 * 레이아웃 JSON 을 읽어 파싱합니다.
 *
 * @param relativePath 템플릿 루트 기준 상대 경로
 * @returns 파싱된 레이아웃 객체
 */
function readLayout(relativePath: string): unknown {
  return JSON.parse(readFileSync(resolve(templateRoot, relativePath), 'utf-8'));
}

/**
 * 객체 트리에서 주어진 정규식과 일치하는 문자열을 모두 수집합니다.
 *
 * @param node 순회할 노드
 * @param pattern 찾을 패턴
 * @param out 누적 배열
 * @returns 수집된 문자열 배열
 */
function collectStrings(node: unknown, pattern: RegExp, out: string[] = []): string[] {
  if (typeof node === 'string') {
    if (pattern.test(node)) out.push(node);

    return out;
  }
  if (Array.isArray(node)) {
    for (const item of node) collectStrings(item, pattern, out);

    return out;
  }
  if (node && typeof node === 'object') {
    for (const value of Object.values(node)) collectStrings(value, pattern, out);
  }

  return out;
}

describe('마이페이지 알림 — 미읽음 건수 바인딩', () => {
  const listPartial = readLayout('layouts/partials/mypage/notifications/_list.json');
  const baseLayout = readLayout('layouts/_user_base.json');

  it('미읽음 건수 라벨은 전용 데이터소스를 참조한다', () => {
    const bindings = collectStrings(listPartial, /unread_count/);

    expect(bindings.length, 'unread_count 바인딩을 찾지 못했습니다').toBeGreaterThan(0);

    for (const binding of bindings) {
      expect(
        binding,
        '미읽음 건수를 목록 데이터소스에서 읽고 있습니다 — 목록 응답에는 unread_count 가 없어 항상 0 이 됩니다'
      ).not.toMatch(/userNotifications[?.]*\.?data\??\.unread_count/);
      expect(binding).toContain('notification_unread_count');
    }
  });

  it('참조하는 데이터소스가 베이스 레이아웃에 실제로 선언되어 있다', () => {
    const sources = (baseLayout as { data_sources?: Array<{ id?: string; endpoint?: string }> })
      .data_sources ?? [];
    const target = sources.find((source) => source.id === 'notification_unread_count');

    expect(target, 'notification_unread_count 데이터소스 선언이 없습니다').toBeDefined();
    expect(target?.endpoint).toContain('/api/user/notifications/unread-count');
  });
});
