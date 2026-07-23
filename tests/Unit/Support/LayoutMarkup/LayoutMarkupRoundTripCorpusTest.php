<?php

namespace Tests\Unit\Support\LayoutMarkup;

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
     * 라우트·참조 0 + `extends` 없는 slots 라 렌더 결과 자체가 없음). 둘 다 PO 승인 삭제다 —
     * 하한을 내릴 때는 **왜 줄었는지**를 여기 남겨야 한다. 그러지 않으면 다음 사람이 이 숫자를
     * 스캔이 좁아진 신호와 구별할 수 없다.
     */
    private const CORPUS_MINIMUM = 637;

    /**
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects every_bundled_layout_round_trips_through_decompile_and_compile
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

            // 아래 둘은 종전 조용한 `continue` 였다. 조용히 넘기면 레지스트리 해석이 깨지거나 JSON 이
            // 손상돼도 "왕복 불일치 0" 이 유지된다 — 같은 파일의 이관 게이트(:250 이하)는 이미 이 둘을
            // 실패로 다루고 있어 엄격도가 비대칭이었다. 여기서도 실패로 맞춘다.
            $registry = $resolver->registryFor($file);

            if ($registry === null) {
                $failures[] = "{$relative} (대상 템플릿 레지스트리를 해석하지 못했습니다)";

                continue;
            }

            $json = json_decode(File::get($file), true);

            if (! is_array($json)) {
                $failures[] = "{$relative} (JSON 파싱 실패 — 손상되었거나 배열이 아닙니다)";

                continue;
            }

            $result = $harness->verify($json, $registry, $file);

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
     * (F7·F8·F12·F13·F14·F15)을 수정한 뒤 합류시켰고, 사문 2건은 PO 승인대로 삭제했다
     * (145 → 143 — 대상 모듈이 없는 `overrides/sirsoft-sample` 과 참조처 없는
     * `admin_language_packs_install_modal`). 각 결함의 회귀는
     * `templates/_bundled/sirsoft-admin_basic/__tests__/layouts/s16-defect-fixes.test.tsx` 가 봉인한다.
     *
     * @var array<int, string>
     */
    private const MIGRATED_MARKUP_FILES = [
        // S7 데모 (W1 이전 완료본)
        'modules/_bundled/gnuboard7-hello_module/resources/layouts/admin/admin_markup_demo.g7.html',
        // W1 — hello admin 템플릿 (7)
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/admin_dashboard.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/401.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/403.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/404.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/500.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/503.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/maintenance.g7.html',
        // W1 — hello user 템플릿 (8)
        'templates/_bundled/gnuboard7-hello_user_template/layouts/_user_base.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/home.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/401.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/403.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/404.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/500.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/503.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/maintenance.g7.html',
        // W2 — sirsoft-page (3)
        'modules/_bundled/sirsoft-page/resources/layouts/admin/admin_page_detail.g7.html',
        'modules/_bundled/sirsoft-page/resources/layouts/admin/admin_page_form.g7.html',
        'modules/_bundled/sirsoft-page/resources/layouts/admin/admin_page_list.g7.html',
        // W2 — sirsoft-gdpr (5) — `partials/_shared/_policy_version_snapshot_modal` 은 F12 로 이관 제외
        'plugins/_bundled/sirsoft-gdpr/resources/extensions/cookie_banner.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/extensions/mypage_privacy_tab.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/gdpr_consent_log.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/partials/plugin_settings/_policy_version_publish_modal.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/plugin_settings.g7.html',
        // W2 — sirsoft-ckeditor5 (3)
        'plugins/_bundled/sirsoft-ckeditor5/resources/extensions/html-content.g7.html',
        'plugins/_bundled/sirsoft-ckeditor5/resources/extensions/html-editor.g7.html',
        'plugins/_bundled/sirsoft-ckeditor5/resources/layouts/admin/plugin_settings.g7.html',
        // W2 — sirsoft-daum_postcode (2)
        'plugins/_bundled/sirsoft-daum_postcode/resources/extensions/ecommerce-address-search.g7.html',
        'plugins/_bundled/sirsoft-daum_postcode/resources/layouts/admin/plugin_settings.g7.html',
        // W2 — sirsoft-marketing (6)
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-detail.g7.html',
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-form.g7.html',
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-profile-view.g7.html',
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-profile.g7.html',
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-register.g7.html',
        'plugins/_bundled/sirsoft-marketing/resources/layouts/admin/plugin_settings.g7.html',
        // W2 — sirsoft-pay_kginicis (4) — `extensions/admin_order_payment_query` 는 F9 로 이관 제외
        'plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/admin_order_list_test_badge.g7.html',
        'plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/checkout_payment_error.g7.html',
        'plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/user_order_show.g7.html',
        'plugins/_bundled/sirsoft-pay_kginicis/resources/layouts/admin/plugin_settings.g7.html',
        // W2 — sirsoft-pay_nhnkcp (6)
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/admin_order_list_test_badge.g7.html',
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/admin_order_payment_query.g7.html',
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/checkout_easy_pay.g7.html',
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/user_order_complete_receipt.g7.html',
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/user_order_show.g7.html',
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/layouts/admin/plugin_settings.g7.html',
        // W2 — sirsoft-pay_nicepayments (5) — `extensions/admin_order_payment_query` 는 F10·F11 로 이관 제외
        'plugins/_bundled/sirsoft-pay_nicepayments/resources/extensions/admin_order_list_test_badge.g7.html',
        'plugins/_bundled/sirsoft-pay_nicepayments/resources/extensions/checkout_easy_pay.g7.html',
        'plugins/_bundled/sirsoft-pay_nicepayments/resources/extensions/user_mypage_order_receipt.g7.html',
        'plugins/_bundled/sirsoft-pay_nicepayments/resources/extensions/user_order_complete_receipt.g7.html',
        'plugins/_bundled/sirsoft-pay_nicepayments/resources/layouts/admin/plugin_settings.g7.html',
        // W2 — sirsoft-verification_kginicis (3) — 아래 RAW_JSON_PENDING_GRAMMAR 참조
        'plugins/_bundled/sirsoft-verification_kginicis/resources/extensions/identity_provider_inicis.g7.html',
        'plugins/_bundled/sirsoft-verification_kginicis/resources/extensions/mypage_identity_card.g7.html',
        'plugins/_bundled/sirsoft-verification_kginicis/resources/layouts/admin/plugin_settings.g7.html',
        // W3 — sirsoft-admin_basic (143) — 전량 이관 (S16 결함 16건 수정 후 잔여 15 합류)
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
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_menu_list/_modal_delete.g7.html',
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
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_identity_message_definition_add.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_identity_message_definition_delete.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_identity_message_definition_reset.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_identity_message_template_form.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_identity_message_template_preview.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_identity_policy_delete.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_identity_policy_form.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_mail_template_form.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_notification_definition_reset.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_notification_template_form.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_notification_template_preview.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_modal_password_confirm.g7.html',
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
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_mail_templates.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_notification_definitions.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_security.g7.html',
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
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/template_partial_test/_content_section.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/template_partial_test/_header_section.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/partials/template_partial_test/_info_card.g7.html',
        'templates/_bundled/sirsoft-admin_basic/layouts/template_partial_test.g7.html',
        // W4 — sirsoft-basic (153) — escape 유발 결함 14파일은 S16 이월로 이관 제외
        'templates/_bundled/sirsoft-basic/extensions/sirsoft-daum_postcode/user-address-search.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/auth/forgot_password.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/auth/identity_challenge.g7.html',
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
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_modal_cancel.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_modal_change_address.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_modal_confirm_purchase.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_modal_write_review.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_orderer.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_payment.g7.html',
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
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_cart_list.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_checkout_discount.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_checkout_items.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_checkout_mileage.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_checkout_orderer.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/partials/shop/_checkout_payment.g7.html',
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
        'templates/_bundled/sirsoft-basic/layouts/shop/index.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/shop/show.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/users/posts.g7.html',
        'templates/_bundled/sirsoft-basic/layouts/users/show.g7.html',
        // W5 — sirsoft-board (45/51). 제외 6파일은 escape 를 유발하는 소스 결함 보유:
        // F3(액션 `condition`) 1 · F4(`trigger`) 4 · F6(`state`+initLocal 병존) 1 ·
        // **F18(중첩 `confirm`)** 1 · **F19(노드 `key`)** 2 — S16 수정 후 합류.
        'modules/_bundled/sirsoft-board/resources/extensions/admin-ecommerce-inquiry-settings.g7.html',
        'modules/_bundled/sirsoft-board/resources/extensions/admin_dashboard_community.g7.html',
        'modules/_bundled/sirsoft-board/resources/extensions/admin_dashboard_quick_menu.g7.html',
        'modules/_bundled/sirsoft-board/resources/extensions/user-notification-detail.g7.html',
        'modules/_bundled/sirsoft-board/resources/extensions/user-notification-settings.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_index.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_post_form.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_reports_detail.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/admin_board_reports_index.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_form/_tab_basic.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_form/_tab_list.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_form/_tab_notification.g7.html',
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
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_identity_policy_delete.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_identity_policy_form.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_mail_template_edit.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_notification_definition_reset.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_notification_template_edit.g7.html',
        'modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_modal_notification_template_preview.g7.html',
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
    ];

    /**
     * 1급 문법이 없어 원시 JSON 으로 남는 자리 (U-RAWJSON — D41 → D42 전면 재집계 → **D42 종료로 비었다**).
     *
     * D41 은 태그 본문(`<params>`/`<fallback>`/`<prop>`/`<props>`)을 "설계된 정본" 으로 보아 계량에서
     * 뺐으나, PO 지적(3회차)으로 **그 제외를 폐기**했다. 태그 예외 없이 다시 세자 대장은 35 로 늘었고
     * (늘어난 것은 새 결함이 아니라 원래 있었으나 안 세던 것), D42 문법 신설이 그것을 전부 해소했다:
     *
     *  - 빈 컬렉션 — `[]`·`{}` 는 구조가 없는 값 리터럴이라 계량 대상이 아니다(D42 선행 판정).
     *    착수 시 7건이었고 이행 후 **16건**이다(`:value` 3→12) — 늘어난 9는 속성 JSON 안에 묻혀
     *    있던 빈 배열이 `<field :value="[]"/>` 리프로 드러난 것이라, 저작 파일의 JSON 은 오히려 줄었다.
     *  - G1 데이터소스 속성 field-map / G2 중첩 `<field>` 트리 → `<params>` 3 · `<fallback>` 6 소멸
     *  - G3 `<prop>` `<field>` / G4 `<prop>` `<item>` / G5 `<props>` `<append>` → `<prop>` 4 · `<props>` 2 소멸
     *  - G6 액션 구조값 파라미터 `<field name>` (중첩 객체·문자열 리스트 포함) → 속성값 전량 소멸
     *  - G7 확장 주입 `<props>` 의 DataGrid 컬럼 트리 → `<props>` 3 소멸
     *  - G8 `switch` 분기 대상 배치 분리(`on`=params.value / `value`=top-level) → G7 이 드러낸
     *    액션 트리 `<json>` 탈출 3건 소멸 (S16 F2 결함을 **고치지 않고 표기만** 갈랐다 — D38 바이트 동등)
     *
     * **대장이 비어 있는 것이 정상 상태다.** 묵인이 아니라 열거이므로, 새 원시 JSON 이 생기면 그대로
     * 실패한다. 실패를 등재로 덮으려면 그 자리에 **문법이 없다는 근거**를 주석으로 함께 적어야 한다.
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
    ];

    /**
     * 이관된 `.g7.html` 이 원본과 100% 동등하고 escape-0 임을 봉인합니다 (S10 W1 DoD).
     *
     * 각 `.g7.html` 을 설치 시점과 같은 파사드로 컴파일해 짝 `.json` 과 정규화 후 deep-equal 을 보고,
     * 저작 파일에 `<json>` 탈출구가 하나도 없음을 확인한다. 기계 이관이 원본 기능을 100% 보존한다는
     * (D38) 계약의 회귀 게이트다. 짝 `.json` 부재·레지스트리 미해석은 **skip 이 아니라 실패**다 —
     * 조용히 건너뛰면 이관이 깨져도 green 이 된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects every_migrated_markup_file_matches_its_json_and_is_escape_free, first_class_grammar_replaces_raw_json_in_migrated_markup
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
                $failures[] = "{$relative} — 이관 저작 파일이 없습니다";

                continue;
            }

            $jsonFile = preg_replace('/\.g7\.html$/i', '.json', $markupFile);
            $source = File::get($markupFile);

            if (str_contains($source, '<json')) {
                $failures[] = "{$relative} — <json> 탈출구가 남아 있음 (escape≠0)";

                continue;
            }

            $registry = $resolver->registryFor($markupFile);

            if ($registry === null) {
                $failures[] = "{$relative} — 대상 템플릿 레지스트리를 해석하지 못했습니다";

                continue;
            }

            // D41 — `<json>` 태그만 세면 속성값·`<prop>` 본문의 통짜 JSON 을 놓친다.
            // 1급 문법이 있는데 blob 으로 떨어진 자리를 여기서 잡는다.
            // 아직 문법이 없는 자리(U-RAWJSON ⓒ)는 아래 대장에 **명시 등재**된 것만 통과한다 —
            // 라벨뿐 아니라 **건수까지** 정확 다중집합으로 대조하므로, 등재 라벨이라도 건수가
            // 늘거나(예: `:value` 3→5) 미등재 라벨이 새로 뜨면 그대로 실패한다.
            //
            // 레지스트리를 함께 넘긴다(D53) — 컴포넌트 노드의 텍스트는 화면에 그려질 콘텐츠라
            // 문법으로 없앨 수 없다. 넘기지 않으면 그런 표시용 JSON 이 영원히 닫히지 않는 대장
            // 항목으로 남아 진짜 잔여와 구분되지 않는다.
            $emitted = array_count_values(LayoutMarkupRoundTrip::rawJsonEmissions($source, $registry));
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
     * 번들 확장·템플릿에서 모든 이관 저작 파일 `.g7.html` 을 스캔합니다.
     *
     * @return array<int, string> `.g7.html` 절대 경로 목록
     */
    private function scanMarkupFiles(): array
    {
        return LayoutCorpus::scan('.g7.html');
    }
}
