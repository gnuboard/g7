import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * 보기 방식 저장 키가 GDPR 필수 저장 허용목록에 등재돼 있는지 확인한다.
 *
 * 이 가드가 여기 있는 이유가 있다. GDPR 쪽 커버리지 테스트
 * (`necessaryAllowlistCoverage.test.ts`)는 소스의 `.setItem()` 호출을 기계적으로 훑어
 * 카탈로그와 대조하는데, **레이아웃의 저장 키는 `.setItem()` 으로 쓰이지 않는다** —
 * 엔진 액션(`saveToLocalStorage`)이 대신 쓰므로 그 스캔에 잡히지 않는다.
 *
 * 등재가 빠지면 functional 미동의 사이트에서 보기 선택이 부팅 때마다 파기되어,
 * 바꿔도 새로고침하면 갤러리로 되돌아간다(#640 화면 테마와 같은 함정).
 * 오류를 남기지 않고 "설정이 안 먹는다" 는 증상으로만 나타나므로 여기서 잠근다.
 *
 * @scenario screen=plugins, view=list
 *
 * @effects gdpr_allowlist_contains_key
 */

const REPO_ROOT = resolve(__dirname, '../../../../..');
const GDPR_PLUGIN = resolve(REPO_ROOT, 'plugins/_bundled/sirsoft-gdpr/plugin.php');
const LAYOUT = resolve(__dirname, '../../layouts/admin_plugin_list.json');

/** 레이아웃이 실제로 쓰는 저장 키 — 카탈로그 패턴이 이 키를 덮어야 한다. */
const STORAGE_KEY = 'g7_view_mode_extensions';

/** 카탈로그에 등재된 접두사 패턴. */
const ALLOWLIST_PATTERN = 'g7_view_mode_*';

describe('보기 방식 저장 키 — GDPR 허용목록 등재', () => {
  // @scenario screen=plugins, view=list
  // @effects gdpr_allowlist_contains_key
  it('레이아웃이 정적 리터럴 키로 저장한다', () => {
    const layout = readFileSync(LAYOUT, 'utf8');

    // 키를 표현식으로 조립하면 어떤 정적 검사도 등재 여부를 판정할 수 없다.
    expect(layout).toContain(`"key": "${STORAGE_KEY}"`);
    expect(layout).not.toMatch(/"key":\s*"\{\{[^"]*view_mode/);
  });

  // @scenario screen=plugins, view=list
  // @effects gdpr_allowlist_contains_key
  it('출하 카탈로그가 그 키를 덮는 패턴을 갖는다', () => {
    const plugin = readFileSync(GDPR_PLUGIN, 'utf8');

    expect(
      plugin.includes(`'${ALLOWLIST_PATTERN}'`),
      `sirsoft-gdpr 카탈로그에 ${ALLOWLIST_PATTERN} 이 없습니다 — 보기 선택이 새로고침마다 사라집니다`,
    ).toBe(true);
  });

  // @scenario screen=plugins, view=list
  // @effects gdpr_allowlist_contains_key
  it('등재 패턴이 실제 저장 키를 덮는다', () => {
    const prefix = ALLOWLIST_PATTERN.replace(/\*$/, '');

    expect(
      STORAGE_KEY.startsWith(prefix),
      `${ALLOWLIST_PATTERN} 는 ${STORAGE_KEY} 를 덮지 못합니다`,
    ).toBe(true);
  });

  // @scenario screen=plugins, view=list
  // @effects gdpr_allowlist_contains_key
  it('기설치본 백필 스텝이 같은 패턴을 쓴다', () => {
    const migration = readFileSync(
      resolve(REPO_ROOT, 'plugins/_bundled/sirsoft-gdpr/upgrades/data/1.1.0/migrations/01_AppendViewModeToNecessaryAllowlist.php'),
      'utf8',
    );

    // 스텝과 카탈로그가 다른 문자열을 쓰면 신규 설치와 기설치본의 목록이 갈린다.
    expect(migration).toContain(`'${ALLOWLIST_PATTERN}'`);
  });
});
