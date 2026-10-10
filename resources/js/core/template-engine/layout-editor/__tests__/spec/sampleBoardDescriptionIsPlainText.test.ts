/**
 * sampleBoardDescriptionIsPlainText.test.ts — 편집기 표본의 게시판 설명이 평문인지 정적 가드
 *
 * 게시판 설명(`board.description`)은 다국어 평문 칸이고(게시판 저장 요청 `TranslatableField`), 사용자 게시판 화면은 이를
 * 글자 노드(`{{posts?.data?.board?.description}}`)로 그린다. 표본이 `<p>…</p>` 를 담아 7.1.0 실측(영역 I2)에서 편집기
 * 미리보기 게시판 머리에 「<p>회원들이 자유롭게 소통하는 공간입니다.</p>」가 태그째 보였다(실화면에는 없는 모습).
 *
 * 모집단은 저장소에서 파생한다 — 번들 편집기 스펙 전부에서 `board` 객체의 `description` 문자열.
 *
 * @since engine-v1.78.3
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';

const ROOT = process.cwd();

/** 번들 편집기 스펙 파일(템플릿 분할 + 모듈·플러그인 단일). */
function specFiles(): string[] {
  const out: string[] = [];
  for (const tpl of readdirSync(resolve(ROOT, 'templates/_bundled'))) {
    const dir = resolve(ROOT, 'templates/_bundled', tpl, 'editor-spec');
    if (existsSync(dir)) for (const f of readdirSync(dir)) if (f.endsWith('.json')) out.push(resolve(dir, f));
  }
  for (const base of ['modules/_bundled', 'plugins/_bundled']) {
    for (const ext of readdirSync(resolve(ROOT, base))) {
      const f = resolve(ROOT, base, ext, 'editor-spec.json');
      if (existsSync(f)) out.push(f);
    }
  }
  return out;
}

describe('편집기 표본 — 게시판 설명은 평문', () => {
  // @scenario source=bundled
  // @effects sample_board_description_has_no_markup
  it('`board.description` 표본에 HTML 태그가 없다', () => {
    const offenders: string[] = [];
    const visit = (node: unknown, file: string, trail: string): void => {
      if (Array.isArray(node)) {
        node.forEach((child) => visit(child, file, trail));
        return;
      }
      if (!node || typeof node !== 'object') return;
      for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
        if (key === 'board' && value && typeof value === 'object' && !Array.isArray(value)) {
          const desc = (value as Record<string, unknown>).description;
          const texts = typeof desc === 'string' ? [desc] : desc && typeof desc === 'object' ? Object.values(desc) : [];
          for (const text of texts) {
            if (typeof text === 'string' && /<\/?[a-z][^>]*>/i.test(text)) offenders.push(`${file.replace(ROOT, '')} ${trail}.board.description=${text}`);
          }
        }
        visit(value, file, `${trail}.${key}`);
      }
    };
    for (const file of specFiles()) visit(JSON.parse(readFileSync(file, 'utf-8')), file, '');
    expect(offenders).toEqual([]);
  });
});
