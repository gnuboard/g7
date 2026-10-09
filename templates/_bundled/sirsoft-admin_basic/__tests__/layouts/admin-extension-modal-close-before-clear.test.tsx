/**
 * @file admin-extension-modal-close-before-clear.test.tsx
 * @description 확장 관리 창이 성공 뒤 대상 선택을 비우기 전에 먼저 닫히는지 (Q-G2 Hello 설치 실측)
 *
 * 설치·업데이트·레이아웃 갱신 등 확장 관리 창의 성공 처리가 대상(`selectedModule` 등)을 먼저 비우고
 * 목록·메뉴·확장 번들을 다시 받은 **뒤에야** 창을 닫았다. 그 몇 초 동안 열린 창이 빈 대상으로 다시 그려져
 * 「"{{name}}"을(를) 설치하시겠습니까?」처럼 자리표시가 그대로 보였다(실측: Hello 모듈 설치 성공 직후).
 * 창을 먼저 닫고 나서 대상을 비운다.
 */

import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync } from 'node:fs';
import { resolve, join } from 'node:path';

type Json = Record<string, any>;

const PARTIALS = resolve(__dirname, '../../layouts/partials');
// 언어팩 관리의 번들 언어팩 설치 창도 같은 순서 결함을 가졌다(7.1.0 validate q2 — 식별자·범위·버전 칸이 비어 그려짐).
const DIRS = ['admin_module_list', 'admin_plugin_list', 'admin_template_list', 'admin_language_pack_list'];
const SELECTED_KEYS = ['selectedModule', 'selectedPlugin', 'selectedTemplate', 'selectedBundledLanguagePack'];

/** 창 partial 의 apiCall onSuccess 액션 목록을 모두 모은다. */
function successLists(tree: unknown): Json[][] {
  const out: Json[][] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (obj.handler === 'apiCall' && Array.isArray(obj.onSuccess)) out.push(obj.onSuccess);
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  return out;
}

/** 대상 선택을 비우는 setState 인가. */
function clearsSelection(action: Json): boolean {
  return action?.handler === 'setState' && SELECTED_KEYS.some((k) => k in (action.params ?? {}) && action.params[k] === null);
}

const modalFiles = DIRS.flatMap((dir) => readdirSync(join(PARTIALS, dir))
  .filter((f) => f.startsWith('_modal_') && f.endsWith('.json'))
  .map((f) => join(dir, f)));

describe('확장 관리 창 — 성공 뒤 닫기와 대상 비우기 순서', () => {
  /**
   * @scenario case=close_modal_before_clearing_selection
   * @effects extension_modal_closes_before_selection_cleared
   */
  it.each(modalFiles)('%s: 대상을 비우는 것은 창을 닫은 뒤다', (file) => {
    const tree = JSON.parse(readFileSync(join(PARTIALS, file), 'utf-8'));
    for (const actions of successLists(tree)) {
      const clearAt = actions.findIndex(clearsSelection);
      const closeAt = actions.findIndex((a) => a?.handler === 'closeModal');
      if (clearAt === -1 || closeAt === -1) continue;
      expect(clearAt, `${file}: closeModal(${closeAt}) 이 대상 비우기(${clearAt})보다 먼저여야 합니다.`).toBeGreaterThan(closeAt);
    }
  });

  /**
   * @scenario case=population_not_empty
   * @effects extension_modal_closes_before_selection_cleared
   */
  it('검사 대상 창이 있다(모집단 0 방지)', () => {
    expect(modalFiles.length).toBeGreaterThan(10);
  });
});
