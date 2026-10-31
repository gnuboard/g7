/**
 * @file personalPaymentI18n.test.ts
 * @description 개인결제 목록·등록·상세 화면의 상태·결제수단·건수·버튼 도움말이 번역 키를 거치는지 잰다.
 *
 * 세 화면은 예시 행으로 그려지는 화면이라 사람 이름·상품명 같은 예시 값은 그대로 두지만, 같은 화면의 필터가 이미
 * 번역 키로 쓰는 상태(대기중·완료·만료)·결제수단 이름과 버튼 도움말(제거·새로고침)은 한국어로 박혀 있어
 * 언어를 바꾸면 필터와 행이 서로 다른 말로 보였다.
 *
 * @vitest-environment node
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, it, expect } from 'vitest';
import list from '../../../layouts/admin/admin_ecommerce_personal_payment.json';
import create from '../../../layouts/admin/admin_ecommerce_personal_payment_create.json';
import detail from '../../../layouts/admin/admin_ecommerce_personal_payment_detail.json';

const LANG = resolve(__dirname, '../../../lang/partial');
const read = (p: string) => JSON.parse(readFileSync(resolve(LANG, p), 'utf8'));

/** 노드 트리에서 props.title 과 text 값을 모은다. */
function collect(node: unknown, field: 'title' | 'text', out: string[] = []): string[] {
  if (!node || typeof node !== 'object') return out;
  const n = node as Record<string, unknown>;
  const props = n.props as Record<string, unknown> | undefined;
  if (field === 'title' && typeof props?.title === 'string') out.push(props.title);
  if (field === 'text' && typeof n.text === 'string') out.push(n.text);
  for (const v of Object.values(n)) collect(v, field, out);
  return out;
}

const VOCAB = ['대기중', '완료', '만료', '신용카드', '계좌이체', '카카오페이', '네이버페이'];

describe('개인결제 화면 — 상태·결제수단·도움말 번역', () => {
  // @scenario surface=personal_payment_list
  // @effects ui_copy_goes_through_translation_keys
  it('목록의 상태 배지·결제수단·총 건수에 한국어가 박혀 있지 않다', () => {
    const texts = collect(list, 'text');
    for (const word of VOCAB) expect(texts, word).not.toContain(word);
    expect(texts).not.toContain('총 9개');
    expect(texts).toContain('$t:sirsoft-ecommerce.admin.personal_payment.status.pending');
    expect(texts).toContain('$t:sirsoft-ecommerce.admin.personal_payment.filter.kakao_pay');
    expect(texts).toContain('$t:sirsoft-ecommerce.admin.personal_payment.table.total_count|count=9');
  });

  // @scenario surface=personal_payment_create
  // @effects ui_copy_goes_through_translation_keys, every_used_key_exists_in_ko_and_en
  it('등록 화면 버튼 도움말이 번역 키를 쓰고 ko·en 에 그 키가 있다', () => {
    const titles = collect(create, 'title');
    expect(titles.filter((t) => /[가-힣]/.test(t))).toEqual([]);
    expect(titles).toContain('$t:sirsoft-ecommerce.common.remove');
    expect(titles).toContain('$t:sirsoft-ecommerce.common.refresh');
    for (const locale of ['ko', 'en']) {
      const common = read(`${locale}/common.json`);
      expect(typeof common.remove, `${locale} remove`).toBe('string');
      expect(typeof common.refresh, `${locale} refresh`).toBe('string');
    }
  });

  // @scenario surface=personal_payment_detail
  // @effects ui_copy_goes_through_translation_keys
  it('상세 화면의 결제수단이 목록 필터와 같은 키를 쓴다', () => {
    const texts = collect(detail, 'text');
    expect(texts).not.toContain('카카오페이');
    expect(texts).toContain('$t:sirsoft-ecommerce.admin.personal_payment.filter.kakao_pay');
  });
});
