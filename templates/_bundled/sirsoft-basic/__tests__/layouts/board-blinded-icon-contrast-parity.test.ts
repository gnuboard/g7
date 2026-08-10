import { describe, it, expect } from 'vitest';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * 게시판 스킨 3종의 블라인드 아이콘 색 패리티 (#519)
 *
 * 블라인드 아이콘은 흰 배경 위에서 배경과 구분되어야 한다. `orange-300` 은 라이트 배경
 * 대비가 1.63 에 그쳐 사실상 보이지 않는다(WCAG 비텍스트 최소 3.0). 이 색을 `orange-500`
 * 으로 올리는 수정이 일반·카드 스킨에는 들어갔는데 갤러리만 빠져 있었다 — 같은 의미의
 * 아이콘이 스킨에 따라 보이기도 하고 안 보이기도 했다.
 *
 * 특정 스킨을 열거하면 스킨이 늘어날 때 또 빠지므로 **디렉토리를 훑어 전수로 강제한다**.
 */
describe('게시판 스킨 — 블라인드 아이콘 색 패리티', () => {
  const typesDir = resolve(
    dirname(fileURLToPath(import.meta.url)),
    '../../layouts/partials/board/types'
  );

  // 스킨을 열거하지 않고 디렉토리에서 도출한다. 열거하면 스킨이 늘어날 때 그 스킨만
  // 조용히 검사 밖에 남아, 처음 이 결함이 갤러리에서만 났던 상황이 그대로 재현된다.
  const skins = readdirSync(typesDir, { withFileTypes: true })
    .filter((entry) => entry.isDirectory() && existsSync(resolve(typesDir, entry.name, 'index.json')))
    .map((entry) => entry.name)
    .sort();

  /**
   * 스킨 레이아웃에서 eye-slash 아이콘의 className 을 모두 뽑아냅니다.
   */
  const blindedIconClasses = (skin: string): string[] => {
    const source = readFileSync(resolve(typesDir, skin, 'index.json'), 'utf8');
    const found: string[] = [];

    for (const match of source.matchAll(/"name":\s*"eye-slash"[^}]*?"className":\s*"([^"]*)"/g)) {
      found.push(match[1]);
    }

    return found;
  };

  it('스킨 디렉토리에서 모집단이 도출된다', () => {
    // 디렉토리 스캔이 빗나가면 skins 가 비고, 아래 단언들은 루프를 돌지 않아 전부 통과한다.
    // 모집단 자체를 먼저 고정해 그 조용한 통과를 막는다.
    expect(skins.length, '발견된 게시판 스킨').toBeGreaterThanOrEqual(3);
    expect(skins).toContain('gallery');
  });

  it('모든 스킨에 블라인드 아이콘이 존재한다', () => {
    // 모집단이 비면 아래 단언이 조용히 통과한다.
    for (const skin of skins) {
      expect(blindedIconClasses(skin).length, `${skin} 스킨`).toBeGreaterThan(0);
    }
  });

  it('어느 스킨도 대비가 모자란 orange-300 을 쓰지 않는다', () => {
    for (const skin of skins) {
      for (const className of blindedIconClasses(skin)) {
        expect(className, `${skin} 스킨의 블라인드 아이콘`).not.toMatch(/text-orange-300\b/);
      }
    }
  });

  it('모든 스킨이 라이트/다크 양쪽 색을 함께 지정한다', () => {
    for (const skin of skins) {
      for (const className of blindedIconClasses(skin)) {
        expect(className, `${skin} 스킨의 블라인드 아이콘 라이트 색`).toMatch(/(^|\s)text-orange-\d{3}\b/);
        expect(className, `${skin} 스킨의 블라인드 아이콘 다크 색`).toMatch(/dark:text-orange-\d{3}\b/);
      }
    }
  });

  it('스킨끼리 같은 색을 쓴다', () => {
    const palettes = skins.map((skin) => {
      const classes = blindedIconClasses(skin);
      const light = classes[0].match(/(?:^|\s)(text-orange-\d{3})\b/)?.[1];
      const dark = classes[0].match(/(dark:text-orange-\d{3})\b/)?.[1];

      return { skin, light, dark };
    });

    const [first] = palettes;

    for (const palette of palettes) {
      expect(palette.light, `${palette.skin} 라이트 색`).toBe(first.light);
      expect(palette.dark, `${palette.skin} 다크 색`).toBe(first.dark);
    }
  });
});
