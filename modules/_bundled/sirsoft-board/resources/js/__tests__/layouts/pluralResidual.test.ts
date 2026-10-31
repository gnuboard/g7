// e2e:allow 사전·레이아웃 소스 단언 — 화면 검증은 en 화면 수 1·2 실측(R-A 결과 문서)으로 한다.
/**
 * 게시판 en 사전의 단수·복수 잔여 정리 (7.1.0 R-A — P20 후속)
 *
 * 번역 엔진은 `count` 인자가 수이면 en 사전의 `키_one` 을 고른다(engine-v1.78.7).
 * `(s)` 표기는 기본 키 = 복수형·`_one` = 단수형으로 나누고, 인자 이름이 `count` 가 아닌 문구
 * (`{{posts_count}} posts`·`{{maxFiles}} files`·`{{updated}} boards`)는 레이아웃이 같은 값을 `count` 로도 넘긴다.
 * 수를 앞에 따로 찍고 단위 문구만 붙이는 자리(반응 종류 삭제 막힘 안내)는 단위 키에 `count` 를 넘긴다.
 */
import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const ROOT = path.resolve(__dirname, '../../../..');
const read = (rel: string): string => fs.readFileSync(path.resolve(ROOT, rel), 'utf-8');

/** en.json 의 `$partial` 을 따라 화면이 실제로 읽는 사전을 펼친다 */
function loadDict(file: string): Record<string, unknown> {
  const node = JSON.parse(fs.readFileSync(file, 'utf-8'));
  const expand = (o: Record<string, unknown>): Record<string, unknown> => {
    if (typeof o.$partial === 'string') return loadDict(path.resolve(path.dirname(file), o.$partial));
    const out: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(o)) out[k] = v && typeof v === 'object' ? expand(v as Record<string, unknown>) : v;
    return out;
  };
  return expand(node);
}
function flat(o: Record<string, unknown>, p = '', out: Record<string, string> = {}): Record<string, string> {
  for (const [k, v] of Object.entries(o)) {
    const kp = p ? `${p}.${k}` : k;
    if (v && typeof v === 'object') flat(v as Record<string, unknown>, kp, out);
    else if (typeof v === 'string') out[kp] = v;
  }
  return out;
}
const en = flat(loadDict(path.resolve(ROOT, 'resources/lang/en.json')));

const NOT_NOUN = /^(is|was|has|does|this|its|us|as|yes|plus|status|always|across|less|address|access|success|process|progress|news|thus|previous|ago|remains|exceeds|matches|requires|contains|includes|uses|selected)$/i;
const COUNT_THEN_PLURAL = /(?:\{\{?\s*count\s*\}?\}|:count)\s+(?:[A-Za-z-]+\s+){0,2}?([A-Za-z]+s)\b/g;

describe('게시판 en 사전 — 단수·복수', () => {
  // @scenario count_form=numeric
  // @effects dictionary_has_singular_variant
  it('`(s)`·`(es)`·`(ies)` 표기가 없다', () => {
    const left = Object.entries(en).filter(([, v]) => /[A-Za-z]\((?:s|es)\)|\(ies\)/.test(v)).map(([k]) => k);
    expect(left).toEqual([]);
  });

  // @scenario count_form=numeric
  // @effects dictionary_has_singular_variant
  it('`{{count}}` 뒤에 복수 명사가 오는 문구는 `_one` 이 있다', () => {
    const missing: string[] = [];
    for (const [k, v] of Object.entries(en)) {
      if (/_(one|two|few|many|zero)$/.test(k)) continue;
      const nouns = [...v.matchAll(COUNT_THEN_PLURAL)].map((m) => m[1]).filter((n) => !NOT_NOUN.test(n));
      if (nouns.length > 0 && !(`${k}_one` in en)) missing.push(k);
    }
    expect(missing).toEqual([]);
  });

  // @scenario count_form=numeric
  // @effects dictionary_has_singular_variant
  it.each([
    ['admin.modals.delete.with_posts_info', 'This board has {{posts_count}} post. Deleting it also permanently removes its comments and attachments (including the uploaded original files).'],
    ['admin.posts.bulk.modal.delete_confirm', 'Delete {{count}} selected post?'],
    ['admin.reports.modals.blinded_will_be_public', '{{count}} blinded item will be made public.'],
    ['admin.reports.detail.report_count_with_unit', '{count} Report'],
    ['admin.settings.reaction_types.delete_blocked_suffix', ' reaction uses this type, so it cannot be deleted — you can turn it off instead'],
    ['admin.settings.bulk_members.result_summary', '{{updated}} board changed, {{skipped}} skipped, {{unchanged}} unchanged'],
    ['admin.posts.form.attachments_desc', 'Max {{maxFiles}} file, {{maxSize}}MB'],
  ])('%s_one = 단수형', (key, one) => {
    expect(en[`${key}_one`]).toBe(one);
  });

  // @scenario count_form=numeric
  // @effects number_free_wording
  it('신고 목록 쪽수 안내는 수와 명사가 맞물리지 않는 꼴이다', () => {
    expect(en['admin.reports.pagination_info']).toBe('Showing {{from}}-{{to}} of {{total}}');
  });
});

describe('게시판 호출부 — count 가 아닌 수 인자도 count 로 넘긴다', () => {
  // @scenario count_form=numeric
  // @effects call_site_passes_count
  it.each([
    ['resources/layouts/admin/admin_board_index.g7.html', "'$t:sirsoft-board.admin.modals.delete.with_posts_info|count=' + _global.modal_data.posts_count + '|posts_count=' + _global.modal_data.posts_count"],
    ['resources/layouts/admin/partials/admin_board_form/_tab_post.g7.html', '$t:sirsoft-board.admin.form.fields.max_file_count.hint|count={{board_config?.data?.limits?.max_file_count_max ?? 20}}'],
    ['resources/layouts/admin/partials/admin_board_post_form/_attachments.g7.html', '$t:sirsoft-board.admin.posts.form.attachments_desc|count={{form_meta?.data?.board?.max_file_count ?? 5}}'],
    ['resources/layouts/admin/partials/admin_board_settings/_section_bulk_members.g7.html', '$t:sirsoft-board.admin.settings.bulk_members.result_summary|count={{_global.boardBulkMembers?.result?.updated_count ?? 0}}'],
  ])('%s', (file, token) => {
    expect(read(file)).toContain(token);
  });

  // @scenario count_form=numeric
  // @effects call_site_passes_count
  it.each([
    'resources/layouts/admin/partials/admin_board_settings/_reaction_type_cards.g7.html',
    'resources/layouts/admin/partials/admin_board_settings/_reaction_type_editor.g7.html',
  ])('%s — 반응 종류 삭제 막힘 안내가 반응 수를 단위 키의 count 로 넘긴다', (file) => {
    const src = read(file);
    expect(src).toContain("(row.reaction_count ?? 0) + '$t:sirsoft-board.admin.settings.reaction_types.delete_blocked_suffix|count=' + (row.reaction_count ?? 0)");
    expect(src).not.toContain("$t('sirsoft-board.admin.settings.reaction_types.delete_blocked_suffix')");
  });

  // @scenario count_form=numeric
  // @effects call_site_passes_count
  it('알림 수신자 고르기 「{count} roles selected」가 고른 수를 count 로 넘긴다', () => {
    const src = read('resources/layouts/admin/partials/admin_board_settings/_modal_notification_template_edit.g7.html');
    expect(src).toContain('$t:admin.settings.notification_definitions.recipients.roles_selected_count|count={{(rcpt.value ?? []).length}}');
    expect(src).toContain('$t:admin.settings.notification_definitions.recipients.users_selected_count|count={{(rcpt.value ?? []).length}}');
  });
});
