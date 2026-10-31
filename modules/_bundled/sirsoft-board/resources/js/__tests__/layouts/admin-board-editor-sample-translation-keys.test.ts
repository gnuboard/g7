/**
 * @file admin-board-editor-sample-translation-keys.test.ts
 * @description 게시판 편집기 표본이 화면이 번역하는 키와 맞는지
 *
 * 레이아웃 편집기 상태 표본을 전수 순회했더니 게시판 환경설정 화면에 번역 키 원문
 * (`sirsoft-board.board.permissions.read` 등)이 그대로 보였다. 실제 화면은 정상이고, 표본의 값이
 * 실제 응답과 달랐다 — 권한 키가 `read`·`write`·`comment` 처럼 줄여 적혀 있었고(실제는 `admin.posts.read` 등),
 * 알림 정의 표본 종류가 없는 이름(`board.post.created`, `sample_notification`)이었으며, 일괄 적용 확인 창
 * 표본에 없는 권한(`manager.write`)이 있었다. 화면은 이 값들로 번역 키를 만들어 원문이 그대로 남았다.
 *
 * 표본 값으로 화면이 만드는 번역 키가 ko 언어 파일에 실제로 있는지 본다.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

const MODULE_ROOT = resolve(__dirname, '../../../..');

type Json = Record<string, any>;

const spec: Json = JSON.parse(readFileSync(resolve(MODULE_ROOT, 'editor-spec.json'), 'utf8'));

/** `sirsoft-board.{네임스페이스}.{경로}` 키가 ko 부분 언어 파일에서 문자열로 풀리는지 본다. */
function hasKo(key: string): boolean {
  const rest = key.replace(/^sirsoft-board\./, '').split('.');
  const candidates: Array<[string, string[]]> = [[`resources/lang/partial/ko/${rest[0]}.json`, rest.slice(1)]];
  if (rest.length > 2) candidates.push([`resources/lang/partial/ko/${rest[0]}/${rest[1]}.json`, rest.slice(2)]);

  return candidates.some(([file, path]) => {
    const abs = resolve(MODULE_ROOT, file);
    if (!existsSync(abs)) return false;
    const value = path.reduce<any>((acc, seg) => (acc && typeof acc === 'object' ? acc[seg] : undefined), JSON.parse(readFileSync(abs, 'utf8')));

    return typeof value === 'string' && value !== '';
  });
}

/** 상태 표본에서 이름이 같은 전역 값을 모두 모은다. */
function globalsNamed(name: string): Json[] {
  const out: Json[] = [];
  for (const group of spec.states?.groups ?? []) {
    for (const item of group.items ?? []) {
      const value = item.initialState?.global?.[name];
      if (value) out.push(value);
    }
  }
  return out;
}

describe('게시판 편집기 표본 — 화면이 만드는 번역 키', () => {
  /**
   * 환경설정 「게시판 설정 › 권한」 표는 `$t:sirsoft-board.board.permissions.{키}` 와 `{키}_desc` 를 그린다.
   *
   * @scenario sample=default_board_permissions
   * @effects board_editor_sample_permission_keys_have_labels
   */
  it('기본 권한 표본의 키마다 이름과 설명 번역이 있다', () => {
    const permissions = spec.sampleData.byDataSourceId.settings.data.basic_defaults.default_board_permissions as Json;
    const keys = Object.keys(permissions);

    expect(keys.length).toBeGreaterThan(0);
    for (const key of keys) {
      expect(hasKo(`sirsoft-board.board.permissions.${key}`), key).toBe(true);
      if (key !== 'manager') expect(hasKo(`sirsoft-board.board.permissions.${key}_desc`), `${key}_desc`).toBe(true);
      expect(Array.isArray(permissions[key]), `${key} 값은 역할 배열`).toBe(true);
    }
  });

  /**
   * 일괄 적용 확인 창은 점이 든 필드와 `manager` 를 권한 이름으로, 나머지를 필드 이름으로 그린다.
   *
   * @scenario sample=bulk_apply_snapshot
   * @effects board_editor_sample_bulk_apply_fields_have_labels
   */
  it('일괄 적용 표본 필드마다 번역이 있다', () => {
    const snapshots = globalsNamed('bulkApplySnapshot');

    expect(snapshots.length).toBeGreaterThan(0);
    for (const snapshot of snapshots) {
      for (const field of snapshot.fields as string[]) {
        const key = field.includes('.') || field === 'manager'
          ? `sirsoft-board.board.permissions.${field}`
          : `sirsoft-board.admin.settings.bulk_apply.field_labels.${field}`;
        expect(hasKo(key), key).toBe(true);
      }
    }
  });

  /**
   * 알림 정의 목록과 템플릿 편집 창 제목은 `$t:sirsoft-board.admin.settings.notification_definitions.types.{종류}` 를 그린다.
   *
   * @scenario sample=notification_definition
   * @effects board_editor_sample_notification_types_have_labels
   */
  it('알림 정의 표본 종류마다 번역이 있다', () => {
    const types = [
      ...(spec.sampleData.byDataSourceId.boardNotificationDefinitions.data.data as Json[]).map((d) => d.type),
      ...globalsNamed('board_notification_template_form_modal').map((m) => m.definition?.type),
    ];

    expect(types.length).toBeGreaterThan(0);
    for (const type of types) {
      expect(hasKo(`sirsoft-board.admin.settings.notification_definitions.types.${type}`), type).toBe(true);
    }
  });

  /**
   * 일괄 적용 확인 창은 고른 게시판을 `$parent._computed.boardOptions`(게시판 목록 표본)에서 이름으로 풀어 보인다.
   * 상태가 표본 목록에 없는 번호를 담으면 이름 대신 번호가 보인다.
   *
   * @scenario sample=bulk_apply_snapshot
   * @effects board_editor_sample_bulk_apply_boards_exist_in_board_list
   */
  it('일괄 적용 창 표본의 게시판은 게시판 목록 표본에 있다', () => {
    const listed = new Set((spec.sampleData.byDataSourceId.boards_list.data.data as Json[]).map((b) => b.id));
    const snapshots = globalsNamed('bulkApplySnapshot').filter((s) => s.applyAll === false);

    expect(snapshots.length).toBeGreaterThan(0);
    for (const snap of snapshots) {
      expect((snap.boardIds ?? []).length).toBeGreaterThan(0);
      for (const id of snap.boardIds) {
        expect(listed.has(id), String(id)).toBe(true);
      }
    }
  });
});
