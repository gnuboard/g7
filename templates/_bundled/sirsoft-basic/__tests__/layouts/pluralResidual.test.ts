// e2e:allow 사전·레이아웃 소스 단언 — 화면 검증은 en 화면 수 1·2 실측(R-A 결과 문서)으로 한다.
/**
 * en 사전의 단수·복수 잔여 정리 (7.1.0 R-A — P20 후속)
 *
 * 번역 엔진은 `count` 인자가 수이면 en 사전의 `키_one` 을 고른다(engine-v1.78.7).
 * `(s)` 표기는 기본 키 = 복수형·`_one` = 단수형으로 나누고, 인자 이름이 `count` 가 아닌 문구
 * (`{{days}} days`·`{{maxFiles}} files`)는 레이아웃·부품이 같은 값을 `count` 로도 넘긴다.
 */
import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const ROOT = path.resolve(__dirname, '../..');
const read = (rel: string): string => fs.readFileSync(path.resolve(ROOT, rel), 'utf-8');

function flat(o: Record<string, unknown>, p = '', out: Record<string, string> = {}): Record<string, string> {
  for (const [k, v] of Object.entries(o)) {
    const kp = p ? `${p}.${k}` : k;
    if (v && typeof v === 'object') flat(v as Record<string, unknown>, kp, out);
    else if (typeof v === 'string') out[kp] = v;
  }
  return out;
}

const EN_DIR = path.resolve(ROOT, 'lang/partial/en');
const en: Record<string, string> = {};
for (const f of fs.readdirSync(EN_DIR).filter((x) => x.endsWith('.json'))) {
  Object.assign(en, flat(JSON.parse(fs.readFileSync(path.join(EN_DIR, f), 'utf-8')), f.replace(/\.json$/, '')));
}

const NOT_NOUN = /^(is|was|has|does|this|its|us|as|yes|plus|status|always|across|less|address|access|success|process|progress|news|thus|previous|ago|remains|exceeds|matches|requires|contains|includes|uses|selected)$/i;
const COUNT_THEN_PLURAL = /\{\{?\s*count\s*\}?\}\s+(?:[A-Za-z-]+\s+){0,2}?([A-Za-z]+s)\b/g;
const ALLOW_PLURAL_ONLY: Record<string, string> = {
  'auth.password_rules.repeated': '「{{count}} or more times」 — or more 뒤는 복수',
  'auth.password_rules.sequential': '「{{count}} or more characters」 — or more 뒤는 복수',
};

describe('basic en 사전 — 단수·복수', () => {
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
    ['mypage.inquiries.count', '{{count}} item'],
    ['claim.detail.collection_overdue', '{{days}} day past the return deadline'],
    ['shop.product.rating_out_of_5', 'Out of 5 · {{total}} review'],
    ['shop.checkout.vbank_due_notice', 'Payment due: within {{days}} day'],
    ['attachment.upload_limit_count', 'Max {{maxFiles}} file'],
    ['auth.password_change.reason_remind', 'Your password has expired (on {{date}}). Change it now, or choose "Change later" to be reminded again in {{days}} day.'],
  ])('%s_one = 단수형', (key, one) => {
    expect(en[`${key}_one`]).toBe(one);
  });

  // @scenario count_form=numeric
  // @effects number_free_wording
  it('사진 일부 업로드 실패 문구의 기본 꼴은 실패 장수(1장일 수 있음)와 엇갈리는 대명사 「them」을 쓰지 않는다', () => {
    // count 는 올린 장수(total)라 기본 꼴은 total ≥ 2 에서 고른다 — 「1 of 3 photos … attach them」이 되지 않아야 한다.
    expect(en['claim.image.upload_partial_failed']).toBe('{{failed}} of {{total}} photos could not be uploaded. Please attach any missing photos again on the request form.');
  });
});

describe('basic 호출부 — count 가 아닌 수 인자도 count 로 넘긴다', () => {
  // @scenario count_form=numeric
  // @effects call_site_passes_count
  it.each([
    ['layouts/partials/auth/_login_form.g7.html', '$t:auth.password_change.reason_remind|count={{_global.passwordChange?.postpone_days ?? 0}}'],
    ['layouts/partials/board/form/_post_form.g7.html', '$t:board.form.attachments_desc|count={{form_meta?.data?.board?.max_file_count ?? 5}}'],
    ['layouts/partials/mypage/claims/_apply_reason.g7.html', '$t:claim.apply.images_hint|count={{(_local.claimableSnapshot ?? claimable?.data)?.settings?.max_images ?? 0}}'],
    ['layouts/partials/mypage/claims/_detail_progress.g7.html', '$t:claim.detail.collection_overdue|count={{claim.data?.collection_lane?.overdue_days ?? 0}}'],
    ['layouts/partials/mypage/claims/_detail_shipping.g7.html', '$t:claim.detail.collection_overdue|count={{claim.data?.claim?.collection_overdue_days ?? 0}}'],
    ['layouts/partials/shop/detail/_purchase_card.g7.html', '$t:shop.max_purchase_qty_notice|count={{product.data?.max_purchase_qty}}'],
    ['layouts/partials/shop/detail/_tab_reviews.g7.html', '$t:shop.product.rating_out_of_5|count={{reviews.data?.total_count ?? 0}}'],
    ['layouts/partials/shop/_checkout_payment.g7.html', '$t:shop.checkout.vbank_due_notice|days={{Number(paymentSettings.data?.order_settings?.auto_cancel_days) > 0 ? Number(paymentSettings.data?.order_settings?.auto_cancel_days) : 3}}|count={{Number(paymentSettings.data?.order_settings?.auto_cancel_days) > 0 ? Number(paymentSettings.data?.order_settings?.auto_cancel_days) : 3}}'],
    ['layouts/partials/shop/_checkout_payment.g7.html', '$t:shop.checkout.dbank_due_notice|days={{Number(paymentSettings.data?.order_settings?.auto_cancel_days) > 0 ? Number(paymentSettings.data?.order_settings?.auto_cancel_days) : 3}}|count={{Number(paymentSettings.data?.order_settings?.auto_cancel_days) > 0 ? Number(paymentSettings.data?.order_settings?.auto_cancel_days) : 3}}'],
  ])('%s — %s', (file, token) => {
    expect(read(file)).toContain(token);
  });

  // @scenario count_form=numeric
  // @effects call_site_passes_count
  it('첨부 안내·초과 알림 부품이 최대 개수를 count 로도 넘긴다', () => {
    const zone = read('src/components/composite/FileUploader/FileDropZone.tsx');
    expect(zone).toContain("t('attachment.upload_limit', { maxFiles, maxSize, count: maxFiles })");
    expect(zone).toContain("t('attachment.upload_limit_count', { maxFiles, count: maxFiles })");
    expect(read('src/components/composite/FileUploader/useFileUploader.ts')).toContain("t('attachment.upload_limit_exceeded', { max: maxFiles, count: maxFiles })");
  });
});
