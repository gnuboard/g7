// e2e:allow 사전·레이아웃·처리기 소스 단언 — 화면 검증은 en 관리자 화면 수 1·2 실측(R-A 결과 문서)으로 한다.
/**
 * 이커머스 en 사전의 단수·복수 정리 (7.1.0 R-A — P20 후속)
 *
 * 번역 엔진은 `count` 인자가 수이면 en 사전의 `키_one` 을 고른다(engine-v1.78.7).
 * - `(s)`·`(ies)` 표기는 기본 키 = 복수형·`_one` = 단수형으로 나눈다.
 * - `{{count}}` 뒤에 복수 명사가 오는 문구는 `_one` 을 둔다.
 * - 인자 이름이 `count` 가 아닌 문구(`{{days}} days`·`{max} images`·`{{kept}} items`)는 레이아웃·처리기가 같은 값을
 *   `count` 로도 넘긴다. `$t('키').replace('{count}', n)` 꼴은 엔진이 수를 모르므로 `$t:키|count=` 로 바꾼다.
 * - 입력칸 뒤 단위(「일」)는 입력값을 `count` 로 넘겨 「1 day」·「30 days」로 맞춘다.
 */
import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const ROOT = path.resolve(__dirname, '../../../..');
const read = (rel: string): string => fs.readFileSync(path.resolve(ROOT, rel), 'utf-8');

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
const COUNT_THEN_PLURAL = /\{\{?\s*count\s*\}?\}\s+(?:[A-Za-z-]+\s+){0,2}?([A-Za-z]+s)\b/g;
/** 늘 2 이상이거나 복수가 맞는 자리 */
const ALLOW_PLURAL_ONLY: Record<string, string> = {};

describe('이커머스 en 사전 — 단수·복수', () => {
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
      if (/_(one|two|few|many|zero)$/.test(k) || k in ALLOW_PLURAL_ONLY) continue;
      const nouns = [...v.matchAll(COUNT_THEN_PLURAL)].map((m) => m[1]).filter((n) => !NOT_NOUN.test(n));
      if (nouns.length > 0 && !(`${k}_one` in en)) missing.push(k);
    }
    expect(missing).toEqual([]);
  });

  // @scenario count_form=numeric
  // @effects dictionary_has_singular_variant
  it.each([
    ['admin.inquiries.modal.bulk_delete.message', 'Delete {{count}} selected inquiry?'],
    ['admin.order.datagrid.total_count', 'Total {count} item'],
    ['admin.category.delete_modal.has_children_cannot_delete', 'This category has {count} child category. Please delete the child category first.'],
    ['admin.claim.complete.submit', 'Complete {{count}} claim'],
    ['admin.claim.detail.exchange_mode.overdue_banner', 'The return deadline ({{date}}) passed {{days}} day ago. Ask the customer to send it back or consider completing as not returned.'],
    ['admin.mileage_settings.expiry.days_suffix', 'day until expiry'],
    ['admin.product.images.messages.max_count', 'You can add up to {max} image.'],
    ['user.claim.image.upload_partial_failed', '{{failed}} of {{total}} photo could not be uploaded. Please attach it again on the request form.'],
  ])('%s_one = 단수형', (key, one) => {
    expect(en[`${key}_one`]).toBe(one);
  });

  // @scenario count_form=numeric
  // @effects number_free_wording
  it('사진 일부 업로드 실패 문구의 기본 꼴은 실패 장수(1장일 수 있음)와 엇갈리는 대명사 「them」을 쓰지 않는다', () => {
    // count 는 올린 장수(total)라 기본 꼴은 total ≥ 2 에서 고른다 — 「1 of 3 photos … attach them」이 되지 않아야 한다.
    expect(en['user.claim.image.upload_partial_failed']).toBe('{{failed}} of {{total}} photos could not be uploaded. Please attach any missing photos again on the request form.');
  });

  // @scenario count_form=numeric
  // @effects number_free_wording
  it('수 두 개가 맞물리는 일괄 수정 결과 문구는 수와 명사가 엇갈리지 않는 꼴이다', () => {
    expect(en['admin.product.bulk.update_success']).toBe('Updated — products {{products}} · options {{options}}.');
  });
});

describe('이커머스 호출부 — 수를 count 로 넘긴다', () => {
  const L = 'resources/layouts/admin/';
  // @scenario count_form=numeric
  // @effects call_site_passes_count
  it.each([
    [L + 'partials/admin_ecommerce_brand_index/_modal_delete_confirm.g7.html', '$t:sirsoft-ecommerce.admin.brand.delete_modal.has_products_cannot_delete|count={{_global.modal_data?.productsCount ?? 0}}'],
    [L + 'partials/admin_ecommerce_category_index/_modal_delete_confirm.g7.html', '$t:sirsoft-ecommerce.admin.category.delete_modal.has_children_cannot_delete|count={{_global.selectedCategory?.children_count ?? 0}}'],
    [L + 'partials/admin_ecommerce_category_index/_modal_delete_confirm.g7.html', '$t:sirsoft-ecommerce.admin.category.delete_modal.has_products_cannot_delete|count={{_global.selectedCategory?.products_count ?? 0}}'],
    [L + 'partials/admin_ecommerce_claim_detail/_partial_claim_reason.g7.html', '$t:sirsoft-ecommerce.admin.claim.detail.reason.images_hint|count={{claim?.data?.attachment_settings?.max_images ?? 0}}'],
    [L + 'partials/admin_ecommerce_claim_detail/_modal_claim_exchange_mode_confirm.g7.html', "$t:sirsoft-ecommerce.admin.claim.detail.exchange_mode.confirm_advance|count={{_global.claimExchangeModeContext?.dueDays ?? ''}}"],
    [L + 'admin_ecommerce_claim_detail.g7.html', '$t:sirsoft-ecommerce.admin.claim.detail.exchange_mode.overdue_banner|count={{claim?.data?.claim?.collection_overdue_days ?? 0}}'],
    [L + 'partials/admin_ecommerce_settings/_tab_mileage_basic_card.g7.html', '$t:sirsoft-ecommerce.admin.mileage_settings.basic.earn_delay_suffix|count={{_local.form?.mileage?.earn_delay_days ?? 0}}'],
    [L + 'partials/admin_ecommerce_settings/_tab_mileage_expiry_card.g7.html', '$t:sirsoft-ecommerce.admin.mileage_settings.expiry.days_suffix|count={{_local.form?.mileage?.expiry_days ?? 365}}'],
    [L + 'partials/admin_ecommerce_settings/_tab_mileage_notification_card.g7.html', '$t:sirsoft-ecommerce.admin.mileage_settings.notification.days_before_suffix|count={{_local.form?.mileage?.expiry_notification_days_before ?? 7}}'],
    [L + 'partials/admin_ecommerce_mileage_transaction_index/_modal_extend_expiry.g7.html', '$t:sirsoft-ecommerce.admin.mileage_transactions.modal_extend.days_suffix|count={{_global.mileageExtend?.days ?? 30}}'],
  ])('%s — %s', (file, token) => {
    expect(read(file)).toContain(token);
  });

  // @scenario count_form=numeric
  // @effects call_site_passes_count
  it('`$t(키).replace(\'{count}\', n)` 꼴이 남지 않는다', () => {
    for (const f of [
      L + 'partials/admin_ecommerce_brand_index/_modal_delete_confirm.g7.html',
      L + 'partials/admin_ecommerce_category_index/_modal_delete_confirm.g7.html',
    ]) {
      expect(read(f)).not.toContain(".replace('{count}'");
    }
  });

  // @scenario count_form=numeric
  // @effects call_site_passes_count
  it('처리기가 수를 count 로도 넘긴다', () => {
    expect(read('resources/js/handlers/claimHandlers.ts')).toContain('{ kept, remaining, count: kept }');
    // 상품 이미지 개수 상한 문구를 내던 `uploadImages` 처리기는 어느 화면도 부르지 않아 삭제했다(7.1.0 출시 점검 D3-2)
    const claimImage = read('resources/js/handlers/claimImageHandlers.ts');
    expect(claimImage.match(/\{ max: Number\(settings\.max_images \?\? 0\), count: Number\(settings\.max_images \?\? 0\) \}/g)?.length).toBe(2);
    expect(claimImage).toContain('{ failed, total: accepted.length, count: accepted.length }');
  });

  // @scenario count_form=numeric
  // @effects call_site_passes_count
  it('알림 수신자 고르기 「{count} roles selected」가 고른 수를 count 로 넘긴다', () => {
    const src = read(L + 'partials/admin_ecommerce_settings/_modal_notification_template_edit.g7.html');
    expect(src).toContain('$t:admin.settings.notification_definitions.recipients.roles_selected_count|count={{(rcpt.value ?? []).length}}');
    expect(src).toContain('$t:admin.settings.notification_definitions.recipients.users_selected_count|count={{(rcpt.value ?? []).length}}');
  });
});
