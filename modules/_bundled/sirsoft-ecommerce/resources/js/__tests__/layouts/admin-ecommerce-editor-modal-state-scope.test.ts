/**
 * @file admin-ecommerce-editor-modal-state-scope.test.ts
 * @description 쇼핑몰 편집기 창 상태가 실제 창을 가리키고, 창이 읽는 상태를 채우는지
 *
 * 레이아웃 편집기 상태 표본을 전수 순회했더니 회원 배송지 목록의 창 세 개(단건 삭제, 선택 삭제, 오류)가 편집기에서
 * 빈 채로 열렸다. 배송지 화면을 조각으로 나누면서 창 id 가 `modal_delete_confirm` 같은 범용 이름이 됐는데,
 * 편집기 상태는 형제 목록(장바구니, 찜, 문의)과 같은 `modal_addresses_*` 를 가리키고 있었고, 상태가 채우는 전역 값도
 * 조각 이전의 이름(`deleteTarget_addresses` 등)이었다. 범용 id 는 상품, 분류, 배너, 쿠폰 삭제 창과도 겹친다.
 *
 * 창 id 를 형제 목록과 같은 규칙으로 되돌리고, 표본을 창이 지금 읽는 이름으로 맞췄다. 여기서는 모듈의 모든 창 상태가
 * 모듈 레이아웃의 실제 창을 가리키는지와, 배송지 창 표본이 창이 읽는 전역 값을 채우는지 본다.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';

const MODULE_ROOT = resolve(__dirname, '../../../..');

type Json = Record<string, any>;

const spec: Json = JSON.parse(readFileSync(join(MODULE_ROOT, 'editor-spec.json'), 'utf8'));

/** 디렉터리 아래 마크업 파일 경로를 모두 모은다. */
function markupFiles(dir: string, acc: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) markupFiles(full, acc);
    else if (full.endsWith('.g7.html')) acc.push(full);
  }
  return acc;
}

const addressDir = join(MODULE_ROOT, 'resources/layouts/admin/partials/admin_ecommerce_user_address_index');

/** 창 마크업이 읽는 전역 값 이름. */
function globalsReadBy(file: string): string[] {
  const text = readFileSync(join(addressDir, file), 'utf8');
  return [...new Set([...text.matchAll(/_global\.([A-Za-z_]+)/g)].map((m) => m[1]))];
}

/** 창 상태 묶음에서 첫 항목이 채우는 전역 값. */
function seededGlobals(modalId: string): Json {
  const group = (spec.states?.groups ?? []).find((g: Json) => g.scope?.kind === 'modal' && g.scope?.match === modalId);

  return group?.items?.[0]?.initialState?.global ?? {};
}

describe('쇼핑몰 편집기 창 상태 — 범위와 표본', () => {
  /**
   * 창 상태의 범위 id 가 모듈 레이아웃에 실제로 있는 창이어야 한다 — 없으면 그 상태는 편집기에서 영영 붙지 않는다.
   *
   * @scenario target=modal_scope
   * @effects ecommerce_editor_modal_state_scopes_point_to_existing_modals
   */
  it('모든 창 상태가 모듈 레이아웃의 실제 창을 가리킨다', () => {
    const ids = new Set<string>();
    for (const file of markupFiles(join(MODULE_ROOT, 'resources/layouts'))) {
      for (const m of readFileSync(file, 'utf8').matchAll(/<modal[^>]*data-ref="([^"]+)"/g)) ids.add(m[1]);
    }
    const scopes = (spec.states?.groups ?? []).filter((g: Json) => g.scope?.kind === 'modal').map((g: Json) => g.scope.match as string);

    expect(scopes.length).toBeGreaterThan(0);
    expect(scopes.filter((id: string) => !ids.has(id))).toEqual([]);
  });

  /**
   * 배송지 창 id 는 형제 목록과 같은 `modal_addresses_*` 다 — 범용 id 는 다른 화면의 삭제 창과 겹친다.
   *
   * @scenario target=address_modals
   * @effects ecommerce_address_modals_use_list_scoped_ids
   */
  it('배송지 목록의 창 세 개가 목록 이름이 붙은 id 를 쓰고, 여는 쪽도 그 id 를 부른다', () => {
    const datagrid = readFileSync(join(addressDir, '_partial_address_datagrid.g7.html'), 'utf8');

    expect(readFileSync(join(addressDir, '_modal_delete_confirm.g7.html'), 'utf8')).toContain('data-ref="modal_addresses_delete"');
    expect(readFileSync(join(addressDir, '_modal_bulk_delete_confirm.g7.html'), 'utf8')).toContain('data-ref="modal_addresses_bulk_delete"');
    expect(readFileSync(join(addressDir, '_modal_error.g7.html'), 'utf8')).toContain('data-ref="modal_addresses_error"');
    expect(datagrid).toContain('<open-modal target="modal_addresses_delete"/>');
    expect(datagrid).toContain('<open-modal target="modal_addresses_bulk_delete"/>');
    expect(datagrid).not.toMatch(/target="modal_(delete_confirm|bulk_delete_confirm|error)"/);
  });

  /**
   * 표본은 창이 지금 읽는 전역 값을 채워야 한다 — 이름이 어긋나면 창은 「0건」·빈 이름으로 열린다.
   *
   * @scenario target=address_modals
   * @effects ecommerce_address_modal_states_seed_what_modals_read
   */
  it('배송지 창 표본이 창이 읽는 전역 값을 모두 채운다', () => {
    const pairs: Array<[string, string]> = [
      ['modal_addresses_delete', '_modal_delete_confirm.g7.html'],
      ['modal_addresses_bulk_delete', '_modal_bulk_delete_confirm.g7.html'],
      ['modal_addresses_error', '_modal_error.g7.html'],
    ];

    for (const [modalId, file] of pairs) {
      const seeded = seededGlobals(modalId);
      for (const name of globalsReadBy(file)) {
        expect(Object.keys(seeded), `${modalId} 표본에 ${name}`).toContain(name);
      }
    }
    expect(seededGlobals('modal_addresses_bulk_delete').bulkSelectedItems.length).toBeGreaterThan(0);
    expect(seededGlobals('modal_addresses_delete').deleteTarget.recipient_name).toBeTruthy();
  });
});
