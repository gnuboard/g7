<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutCorpus;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use Illuminate\Support\Facades\File;

/**
 * 전량 왕복 하네스(S9 DoD) — 번들 확장·템플릿의 모든 레이아웃 `.json` 이 왕복함을 기계 확인한다.
 *
 * 계획서 DoD "왕복 불일치 0" 의 회귀 봉인. `layout:compile --all --roundtrip` 과 같은 검사를
 * PHPUnit 으로 상시 돌려, 역변환기·직렬화기·컴파일러 어느 층의 회귀도 이 한 테스트가 잡는다.
 *
 * 표기 탈출구(`<json>`)로도 왕복하지 못하는 건이 하나라도 있으면 실패한다 — deep-equal 이 유일한 게이트다.
 */
class LayoutMarkupRoundTripCorpusTest extends LayoutMarkupTestCase
{
    /**
     * 왕복 코퍼스 모집단의 **하한** — 본 레이아웃 + fragment 전량.
     *
     * 스캔 글롭이 좁아지면 게이트가 조용히 작아진 모집단 위에서 green 을 내므로(㉓ 이 겪은
     * "fragment 42건이 계량 밖" 사고) 하한으로 못박는다. 레이아웃 추가는 정상이라 상한은 없다.
     *
     * 639(㉓ 실측) → **637** — S16 W3 에서 **도달 경로가 0인 사문 레이아웃 2건을 삭제**했다
     * (`overrides/sirsoft-sample/index.json` 대상 모듈 부재 · `admin_language_packs_install_modal.json`
     * 라우트·참조 0 + `extends` 없는 slots 라 렌더 결과 자체가 없음). 둘 다 승인된 삭제다 —
     * 하한을 내릴 때는 **왜 줄었는지**를 여기 남겨야 한다. 그러지 않으면 다음 사람이 이 숫자를
     * 스캔이 좁아진 신호와 구별할 수 없다.
     *
     * 637 → **635** — S16 에서 **도달 경로가 0인 고아 레이아웃 2건을 삭제**했다 (설계 결정):
     * `partials/shop/_product_purchase_card.json` 은 살아 있는 `partials/shop/detail/_purchase_card.json`
     * 으로 대체돼 include 0 이었고(그 안에만 있던 A25 옵션 수량 한도 검사는 살아 있는 카드로 이식했다),
     * `partials/search/products/_item_list.json` 은 검색 결과가 `ProductCard` 를 쓰게 되면서 include 0 이
     * 됐다(상품명·하이라이트 모두 그쪽이 담당).
     *
     * 635 → **634** — S16 감사에서 **세 번째 고아**를 확인해 삭제했다 (설계 결정):
     * `partials/admin_ecommerce_order_list/_partial_preset_section.json` 은 결함(F21 미등록
     * `DropdownItem` · 죽은 노드 `iterator`)을 고쳐 두었으나 include 가 끝내 0이었고, 살아 있는
     * `_partial_filter_section.json` 의 `preset_row` 가 같은 기능을 **데이터소스 전량 반복**으로
     * 이미 제공한다(고아 쪽은 정적 퀵프리셋 2개 + `slice(0, 3)` + 더보기 드롭다운이라 설계가 낡았다).
     * "살아 있는 것 기준, 미사용 건은 삭제" 가 설계 판단이다.
     */
    private const CORPUS_MINIMUM = 634;

    /**
     * 번들 레이아웃 전량이 decompile → compile 왕복에서 항등이고 escape 가 0 인지 확인한다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects every_bundled_layout_round_trips_through_decompile_and_compile
     *
     * @return void 반환값 없음
     */
    public function test_every_bundled_layout_round_trips(): void
    {
        $resolver = new MarkupCompileContextResolver;
        $harness = new LayoutMarkupRoundTrip;
        $files = $this->scanLayoutFiles();

        // 모집단을 하한으로 못박는다. `assertNotEmpty` 만으로는 글롭이 좁아져 639→1 이 되어도 green 이다
        // — ㉓ 이 겪은 "반쪽 코퍼스 위의 escape-0"(fragment 42건이 통째로 계량 밖이던 것)이 바로 그
        // 형태였고, 그때 이 게이트는 아무 말도 하지 않았다. 레이아웃이 늘어나는 것은 정상이므로
        // 상한은 두지 않고, **줄어드는 것만** 회귀로 잡는다.
        $this->assertGreaterThanOrEqual(
            self::CORPUS_MINIMUM,
            count($files),
            '왕복 코퍼스 모집단이 줄었습니다 — 스캔 글롭이 좁아졌거나 레이아웃이 사라졌습니다 (기대 최소 '.self::CORPUS_MINIMUM.', 실측 '.count($files).').',
        );

        $failures = [];

        foreach ($files as $file) {
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            // 레지스트리는 템플릿 자신의 파일에서만 해석된다(검사용). 모듈·플러그인 파일은 null 이 정상이다 —
            // 컴파일·역변환 산출은 템플릿과 무관하므로 템플릿 없이 왕복한다(설치 경로와 같은 조건).
            $registry = $resolver->registryFor($file);

            $json = json_decode(File::get($file), true);

            if (! is_array($json)) {
                $failures[] = "{$relative} (JSON 파싱 실패 — 손상되었거나 배열이 아닙니다)";

                continue;
            }

            // 프로덕션 진입점 문맥 — 설치가 보는 것과 같은 검증·lint 로 되컴파일한다.
            $result = $harness->verify($json, $registry, $file, $resolver->contextFor($file));

            if (! $result['ok']) {
                $failures[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).($result['error'] !== null ? " ({$result['error']})" : '');
            }
        }

        $this->assertSame([], $failures, '왕복 불일치 레이아웃:'.PHP_EOL.implode(PHP_EOL, array_slice($failures, 0, 20)));
    }

    /**
     * 이관 완료된 `.g7.html` 의 **명시 목록**이다 (계획서 §201·§207 "확장 목록형").
     *
     * 디렉토리 스캔만으로 게이트를 걸면 이관 파일이 사라져도 green 이 된다 — 회귀 감지력이 없다.
     * 그래서 W1 산출 16건(15 clean + S7 데모)을 목록으로 못박고, 스캔 결과가 이 목록과 정확히
     * 일치하는지까지 단언한다. 이후 웨이브는 이 목록을 확장한다.
     *
     * **W3(sirsoft-admin_basic) 은 전량 이관됐다 (143/143)** — 착수 시 제외했던 16파일은 S16 결함
     * (F7·F8·F12·F13·F14·F15)을 수정한 뒤 합류시켰고, 사문 2건은 승인대로 삭제했다
     * (145 → 143 — 대상 모듈이 없는 `overrides/sirsoft-sample` 과 참조처 없는
     * `admin_language_packs_install_modal`). 각 결함의 회귀는
     * `templates/_bundled/sirsoft-admin_basic/__tests__/layouts/s16-defect-fixes.test.tsx` 가 봉인한다.
     *
     * @var array<int, string>
     */
    private const MIGRATED_MARKUP_FILES = [
        'modules/_bundled/gnuboard7-hello_module/resources/layouts/admin/admin_markup_demo.g7.html',
        'modules/_bundled/gnuboard7-hello_module/resources/layouts/admin/admin_memo_form.g7.html',
        'modules/_bundled/gnuboard7-hello_module/resources/layouts/admin/admin_memo_list.g7.html',
        'modules/_bundled/gnuboard7-hello_module/resources/layouts/user/user_memo_list.g7.html',
        'modules/_bundled/sirsoft-board/resources/extensions/admin-ecommerce-inquiry-settings.g7.html',
        'modules/_bundled/sirsoft-board/resources/extensions/admin_dashboard_community.g7.html',
        'modules/_bundled/sirsoft-board/resources/extensions/admin_dashboard_quick_menu.g7.html',
        'modules/_bundled/sirsoft-board/resources/extensions/user-notification-detail.g7.html',
        'modules/_bundled/sirsoft-board/resources/extensions/user-notification-settings.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_form.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_index.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_post_detail.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_post_form.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_posts_index.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_reports_detail.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_reports_index.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_settings.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_form/_board_type_manage_modal.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_form/_tab_basic.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_form/_tab_list.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_form/_tab_notification.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_form/_tab_permissions.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_form/_tab_post.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_post_detail/_comment.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_post_detail/_comments.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_post_detail/_post_card_content.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_post_detail/_reply_card_content.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_post_form/_attachments.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_post_form/_form_fields.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_post_form/_parent_post.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_reports_detail/_alert_status.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_reports_detail/_card_history.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_reports_detail/_card_report_info.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_bulk_apply_modal.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_bulk_members_confirm.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_identity_policy_delete.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_identity_policy_form.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_mail_template_edit.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_notification_definition_reset.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_notification_template_edit.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_notification_template_preview.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_privacy_targets.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_unsaved_tab_switch.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_section_bulk_members.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_board_settings_attachment.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_board_settings_basic.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_board_settings_bulk_apply.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_board_settings_comment.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_board_settings_list.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_board_settings_notification.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_board_settings_permissions.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_board_settings_post.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_board_settings_reply.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_general.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_identity_policies.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_notification_definitions.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_report_policy.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_seo.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_spam_security.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/admin-user-currency-field.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/admin-user-mileage-tab.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/admin-user-shipping-country-field.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/admin_dashboard_commerce.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/admin_dashboard_quick_menu.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/checkout_cash_receipt.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/header-currency-selector-admin.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/header-currency-selector-user.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/mypage-profile-address-card.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/mypage-profile-mileage-card.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/mypage-withdraw-refund-credit-notice.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/mypage_order_cash_receipt.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/register-currency-field.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/register-shipping-country-field.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_brand_index.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_category_index.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_deposit_index.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_excel_download_index.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_main_banner_index.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_mileage_transaction_index.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_order_detail.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_order_index.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_order_list.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_payment_failure_history.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_personal_payment.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_personal_payment_create.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_personal_payment_detail.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_product_common_info_index.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_product_form.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_product_list.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_product_notice_index.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_product_review_index.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_promotion_coupon_form.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_promotion_coupon_list.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_promotion_discount_code_create.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_promotion_discount_code_index.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_settings.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_shipping_policy_form.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_shipping_policy_list.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_brand_index/_modal_delete_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_brand_index/_modal_editing_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_brand_index/_panel_brand_list.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_brand_index/_panel_detail.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_brand_index/_panel_form.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_brand_index/_panel_view.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_category_index/_modal_delete_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_category_index/_modal_image_preview.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_category_index/_panel_category_list.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_category_index/_panel_detail.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_category_index/_panel_form.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_category_index/_panel_view.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_deposit_index/_modal_bulk_match.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_deposit_index/_modal_manual_match.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_deposit_index/_modal_process_history.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_excel_download_index/_modal_download_history.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_main_banner_index/_modal_delete_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_main_banner_index/_modal_edit_cancel.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_main_banner_index/_modal_status_change.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_main_banner_index/_partial_banner_detail.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_main_banner_index/_partial_banner_form.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_main_banner_index/_partial_banner_list.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_main_banner_index/_partial_preview_slider.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_filters.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_modal_edit_transaction.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_modal_extend_expiry.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_modal_manual_transaction.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_transactions_table.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_detail/_modal_batch_change_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_detail/_modal_cancel_order.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_detail/_modal_confirm_deposit.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_detail/_modal_issue_cash_receipt.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_detail/_modal_reset_guest_password.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_detail/_modal_send_email.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_detail/_modal_send_sms.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_detail/_partial_activity_log.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_detail/_partial_claim_history.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_info.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_detail/_partial_payment_info.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_progress.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_index/_modal_excel_download.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_index/_modal_preset_manage.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_index/_modal_preset_save.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_list/_modal_bulk_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_list/_modal_excel_download.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_list/_modal_preset_delete_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_list/_modal_preset_edit.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_list/_modal_preset_manage.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_list/_modal_preset_save.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_list/_partial_bulk_action_section.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_list/_partial_filter_section.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_list/_partial_order_datagrid.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_personal_payment_create/_modal_member_search.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_personal_payment_create/_modal_order_search.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_common_info_index/_modal_content_mode_change.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_common_info_index/_modal_copy_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_common_info_index/_modal_default_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_common_info_index/_modal_delete_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_common_info_index/_modal_editing_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_common_info_index/_modal_set_default_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_common_info_index/_panel_detail.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_common_info_index/_panel_form.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_common_info_index/_panel_list.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_common_info_index/_panel_view.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_common_info_index/_partial_common_info_detail.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_common_info_index/_partial_common_info_form.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_modal_add_language.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_modal_additional_options_clear.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_modal_confirm_regenerate.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_modal_copy_product.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_modal_delete_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_modal_label_delete_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_modal_label_form.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_modal_label_uncheck_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_modal_multilingual_tag_edit.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_modal_notice_bulk_change.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_modal_notice_template_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_modal_save_template.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_partial_activity_log.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_partial_basic_info.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_partial_common_info.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_partial_description.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_partial_identification_codes.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_partial_image_upload.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_partial_other_info.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_partial_product_notice.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_partial_product_options.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_partial_sales_info.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_partial_seo_settings.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_partial_shipping.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_partial_shopping_integration.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_list/_modal_bulk_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_list/_modal_bulk_price.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_list/_modal_bulk_stock.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_list/_modal_copy_product.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_list/_modal_delete_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_list/_modal_excel_download.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_list/_modal_preset_delete_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_list/_modal_preset_edit.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_list/_modal_preset_manage.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_list/_modal_preset_save.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_list/_partial_filter_section.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_list/_partial_product_datagrid.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_notice_index/_modal_bulk_change.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_notice_index/_modal_delete_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_notice_index/_modal_editing_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_notice_index/_panel_detail.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_notice_index/_panel_form.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_notice_index/_panel_list.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_notice_index/_panel_view.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_review_index/_modal_image_preview.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_review_index/_modal_reply_delete.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_review_index/_modal_status_change.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_promotion_coupon_form/_partial_basic_info.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_promotion_coupon_form/_partial_benefit_settings.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_promotion_coupon_form/_partial_issue_settings.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_promotion_coupon_form/_partial_usage_conditions.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_modal_cancel_issue_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_modal_delete_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_modal_direct_issue.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_modal_issue_history.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_modal_status_change_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_partial_coupon_datagrid.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_partial_filter_section.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_promotion_discount_code_index/_modal_delete_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_promotion_discount_code_index/_modal_status_change.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_bank_accounts_cards.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_bank_accounts_table.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_bank_management_modal.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_currency_exchange_cards.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_currency_exchange_table.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_disable_international_shipping_modal.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_modal_clear_seo_cache.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_modal_identity_policy_delete.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_modal_identity_policy_form.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_modal_mail_template_edit.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_modal_notification_definition_reset.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_modal_notification_template_edit.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_modal_notification_template_preview.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_modal_privacy_targets.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_modal_unsaved_tab_switch.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_payment_methods_cards.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_payment_methods_list.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_refund_reason_cards.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_refund_reason_section.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_shipping_carrier_cards.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_shipping_carrier_section.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_shipping_country_cards.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_shipping_country_table.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_shipping_type_cards.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_shipping_type_section.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_basic_info.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_claim.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_identity_policies.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_language_currency.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_mileage.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_mileage_basic_card.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_mileage_currency_cards.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_mileage_currency_table.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_mileage_expiry_card.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_mileage_notification_card.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_notification_definitions.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_order_settings.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_privacy_retention.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_review_settings.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_seo.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_tab_shipping.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_claim_policy_section.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_exchange_reason_cards.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_exchange_reason_section.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_return_reason_cards.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_return_reason_section.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_shipping_policy_form/_modal_extra_fee_template.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_basic_info.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_charge_settings.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_country_basic_fields.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_country_tabs.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_extra_fee.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_claim_addresses.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_claim_country_fee.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_shipping_policy_list/_modal_bulk_delete.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_shipping_policy_list/_modal_bulk_toggle.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_shipping_policy_list/_modal_copy.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_shipping_policy_list/_modal_delete.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_shipping_policy_list/_modal_set_default.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_shipping_policy_list/_partial_bulk_actions.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_shipping_policy_list/_partial_datagrid.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_shipping_policy_list/_partial_filter.g7.html',
        'modules/_bundled/sirsoft-page/resources/layouts/admin/admin_page_detail.g7.html',
        'modules/_bundled/sirsoft-page/resources/layouts/admin/admin_page_form.g7.html',
        'modules/_bundled/sirsoft-page/resources/layouts/admin/admin_page_list.g7.html',
        'plugins/_bundled/gnuboard7-hello_plugin/resources/layouts/admin/plugin_settings.g7.html',
        'plugins/_bundled/sirsoft-ckeditor5/resources/extensions/html-content.g7.html',
        'plugins/_bundled/sirsoft-ckeditor5/resources/extensions/html-editor.g7.html',
        'plugins/_bundled/sirsoft-ckeditor5/resources/layouts/admin/ckeditor5_uploads.g7.html',
        'plugins/_bundled/sirsoft-ckeditor5/resources/layouts/admin/plugin_settings.g7.html',
        'plugins/_bundled/sirsoft-daum_postcode/resources/extensions/ecommerce-address-search.g7.html',
        'plugins/_bundled/sirsoft-daum_postcode/resources/layouts/admin/plugin_settings.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/extensions/cookie_banner.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/extensions/mypage_privacy_tab.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/gdpr_consent_log.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/gdpr_notice_log.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/partials/_shared/_policy_version_snapshot_modal.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/partials/plugin_settings/_modal_notification_definition_reset.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/partials/plugin_settings/_modal_notification_template_edit.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/partials/plugin_settings/_modal_notification_template_preview.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/partials/plugin_settings/_modal_privacy_targets.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/partials/plugin_settings/_policy_version_publish_modal.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/plugin_settings.g7.html',
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-detail.g7.html',
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-form.g7.html',
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-profile-view.g7.html',
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-profile.g7.html',
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-register.g7.html',
        'plugins/_bundled/sirsoft-marketing/resources/layouts/admin/plugin_settings.g7.html',
        'plugins/_bundled/sirsoft-message_bizppurio/resources/extensions/notification_log_result.g7.html',
        'plugins/_bundled/sirsoft-message_bizppurio/resources/extensions/notification_row_footer.g7.html',
        'plugins/_bundled/sirsoft-message_bizppurio/resources/extensions/notification_tab_board.g7.html',
        'plugins/_bundled/sirsoft-message_bizppurio/resources/extensions/notification_tab_core.g7.html',
        'plugins/_bundled/sirsoft-message_bizppurio/resources/extensions/notification_tab_ecommerce.g7.html',
        'plugins/_bundled/sirsoft-message_bizppurio/resources/extensions/notification_template_form_footer_actions.g7.html',
        'plugins/_bundled/sirsoft-message_bizppurio/resources/extensions/notification_template_form_sections.g7.html',
        'plugins/_bundled/sirsoft-message_bizppurio/resources/layouts/admin/plugin_settings.g7.html',
        'plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/admin_order_list_payment_method_badge.g7.html',
        'plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/admin_order_payment_query.g7.html',
        'plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/checkout_payment_error.g7.html',
        'plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/user_order_complete_receipt.g7.html',
        'plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/user_order_show.g7.html',
        'plugins/_bundled/sirsoft-pay_kginicis/resources/layouts/admin/plugin_settings.g7.html',
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/admin_order_list_payment_method_badge.g7.html',
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/admin_order_payment_query.g7.html',
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/checkout_easy_pay.g7.html',
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/user_order_complete_receipt.g7.html',
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/user_order_show.g7.html',
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/layouts/admin/plugin_settings.g7.html',
        'plugins/_bundled/sirsoft-pay_nicepayments/resources/extensions/admin_order_list_payment_method_badge.g7.html',
        'plugins/_bundled/sirsoft-pay_nicepayments/resources/extensions/admin_order_payment_query.g7.html',
        'plugins/_bundled/sirsoft-pay_nicepayments/resources/extensions/user_mypage_order_receipt.g7.html',
        'plugins/_bundled/sirsoft-pay_nicepayments/resources/extensions/user_order_complete_receipt.g7.html',
        'plugins/_bundled/sirsoft-pay_nicepayments/resources/layouts/admin/plugin_settings.g7.html',
        'plugins/_bundled/sirsoft-tosspayments/resources/extensions/admin_order_payment.g7.html',
        'plugins/_bundled/sirsoft-tosspayments/resources/extensions/checkout-payment-error.g7.html',
        'plugins/_bundled/sirsoft-tosspayments/resources/extensions/user_order_show.g7.html',
        'plugins/_bundled/sirsoft-tosspayments/resources/layouts/admin/plugin_settings.g7.html',
        'plugins/_bundled/sirsoft-totp/resources/extensions/admin_user_totp_card.g7.html',
        'plugins/_bundled/sirsoft-totp/resources/layouts/admin/plugin_settings.g7.html',
        'plugins/_bundled/sirsoft-verification_kginicis/resources/extensions/identity_provider_inicis.g7.html',
        'plugins/_bundled/sirsoft-verification_kginicis/resources/extensions/mypage_identity_card.g7.html',
        'plugins/_bundled/sirsoft-verification_kginicis/resources/layouts/admin/plugin_settings.g7.html',
        'plugins/_bundled/sirsoft-verification_nhnkcp/resources/extensions/identity_provider_nhnkcp.g7.html',
        'plugins/_bundled/sirsoft-verification_nhnkcp/resources/extensions/mypage_identity_card.g7.html',
        'plugins/_bundled/sirsoft-verification_nhnkcp/resources/layouts/admin/plugin_settings.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/_admin_base.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/admin_dashboard.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/401.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/403.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/404.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/500.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/503.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/maintenance.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/_user_base.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/401.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/403.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/404.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/500.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/503.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/maintenance.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/home.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/_admin_base.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_activity_log_list.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_dashboard.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_forgot_password.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_identity_logs.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_language_pack_list.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_language_packs.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_login.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_menu_list.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_module_language_packs.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_module_list.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_notification_log_list.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_plugin_language_packs.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_plugin_list.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_reset_password.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_role_form.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_role_list.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_schedule_list.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_settings.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_template_language_packs.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_template_layout_edit.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_template_list.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_user_detail.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_user_form.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_user_list.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/auth/identity_challenge.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/errors/401.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/errors/403.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/errors/404.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/errors/500.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/errors/503.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/errors/maintenance.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/_identity_challenge_modal.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/_modal_changelog.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/_modal_license.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/_modal_notification_delete_all_confirm.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_activity_log_list/_partial_datagrid.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_activity_log_list/_partial_filter.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_extension_language_packs/_content.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_identity_logs/_modal_log_detail.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_identity_logs/_modal_purge_confirm.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_identity_logs/_partial_datagrid.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_identity_logs/_partial_filter.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_language_pack_list/_content.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_language_pack_list/_drawer_manifest_preview.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_language_pack_list/_modal_detail.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_language_pack_list/_modal_install.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_language_pack_list/_modal_install_bundled.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_language_pack_list/_modal_refresh_cache.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_language_pack_list/_modal_slot_conflict.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_language_pack_list/_modal_uninstall.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_language_pack_list/_modal_update.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_menu_list/_modal_bulk_roles_confirm.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_menu_list/_modal_delete.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_menu_list/_panel_bulk_roles.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_menu_list/_panel_detail.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_menu_list/_panel_form.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_menu_list/_panel_menu_list.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_menu_list/_panel_view.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_module_list/_drawer_manifest_preview.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_module_list/_modal_deactivate_warning.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_module_list/_modal_detail.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_module_list/_modal_extension_license.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_module_list/_modal_force_activate.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_module_list/_modal_force_deactivate.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_module_list/_modal_install.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_module_list/_modal_manual_install.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_module_list/_modal_reactivate_language_packs.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_module_list/_modal_refresh_layouts.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_module_list/_modal_uninstall.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_module_list/_modal_update.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_notification_log_list/_modal_log_detail.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_notification_log_list/_partial_datagrid.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_notification_log_list/_partial_filter.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_plugin_list/_drawer_manifest_preview.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_plugin_list/_modal_detail.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_plugin_list/_modal_extension_license.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_plugin_list/_modal_force_activate.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_plugin_list/_modal_force_deactivate.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_plugin_list/_modal_install.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_plugin_list/_modal_manual_install.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_plugin_list/_modal_reactivate_language_packs.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_plugin_list/_modal_refresh_layouts.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_plugin_list/_modal_uninstall.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_plugin_list/_modal_update.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_role_list/_modal_delete.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_schedule_list/_modal_delete.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_schedule_list/_modal_duplicate.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_schedule_list/_modal_form.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_schedule_list/_modal_history.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_schedule_list/_modal_run.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_schedule_list/_tab_schedules.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_cache_delete.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_core_changelog.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_core_update_guide.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_core_update_result.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_identity_policy_delete.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_identity_policy_form.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_ip_restriction_rule_form.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_notification_channel_off_confirm.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_notification_definition_reset.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_notification_identity_definition_add.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_notification_identity_definition_delete.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_notification_template_form.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_notification_template_preview.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_password_confirm.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_privacy_targets.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_static_cache_republish.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_unsaved_tab_switch.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_advanced.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_drivers.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_general.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_identity.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_identity_basic.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_identity_messages.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_identity_policies.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_identity_providers.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_info.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_language_packs.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_mail.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_notification_definitions.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_privacy.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_security.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_security_activity_log.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_security_ip_restriction.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_security_password_policy.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_seo.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_upload.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_layout_edit/_modal_extension_preview_layout.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_layout_edit/_modal_extension_version_history.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_layout_edit/_modal_version_history.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_list/_drawer_manifest_preview.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_list/_modal_activate.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_list/_modal_deactivate.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_list/_modal_detail.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_list/_modal_extension_license.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_list/_modal_force_activate.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_list/_modal_install.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_list/_modal_manual_install.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_list/_modal_reactivate_language_packs.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_list/_modal_refresh_layouts.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_list/_modal_uninstall.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_list/_modal_update.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_list/_tab_admin.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_list/_tab_user.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_user_list/_modal_bulk_roles.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/template_partial_test/_content_section.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/template_partial_test/_header_section.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/template_partial_test/_info_card.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/template_partial_test.g7.html',
        'templates/_bundled/sirsoft-basic/extensions/sirsoft-daum_postcode/user-address-search.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/_user_base.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/auth/forgot_password.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/auth/identity_challenge.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/auth/identity_verify.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/auth/login.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/auth/register.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/auth/reset_password.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/board/boards.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/board/form.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/board/index.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/board/popular.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/board/show.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/errors/401.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/errors/403.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/errors/404.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/errors/500.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/errors/503.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/errors/maintenance.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/home.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/mypage/addresses.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/mypage/board.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/mypage/change-password.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/mypage/inquiries.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/mypage/mileage.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/mypage/notifications.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/mypage/orders.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/mypage/orders/show.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/mypage/profile-edit.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/mypage/profile.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/mypage/wishlist.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/page/show.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/_identity_challenge_modal.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/_modal_notification_delete_all_confirm.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/auth/_login_form.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/auth/_modal_privacy.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/auth/_modal_terms.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/auth/_redirect_if_logged_in.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/auth/_register_form.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/boards/_board_card.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/form/_parent_post.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/form/_password_verify_modal.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/form/_post_form.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/form/_type_renderer.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/index/_admin_links.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/index/_empty_states.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/index/_loading_error.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/index/_type_renderer.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/index/_write_button.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/popular/_popular_item.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/popular/_popular_list.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/show/_comment_input.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/show/_comment_item.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/show/_comment_section.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/show/_navigation.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/show/_post_attachments.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/show/_reply_section.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/show/_type_renderer.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/show/modals/_modal_delete.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/show/modals/_modal_report.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/show/modals/_password_verify_modal.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/types/basic/form.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/types/basic/index.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/types/basic/show.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/types/card/index.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/board/types/gallery/index.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/common/_currency_selector.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/home/_board_summary.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/home/_community_guide.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/home/_popular_boards.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/home/_recent_posts.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/home/_shop_promo.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/home/_stat_card_boards.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/home/_stat_card_comments.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/home/_stat_card_posts.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/home/_stat_card_users.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/home/_welcome_card.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/_tab_navigation.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/addresses/_list.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/addresses/_modal_address.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/addresses/_modal_confirm_delete.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/addresses/_modal_confirm_overwrite.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/board/_list.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/board/_my_comments.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/board/_my_posts.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/inquiries/_list.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/mileage/_list.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/notifications/_list.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_history.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_items.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_list.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_modal_change_address.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_modal_confirm_purchase.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_modal_write_review.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_orderer.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_payment.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_shipping.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_status_header.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/profile/_edit.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/profile/_modal_withdraw.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/profile/_password_verify_section.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/profile/_view.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/wishlist/_list.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/search/_search_filters.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/search/_search_input.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/search/_search_results.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/search/_search_states.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/search/_search_tabs.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/search/pages/_section.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/search/posts/_list.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/search/posts/_section.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/search/products/_item_card.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/search/products/_section.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_cart_item.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_cart_list.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_cart_summary.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_checkout_discount.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_checkout_items.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_checkout_mileage.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_checkout_orderer.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_checkout_payment.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_checkout_shipping.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_checkout_summary.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_modal_address_manage.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_modal_cart_delete_confirm.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_modal_cart_option_change.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_modal_cart_unavailable.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_modal_coupon_download.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_modal_exclusive_coupon_confirm.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_modal_temp_order_not_found.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_admin_edit_link.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_header.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_info_summary.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_modal_cart_added.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_modal_coupon_download_confirm.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_modal_inquiry_delete.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_modal_login_required.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_modal_qna_reply.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_modal_qna_write.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_modal_review_image.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_price_mobile.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_purchase_card.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_review_avatar.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_tab_detail.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_tab_qna.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/detail/_tab_reviews.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/list/_category_breadcrumb.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/list/_category_filter.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/list/_new_products.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/list/_popular_products.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/list/_product_grid.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/list/_recent_products.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/list/_search_filter_bar.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/search/index.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/shop/cart.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/shop/category.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/shop/checkout.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/shop/guest_order_form.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/shop/guest_order_show.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/shop/index.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/shop/order_complete.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/shop/reorder.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/shop/show.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/users/posts.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/users/show.g7.html',
        // ── develop-7.1.0 2차 병합 전수 보존 검사(2026-09-17)가 등재한 39건. 저작 트랙 종료 게이트를 1·2차 병합
        // 어느 회차도 돌리지 않아 세 갈래가 검사 없이 합류했다 — issue524 가 만든 회원 데이터 관리자 화면·
        // 스크랩·알림 목록·코드 편집기 모달 14건(base 선재), #525 반응·신고 저작본 13건, #675 수출입은행
        // 환율 플러그인 12건. 전건 짝 `.json` 굽기 최신·escape 0 을 이 게이트가 다시 잰다.
        'modules/_bundled/sirsoft-board/resources/extensions/admin-user-reactions-tab.g7.html',
        'modules/_bundled/sirsoft-board/resources/extensions/admin-user-scraps-tab.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_reactions_index.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_scraps_index.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_reactions_index/_partial_filter.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_scraps_index/_partial_filter.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_reaction_type_cards.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_reaction_type_editor.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/admin-user-ecommerce-data-tab.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_cart_index.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_inquiry_index.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_user_address_index.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_user_address_index/_modal_bulk_delete_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_user_address_index/_modal_delete_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_user_address_index/_modal_error.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_user_address_index/_partial_address_datagrid.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_user_address_index/_partial_filter_section.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_wishlist_index.g7.html',
        'plugins/_bundled/sirsoft-koreaexim/resources/layouts/admin/partials/plugin_settings/_history_filter.g7.html',
        'plugins/_bundled/sirsoft-koreaexim/resources/layouts/admin/partials/plugin_settings/_history_grid.g7.html',
        'plugins/_bundled/sirsoft-koreaexim/resources/layouts/admin/partials/plugin_settings/_modal_notification_definition_reset.g7.html',
        'plugins/_bundled/sirsoft-koreaexim/resources/layouts/admin/partials/plugin_settings/_modal_notification_template_edit.g7.html',
        'plugins/_bundled/sirsoft-koreaexim/resources/layouts/admin/partials/plugin_settings/_modal_notification_template_preview.g7.html',
        'plugins/_bundled/sirsoft-koreaexim/resources/layouts/admin/partials/plugin_settings/_notifications_card.g7.html',
        'plugins/_bundled/sirsoft-koreaexim/resources/layouts/admin/partials/plugin_settings/_recent_runs.g7.html',
        'plugins/_bundled/sirsoft-koreaexim/resources/layouts/admin/partials/plugin_settings/_settings_form.g7.html',
        'plugins/_bundled/sirsoft-koreaexim/resources/layouts/admin/partials/plugin_settings/_status_card.g7.html',
        'plugins/_bundled/sirsoft-koreaexim/resources/layouts/admin/partials/plugin_settings/_tab_history.g7.html',
        'plugins/_bundled/sirsoft-koreaexim/resources/layouts/admin/partials/plugin_settings/_tab_settings.g7.html',
        'plugins/_bundled/sirsoft-koreaexim/resources/layouts/admin/plugin_settings.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_member_notification_list.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_member_notification_list/_partial_datagrid.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_member_notification_list/_partial_filter.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_layout_edit/_modal_file_delete_confirm.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_template_layout_edit/_modal_file_form.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/mypage/scrap.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/mypage/claims.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/mypage/claims/apply.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/mypage/claims/show.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/board/_modal_reaction_bulk_delete.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/board/_modal_reaction_delete.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/board/_modal_report_cancel.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/board/_my_reactions.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/board/_my_reports.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/scrap/_list.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/scrap/_modal_scrap_bulk_delete.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/scrap/_modal_scrap_delete.g7.html',
        // ── develop-7.1.0 1차 병합(2026-09-26) 뒤 저작 트랙 종료 게이트가 잡은 31건. 양쪽 부채의 합류다 — #636
        // 개인정보 조치 직권 실행 확인 창·보유기간 탭 5건(게시판 2·쇼핑몰 1·gdpr 1·코어 1)과 #682 방문자 통계
        // 화면·조각 26건(대시보드 위젯·sirsoft-basic 방문자 수 조각 포함). 주석 앵커(S21)를 채워 짝 `.json` 을 다시 구웠다.
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_privacy_run.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_privacy_retention.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_settings/_modal_privacy_run.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/partials/plugin_settings/_modal_privacy_run.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_analytics_actions.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_analytics_bots.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_analytics_diagnostics.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_analytics_environment.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_analytics_overview.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_analytics_pages.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_analytics_patterns.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_analytics_privacy_requests.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_analytics_realtime.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_analytics_referrers.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_analytics_regions.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_analytics_settings.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_analytics_visitor_detail.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/admin_analytics_visitors.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_analytics/_modal_purge_all.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_analytics/_modal_reaggregate.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_analytics/_modal_retro_anonymize.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_analytics/_modal_row_trend.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_analytics/_modal_subject_delete.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_analytics/_partial_kpi_row.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_analytics/_partial_notification_definitions.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_analytics/_partial_period_bar.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_analytics/_partial_realtime_bar.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_analytics/_partial_report_grid.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_analytics/_partial_status_alerts.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_dashboard/_analytics_widget.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_privacy_run.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_password_expiry_run.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/_visitor_counter.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/claims/_apply_comparison.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/claims/_apply_items.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/claims/_apply_reason.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/claims/_apply_redelivery.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/claims/_apply_refund_account.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/claims/_apply_summary.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/claims/_detail_claim_info.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/claims/_detail_items.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/claims/_detail_progress.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/claims/_detail_reason.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/claims/_detail_shipping.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/claims/_list.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/claims/_modal_change_product.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/claims/_modal_image_viewer.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/claims/_modal_withdraw.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_claim_detail.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_claim_list.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_item_quantity_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_refund_execute.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_activity_log.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_comparison.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_items.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_payment.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_reason.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_shipping.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_modal_bulk_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_modal_claim_complete.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_modal_preset_delete_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_modal_preset_edit.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_modal_preset_manage.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_modal_preset_save.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_partial_bulk_action_section.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_partial_claim_datagrid.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_partial_filter_section.g7.html',
        // ── #599 상품 행 송장·선교환·관리자 직권 접수(2026-09-25) 가 새로 저작한 모달 4건.
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_exchange_mode_confirm.g7.html',
        // 선행 커밋(첨부 전체 삭제 확인 창)이 저작하고 등재를 빠뜨린 1건 — 같은 게이트가 굽기 최신·escape 0 을 잰다.
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_images_clear.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_request_edit.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_withdraw_on_behalf.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_detail/_modal_admin_claim_request.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/shop/guest_claim_apply.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/shop/guest_claim_show.g7.html',
        // ── #705 회원관리 목록 필터 개편이 새로 저작한 조각 1건 + 이커머스 확장 필터 예제 2건.
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_user_list/_partial_filter_section.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/admin-user-list-filter-mileage.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/extensions/admin-user-list-filter-address.g7.html',
    ];

    /**
     * 1급 문법이 없어 원시 JSON 으로 남는 자리 (U-RAWJSON — D41 → D42 전면 재집계 → **D42 종료로 비었다**).
     *
     * D41 은 태그 본문(`<params>`/`<fallback>`/`<prop>`/`<props>`)을 "설계된 정본" 으로 보아 계량에서
     * 뺐으나, 설계 검토 지적(3회차)으로 **그 제외를 폐기**했다. 태그 예외 없이 다시 세자 대장은 35 로 늘었고
     * (늘어난 것은 새 결함이 아니라 원래 있었으나 안 세던 것), D42 문법 신설이 그것을 전부 해소했다:
     *
     *  - 빈 컬렉션 — `[]`·`{}` 는 구조가 없는 값 리터럴이라 계량 대상이 아니다(D42 선행 판정).
     *    착수 시 7건이었고 이행 후 **16건**이다(`:value` 3→12) — 늘어난 9는 속성 JSON 안에 묻혀
     *    있던 빈 배열이 `<field :value="[]"/>` 리프로 드러난 것이라, 소스 파일의 JSON 은 오히려 줄었다.
     *  - G1 데이터소스 속성 field-map / G2 중첩 `<field>` 트리 → `<params>` 3 · `<fallback>` 6 소멸
     *  - G3 `<prop>` `<field>` / G4 `<prop>` `<item>` / G5 `<props>` `<append>` → `<prop>` 4 · `<props>` 2 소멸
     *  - G6 액션 구조값 파라미터 `<field name>` (중첩 객체·문자열 리스트 포함) → 속성값 전량 소멸
     *  - G7 확장 주입 `<props>` 의 DataGrid 컬럼 트리 → `<props>` 3 소멸
     *  - G8 `switch` 분기 대상 배치 분리(`on`=params.value / `value`=top-level) → G7 이 드러낸
     *    액션 트리 `<json>` 탈출 3건 소멸 (S16 F2 결함을 **고치지 않고 표기만** 갈랐다 — D38 바이트 동등)
     *
     * **묵인이 아니라 열거다.** 새 원시 JSON 이 생기면 그대로 실패하고, 실패를 등재로 덮으려면
     * 그 자리에 **문법이 없다는 근거**를 주석으로 함께 적어야 한다.
     *
     * 대장이 비는 것이 이상적이지만 **빈 상태가 정상 상태는 아니다.** D42 종료 시점(㉙, 07-21)에는
     * 실제로 비었으나, 그 뒤 W4(㉟)가 정수 리스트 리터럴 2건, W6(㊴)이 상태 트리 리스트 2건,
     * S16(㊵)이 F3 를 고쳐 드러난 `:ui` 1건을 근거와 함께 등재해 현재 **4파일 / 5쌍**이다.
     * 이 값들은 D42 가 **표기를 주지 않기로 확정한 형태**라 문법 신설 대상이 아니다
     * (`EmitsMarkup::isStructuredValue` 독블록).
     * 종전 독블록이 "비어 있는 것이 정상" 이라고 단언해 아래 배열과 정면으로 모순이었다(2026-07-26 정정).
     *
     * 값은 라벨→**정확 건수** 맵이다. 게이트가 다중집합을 정확 대조하므로 등재 라벨이라도 건수가
     * 늘면(예: `:value` 3→5) 실패한다 — 라벨뿐 아니라 건수까지 봉인한다.
     *
     * @var array<string, array<string, int>> 파일(상대경로) → (허용 방출 라벨 → 정확 건수)
     */
    private const RAW_JSON_PENDING_GRAMMAR = [
        // W4 — **정수 리스트 리터럴**. `[5, 4, 3, 2, 1]`(별점 눈금)은 문자열 아닌 스칼라 원소라
        // D42 가 **표기를 주지 않기로 확정한** 형태다: `<item :value="5"/>` 는 객체 `{"value": 5}` 와
        // 구별되지 않아 왕복이 깨진다({@see EmitsMarkup::isStructuredValue} 독블록). 문법이 없다는
        // 근거가 설계 결정 자체이므로 등재한다 — 새 문법이 생기면 이 행을 지우고 재생성한다.
        'templates/_bundled/sirsoft-basic/layouts/shop/show.g7.html' => [':starList' => 1, ':starNums' => 1],
        'templates/_bundled/sirsoft-basic/layouts/shop/guest_claim_apply.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/shop/guest_claim_show.g7.html',
        // W6 — **상태 트리의 표기 불가 리스트**. G18(D53)이 상태 트리 `<field>` 에 `<item>` 을 열어
        // 문자열 리스트 13건을 해소했고, 남은 둘은 원소가 문자열이 아니거나(`[null,null,null,null]`)
        // 빈 문자열(`["","","","",""]`)이라 `<item>` 이 객체 `{"value":…}`·빈 원소와 구별되지 않는
        // 형태다({@see EmitsMarkup::isStructuredValue}). W4 의 정수 리스트와 같은 근거로 등재한다.
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_order_index.g7.html' => [':value' => 1],
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_form/_partial_basic_info.g7.html' => [':value' => 1],
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_item_quantity_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_refund_execute.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_activity_log.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_comparison.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_items.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_payment.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_reason.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_shipping.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_modal_bulk_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_modal_claim_complete.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_modal_preset_delete_confirm.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_modal_preset_edit.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_modal_preset_manage.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_modal_preset_save.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_partial_bulk_action_section.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_partial_claim_datagrid.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_claim_list/_partial_filter_section.g7.html',
        // S16 — **결함을 고치자 드러난 원시 JSON**. 이 파일의 `init_actions` 는 액션 `condition`(F3)
        // 때문에 통째로 `<json>` 탈출구에 들어가 있었고, 그래서 그 안의 `:ui` 는 계량에 잡히지 않았다.
        // F3 를 고쳐 탈출구가 사라지자 비로소 보인다 — G14 가 겪은 "웨이브가 바뀌어야 보이는 비대칭"과
        // 같은 계열이며, **결함 수정도 새 표면을 연다**는 것이 이 행의 교훈이다.
        // 표기 불가 근거는 위 두 항목과 동일하다: `ui.selectedCategories` 가 `[null,null,null,null]` 이라
        // `<item>` 이 객체·빈 원소와 구별되지 않는다({@see EmitsMarkup::isStructuredValue}).
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_product_form.g7.html' => [':ui' => 1],
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_claim_detail.g7.html',
        'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_claim_list.g7.html',
    ];

    /**
     * 이관에서 **의도적으로 제외**한 원본 `.json` 과 그 사유 (S16 이월 대장 — ㉘ 감사가 등재한 미조치 장치).
     *
     * 웨이브는 escape 유발 결함이 있는 파일을 이관하지 않고 원본 `.json` 으로 남긴다(D40 — 고치는
     * 작업과 옮기는 작업을 섞지 않는다). 문제는 **그 다음**이다: S16 이 결함을 고치고 나면 escape 가
     * 0 이 되므로 "escape 가 남아 있어서 눈에 띈다" 는 신호가 사라지고, 이관을 잊어도 아무 게이트도
     * 실패하지 않는다. `MIGRATED_MARKUP_FILES` 는 **반대 방향만** 잡는다 — `.g7.html` 을 만들고 목록에
     * 안 넣으면 "잉여" 로 실패하지만, `.json` 만 고치고 `.g7.html` 을 안 만들면 스캔·목록이 모두
     * 그대로라 green 이다.
     *
     * 그래서 이 상수가 **"제외했다는 사실 자체"** 를 봉인한다. 여기 등재된 `.json` 은 짝 `.g7.html` 이
     * 없어야 하고(있으면 목록 미등재 이관이므로 실패), 반대로 **S16 이 결함을 고쳐 이관을 마치면 그
     * 행을 지우고 `MIGRATED_MARKUP_FILES` 로 옮겨야** 한다.
     *
     * **S16 에서 비었다** — 68행(W1 4 · W2 3 · W4 14 · W5 6 · W6 41 · 삭제 2)이 전부 해소됐다. 결함은
     * {@see LayoutDefectLedgerTest} 가 계열별 0건으로 봉인하고, 이관 자체는 아래 `MIGRATED_MARKUP_FILES`
     * 가 스캔 결과와의 정확 일치로 봉인한다. 앞으로 새 웨이브가 제외를 만들면 여기에 다시 등재한다.
     *
     * @var array<string, string> 제외한 원본 `.json`(상대경로) → 사유
     */
    private const EXCLUDED_FROM_MIGRATION = [];

    /**
     * 이관된 `.g7.html` 이 원본과 100% 동등하고 escape-0 임을 봉인합니다 (S10 W1 DoD).
     *
     * 각 `.g7.html` 을 설치 시점과 같은 파사드로 컴파일해 짝 `.json` 과 정규화 후 deep-equal 을 보고,
     * 소스 파일에 `<json>` 탈출구가 하나도 없음을 확인한다. 기계 이관이 원본 기능을 100% 보존한다는
     * (D38) 계약의 회귀 게이트다. 짝 `.json` 부재·레지스트리 미해석은 **skip 이 아니라 실패**다 —
     * 조용히 건너뛰면 이관이 깨져도 green 이 된다.
     *
     * ## S18.5 — 이 테스트의 **의미가 바뀌었다** (코드는 그대로다)
     *
     * 굽기(`layout:decompile --all --sync`) 이전에 짝 `.json` 은 **손유지 저작물**이었고, 이 단언은
     * *"두 소스가 서로 일치하는가"* 를 물었다. 굽기 이후 짝은 **기계 산출물**이므로 같은 단언이
     * 이제 *"굽기가 최신인가"* 를 묻는다.
     *
     * 이 변화를 여기 적어 두는 이유는 **실패했을 때 할 일이 정반대**이기 때문이다. 종전에는 "`.json`
     * 을 손으로 맞춰라" 가 맞았지만, 지금 그렇게 하면 다음 굽기에 그 편집이 소실된다. 지금 이
     * 테스트가 red 면 할 일은 하나다:
     *
     * ```bash
     * php artisan layout:decompile --all --sync
     * ```
     *
     * 바이트 단위 최신성은 `--check` 가 따로 본다(이 단언은 정규화 후 비교라 키 순서·들여쓰기
     * 차이를 통과시킨다 — 두 검사는 강도가 다르고 둘 다 필요하다).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects every_migrated_markup_file_matches_its_json_and_is_escape_free, first_class_grammar_replaces_raw_json_in_migrated_markup
     *
     * @return void 반환값 없음
     */
    public function test_every_migrated_markup_file_matches_its_json_and_is_escape_free(): void
    {
        $resolver = new MarkupCompileContextResolver;

        // 스캔 결과가 명시 목록과 정확히 일치해야 한다 (누락도 잉여도 회귀).
        $scanned = array_map(
            fn (string $path): string => str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)),
            $this->scanMarkupFiles(),
        );
        sort($scanned);

        $expected = self::MIGRATED_MARKUP_FILES;
        sort($expected);

        $this->assertSame($expected, $scanned, '이관된 .g7.html 집합이 명시 목록과 다릅니다 (누락 또는 미등록 신규 이관).');

        $failures = [];

        foreach (self::MIGRATED_MARKUP_FILES as $relative) {
            $markupFile = base_path($relative);

            if (! File::exists($markupFile)) {
                $failures[] = "{$relative} — 이관 소스 파일이 없습니다";

                continue;
            }

            $jsonFile = preg_replace('/\.g7\.html$/i', '.json', $markupFile);
            $source = File::get($markupFile);

            if (str_contains($source, '<json')) {
                $failures[] = "{$relative} — <json> 탈출구가 남아 있음 (escape≠0)";

                continue;
            }

            // 템플릿 자신의 파일만 검사용 레지스트리가 있다 — 모듈·플러그인 파일은 템플릿 없이 컴파일한다.
            $registry = $resolver->registryFor($markupFile);

            // D41 — `<json>` 태그만 세면 속성값·`<prop>` 본문의 통짜 JSON 을 놓친다.
            // 1급 문법이 있는데 blob 으로 떨어진 자리를 여기서 잡는다.
            // 아직 문법이 없는 자리(U-RAWJSON ⓒ)는 아래 대장에 **명시 등재**된 것만 통과한다 —
            // 라벨뿐 아니라 **건수까지** 정확 다중집합으로 대조하므로, 등재 라벨이라도 건수가
            // 늘거나(예: `:value` 3→5) 미등재 라벨이 새로 뜨면 그대로 실패한다.
            //
            // 레지스트리를 함께 넘긴다(D53) — 컴포넌트 노드의 텍스트는 화면에 그려질 콘텐츠라
            // 문법으로 없앨 수 없다. 넘기지 않으면 그런 표시용 JSON 이 영원히 닫히지 않는 대장
            // 항목으로 남아 진짜 잔여와 구분되지 않는다.
            //
            // 모듈·플러그인 파일은 붙을 템플릿이 정해지지 않으므로(컴파일은 템플릿과 무관) 계량 보조로만
            // 저장소에 있는 모든 템플릿의 컴포넌트를 합친 목록을 쓴다 — 특정 템플릿을 고르지 않는다.
            $emitted = array_count_values(LayoutMarkupRoundTrip::rawJsonEmissions($source, $registry ?? $this->allTemplateComponents()));
            ksort($emitted);

            $allowed = self::RAW_JSON_PENDING_GRAMMAR[$relative] ?? [];
            ksort($allowed);

            if ($emitted !== $allowed) {
                $format = static function (array $counts): string {
                    if ($counts === []) {
                        return '(없음)';
                    }

                    return implode(', ', array_map(
                        static fn (string $k, int $n): string => "{$k}×{$n}",
                        array_keys($counts),
                        array_values($counts),
                    ));
                };

                $emittedStr = $format($emitted);
                $allowedStr = $format($allowed);

                $failures[] = "{$relative} — 원시 JSON 방출이 대장과 불일치: 실측 [{$emittedStr}] · 대장 [{$allowedStr}]";

                continue;
            }

            if (! File::exists($jsonFile)) {
                $failures[] = "{$relative} — 짝 원본 .json 이 없습니다 (병존 규약 위반)";

                continue;
            }

            // 레지스트리는 원시 JSON 계량(:552)에서 이미 해석했다 — 그대로 재사용한다.
            $original = json_decode(File::get($jsonFile), true);

            try {
                $compiled = (new LayoutMarkupCompiler($registry))->compile(
                    $source,
                    $markupFile,
                    $resolver->contextFor($markupFile),
                )->json;
            } catch (\Throwable $e) {
                $failures[] = "{$relative} — 컴파일 오류: {$e->getMessage()}";

                continue;
            }

            // 왕복 오라클(`LayoutMarkupRoundTrip::verify`)과 **동일한 기준**으로 비교한다 —
            // `normalizeDocument` 를 빼면 문서층 별칭(`state`→`initLocal`)과 "옮기지 않고 버리는"
            // 사어 키(`schema`·`pageConfig`·`_comment_*`)가 차이로 잡혀, 정상 이관이 실패로 보고된다.
            $a = LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize(LayoutMarkupRoundTrip::normalize(LayoutMarkupRoundTrip::normalizeDocument($original))));
            $b = LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize(LayoutMarkupRoundTrip::normalize(LayoutMarkupRoundTrip::normalizeDocument($compiled))));

            if ($a !== $b) {
                $failures[] = "{$relative} — 컴파일 산출이 원본 .json 과 다름";
            }
        }

        $this->assertSame([], $failures, '이관 동등성·escape-0 위반:'.PHP_EOL.implode(PHP_EOL, array_slice($failures, 0, 20)));
    }

    /**
     * 이관에서 제외한 원본 `.json` 집합이 대장과 정확히 일치함을 봉인합니다 (㉘ 감사 미조치 장치 이행).
     *
     * **이 단언이 잡는 방향이 다른 게이트와 반대다.** `MIGRATED_MARKUP_FILES` 는 "`.g7.html` 을 만들고
     * 목록에 안 넣으면" 실패하지만, 그 반대 — **결함만 고치고 이관을 잊는 것** — 은 스캔·목록이 모두
     * 그대로라 green 이다. 게다가 S16 이 결함을 고치고 나면 escape 가 0 이 되므로 "escape 가 남아 있어
     * 눈에 띈다" 는 수동 신호마저 사라진다. 이관 망각이 조용히 통과할 수 있는 유일한 구멍이라
     * 여기서 막는다.
     *
     * 판정은 **코퍼스 전량**이다. 종전에는 "`.g7.html` 이 하나라도 있는 확장" 으로 범위를 좁혔는데
     * (웨이브 진행 중에는 미이관 확장이 정상 상태였으므로), 그 규칙이 **확장 하나가 통째로 이관을
     * 건너뛴 경우를 구조적으로 못 보는 사각**을 만들었다 — `gnuboard7-hello_plugin` 은 레이아웃이
     * `plugin_settings.json` 하나뿐이라 W1 이 지나갔는데도 "웨이브가 닿지 않은 확장" 으로 분류돼
     * 대장에도 게이트에도 걸리지 않았고, S16 감사가 코퍼스 `.json` 635 vs `.g7.html` 634 를 손으로
     * 세고서야 드러났다. 이관이 끝난 지금은 좁힐 이유가 없으므로 **전 코퍼스**를 본다.
     *
     * S16 이 결함을 고치고 이관을 마치면 해당 행을 지우고 `MIGRATED_MARKUP_FILES` 로 옮긴다 —
     * **S16 종료 시 이 상수는 비어야 한다.**
     *
     * @scenario compile_unit=install,source_health=defect
     *
     * @effects migration_exclusions_are_pinned_with_reasons
     *
     * @return void 반환값 없음
     */
    public function test_migration_exclusions_match_the_ledger(): void
    {
        $relative = static fn (string $path): string => str_replace(
            '\\',
            '/',
            str_replace(base_path().DIRECTORY_SEPARATOR, '', $path),
        );

        $markup = array_map($relative, $this->scanMarkupFiles());

        // 34차 — 대장이 S16 에서 비었으므로 이 축의 단언은 `assertSame([], [])` 가 됐고, 그러면
        // **스캔이 죽어 0건이 되어도 green** 이다(31차가 "결함 대장 19행이 공허하게 참" 으로 잡은
        // 형태의 재발). 대장이 빈 상태에서도 성립해야 하는 것은 "모집단이 살아 있다" 이므로,
        // 하한은 대장이 아니라 스캔 쪽에 건다.
        $this->assertNotEmpty($markup,
            '소스 스캔이 0건이다 — 글롭이 낡으면 제외 대장이 비어 있는 한 이 테스트는 영원히 '
            .'통과한다. 모집단 도출이 살아 있는지 먼저 확인해야 한다');

        $unpaired = [];

        foreach (array_map($relative, $this->scanLayoutFiles()) as $json) {
            if (! in_array(preg_replace('/\.json$/i', '.g7.html', $json), $markup, true)) {
                $unpaired[] = $json;
            }
        }

        sort($unpaired);

        $ledger = array_keys(self::EXCLUDED_FROM_MIGRATION);
        sort($ledger);

        $this->assertSame(
            $ledger,
            $unpaired,
            '이관 제외 집합이 대장과 다릅니다 — 결함만 고치고 이관을 잊었거나(대장에 남아 있음), '
            .'사유 없이 새로 제외됐습니다(대장에 없음).',
        );
    }

    /** @var ComponentRegistry|null 저장소 전 템플릿 컴포넌트 합집합 (계량 보조, 1회 생성) */
    private ?ComponentRegistry $allTemplateComponents = null;

    /**
     * 저장소에 있는 모든 템플릿의 컴포넌트를 합친 레지스트리를 만듭니다 (원시 JSON 계량의 컴포넌트 판별용).
     *
     * 템플릿 목록은 디렉터리에서 도출한다 — 특정 템플릿을 이름으로 고르지 않는다. 같은 이름은 한 번만 싣는다.
     *
     * @return ComponentRegistry 합집합 레지스트리
     */
    private function allTemplateComponents(): ComponentRegistry
    {
        if ($this->allTemplateComponents !== null) {
            return $this->allTemplateComponents;
        }

        $merged = ['templateId' => 'measurement-union', 'version' => '0.0.0', 'components' => ['basic' => [], 'composite' => [], 'layout' => []]];
        $seen = [];

        foreach (glob(base_path('templates/_bundled/*/components.json')) ?: [] as $manifest) {
            $decoded = json_decode((string) file_get_contents($manifest), true);

            foreach (($decoded['components'] ?? []) as $category => $entries) {
                foreach ((array) $entries as $entry) {
                    $name = is_array($entry) ? ($entry['name'] ?? null) : null;

                    if (! is_string($name) || isset($seen[$name]) || ! isset($merged['components'][$category])) {
                        continue;
                    }

                    $seen[$name] = true;
                    $merged['components'][$category][] = $entry;
                }
            }
        }

        return $this->allTemplateComponents = ComponentRegistry::fromArray($merged);
    }

    /**
     * 번들 확장·템플릿에서 모든 레이아웃·fragment `.json` 을 스캔합니다.
     *
     * 모집단은 {@see LayoutCorpus} 가 SSoT — 커맨드(`layout:compile --all`)와 같은 코퍼스를 본다.
     * 스캔 글롭을 이 테스트가 따로 들고 있으면 한쪽만 넓힐 때 게이트가 반쪽 코퍼스에 남는다.
     *
     * @return array<int, string> `.json` 절대 경로 목록
     */
    private function scanLayoutFiles(): array
    {
        return LayoutCorpus::scan('.json');
    }

    /**
     * 번들 확장·템플릿에서 모든 이관 소스 파일 `.g7.html` 을 스캔합니다.
     *
     * @return array<int, string> `.g7.html` 절대 경로 목록
     */
    private function scanMarkupFiles(): array
    {
        return LayoutCorpus::scan('.g7.html');
    }
}
