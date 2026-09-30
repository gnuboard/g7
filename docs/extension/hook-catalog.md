# 공개 훅 목록 (Hook Catalog)

> 자동 생성 문서 — 손으로 고치지 않는다. 생성: `php artisan hooks:catalog` · 확인: `php artisan hooks:catalog --check`
> 원칙: [hooks.md 「공개 훅 호환 원칙」](hooks.md#공개-훅-호환-원칙) — 공개 훅의 이름·인자 위치는 마이너 버전에서 바꾸지 않는다.

코어와 번들 확장이 실제로 발화하는 훅 전부다(소스 스캔). **인자 개수**는 훅 이름 뒤 인자 수이고(`*` = 전개 인자),
발화처가 여럿이면 관측된 개수를 모두 적는다. 훅별 서술은 그 훅을 소유한 도메인 문서를 본다.

## 목차

- [코어](#코어) (580)
- [gnuboard7-hello_module](#gnuboard7-hello_module) (2)
- [gnuboard7-hello_plugin](#gnuboard7-hello_plugin) (1)
- [sirsoft-board](#sirsoft-board) (101)
- [sirsoft-ckeditor5](#sirsoft-ckeditor5) (4)
- [sirsoft-ecommerce](#sirsoft-ecommerce) (604)
- [sirsoft-gdpr](#sirsoft-gdpr) (11)
- [sirsoft-koreaexim](#sirsoft-koreaexim) (1)
- [sirsoft-marketing](#sirsoft-marketing) (4)
- [sirsoft-message_bizppurio](#sirsoft-message_bizppurio) (1)
- [sirsoft-page](#sirsoft-page) (21)
- [sirsoft-pay_kginicis](#sirsoft-pay_kginicis) (6)
- [sirsoft-pay_nhnkcp](#sirsoft-pay_nhnkcp) (8)
- [sirsoft-pay_nicepayments](#sirsoft-pay_nicepayments) (5)
- [sirsoft-tosspayments](#sirsoft-tosspayments) (4)
- [sirsoft-totp](#sirsoft-totp) (1)
- [사용 중단](#사용-중단)
- [이름을 조립하는 발화처](#이름을-조립하는-발화처)

## 코어

| 훅 | 유형 | 인자 개수 | 비고 |
|----|------|-----------|------|
| `auth.logout_all_devices` | action | 1 |  |
| `core.activity_log.after_delete` | action | 1 |  |
| `core.activity_log.after_delete_many` | action | 2 |  |
| `core.activity_log.after_prune` | action | 2 |  |
| `core.activity_log.before_delete` | action | 1 |  |
| `core.activity_log.before_delete_many` | action | 1 |  |
| `core.activity_log.before_prune` | action | 1 |  |
| `core.activity_log.filter_description_params` | filter | 3 |  |
| `core.activity_log.index_validation_rules` | filter | 2 |  |
| `core.analytics.after_prune` | action | 1 |  |
| `core.analytics.after_track` | action | 1 |  |
| `core.analytics.anonymize_attributes` | filter | 3 |  |
| `core.analytics.anonymize_validation_rules` | filter | 2 |  |
| `core.analytics.anonymized` | action | 1 |  |
| `core.analytics.archive.day` | action | 1 |  |
| `core.analytics.archive_failed` | action | 1 |  |
| `core.analytics.archive_requested` | action | 1 |  |
| `core.analytics.archive_run_validation_rules` | filter | 2 |  |
| `core.analytics.archive_runs_validation_rules` | filter | 2 |  |
| `core.analytics.before_prune` | action | 1 |  |
| `core.analytics.campaign_params` | filter | 1 |  |
| `core.analytics.collect_failures_validation_rules` | filter | 2 |  |
| `core.analytics.collect_validation_rules` | filter | 2 |  |
| `core.analytics.consent_session_id` | filter | 2 |  |
| `core.analytics.csv_columns` | filter | 3 |  |
| `core.analytics.csv_row` | filter | 3 |  |
| `core.analytics.data_purged` | action | 1 |  |
| `core.analytics.diagnostics_sections` | filter | 2 |  |
| `core.analytics.diagnostics_validation_rules` | filter | 2 |  |
| `core.analytics.exclusion_rules` | filter | 2 |  |
| `core.analytics.extension_purged` | action | 1 |  |
| `core.analytics.ledger_limit_exceeded` | action | 1 |  |
| `core.analytics.opt_out_changed` | action | 1 |  |
| `core.analytics.opt_out_validation_rules` | filter | 2 |  |
| `core.analytics.period_validation_rules` | filter | 2 |  |
| `core.analytics.privacy_mode_changed` | action | 1 |  |
| `core.analytics.purge_all` | filter | 1 |  |
| `core.analytics.purge_validation_rules` | filter | 2 |  |
| `core.analytics.realtime_fields` | filter | 2 |  |
| `core.analytics.realtime_validation_rules` | filter | 2 |  |
| `core.analytics.referrer_urls_validation_rules` | filter | 2 |  |
| `core.analytics.registered_dimensions` | filter | 1 |  |
| `core.analytics.registered_exclusion_reasons` | filter | 1 |  |
| `core.analytics.registered_goals` | filter | 1 |  |
| `core.analytics.report_validation_rules` | filter | 2 |  |
| `core.analytics.resolve_dimensions` | filter | 2 |  |
| `core.analytics.row_series_validation_rules` | filter | 2 |  |
| `core.analytics.search_engines` | filter | 1 |  |
| `core.analytics.settings_updated` | action | 1 |  |
| `core.analytics.settings_validation_rules` | filter | 2 |  |
| `core.analytics.storage_tables` | filter | 1 |  |
| `core.analytics.stripped_query_params` | filter | 1 |  |
| `core.analytics.subject_delete` | filter | 5 |  |
| `core.analytics.subject_delete_validation_rules` | filter | 2 |  |
| `core.analytics.subject_deleted` | action | 1 |  |
| `core.analytics.subject_export` | filter | 5 |  |
| `core.analytics.subject_exported` | action | 1 |  |
| `core.analytics.subject_validation_rules` | filter | 2 |  |
| `core.analytics.tracking_request_received` | action | 1 |  |
| `core.analytics.ua_rules` | filter | 1 |  |
| `core.analytics.visit_limit_exceeded` | action | 1 |  |
| `core.analytics.visitor_has_consent` | filter | 3 |  |
| `core.analytics.visitor_index_validation_rules` | filter | 2 |  |
| `core.analytics.visitor_show_validation_rules` | filter | 2 |  |
| `core.assets.custom_assets` | filter | 3 |  |
| `core.attachment.after_bulk_delete` | action | 4 |  |
| `core.attachment.after_delete` | action | 1 |  |
| `core.attachment.after_reorder` | action | 1 |  |
| `core.attachment.after_upload` | action | 1 |  |
| `core.attachment.allowed_extensions` | filter | 2 |  |
| `core.attachment.before_bulk_delete` | action | 1 |  |
| `core.attachment.before_delete` | action | 1 |  |
| `core.attachment.before_download` | filter | 2 |  |
| `core.attachment.before_download_action` | action | 2 |  |
| `core.attachment.before_reorder` | action | 1 |  |
| `core.attachment.before_upload` | action | 3 |  |
| `core.attachment.filter_upload_file` | filter | 1 |  |
| `core.attachment.reorder_validation_rules` | filter | 2 |  |
| `core.attachment.upload_batch_validation_rules` | filter | 2 |  |
| `core.attachment.upload_validation_rules` | filter | 2 |  |
| `core.auth.account_locked` | action | 2 |  |
| `core.auth.account_unlocked` | action | 2 |  |
| `core.auth.after_login` | action | 2 |  |
| `core.auth.after_password_changed` | action | 1 |  |
| `core.auth.after_register` | action | 2 |  |
| `core.auth.after_register_activated` | action | 1 |  |
| `core.auth.after_reset_password_request` | action | 2 |  |
| `core.auth.before_logout` | action | 1 |  |
| `core.auth.before_register` | action | 2 |  |
| `core.auth.before_reset_password` | action | 1 |  |
| `core.auth.forgot_password_validation_rules` | filter | 2 |  |
| `core.auth.lockout_minutes` | filter | 2 |  |
| `core.auth.login_failed` | action | 2 |  |
| `core.auth.login_validation_rules` | filter | 2 |  |
| `core.auth.logout` | action | 1 |  |
| `core.auth.max_login_attempts` | filter | 2 |  |
| `core.auth.record_consents` | action | 4 |  |
| `core.auth.register_validation_rules` | filter | 2 |  |
| `core.auth.reset_password_validation_rules` | filter | 2 |  |
| `core.auth.two_factor_requested` | action | 2 |  |
| `core.auth.two_factor_resend_validation_rules` | filter | 2 |  |
| `core.auth.two_factor_validation_rules` | filter | 2 |  |
| `core.auth.validate_reset_token_messages` | filter | 2 |  |
| `core.auth.validate_reset_token_rules` | filter | 2 | 호환용 옛 이름 → `core.auth.validate_reset_token_validation_rules` |
| `core.auth.validate_reset_token_validation_rules` | filter | 2 |  |
| `core.auth.verify_password_rules` | filter | 1 | 호환용 옛 이름 → `core.auth.verify_password_validation_rules` |
| `core.auth.verify_password_validation_rules` | filter | 1 |  |
| `core.consent.allowed_types` | filter | 1 |  |
| `core.custom_assets.after_change` | action | 4 |  |
| `core.custom_translation.bulk_destroy_validation_rules` | filter | 2 |  |
| `core.custom_translation.index_validation_rules` | filter | 2 |  |
| `core.custom_translation.store_validation_rules` | filter | 2 |  |
| `core.custom_translation.update_validation_rules` | filter | 2 |  |
| `core.dashboard.activities` | filter | 2 |  |
| `core.dashboard.alerts` | filter | 1 |  |
| `core.dashboard.before_resources` | action | 0 |  |
| `core.dashboard.before_stats` | action | 0 |  |
| `core.dashboard.resources` | filter | 1 |  |
| `core.dashboard.stats` | filter | 1 |  |
| `core.extension.changelog_rules` | filter | 1 | 호환용 옛 이름 → `core.extension.changelog_validation_rules` |
| `core.extension.changelog_validation_rules` | filter | 1 |  |
| `core.extension_custom_asset.read_validation_rules` | filter | 2 |  |
| `core.extension_custom_asset.rename_validation_rules` | filter | 2 |  |
| `core.extension_custom_asset.save_validation_rules` | filter | 2 |  |
| `core.extension_custom_asset.search_validation_rules` | filter | 2 |  |
| `core.extension_custom_asset.upload_validation_rules` | filter | 2 |  |
| `core.frontend.filter_app_config` | filter | 1 |  |
| `core.geoip.database.after_update` | action | 1 |  |
| `core.geoip.database.after_update_failed` | action | 1 |  |
| `core.geoip.database.before_update` | action | 1 |  |
| `core.geoip.database.filter_download_context` | filter | 1 |  |
| `core.identity.after_cancel` | action | 3 |  |
| `core.identity.after_consume_token` | action | 3 |  |
| `core.identity.after_request` | action | 4 |  |
| `core.identity.after_verify` | action | 3 |  |
| `core.identity.before_cancel` | action | 2 |  |
| `core.identity.before_consume_token` | action | 2 |  |
| `core.identity.before_request` | action | 3 |  |
| `core.identity.before_verify` | action | 3 |  |
| `core.identity.challenge_expired` | action | 1 |  |
| `core.identity.filter_default_message_definitions` | filter | 2 |  |
| `core.identity.message.after_send` | action | 1 |  |
| `core.identity.message.before_send` | action | 1 |  |
| `core.identity.message.resolve_failed` | action | 1 |  |
| `core.identity.message.send_failed` | action | 1 |  |
| `core.identity.message_definition.after_create` | action | 1 |  |
| `core.identity.message_definition.after_delete` | action | 1 |  |
| `core.identity.message_definition.after_toggle_active` | action | 1 |  |
| `core.identity.message_definition.after_update` | action | 2 |  |
| `core.identity.message_definition.before_create` | action | 1 |  |
| `core.identity.message_definition.before_delete` | action | 1 |  |
| `core.identity.message_definition.before_toggle_active` | action | 1 |  |
| `core.identity.message_definition.before_update` | action | 2 |  |
| `core.identity.message_definition.filter_create_data` | filter | 1 |  |
| `core.identity.message_definition.filter_update_data` | filter | 2 |  |
| `core.identity.message_definition.index_validation_rules` | filter | 1 |  |
| `core.identity.message_definition.store_validation_rules` | filter | 1 |  |
| `core.identity.message_definition.update_validation_rules` | filter | 2 |  |
| `core.identity.message_template.after_reset` | action | 1 |  |
| `core.identity.message_template.after_toggle_active` | action | 1 |  |
| `core.identity.message_template.after_update` | action | 2 |  |
| `core.identity.message_template.before_reset` | action | 1 |  |
| `core.identity.message_template.before_toggle_active` | action | 1 |  |
| `core.identity.message_template.before_update` | action | 2 |  |
| `core.identity.message_template.filter_update_data` | filter | 2 |  |
| `core.identity.message_template.reset_no_default` | action | 1 |  |
| `core.identity.message_template.update_validation_rules` | filter | 2 |  |
| `core.identity.purposes` | filter | 1 |  |
| `core.identity.registered_providers` | filter | 1 |  |
| `core.identity.request_validation_rules` | filter | 2 |  |
| `core.identity.resolve_policy` | filter | 4 |  |
| `core.identity.runtime_policy_registered` | action | 1 |  |
| `core.identity.settings_schema` | filter | 2 |  |
| `core.identity.verify_validation_rules` | filter | 2 |  |
| `core.identity_policy.destroy_validation_rules` | filter | 2 |  |
| `core.identity_policy.reset_field_validation_rules` | filter | 2 |  |
| `core.identity_policy.store_validation_rules` | filter | 2 |  |
| `core.identity_policy.update_validation_rules` | filter | 2 |  |
| `core.language_packs.activated` | action | 1 |  |
| `core.language_packs.bulk_activate_validation_rules` | filter | 2 |  |
| `core.language_packs.deactivated` | action | 1 |  |
| `core.language_packs.install_from_bundled_validation_rules` | filter | 2 |  |
| `core.language_packs.install_from_file_validation_rules` | filter | 2 |  |
| `core.language_packs.install_from_github_validation_rules` | filter | 2 |  |
| `core.language_packs.install_from_url_validation_rules` | filter | 2 |  |
| `core.language_packs.installed` | action | 1 |  |
| `core.language_packs.manifest_preview_validation_rules` | filter | 2 |  |
| `core.language_packs.uninstalled` | action | 1 |  |
| `core.language_packs.updated` | action | 1 |  |
| `core.layout.after_cache_clear` | action | 3 |  |
| `core.layout.after_create` | action | 3 |  |
| `core.layout.after_delete` | action | 3 |  |
| `core.layout.after_dependent_cache_clear` | action | 3 |  |
| `core.layout.after_get` | action | 4 |  |
| `core.layout.after_index` | action | 2 |  |
| `core.layout.after_load` | action | 4 |  |
| `core.layout.after_merge` | action | 3 |  |
| `core.layout.after_rename` | action | 4 |  |
| `core.layout.after_sanitize` | action | 1 |  |
| `core.layout.after_show` | action | 3 |  |
| `core.layout.after_update` | action | 4 |  |
| `core.layout.after_version_restore` | action | 4 |  |
| `core.layout.after_version_show` | action | 4 |  |
| `core.layout.after_versions_index` | action | 3 |  |
| `core.layout.before_cache_clear` | action | 2 |  |
| `core.layout.before_create` | action | 3 |  |
| `core.layout.before_delete` | action | 2 |  |
| `core.layout.before_dependent_cache_clear` | action | 2 |  |
| `core.layout.before_get` | action | 2 |  |
| `core.layout.before_index` | action | 1 |  |
| `core.layout.before_load` | action | 2 |  |
| `core.layout.before_merge` | action | 2 |  |
| `core.layout.before_rename` | action | 3 |  |
| `core.layout.before_sanitize` | action | 1 |  |
| `core.layout.before_show` | action | 2 |  |
| `core.layout.before_update` | action | 3 |  |
| `core.layout.before_version_restore` | action | 3 |  |
| `core.layout.before_version_show` | action | 3 |  |
| `core.layout.before_versions_index` | action | 2 |  |
| `core.layout.create_validation_rules` | filter | 2 |  |
| `core.layout.filter_child_data` | filter | 2 |  |
| `core.layout.filter_merged` | filter | 3 |  |
| `core.layout.filter_parent_data` | filter | 2 |  |
| `core.layout.filter_update_data` | filter | 3 |  |
| `core.layout.get_validation_messages` | filter | 2 |  |
| `core.layout.get_validation_rules` | filter | 2 |  |
| `core.layout.markup_compile_validation_rules` | filter | 2 |  |
| `core.layout.rename_validation_rules` | filter | 2 |  |
| `core.layout.search_validation_rules` | filter | 2 |  |
| `core.layout.store_inheritance_validation_rules` | filter | 2 |  |
| `core.layout.store_preview_validation_rules` | filter | 2 |  |
| `core.layout.store_validation_rules` | filter | 2 |  |
| `core.layout.trusted_script_hosts` | filter | 1 |  |
| `core.layout.update_content_validation_rules` | filter | 2 |  |
| `core.layout.update_inheritance_validation_rules` | filter | 2 |  |
| `core.layout.update_validation_rules` | filter | 2 |  |
| `core.layout_extension.after_apply` | filter | 2 |  |
| `core.layout_extension.after_index` | filter | 2 |  |
| `core.layout_extension.after_update` | action | 3 |  |
| `core.layout_extension.after_version_restore` | action | 3 |  |
| `core.layout_extension.after_version_show` | filter | 3 |  |
| `core.layout_extension.after_versions_index` | filter | 2 |  |
| `core.layout_extension.before_apply` | action | 2 |  |
| `core.layout_extension.before_index` | action | 1 |  |
| `core.layout_extension.before_update` | action | 2 |  |
| `core.layout_extension.before_version_restore` | action | 2 |  |
| `core.layout_extension.before_version_show` | action | 2 |  |
| `core.layout_extension.before_versions_index` | action | 1 |  |
| `core.layout_extension.filter_update_data` | filter | 2 |  |
| `core.layout_extension.store_preview_validation_rules` | filter | 2 |  |
| `core.layout_extension.update_content_validation_rules` | filter | 2 |  |
| `core.layout_preview.after_generate` | action | 1 |  |
| `core.layout_preview.before_generate` | action | 4 |  |
| `core.layout_resolver.after_all_cache_clear` | action | 2 |  |
| `core.layout_resolver.after_cache_clear` | action | 3 |  |
| `core.layout_resolver.after_module_cache_clear` | action | 2 |  |
| `core.layout_resolver.after_resolve` | action | 4 |  |
| `core.layout_resolver.before_all_cache_clear` | action | 1 |  |
| `core.layout_resolver.before_cache_clear` | action | 2 |  |
| `core.layout_resolver.before_module_cache_clear` | action | 1 |  |
| `core.layout_resolver.before_resolve` | action | 2 |  |
| `core.layout_version.list_validation_rules` | filter | 2 |  |
| `core.mail.after_send` | action | 1 |  |
| `core.mail.send_failed` | action | 1 |  |
| `core.mail.send_skipped` | action | 1 |  |
| `core.member_notification.after_bulk_delete` | action | 2 |  |
| `core.member_notification.before_bulk_delete` | action | 1 |  |
| `core.member_notification.bulk_delete_validation_rules` | filter | 2 |  |
| `core.member_notification.index_validation_rules` | filter | 1 |  |
| `core.menu.after_create` | action | 1 |  |
| `core.menu.after_delete` | action | 1 |  |
| `core.menu.after_sync_roles` | action | 3 |  |
| `core.menu.after_toggle_status` | action | 1 |  |
| `core.menu.after_update` | action | 2 |  |
| `core.menu.after_update_order` | action | 1 |  |
| `core.menu.after_update_order_flat` | action | 1 |  |
| `core.menu.before_create` | action | 1 |  |
| `core.menu.before_delete` | action | 1 |  |
| `core.menu.before_toggle_status` | action | 2 |  |
| `core.menu.before_update` | action | 2 |  |
| `core.menu.before_update_order` | action | 1 |  |
| `core.menu.create_validation_rules` | filter | 2 |  |
| `core.menu.filter_create_data` | filter | 1 |  |
| `core.menu.filter_update_data` | filter | 2 |  |
| `core.menu.list_validation_rules` | filter | 2 |  |
| `core.menu.update_order_validation_rules` | filter | 2 |  |
| `core.menu.update_validation_rules` | filter | 2 |  |
| `core.module.activate_validation_rules` | filter | 2 |  |
| `core.module.deactivate_validation_rules` | filter | 2 |  |
| `core.module.index_validation_rules` | filter | 2 |  |
| `core.module.install_from_file_validation_rules` | filter | 2 |  |
| `core.module.install_from_github_validation_rules` | filter | 2 |  |
| `core.module.install_validation_rules` | filter | 2 |  |
| `core.module.perform_update_validation_rules` | filter | 2 |  |
| `core.module.refresh_layouts_validation_rules` | filter | 2 |  |
| `core.module.uninstall_validation_rules` | filter | 2 |  |
| `core.module.update_validation_rules` | filter | 2 |  |
| `core.module_settings.after_delete_directory` | action | 1 |  |
| `core.module_settings.after_reset` | action | 1 |  |
| `core.module_settings.after_save` | action | 3 |  |
| `core.module_settings.before_delete_directory` | action | 1 |  |
| `core.module_settings.before_reset` | action | 1 |  |
| `core.module_settings.before_save` | action | 2 |  |
| `core.module_settings.filter_save_data` | filter | 2 |  |
| `core.modules.activated` | action | 1 | deprecated → `core.modules.after_activate` |
| `core.modules.after_activate` | action | 2 |  |
| `core.modules.after_check_updates` | action | 1 |  |
| `core.modules.after_deactivate` | action | 1 |  |
| `core.modules.after_install` | action | 2 |  |
| `core.modules.after_refresh_layouts` | action | 2 |  |
| `core.modules.after_uninstall` | action | 3 |  |
| `core.modules.after_update` | action | 3 |  |
| `core.modules.before_activate` | action | 2 |  |
| `core.modules.before_check_updates` | action | 0 |  |
| `core.modules.before_deactivate` | action | 2 |  |
| `core.modules.before_install` | action | 1 |  |
| `core.modules.before_refresh_layouts` | action | 1 |  |
| `core.modules.before_uninstall` | action | 2 |  |
| `core.modules.before_update` | action | 1 |  |
| `core.modules.installed` | action | 1 | deprecated → `core.modules.after_install` |
| `core.modules.updated` | action | 1 | deprecated → `core.modules.after_update` |
| `core.notification.after_channel_send` | action | 2 |  |
| `core.notification.after_cleanup` | action | 1 |  |
| `core.notification.after_delete` | action | 2 |  |
| `core.notification.after_delete_all` | action | 2 |  |
| `core.notification.after_list` | action | 2 |  |
| `core.notification.after_mark_all_read` | action | 2 |  |
| `core.notification.after_mark_batch_read` | action | 3 |  |
| `core.notification.after_mark_read` | action | 2 |  |
| `core.notification.after_send` | action | 3 |  |
| `core.notification.batch_read_validation_rules` | filter | 1 |  |
| `core.notification.before_channel_send` | action | 2 |  |
| `core.notification.before_cleanup` | action | 2 |  |
| `core.notification.before_delete` | action | 2 |  |
| `core.notification.before_delete_all` | action | 1 |  |
| `core.notification.before_mark_all_read` | action | 1 |  |
| `core.notification.before_mark_batch_read` | action | 2 |  |
| `core.notification.before_mark_read` | action | 2 |  |
| `core.notification.channel_enabled` | filter | 4 |  |
| `core.notification.channel_guest_allowed` | filter | 2 |  |
| `core.notification.channel_readiness` | filter | 2 |  |
| `core.notification.channel_send_failed` | action | 2 |  |
| `core.notification.filter_available_channels` | filter | 1 |  |
| `core.notification.filter_default_definitions` | filter | 2 |  |
| `core.notification.filter_list_params` | filter | 2 |  |
| `core.notification.index_validation_rules` | filter | 1 |  |
| `core.notification_definition.after_toggle_active` | action | 1 |  |
| `core.notification_definition.after_update` | action | 2 |  |
| `core.notification_definition.before_toggle_active` | action | 1 |  |
| `core.notification_definition.before_update` | action | 2 |  |
| `core.notification_definition.filter_update_data` | filter | 2 |  |
| `core.notification_definition.index_validation_rules` | filter | 1 |  |
| `core.notification_definition.update_validation_rules` | filter | 2 |  |
| `core.notification_log.after_bulk_delete` | action | 2 |  |
| `core.notification_log.after_delete` | action | 1 |  |
| `core.notification_log.after_log_failed` | action | 1 |  |
| `core.notification_log.after_log_sent` | action | 1 |  |
| `core.notification_log.after_log_skipped` | action | 1 |  |
| `core.notification_log.after_prune` | action | 2 |  |
| `core.notification_log.before_bulk_delete` | action | 1 |  |
| `core.notification_log.before_delete` | action | 1 |  |
| `core.notification_log.before_log_failed` | action | 1 |  |
| `core.notification_log.before_log_sent` | action | 1 |  |
| `core.notification_log.before_log_skipped` | action | 1 |  |
| `core.notification_log.before_prune` | action | 1 |  |
| `core.notification_log.index_validation_rules` | filter | 1 |  |
| `core.notification_template.after_toggle_active` | action | 1 |  |
| `core.notification_template.after_update` | action | 3 |  |
| `core.notification_template.before_toggle_active` | action | 1 |  |
| `core.notification_template.before_update` | action | 2 |  |
| `core.notification_template.filter_update_data` | filter | 2 |  |
| `core.notification_template.update_validation_rules` | filter | 2 |  |
| `core.pagination.filter_max_page` | filter | 2 |  |
| `core.pagination.filter_result_cap` | filter | 2 |  |
| `core.permission.filter_tree_result` | filter | 1 |  |
| `core.permission.query_scoped_permissions` | filter | 1 |  |
| `core.permission.update_validation_rules` | filter | 2 |  |
| `core.plugin.activate_validation_rules` | filter | 2 |  |
| `core.plugin.deactivate_validation_rules` | filter | 2 |  |
| `core.plugin.index_validation_rules` | filter | 2 |  |
| `core.plugin.install_from_file_validation_rules` | filter | 2 |  |
| `core.plugin.install_from_github_validation_rules` | filter | 2 |  |
| `core.plugin.install_validation_rules` | filter | 2 |  |
| `core.plugin.perform_update_validation_rules` | filter | 2 |  |
| `core.plugin.refresh_layouts_validation_rules` | filter | 2 |  |
| `core.plugin.uninstall_validation_rules` | filter | 2 |  |
| `core.plugin.update_validation_rules` | filter | 2 |  |
| `core.plugin_settings.after_delete_directory` | action | 1 |  |
| `core.plugin_settings.after_reset` | action | 1 |  |
| `core.plugin_settings.after_save` | action | 3 |  |
| `core.plugin_settings.before_delete_directory` | action | 1 |  |
| `core.plugin_settings.before_reset` | action | 1 |  |
| `core.plugin_settings.before_save` | action | 2 |  |
| `core.plugin_settings.filter_save_data` | filter | 2 |  |
| `core.plugin_settings.update_rules` | filter | 3 | 호환용 옛 이름 → `core.plugin_settings.update_validation_rules` |
| `core.plugin_settings.update_validation_rules` | filter | 3 |  |
| `core.plugins.activated` | action | 1 | deprecated → `core.plugins.after_activate` |
| `core.plugins.after_activate` | action | 2 |  |
| `core.plugins.after_check_updates` | action | 1 |  |
| `core.plugins.after_deactivate` | action | 1 |  |
| `core.plugins.after_install` | action | 2 |  |
| `core.plugins.after_uninstall` | action | 3 |  |
| `core.plugins.after_update` | action | 3 |  |
| `core.plugins.before_activate` | action | 1 |  |
| `core.plugins.before_check_updates` | action | 0 |  |
| `core.plugins.before_deactivate` | action | 1 |  |
| `core.plugins.before_install` | action | 1 |  |
| `core.plugins.before_uninstall` | action | 2 |  |
| `core.plugins.before_update` | action | 1 |  |
| `core.plugins.installed` | action | 1 | deprecated → `core.plugins.after_install` |
| `core.plugins.updated` | action | 1 | deprecated → `core.plugins.after_update` |
| `core.privacy.dormant_converted` | action | 2 |  |
| `core.privacy.dormant_pre_notice` | action | 2 |  |
| `core.privacy.dormant_withdraw_notice` | action | 2 |  |
| `core.privacy.manual_run_finished` | action | 2 |  |
| `core.privacy.manual_run_queued` | action | 2 |  |
| `core.privacy.manual_run_status_validation_rules` | filter | 2 |  |
| `core.privacy.manual_run_targets` | filter | 1 |  |
| `core.privacy.manual_run_validation_rules` | filter | 2 |  |
| `core.privacy.retention_handlers` | filter | 1 |  |
| `core.privacy.target_list_validation_rules` | filter | 2 |  |
| `core.privacy.withdrawn_user.after_prune` | action | 2 |  |
| `core.privacy.withdrawn_user.before_prune` | action | 1 |  |
| `core.role.after_create` | action | 1 |  |
| `core.role.after_delete` | action | 1 |  |
| `core.role.after_sync_permissions` | action | 3 |  |
| `core.role.after_toggle_status` | action | 1 |  |
| `core.role.after_update` | action | 2 |  |
| `core.role.before_create` | action | 1 |  |
| `core.role.before_delete` | action | 1 |  |
| `core.role.before_toggle_status` | action | 2 |  |
| `core.role.before_update` | action | 2 |  |
| `core.role.filter_create_data` | filter | 1 |  |
| `core.role.filter_update_data` | filter | 2 |  |
| `core.role.store_validation_rules` | filter | 2 |  |
| `core.role.update_validation_rules` | filter | 2 |  |
| `core.routes.filter_merged` | filter | 3 |  |
| `core.schedule.after_bulk_delete` | action | 2 |  |
| `core.schedule.after_bulk_update` | action | 3 |  |
| `core.schedule.after_create` | action | 1 |  |
| `core.schedule.after_delete` | action | 1 |  |
| `core.schedule.after_prune_history` | action | 2 |  |
| `core.schedule.after_run` | action | 2 |  |
| `core.schedule.after_update` | action | 2 |  |
| `core.schedule.before_bulk_delete` | action | 1 |  |
| `core.schedule.before_bulk_update` | action | 2 |  |
| `core.schedule.before_create` | action | 1 |  |
| `core.schedule.before_delete` | action | 1 |  |
| `core.schedule.before_prune_history` | action | 1 |  |
| `core.schedule.before_run` | action | 1 |  |
| `core.schedule.before_update` | action | 2 |  |
| `core.schedule.bulk_delete_validation_rules` | filter | 2 |  |
| `core.schedule.bulk_update_status_validation_rules` | filter | 2 |  |
| `core.schedule.create_validation_rules` | filter | 2 |  |
| `core.schedule.filter_create_data` | filter | 1 |  |
| `core.schedule.filter_update_data` | filter | 2 |  |
| `core.schedule.history_list_validation_rules` | filter | 2 |  |
| `core.schedule.list_validation_rules` | filter | 2 |  |
| `core.schedule.update_validation_rules` | filter | 2 |  |
| `core.search.after_search` | action | 1 |  |
| `core.search.build_response` | filter | 3 |  |
| `core.search.engine_drivers` | filter | 1 |  |
| `core.search.index_maintainers` | filter | 1 |  |
| `core.search.index_validation_rules` | filter | 2 |  |
| `core.search.like_operators` | filter | 1 |  |
| `core.search.results` | filter | 2 |  |
| `core.search.validation_rules` | filter | 2 | 호환용 옛 이름 → `core.search.index_validation_rules` |
| `core.security.ip_restriction_exempt_paths` | filter | 1 |  |
| `core.seo.filter_context` | filter | 2 |  |
| `core.seo.filter_meta` | filter | 2 |  |
| `core.seo.filter_og_data` | filter | 2 |  |
| `core.seo.filter_structured_data` | filter | 2 |  |
| `core.seo.filter_twitter_data` | filter | 2 |  |
| `core.seo.filter_view_data` | filter | 2 |  |
| `core.seo.register_layouts` | filter | 1 |  |
| `core.seo.resolve_is_bot` | filter | 2 |  |
| `core.seo.sitemap.after_regenerate` | action | 2 |  |
| `core.seo.sitemap.after_regenerate_failed` | action | 2 |  |
| `core.seo.sitemap.before_regenerate` | action | 0 |  |
| `core.seo.sitemap.regenerate_failed_final` | action | 2 |  |
| `core.seo_bot_preview.show_validation_rules` | filter | 2 |  |
| `core.seo_candidate.index_validation_rules` | filter | 2 |  |
| `core.seo_og_preview.show_validation_rules` | filter | 2 |  |
| `core.settings.after_save` | action | 3 |  |
| `core.settings.after_set` | action | 3 |  |
| `core.settings.apply_driver_config` | action | 3 |  |
| `core.settings.before_save` | action | 1 |  |
| `core.settings.before_set` | action | 2 |  |
| `core.settings.filter_save_data` | filter | 1 |  |
| `core.settings.privacy_preview_validation_rules` | filter | 2 |  |
| `core.settings.regenerate_app_key_validation_rules` | filter | 2 |  |
| `core.settings.restore_validation_rules` | filter | 2 |  |
| `core.settings.save_validation_attributes` | filter | 2 |  |
| `core.settings.save_validation_rules` | filter | 2 |  |
| `core.settings.test_driver_connection_validation_rules` | filter | 2 |  |
| `core.settings.test_mail_validation_rules` | filter | 2 |  |
| `core.settings.test_outbound_proxy_validation_rules` | filter | 2 |  |
| `core.settings.update_validation_rules` | filter | 2 |  |
| `core.storage.filter_url` | filter | 2 |  |
| `core.template.activate_validation_rules` | filter | 2 |  |
| `core.template.deactivate_validation_rules` | filter | 2 |  |
| `core.template.index_validation_rules` | filter | 2 |  |
| `core.template.install_from_file_validation_rules` | filter | 2 |  |
| `core.template.install_from_github_validation_rules` | filter | 2 |  |
| `core.template.install_validation_rules` | filter | 2 |  |
| `core.template.perform_update_validation_rules` | filter | 2 |  |
| `core.template.refresh_layouts_validation_rules` | filter | 2 |  |
| `core.template.uninstall_validation_rules` | filter | 2 |  |
| `core.template.update_validation_rules` | filter | 2 |  |
| `core.template_layout_attachment.after_upload` | action | 1 |  |
| `core.template_layout_attachment.before_upload` | action | 3 |  |
| `core.template_layout_attachment.filter_upload_file` | filter | 1 |  |
| `core.template_layout_attachment.list_validation_rules` | filter | 2 |  |
| `core.template_layout_attachment.upload_validation_rules` | filter | 2 |  |
| `core.templates.activated` | action | 1 | deprecated → `core.templates.after_activate` |
| `core.templates.after_activate` | action | 1 |  |
| `core.templates.after_check_updates` | action | 1 |  |
| `core.templates.after_deactivate` | action | 1 |  |
| `core.templates.after_delete` | action | 2 |  |
| `core.templates.after_find_by_identifier` | action | 2 |  |
| `core.templates.after_install` | action | 2 |  |
| `core.templates.after_list` | action | 2 |  |
| `core.templates.after_list_paginated` | action | 2 |  |
| `core.templates.after_refresh_layouts` | action | 2 |  |
| `core.templates.after_show` | action | 2 |  |
| `core.templates.after_uninstall` | action | 3 |  |
| `core.templates.after_update` | action | 2 |  |
| `core.templates.after_version_update` | action | 3 |  |
| `core.templates.before_activate` | action | 1 |  |
| `core.templates.before_check_updates` | action | 0 |  |
| `core.templates.before_deactivate` | action | 1 |  |
| `core.templates.before_delete` | action | 1 |  |
| `core.templates.before_find_by_identifier` | action | 1 |  |
| `core.templates.before_install` | action | 1 |  |
| `core.templates.before_list` | action | 1 |  |
| `core.templates.before_list_paginated` | action | 1 |  |
| `core.templates.before_refresh_layouts` | action | 1 |  |
| `core.templates.before_show` | action | 1 |  |
| `core.templates.before_uninstall` | action | 2 |  |
| `core.templates.before_update` | action | 2 |  |
| `core.templates.before_version_update` | action | 1 |  |
| `core.templates.filter_activate_data` | filter | 2 |  |
| `core.templates.filter_update_data` | filter | 2 |  |
| `core.templates.installed` | action | 1 | deprecated → `core.templates.after_install` |
| `core.templates.updated` | action | 1 | deprecated → `core.templates.after_version_update` |
| `core.unique_id.after_generate` | action | 2 |  |
| `core.unique_id.before_generate` | action | 1 |  |
| `core.user.after_create` | action | 2 |  |
| `core.user.after_delete` | action | 1 |  |
| `core.user.after_list` | action | 1 |  |
| `core.user.after_search` | action | 2 |  |
| `core.user.after_show` | action | 1 |  |
| `core.user.after_update` | action | 3 |  |
| `core.user.after_withdraw` | action | 1 |  |
| `core.user.before_create` | action | 1 |  |
| `core.user.before_delete` | action | 1 |  |
| `core.user.before_unlock` | action | 1 |  |
| `core.user.before_update` | action | 2 |  |
| `core.user.before_withdraw` | action | 1 |  |
| `core.user.bulk_update_status_validation_rules` | filter | 2 |  |
| `core.user.change_password_validation_rules` | filter | 2 |  |
| `core.user.check_email_validation_rules` | filter | 2 |  |
| `core.user.create_validation_rules` | filter | 2 |  |
| `core.user.delete_validation_rules` | filter | 2 |  |
| `core.user.filter_create_data` | filter | 1 |  |
| `core.user.filter_resource_data` | filter | 2 |  |
| `core.user.filter_update_data` | filter | 2 |  |
| `core.user.list_validation_rules` | filter | 2 |  |
| `core.user.search_validation_rules` | filter | 2 |  |
| `core.user.update_language_validation_rules` | filter | 2 |  |
| `core.user.update_profile_validation_rules` | filter | 2 |  |
| `core.user.update_validation_rules` | filter | 2 |  |
| `core.user.upload_avatar_rules` | filter | 1 | 호환용 옛 이름 → `core.user.upload_avatar_validation_rules` |
| `core.user.upload_avatar_validation_rules` | filter | 1 |  |
| `hook.context.capture` | filter | 1 |  |
| `hook.context.restore` | action | 1 |  |
| `sirsoft-core.user.after_bulk_update` | action | 3 |  |
| `sirsoft-core.user.before_bulk_update` | action | 2 |  |
| `sitemap.index.collect_for_resource` | filter | 4 |  |
| `template.language.merge` | filter | 3 |  |

## gnuboard7-hello_module

| 훅 | 유형 | 인자 개수 | 비고 |
|----|------|-----------|------|
| `gnuboard7-hello_module.memo.created` | action | 1 |  |
| `gnuboard7-hello_module.memo.title.filter` | filter | 1 |  |

## gnuboard7-hello_plugin

| 훅 | 유형 | 인자 개수 | 비고 |
|----|------|-----------|------|
| `gnuboard7-hello_plugin.log.written` | action | 2 |  |

## sirsoft-board

| 훅 | 유형 | 인자 개수 | 비고 |
|----|------|-----------|------|
| `sirsoft-board.attachment.after_delete` | action | 1 |  |
| `sirsoft-board.attachment.after_download` | action | 2 |  |
| `sirsoft-board.attachment.after_link` | action | 1 |  |
| `sirsoft-board.attachment.after_reorder` | action | 2 |  |
| `sirsoft-board.attachment.after_upload` | action | 1 |  |
| `sirsoft-board.attachment.before_delete` | action | 1 |  |
| `sirsoft-board.attachment.before_reorder` | action | 2 |  |
| `sirsoft-board.attachment.before_upload` | action | 3 |  |
| `sirsoft-board.attachment.filter_upload_file` | filter | 1 |  |
| `sirsoft-board.attachment.reorder_validation_rules` | filter | 2 |  |
| `sirsoft-board.attachment.upload_validation_rules` | filter | 2 |  |
| `sirsoft-board.author_ip.after_prune` | action | 2 |  |
| `sirsoft-board.author_ip.before_prune` | action | 1 |  |
| `sirsoft-board.board.after_add_to_menu` | action | 2 |  |
| `sirsoft-board.board.after_create` | action | 2 |  |
| `sirsoft-board.board.after_delete` | action | 1 |  |
| `sirsoft-board.board.after_remove_from_menu` | action | 2 |  |
| `sirsoft-board.board.after_update` | action | 3 |  |
| `sirsoft-board.board.before_add_to_menu` | action | 1 |  |
| `sirsoft-board.board.before_copy` | action | 1 |  |
| `sirsoft-board.board.before_create` | action | 1 |  |
| `sirsoft-board.board.before_delete` | action | 1 |  |
| `sirsoft-board.board.before_remove_from_menu` | action | 1 |  |
| `sirsoft-board.board.before_update` | action | 2 |  |
| `sirsoft-board.board.filter_copy_data` | filter | 2 |  |
| `sirsoft-board.board.filter_create_data` | filter | 1 |  |
| `sirsoft-board.board.filter_menu_data` | filter | 2 |  |
| `sirsoft-board.board.filter_update_data` | filter | 2 |  |
| `sirsoft-board.board.posts.before_force_delete` | action | 2 |  |
| `sirsoft-board.board.store_validation_rules` | filter | 2 |  |
| `sirsoft-board.board.update_validation_rules` | filter | 2 |  |
| `sirsoft-board.board_type.after_create` | action | 2 |  |
| `sirsoft-board.board_type.after_delete` | action | 1 |  |
| `sirsoft-board.board_type.after_update` | action | 3 |  |
| `sirsoft-board.board_type.before_create` | action | 1 |  |
| `sirsoft-board.board_type.before_delete` | action | 1 |  |
| `sirsoft-board.board_type.before_update` | action | 2 |  |
| `sirsoft-board.board_type.filter_create_data` | filter | 1 |  |
| `sirsoft-board.board_type.filter_update_data` | filter | 2 |  |
| `sirsoft-board.comment.after_blind` | action | 2 |  |
| `sirsoft-board.comment.after_create` | action | 2 |  |
| `sirsoft-board.comment.after_delete` | action | 2 |  |
| `sirsoft-board.comment.after_restore` | action | 2 |  |
| `sirsoft-board.comment.after_update` | action | 3 |  |
| `sirsoft-board.comment.before_blind` | action | 3 |  |
| `sirsoft-board.comment.before_create` | action | 2 |  |
| `sirsoft-board.comment.before_delete` | action | 2 |  |
| `sirsoft-board.comment.before_restore` | action | 3 |  |
| `sirsoft-board.comment.before_update` | action | 3 |  |
| `sirsoft-board.comment.filter_create_data` | filter | 2 |  |
| `sirsoft-board.comment.filter_update_data` | filter | 3 |  |
| `sirsoft-board.comment.store_validation_rules` | filter | 2 |  |
| `sirsoft-board.comment.update_validation_rules` | filter | 2 |  |
| `sirsoft-board.guest_author.after_prune` | action | 2 |  |
| `sirsoft-board.guest_author.before_prune` | action | 1 |  |
| `sirsoft-board.permissions.after_create` | action | 1 |  |
| `sirsoft-board.permissions.after_delete` | action | 1 |  |
| `sirsoft-board.permissions.after_update` | action | 1 |  |
| `sirsoft-board.post.after_blind` | action | 2 |  |
| `sirsoft-board.post.after_bulk_action` | action | 4 |  |
| `sirsoft-board.post.after_create` | action | 3 |  |
| `sirsoft-board.post.after_delete` | action | 3 |  |
| `sirsoft-board.post.after_restore` | action | 2 |  |
| `sirsoft-board.post.after_update` | action | 3 |  |
| `sirsoft-board.post.before_blind` | action | 3 |  |
| `sirsoft-board.post.before_bulk_action` | action | 4 |  |
| `sirsoft-board.post.before_create` | action | 2 |  |
| `sirsoft-board.post.before_delete` | action | 2 |  |
| `sirsoft-board.post.before_restore` | action | 3 |  |
| `sirsoft-board.post.before_update` | action | 3 |  |
| `sirsoft-board.post.filter_content_thumbnail` | filter | 3 |  |
| `sirsoft-board.post.filter_create_data` | filter | 2 |  |
| `sirsoft-board.post.filter_update_data` | filter | 3 |  |
| `sirsoft-board.post.store_validation_rules` | filter | 2 |  |
| `sirsoft-board.post.update_validation_rules` | filter | 2 |  |
| `sirsoft-board.reaction.after_react` | action | 5, 6 |  |
| `sirsoft-board.reaction.before_react` | action | 4 |  |
| `sirsoft-board.report.after_blind_content` | action | 1 |  |
| `sirsoft-board.report.after_bulk_update_status` | action | 4 |  |
| `sirsoft-board.report.after_cancel` | action | 4 |  |
| `sirsoft-board.report.after_create` | action | 1 |  |
| `sirsoft-board.report.after_delete` | action | 1 |  |
| `sirsoft-board.report.after_delete_content` | action | 1 |  |
| `sirsoft-board.report.after_restore_content` | action | 1 |  |
| `sirsoft-board.report.after_update_status` | action | 1 |  |
| `sirsoft-board.report.before_bulk_update_status` | action | 2 |  |
| `sirsoft-board.report.before_cancel` | action | 2 |  |
| `sirsoft-board.report.before_create` | action | 1 |  |
| `sirsoft-board.report.before_delete` | action | 1 |  |
| `sirsoft-board.report.before_update_status` | action | 2 |  |
| `sirsoft-board.report.filter_create_data` | filter | 1 |  |
| `sirsoft-board.roles.after_create` | action | 1 |  |
| `sirsoft-board.roles.after_delete` | action | 1 |  |
| `sirsoft-board.scrap.after_toggle` | action | 3 |  |
| `sirsoft-board.scrap.before_toggle` | action | 2 |  |
| `sirsoft-board.search.post.index_should_update` | filter | 2 |  |
| `sirsoft-board.settings.after_bulk_apply` | action | 2 |  |
| `sirsoft-board.settings.after_bulk_apply_aborted` | action | 2 |  |
| `sirsoft-board.settings.before_bulk_apply` | action | 3 |  |
| `sirsoft-board.user_post.store_validation_rules` | filter | 2 |  |
| `sirsoft-board.user_post.update_validation_rules` | filter | 2 |  |

## sirsoft-ckeditor5

| 훅 | 유형 | 인자 개수 | 비고 |
|----|------|-----------|------|
| `sirsoft-ckeditor5.image.after_upload` | action | 1 |  |
| `sirsoft-ckeditor5.image.before_upload` | action | 2 |  |
| `sirsoft-ckeditor5.image.filter_reference_sources` | filter | 1 |  |
| `sirsoft-ckeditor5.image.filter_upload_file` | filter | 1 |  |

## sirsoft-ecommerce

| 훅 | 유형 | 인자 개수 | 비고 |
|----|------|-----------|------|
| `sirsoft-ecommerce.adjustment.filter_restore_promotions` | filter | 2 |  |
| `sirsoft-ecommerce.admin.user_currency.changed` | action | 2 |  |
| `sirsoft-ecommerce.admin.user_shipping_country.changed` | action | 2 |  |
| `sirsoft-ecommerce.brand.after_create` | action | 2 |  |
| `sirsoft-ecommerce.brand.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.brand.after_list` | action | 2 |  |
| `sirsoft-ecommerce.brand.after_show` | action | 1 |  |
| `sirsoft-ecommerce.brand.after_toggle_status` | action | 1 |  |
| `sirsoft-ecommerce.brand.after_update` | action | 3 |  |
| `sirsoft-ecommerce.brand.before_create` | action | 1 |  |
| `sirsoft-ecommerce.brand.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.brand.before_list` | action | 1 |  |
| `sirsoft-ecommerce.brand.before_show` | action | 1 |  |
| `sirsoft-ecommerce.brand.before_toggle_status` | action | 1 |  |
| `sirsoft-ecommerce.brand.before_update` | action | 2 |  |
| `sirsoft-ecommerce.brand.create_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.brand.filter_create_data` | filter | 1 |  |
| `sirsoft-ecommerce.brand.filter_list_query` | filter | 1 |  |
| `sirsoft-ecommerce.brand.filter_list_result` | filter | 2 |  |
| `sirsoft-ecommerce.brand.filter_show_result` | filter | 1 |  |
| `sirsoft-ecommerce.brand.filter_update_data` | filter | 2 |  |
| `sirsoft-ecommerce.brand.update_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.calculation.after_final_result` | filter | 2 |  |
| `sirsoft-ecommerce.calculation.after_item_subtotals` | filter | 2 |  |
| `sirsoft-ecommerce.calculation.after_order_discount` | filter | 3 |  |
| `sirsoft-ecommerce.calculation.after_payment_amount` | filter | 3 |  |
| `sirsoft-ecommerce.calculation.after_points_earning` | filter | 4 |  |
| `sirsoft-ecommerce.calculation.after_points_usage` | filter | 4 |  |
| `sirsoft-ecommerce.calculation.after_product_discount` | filter | 3 |  |
| `sirsoft-ecommerce.calculation.after_shipping_discount` | filter | 2 |  |
| `sirsoft-ecommerce.calculation.after_shipping_fee` | filter | 3 |  |
| `sirsoft-ecommerce.calculation.after_tax_classification` | filter | 3 |  |
| `sirsoft-ecommerce.calculation.before_final_result` | filter | 2 |  |
| `sirsoft-ecommerce.calculation.before_item_subtotals` | filter | 2 |  |
| `sirsoft-ecommerce.calculation.before_order_discount` | filter | 3 |  |
| `sirsoft-ecommerce.calculation.before_payment_amount` | filter | 2 |  |
| `sirsoft-ecommerce.calculation.before_product_discount` | filter | 3 |  |
| `sirsoft-ecommerce.calculation.before_shipping_discount` | filter | 2 |  |
| `sirsoft-ecommerce.calculation.before_shipping_fee` | filter | 2 |  |
| `sirsoft-ecommerce.calculation.filter_promotions_snapshot` | filter | 2 |  |
| `sirsoft-ecommerce.cart.after_add` | action | 2 |  |
| `sirsoft-ecommerce.cart.after_bulk_delete` | action | 3 |  |
| `sirsoft-ecommerce.cart.after_change_option` | action | 3 |  |
| `sirsoft-ecommerce.cart.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.cart.after_delete_all` | action | 3 |  |
| `sirsoft-ecommerce.cart.after_delete_multiple` | action | 2 |  |
| `sirsoft-ecommerce.cart.after_list` | action | 3 |  |
| `sirsoft-ecommerce.cart.after_merge` | action | 4 |  |
| `sirsoft-ecommerce.cart.after_reorder` | action | 4 |  |
| `sirsoft-ecommerce.cart.after_update_quantity` | action | 2 |  |
| `sirsoft-ecommerce.cart.before_add` | action | 1 |  |
| `sirsoft-ecommerce.cart.before_bulk_delete` | action | 1 |  |
| `sirsoft-ecommerce.cart.before_change_option` | action | 3 |  |
| `sirsoft-ecommerce.cart.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.cart.before_delete_all` | action | 2 |  |
| `sirsoft-ecommerce.cart.before_delete_multiple` | action | 1 |  |
| `sirsoft-ecommerce.cart.before_list` | action | 2 |  |
| `sirsoft-ecommerce.cart.before_merge` | action | 2 |  |
| `sirsoft-ecommerce.cart.before_reorder` | action | 2 |  |
| `sirsoft-ecommerce.cart.before_update_quantity` | action | 2 |  |
| `sirsoft-ecommerce.cart.bulk_add_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.cart.change_option_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.cart.delete_items_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.cart.filter_add_data` | filter | 1 |  |
| `sirsoft-ecommerce.cart.filter_list_result` | filter | 3 |  |
| `sirsoft-ecommerce.cart.get_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.cart.update_quantity_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.cash_receipt.cancel` | filter | 4 |  |
| `sirsoft-ecommerce.cash_receipt.issue` | filter | 4 |  |
| `sirsoft-ecommerce.cash_receipt.registered_providers` | filter | 1 |  |
| `sirsoft-ecommerce.category-image.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.category-image.after_reorder` | action | 1 |  |
| `sirsoft-ecommerce.category-image.after_update` | action | 1 |  |
| `sirsoft-ecommerce.category-image.after_upload` | action | 1 |  |
| `sirsoft-ecommerce.category-image.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.category-image.before_reorder` | action | 1 |  |
| `sirsoft-ecommerce.category-image.before_update` | action | 2 |  |
| `sirsoft-ecommerce.category-image.before_upload` | action | 2 |  |
| `sirsoft-ecommerce.category-image.filter_reorder_validation_rules` | filter | 1 |  |
| `sirsoft-ecommerce.category-image.filter_update_data` | filter | 1 |  |
| `sirsoft-ecommerce.category-image.filter_upload_file` | filter | 1 |  |
| `sirsoft-ecommerce.category-image.filter_upload_validation_rules` | filter | 1 |  |
| `sirsoft-ecommerce.category.after_create` | action | 2 |  |
| `sirsoft-ecommerce.category.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.category.after_list` | action | 2 |  |
| `sirsoft-ecommerce.category.after_public_list` | action | 1 |  |
| `sirsoft-ecommerce.category.after_public_show` | action | 1 |  |
| `sirsoft-ecommerce.category.after_reorder` | action | 1 |  |
| `sirsoft-ecommerce.category.after_show` | action | 1 |  |
| `sirsoft-ecommerce.category.after_toggle_status` | action | 1 |  |
| `sirsoft-ecommerce.category.after_update` | action | 3 |  |
| `sirsoft-ecommerce.category.before_create` | action | 1 |  |
| `sirsoft-ecommerce.category.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.category.before_list` | action | 1 |  |
| `sirsoft-ecommerce.category.before_public_list` | action | 0 |  |
| `sirsoft-ecommerce.category.before_public_show` | action | 1 |  |
| `sirsoft-ecommerce.category.before_reorder` | action | 1 |  |
| `sirsoft-ecommerce.category.before_show` | action | 1 |  |
| `sirsoft-ecommerce.category.before_toggle_status` | action | 1 |  |
| `sirsoft-ecommerce.category.before_update` | action | 2 |  |
| `sirsoft-ecommerce.category.create_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.category.filter_create_data` | filter | 1 |  |
| `sirsoft-ecommerce.category.filter_list_query` | filter | 1 |  |
| `sirsoft-ecommerce.category.filter_list_result` | filter | 2 |  |
| `sirsoft-ecommerce.category.filter_public_list_result` | filter | 1 |  |
| `sirsoft-ecommerce.category.filter_public_show_result` | filter | 1 |  |
| `sirsoft-ecommerce.category.filter_show_result` | filter | 1 |  |
| `sirsoft-ecommerce.category.filter_update_data` | filter | 2 |  |
| `sirsoft-ecommerce.category.reorder_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.category.update_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.checkout.before_payment` | action | 2 |  |
| `sirsoft-ecommerce.checkout.filter_response_data` | filter | 3 |  |
| `sirsoft-ecommerce.checkout.update_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.checkout.validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.claim-image.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.claim-image.after_upload` | action | 1 |  |
| `sirsoft-ecommerce.claim-image.before_upload` | action | 4 |  |
| `sirsoft-ecommerce.claim-image.filter_upload_file` | filter | 1 |  |
| `sirsoft-ecommerce.claim.cancel.option_status_changed` | action | 4 |  |
| `sirsoft-ecommerce.claim.cancel.requested` | action | 2 |  |
| `sirsoft-ecommerce.claim.cancel.status_changed` | action | 3 |  |
| `sirsoft-ecommerce.claim.exchange.after_mode_change` | action | 4 |  |
| `sirsoft-ecommerce.claim.exchange.after_tracking_deliver` | action | 5 |  |
| `sirsoft-ecommerce.claim.exchange.approved` | action | 1 |  |
| `sirsoft-ecommerce.claim.exchange.before_complete` | action | 4 |  |
| `sirsoft-ecommerce.claim.exchange.before_mode_change` | action | 3 |  |
| `sirsoft-ecommerce.claim.exchange.before_option_status_change` | action | 4 |  |
| `sirsoft-ecommerce.claim.exchange.before_request` | action | 2 |  |
| `sirsoft-ecommerce.claim.exchange.before_status_change` | action | 4 |  |
| `sirsoft-ecommerce.claim.exchange.before_update_request` | action | 2 |  |
| `sirsoft-ecommerce.claim.exchange.before_withdraw` | action | 1 |  |
| `sirsoft-ecommerce.claim.exchange.collection_overdue` | action | 2 |  |
| `sirsoft-ecommerce.claim.exchange.completed` | action | 1 |  |
| `sirsoft-ecommerce.claim.exchange.filter_complete_options` | filter | 4 |  |
| `sirsoft-ecommerce.claim.exchange.filter_request_data` | filter | 2 |  |
| `sirsoft-ecommerce.claim.exchange.filter_status_change_data` | filter | 4 |  |
| `sirsoft-ecommerce.claim.exchange.filter_update_request_data` | filter | 2 |  |
| `sirsoft-ecommerce.claim.exchange.option_status_changed` | action | 4 |  |
| `sirsoft-ecommerce.claim.exchange.rejected` | action | 1 |  |
| `sirsoft-ecommerce.claim.exchange.request_updated` | action | 3 |  |
| `sirsoft-ecommerce.claim.exchange.requested` | action | 3 |  |
| `sirsoft-ecommerce.claim.exchange.status_changed` | action | 3 |  |
| `sirsoft-ecommerce.claim.exchange.withdrawn` | action | 1 |  |
| `sirsoft-ecommerce.claim.items_transitioned` | action | 3 |  |
| `sirsoft-ecommerce.claim.notify_partially_processed` | action | 2 |  |
| `sirsoft-ecommerce.claim.notify_withdrawn` | action | 2 |  |
| `sirsoft-ecommerce.claim.notify_withdrawn_on_behalf` | action | 2 |  |
| `sirsoft-ecommerce.claim.payment_cancelled` | action | 3 |  |
| `sirsoft-ecommerce.claim.refund.approved` | action | 1 |  |
| `sirsoft-ecommerce.claim.refund.before_complete` | action | 3 |  |
| `sirsoft-ecommerce.claim.refund.before_option_status_change` | action | 4 |  |
| `sirsoft-ecommerce.claim.refund.before_request` | action | 2 |  |
| `sirsoft-ecommerce.claim.refund.before_status_change` | action | 4 |  |
| `sirsoft-ecommerce.claim.refund.before_update_request` | action | 2 |  |
| `sirsoft-ecommerce.claim.refund.before_withdraw` | action | 1 |  |
| `sirsoft-ecommerce.claim.refund.completed` | action | 1 |  |
| `sirsoft-ecommerce.claim.refund.filter_request_data` | filter | 2 |  |
| `sirsoft-ecommerce.claim.refund.filter_status_change_data` | filter | 4 |  |
| `sirsoft-ecommerce.claim.refund.filter_update_request_data` | filter | 2 |  |
| `sirsoft-ecommerce.claim.refund.on_hold` | action | 1 |  |
| `sirsoft-ecommerce.claim.refund.option_status_changed` | action | 4 |  |
| `sirsoft-ecommerce.claim.refund.rejected` | action | 1 |  |
| `sirsoft-ecommerce.claim.refund.request_updated` | action | 3 |  |
| `sirsoft-ecommerce.claim.refund.requested` | action | 2, 3 |  |
| `sirsoft-ecommerce.claim.refund.status_changed` | action | 4 |  |
| `sirsoft-ecommerce.claim.refund.withdrawn` | action | 1 |  |
| `sirsoft-ecommerce.claim.return.after_tracking_deliver` | action | 5 |  |
| `sirsoft-ecommerce.claim.return.approved` | action | 1 |  |
| `sirsoft-ecommerce.claim.return.before_complete` | action | 4 |  |
| `sirsoft-ecommerce.claim.return.before_option_status_change` | action | 4 |  |
| `sirsoft-ecommerce.claim.return.before_request` | action | 2 |  |
| `sirsoft-ecommerce.claim.return.before_status_change` | action | 4 |  |
| `sirsoft-ecommerce.claim.return.before_update_request` | action | 2 |  |
| `sirsoft-ecommerce.claim.return.before_withdraw` | action | 1 |  |
| `sirsoft-ecommerce.claim.return.completed` | action | 1 |  |
| `sirsoft-ecommerce.claim.return.filter_complete_options` | filter | 4 |  |
| `sirsoft-ecommerce.claim.return.filter_request_data` | filter | 2 |  |
| `sirsoft-ecommerce.claim.return.filter_status_change_data` | filter | 4 |  |
| `sirsoft-ecommerce.claim.return.filter_update_request_data` | filter | 2 |  |
| `sirsoft-ecommerce.claim.return.option_status_changed` | action | 4 |  |
| `sirsoft-ecommerce.claim.return.rejected` | action | 1 |  |
| `sirsoft-ecommerce.claim.return.request_updated` | action | 3 |  |
| `sirsoft-ecommerce.claim.return.requested` | action | 3 |  |
| `sirsoft-ecommerce.claim.return.status_changed` | action | 3 |  |
| `sirsoft-ecommerce.claim.return.withdrawn` | action | 1 |  |
| `sirsoft-ecommerce.claim_payment.approve` | filter | 3 |  |
| `sirsoft-ecommerce.claim_payment.refund` | filter | 5 |  |
| `sirsoft-ecommerce.claim_payment.void_approval` | filter | 4 |  |
| `sirsoft-ecommerce.claim_reason.after_create` | action | 2 |  |
| `sirsoft-ecommerce.claim_reason.after_delete` | action | 2 |  |
| `sirsoft-ecommerce.claim_reason.after_list` | action | 2 |  |
| `sirsoft-ecommerce.claim_reason.after_show` | action | 1 |  |
| `sirsoft-ecommerce.claim_reason.after_toggle_status` | action | 1 |  |
| `sirsoft-ecommerce.claim_reason.after_update` | action | 2 |  |
| `sirsoft-ecommerce.claim_reason.before_create` | action | 1 |  |
| `sirsoft-ecommerce.claim_reason.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.claim_reason.before_list` | action | 1 |  |
| `sirsoft-ecommerce.claim_reason.before_show` | action | 1 |  |
| `sirsoft-ecommerce.claim_reason.before_toggle_status` | action | 1 |  |
| `sirsoft-ecommerce.claim_reason.before_update` | action | 2 |  |
| `sirsoft-ecommerce.claim_reason.filter_create_data` | filter | 1 |  |
| `sirsoft-ecommerce.claim_reason.filter_list_query` | filter | 1 |  |
| `sirsoft-ecommerce.claim_reason.filter_list_result` | filter | 2 |  |
| `sirsoft-ecommerce.claim_reason.filter_show_result` | filter | 1 |  |
| `sirsoft-ecommerce.claim_reason.filter_update_data` | filter | 2 |  |
| `sirsoft-ecommerce.coupon.after_bulk_status` | action | 4 |  |
| `sirsoft-ecommerce.coupon.after_create` | action | 2 |  |
| `sirsoft-ecommerce.coupon.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.coupon.after_direct_issue` | action | 3 |  |
| `sirsoft-ecommerce.coupon.after_direct_issue_batch` | action | 2 |  |
| `sirsoft-ecommerce.coupon.after_issue_cancel` | action | 1 |  |
| `sirsoft-ecommerce.coupon.after_issues_list` | action | 3 |  |
| `sirsoft-ecommerce.coupon.after_list` | action | 2 |  |
| `sirsoft-ecommerce.coupon.after_show` | action | 1 |  |
| `sirsoft-ecommerce.coupon.after_update` | action | 3 |  |
| `sirsoft-ecommerce.coupon.before_bulk_status` | action | 2 |  |
| `sirsoft-ecommerce.coupon.before_create` | action | 1 |  |
| `sirsoft-ecommerce.coupon.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.coupon.before_direct_issue` | action | 2 |  |
| `sirsoft-ecommerce.coupon.before_issue_cancel` | action | 1 |  |
| `sirsoft-ecommerce.coupon.before_issues_list` | action | 2 |  |
| `sirsoft-ecommerce.coupon.before_list` | action | 1 |  |
| `sirsoft-ecommerce.coupon.before_show` | action | 1 |  |
| `sirsoft-ecommerce.coupon.before_update` | action | 2 |  |
| `sirsoft-ecommerce.coupon.create_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.coupon.filter_create_data` | filter | 1 |  |
| `sirsoft-ecommerce.coupon.filter_list_query` | filter | 1 |  |
| `sirsoft-ecommerce.coupon.filter_list_result` | filter | 2 |  |
| `sirsoft-ecommerce.coupon.filter_show_result` | filter | 1 |  |
| `sirsoft-ecommerce.coupon.filter_update_data` | filter | 2 |  |
| `sirsoft-ecommerce.coupon.issues_list_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.coupon.restore` | action | 2 |  |
| `sirsoft-ecommerce.coupon.update_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.coupon.use` | action | 2 |  |
| `sirsoft-ecommerce.coupon.user_available_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.coupon.user_downloadable_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.coupon.user_list_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.currency.after_apply_rates` | action | 2 |  |
| `sirsoft-ecommerce.currency.registered_rate_providers` | filter | 1 |  |
| `sirsoft-ecommerce.extra_fee_template.after_bulk_create` | action | 3 |  |
| `sirsoft-ecommerce.extra_fee_template.after_bulk_delete` | action | 3 |  |
| `sirsoft-ecommerce.extra_fee_template.after_bulk_toggle_active` | action | 4 |  |
| `sirsoft-ecommerce.extra_fee_template.after_create` | action | 1 |  |
| `sirsoft-ecommerce.extra_fee_template.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.extra_fee_template.after_read` | action | 1 |  |
| `sirsoft-ecommerce.extra_fee_template.after_toggle_active` | action | 1 |  |
| `sirsoft-ecommerce.extra_fee_template.after_update` | action | 2 |  |
| `sirsoft-ecommerce.extra_fee_template.before_bulk_create` | action | 1 |  |
| `sirsoft-ecommerce.extra_fee_template.before_bulk_delete` | action | 1 |  |
| `sirsoft-ecommerce.extra_fee_template.before_bulk_toggle_active` | action | 2 |  |
| `sirsoft-ecommerce.extra_fee_template.before_create` | action | 1 |  |
| `sirsoft-ecommerce.extra_fee_template.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.extra_fee_template.before_toggle_active` | action | 1 |  |
| `sirsoft-ecommerce.extra_fee_template.before_update` | action | 2 |  |
| `sirsoft-ecommerce.extra_fee_template.filter_bulk_create_data` | filter | 1 |  |
| `sirsoft-ecommerce.extra_fee_template.filter_create_data` | filter | 1 |  |
| `sirsoft-ecommerce.extra_fee_template.filter_list_params` | filter | 1 |  |
| `sirsoft-ecommerce.extra_fee_template.filter_update_data` | filter | 2 |  |
| `sirsoft-ecommerce.inquiry.count_replies` | filter | 2 |  |
| `sirsoft-ecommerce.inquiry.create` | filter | 3 |  |
| `sirsoft-ecommerce.inquiry.delete` | filter | 3 |  |
| `sirsoft-ecommerce.inquiry.delete_reply` | filter | 3 |  |
| `sirsoft-ecommerce.inquiry.get_by_ids` | filter | 2 |  |
| `sirsoft-ecommerce.inquiry.get_settings` | filter | 2 |  |
| `sirsoft-ecommerce.inquiry.store_validation_messages` | filter | 2 |  |
| `sirsoft-ecommerce.inquiry.store_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.inquiry.update` | filter | 4 |  |
| `sirsoft-ecommerce.inquiry.update_reply` | filter | 4 |  |
| `sirsoft-ecommerce.inquiry.update_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.label.after_create` | action | 2 |  |
| `sirsoft-ecommerce.label.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.label.after_list` | action | 2 |  |
| `sirsoft-ecommerce.label.after_show` | action | 1 |  |
| `sirsoft-ecommerce.label.after_toggle_status` | action | 1 |  |
| `sirsoft-ecommerce.label.after_update` | action | 3 |  |
| `sirsoft-ecommerce.label.before_create` | action | 1 |  |
| `sirsoft-ecommerce.label.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.label.before_list` | action | 1 |  |
| `sirsoft-ecommerce.label.before_show` | action | 1 |  |
| `sirsoft-ecommerce.label.before_toggle_status` | action | 1 |  |
| `sirsoft-ecommerce.label.before_update` | action | 2 |  |
| `sirsoft-ecommerce.label.filter_create_data` | filter | 1 |  |
| `sirsoft-ecommerce.label.filter_list_query` | filter | 1 |  |
| `sirsoft-ecommerce.label.filter_list_result` | filter | 2 |  |
| `sirsoft-ecommerce.label.filter_show_result` | filter | 1 |  |
| `sirsoft-ecommerce.label.filter_update_data` | filter | 2 |  |
| `sirsoft-ecommerce.mileage.earn` | action | 2 |  |
| `sirsoft-ecommerce.mileage.max_usable_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.mileage.notify_expiring` | action | 5 |  |
| `sirsoft-ecommerce.mileage.restore` | action | 4 |  |
| `sirsoft-ecommerce.mileage.use` | action | 2 |  |
| `sirsoft-ecommerce.notification.channels` | filter | 2 |  |
| `sirsoft-ecommerce.option.after_bulk_update` | action | 3 |  |
| `sirsoft-ecommerce.option.before_bulk_update` | action | 1 |  |
| `sirsoft-ecommerce.option.bulk_update_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.option.filter_bulk_update_data` | filter | 1 |  |
| `sirsoft-ecommerce.order-option.after_confirm` | action | 3 |  |
| `sirsoft-ecommerce.order-option.before_confirm` | action | 2 |  |
| `sirsoft-ecommerce.order.after_admin_notify` | action | 1 |  |
| `sirsoft-ecommerce.order.after_anonymize` | action | 1 |  |
| `sirsoft-ecommerce.order.after_bulk_shipping_update` | action | 3 |  |
| `sirsoft-ecommerce.order.after_bulk_status_update` | action | 3 |  |
| `sirsoft-ecommerce.order.after_bulk_update` | action | 3 |  |
| `sirsoft-ecommerce.order.after_cancel` | action | 3 |  |
| `sirsoft-ecommerce.order.after_complete` | action | 1 |  |
| `sirsoft-ecommerce.order.after_confirm` | action | 1 |  |
| `sirsoft-ecommerce.order.after_create` | action | 1 |  |
| `sirsoft-ecommerce.order.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.order.after_deliver` | action | 1 |  |
| `sirsoft-ecommerce.order.after_deposit_recorded` | action | 2 |  |
| `sirsoft-ecommerce.order.after_partial_cancel` | action | 3 |  |
| `sirsoft-ecommerce.order.after_payment_complete` | action | 2 |  |
| `sirsoft-ecommerce.order.after_prune` | action | 2 |  |
| `sirsoft-ecommerce.order.after_purchase_confirmed` | action | 1 |  |
| `sirsoft-ecommerce.order.after_read` | action | 1 |  |
| `sirsoft-ecommerce.order.after_reset_guest_password` | action | 1 |  |
| `sirsoft-ecommerce.order.after_send_email` | action | 1 |  |
| `sirsoft-ecommerce.order.after_ship` | action | 1 |  |
| `sirsoft-ecommerce.order.after_status_change` | action | 3 |  |
| `sirsoft-ecommerce.order.after_status_sync` | action | 3 |  |
| `sirsoft-ecommerce.order.after_update` | action | 2 |  |
| `sirsoft-ecommerce.order.after_update_shipping_address` | action | 3 |  |
| `sirsoft-ecommerce.order.before_bulk_shipping_update` | action | 2 |  |
| `sirsoft-ecommerce.order.before_bulk_status_update` | action | 2 |  |
| `sirsoft-ecommerce.order.before_bulk_update` | action | 2 |  |
| `sirsoft-ecommerce.order.before_cancel` | action | 3 |  |
| `sirsoft-ecommerce.order.before_create` | action | 4 |  |
| `sirsoft-ecommerce.order.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.order.before_payment_complete` | action | 2 |  |
| `sirsoft-ecommerce.order.before_prune` | action | 1 |  |
| `sirsoft-ecommerce.order.before_reset_guest_password` | action | 1 |  |
| `sirsoft-ecommerce.order.before_update` | action | 2 |  |
| `sirsoft-ecommerce.order.before_update_shipping_address` | action | 2 |  |
| `sirsoft-ecommerce.order.create_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.order.filter_create_data` | filter | 2 |  |
| `sirsoft-ecommerce.order.filter_export_params` | filter | 1 |  |
| `sirsoft-ecommerce.order.filter_list_params` | filter | 1 |  |
| `sirsoft-ecommerce.order.filter_update_data` | filter | 2 |  |
| `sirsoft-ecommerce.order.list_validation_messages` | filter | 2 |  |
| `sirsoft-ecommerce.order.list_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.order.payment_failed` | action | 4 |  |
| `sirsoft-ecommerce.order.shipping_address_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.order.tax_invoice_stale_after_cancel` | action | 2 |  |
| `sirsoft-ecommerce.order_option.after_bulk_status_change` | action | 3 |  |
| `sirsoft-ecommerce.order_option.after_status_change` | action | 4 |  |
| `sirsoft-ecommerce.order_option.after_sync_status` | action | 3 |  |
| `sirsoft-ecommerce.order_option.before_bulk_status_change` | action | 2 |  |
| `sirsoft-ecommerce.order_option.before_status_change` | action | 3 |  |
| `sirsoft-ecommerce.order_shipping.tracking_updated` | action | 3 |  |
| `sirsoft-ecommerce.payment.before_approve` | action | 1 |  |
| `sirsoft-ecommerce.payment.before_cancel` | action | 3 |  |
| `sirsoft-ecommerce.payment.before_confirm_deposit` | action | 1, 3 |  |
| `sirsoft-ecommerce.payment.get_client_config` | filter | 2 |  |
| `sirsoft-ecommerce.payment.refund` | filter | 6 |  |
| `sirsoft-ecommerce.payment.registered_pg_providers` | filter | 1 |  |
| `sirsoft-ecommerce.payment.supports_partial_cancel` | filter | 2 |  |
| `sirsoft-ecommerce.preset.after_create` | action | 2 |  |
| `sirsoft-ecommerce.preset.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.preset.after_update` | action | 2 |  |
| `sirsoft-ecommerce.preset.before_create` | action | 1 |  |
| `sirsoft-ecommerce.preset.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.preset.before_update` | action | 2 |  |
| `sirsoft-ecommerce.preset.filter_create_data` | filter | 1 |  |
| `sirsoft-ecommerce.preset.filter_update_data` | filter | 2 |  |
| `sirsoft-ecommerce.preset.store_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.preset.update_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product-common-info.after_create` | action | 2 |  |
| `sirsoft-ecommerce.product-common-info.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.product-common-info.after_list` | action | 2 |  |
| `sirsoft-ecommerce.product-common-info.after_list_paginated` | action | 2 |  |
| `sirsoft-ecommerce.product-common-info.after_show` | action | 1 |  |
| `sirsoft-ecommerce.product-common-info.after_update` | action | 3 |  |
| `sirsoft-ecommerce.product-common-info.before_create` | action | 1 |  |
| `sirsoft-ecommerce.product-common-info.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.product-common-info.before_list` | action | 1 |  |
| `sirsoft-ecommerce.product-common-info.before_show` | action | 1 |  |
| `sirsoft-ecommerce.product-common-info.before_update` | action | 2 |  |
| `sirsoft-ecommerce.product-common-info.create_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product-common-info.filter_create_data` | filter | 1 |  |
| `sirsoft-ecommerce.product-common-info.filter_list_query` | filter | 1 |  |
| `sirsoft-ecommerce.product-common-info.filter_list_result` | filter | 2 |  |
| `sirsoft-ecommerce.product-common-info.filter_show_result` | filter | 1 |  |
| `sirsoft-ecommerce.product-common-info.filter_update_data` | filter | 2 |  |
| `sirsoft-ecommerce.product-common-info.update_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product-image.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.product-image.after_reorder` | action | 1 |  |
| `sirsoft-ecommerce.product-image.after_upload` | action | 1 |  |
| `sirsoft-ecommerce.product-image.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.product-image.before_reorder` | action | 1 |  |
| `sirsoft-ecommerce.product-image.before_upload` | action | 2 |  |
| `sirsoft-ecommerce.product-image.filter_reorder_validation_rules` | filter | 1 |  |
| `sirsoft-ecommerce.product-image.filter_upload_file` | filter | 1 |  |
| `sirsoft-ecommerce.product-image.filter_upload_validation_rules` | filter | 1 |  |
| `sirsoft-ecommerce.product-notice-template.after_copy` | action | 2 |  |
| `sirsoft-ecommerce.product-notice-template.after_create` | action | 2 |  |
| `sirsoft-ecommerce.product-notice-template.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.product-notice-template.after_list` | action | 2 |  |
| `sirsoft-ecommerce.product-notice-template.after_list_paginated` | action | 2 |  |
| `sirsoft-ecommerce.product-notice-template.after_show` | action | 1 |  |
| `sirsoft-ecommerce.product-notice-template.after_toggle_active` | action | 1 |  |
| `sirsoft-ecommerce.product-notice-template.after_update` | action | 3 |  |
| `sirsoft-ecommerce.product-notice-template.before_copy` | action | 1 |  |
| `sirsoft-ecommerce.product-notice-template.before_create` | action | 1 |  |
| `sirsoft-ecommerce.product-notice-template.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.product-notice-template.before_list` | action | 1 |  |
| `sirsoft-ecommerce.product-notice-template.before_show` | action | 1 |  |
| `sirsoft-ecommerce.product-notice-template.before_update` | action | 2 |  |
| `sirsoft-ecommerce.product-notice-template.create_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product-notice-template.filter_create_data` | filter | 1 |  |
| `sirsoft-ecommerce.product-notice-template.filter_list_query` | filter | 1 |  |
| `sirsoft-ecommerce.product-notice-template.filter_list_result` | filter | 2 |  |
| `sirsoft-ecommerce.product-notice-template.filter_show_result` | filter | 1 |  |
| `sirsoft-ecommerce.product-notice-template.filter_update_data` | filter | 2 |  |
| `sirsoft-ecommerce.product-notice-template.update_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product-review.after_bulk_delete` | action | 2 |  |
| `sirsoft-ecommerce.product-review.after_create` | action | 1 |  |
| `sirsoft-ecommerce.product-review.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.product-review.before_bulk_delete` | action | 2 |  |
| `sirsoft-ecommerce.product-review.before_create` | action | 1 |  |
| `sirsoft-ecommerce.product-review.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.product.after_bulk_price_update` | action | 3 |  |
| `sirsoft-ecommerce.product.after_bulk_stock_update` | action | 3 |  |
| `sirsoft-ecommerce.product.after_bulk_update` | action | 3 |  |
| `sirsoft-ecommerce.product.after_create` | action | 1 |  |
| `sirsoft-ecommerce.product.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.product.after_new_list` | action | 1 |  |
| `sirsoft-ecommerce.product.after_options_sync` | action | 4 |  |
| `sirsoft-ecommerce.product.after_popular_list` | action | 1 |  |
| `sirsoft-ecommerce.product.after_public_list` | action | 1 |  |
| `sirsoft-ecommerce.product.after_read` | action | 1 |  |
| `sirsoft-ecommerce.product.after_stock_sync` | action | 2 |  |
| `sirsoft-ecommerce.product.after_update` | action | 2 |  |
| `sirsoft-ecommerce.product.before_bulk_price_update` | action | 2 |  |
| `sirsoft-ecommerce.product.before_bulk_stock_update` | action | 2 |  |
| `sirsoft-ecommerce.product.before_bulk_update` | action | 1, 2 |  |
| `sirsoft-ecommerce.product.before_create` | action | 1 |  |
| `sirsoft-ecommerce.product.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.product.before_new_list` | action | 0 |  |
| `sirsoft-ecommerce.product.before_popular_list` | action | 0 |  |
| `sirsoft-ecommerce.product.before_public_list` | action | 1 |  |
| `sirsoft-ecommerce.product.before_update` | action | 2 |  |
| `sirsoft-ecommerce.product.bulk_price_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product.bulk_status_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product.bulk_stock_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product.bulk_update_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product.filter_bulk_update_data` | filter | 1 |  |
| `sirsoft-ecommerce.product.filter_content_thumbnail` | filter | 3 |  |
| `sirsoft-ecommerce.product.filter_create_data` | filter | 1 |  |
| `sirsoft-ecommerce.product.filter_list_params` | filter | 1 |  |
| `sirsoft-ecommerce.product.filter_new_list_result` | filter | 1 |  |
| `sirsoft-ecommerce.product.filter_popular_list_result` | filter | 1 |  |
| `sirsoft-ecommerce.product.filter_public_list_params` | filter | 1 |  |
| `sirsoft-ecommerce.product.filter_update_data` | filter | 2 |  |
| `sirsoft-ecommerce.product.list_validation_messages` | filter | 2 |  |
| `sirsoft-ecommerce.product.list_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product.logs_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product.public_list_validation_messages` | filter | 2 |  |
| `sirsoft-ecommerce.product.public_list_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product.public_new_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product.public_popular_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product.public_recent_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product.show_for_copy_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product.store_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product.update_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product_inquiry.after_bulk_delete` | action | 2 |  |
| `sirsoft-ecommerce.product_inquiry.after_create` | action | 1 |  |
| `sirsoft-ecommerce.product_inquiry.after_reply` | action | 1 |  |
| `sirsoft-ecommerce.product_inquiry.before_bulk_delete` | action | 1 |  |
| `sirsoft-ecommerce.product_option.after_bulk_price_update` | action | 3 |  |
| `sirsoft-ecommerce.product_option.after_bulk_stock_update` | action | 2, 3 |  |
| `sirsoft-ecommerce.product_option.before_bulk_price_update` | action | 2 |  |
| `sirsoft-ecommerce.product_option.before_bulk_stock_update` | action | 2 |  |
| `sirsoft-ecommerce.product_option.bulk_price_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.product_option.bulk_stock_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.review-image.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.review-image.after_upload` | action | 1 |  |
| `sirsoft-ecommerce.review-image.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.review-image.before_upload` | action | 2 |  |
| `sirsoft-ecommerce.review-image.filter_upload_file` | filter | 1 |  |
| `sirsoft-ecommerce.review.bulk_validation_messages` | filter | 2 |  |
| `sirsoft-ecommerce.review.bulk_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.review.list_validation_messages` | filter | 2 |  |
| `sirsoft-ecommerce.review.list_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.review.public_list_validation_messages` | filter | 2 |  |
| `sirsoft-ecommerce.review.public_list_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.review.store_reply_validation_messages` | filter | 2 |  |
| `sirsoft-ecommerce.review.store_reply_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.review.store_validation_messages` | filter | 2 |  |
| `sirsoft-ecommerce.review.store_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.review.update_status_validation_messages` | filter | 2 |  |
| `sirsoft-ecommerce.review.update_status_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.search.brand.index_should_update` | filter | 2 |  |
| `sirsoft-ecommerce.search.category.index_should_update` | filter | 2 |  |
| `sirsoft-ecommerce.search.coupon.index_should_update` | filter | 2 |  |
| `sirsoft-ecommerce.search.product.index_should_update` | filter | 2 |  |
| `sirsoft-ecommerce.search.product_common_info.index_should_update` | filter | 2 |  |
| `sirsoft-ecommerce.search_preset.list_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.sequence.after_generate` | action | 2 |  |
| `sirsoft-ecommerce.sequence.before_generate` | action | 1 |  |
| `sirsoft-ecommerce.settings.after_save` | action | 1 |  |
| `sirsoft-ecommerce.settings.filter_available_payment_methods` | filter | 1 |  |
| `sirsoft-ecommerce.shipping.calculate_fee` | filter | 3 |  |
| `sirsoft-ecommerce.shipping_carrier.after_create` | action | 2 |  |
| `sirsoft-ecommerce.shipping_carrier.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.shipping_carrier.after_list` | action | 2 |  |
| `sirsoft-ecommerce.shipping_carrier.after_show` | action | 1 |  |
| `sirsoft-ecommerce.shipping_carrier.after_toggle_status` | action | 1 |  |
| `sirsoft-ecommerce.shipping_carrier.after_update` | action | 3 |  |
| `sirsoft-ecommerce.shipping_carrier.before_create` | action | 1 |  |
| `sirsoft-ecommerce.shipping_carrier.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.shipping_carrier.before_list` | action | 1 |  |
| `sirsoft-ecommerce.shipping_carrier.before_show` | action | 1 |  |
| `sirsoft-ecommerce.shipping_carrier.before_toggle_status` | action | 1 |  |
| `sirsoft-ecommerce.shipping_carrier.before_update` | action | 2 |  |
| `sirsoft-ecommerce.shipping_carrier.create_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.shipping_carrier.filter_create_data` | filter | 1 |  |
| `sirsoft-ecommerce.shipping_carrier.filter_list_query` | filter | 1 |  |
| `sirsoft-ecommerce.shipping_carrier.filter_list_result` | filter | 2 |  |
| `sirsoft-ecommerce.shipping_carrier.filter_show_result` | filter | 1 |  |
| `sirsoft-ecommerce.shipping_carrier.filter_update_data` | filter | 2 |  |
| `sirsoft-ecommerce.shipping_carrier.update_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.shipping_policy.after_bulk_delete` | action | 3 |  |
| `sirsoft-ecommerce.shipping_policy.after_bulk_toggle_active` | action | 4 |  |
| `sirsoft-ecommerce.shipping_policy.after_create` | action | 1 |  |
| `sirsoft-ecommerce.shipping_policy.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.shipping_policy.after_read` | action | 1 |  |
| `sirsoft-ecommerce.shipping_policy.after_set_default` | action | 1 |  |
| `sirsoft-ecommerce.shipping_policy.after_toggle_active` | action | 1 |  |
| `sirsoft-ecommerce.shipping_policy.after_update` | action | 2 |  |
| `sirsoft-ecommerce.shipping_policy.before_bulk_delete` | action | 1 |  |
| `sirsoft-ecommerce.shipping_policy.before_bulk_toggle_active` | action | 2 |  |
| `sirsoft-ecommerce.shipping_policy.before_create` | action | 1 |  |
| `sirsoft-ecommerce.shipping_policy.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.shipping_policy.before_set_default` | action | 1 |  |
| `sirsoft-ecommerce.shipping_policy.before_toggle_active` | action | 1 |  |
| `sirsoft-ecommerce.shipping_policy.before_update` | action | 2 |  |
| `sirsoft-ecommerce.shipping_policy.bulk_delete_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.shipping_policy.bulk_toggle_active_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.shipping_policy.filter_create_data` | filter | 1 |  |
| `sirsoft-ecommerce.shipping_policy.filter_list_params` | filter | 1 |  |
| `sirsoft-ecommerce.shipping_policy.filter_update_data` | filter | 2 |  |
| `sirsoft-ecommerce.shipping_policy.list_validation_messages` | filter | 2 |  |
| `sirsoft-ecommerce.shipping_policy.list_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.shipping_policy.store_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.shipping_policy.update_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.shipping_type.after_create` | action | 2 |  |
| `sirsoft-ecommerce.shipping_type.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.shipping_type.after_list` | action | 2 |  |
| `sirsoft-ecommerce.shipping_type.after_show` | action | 1 |  |
| `sirsoft-ecommerce.shipping_type.after_update` | action | 3 |  |
| `sirsoft-ecommerce.shipping_type.before_create` | action | 1 |  |
| `sirsoft-ecommerce.shipping_type.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.shipping_type.before_list` | action | 1 |  |
| `sirsoft-ecommerce.shipping_type.before_show` | action | 1 |  |
| `sirsoft-ecommerce.shipping_type.before_update` | action | 2 |  |
| `sirsoft-ecommerce.shipping_type.filter_create_data` | filter | 1 |  |
| `sirsoft-ecommerce.shipping_type.filter_list_query` | filter | 1 |  |
| `sirsoft-ecommerce.shipping_type.filter_list_result` | filter | 2 |  |
| `sirsoft-ecommerce.shipping_type.filter_show_result` | filter | 1 |  |
| `sirsoft-ecommerce.shipping_type.filter_update_data` | filter | 2 |  |
| `sirsoft-ecommerce.stock.after_deduct` | action | 1 |  |
| `sirsoft-ecommerce.stock.after_restore` | action | 1 |  |
| `sirsoft-ecommerce.stock.before_deduct` | action | 1 |  |
| `sirsoft-ecommerce.stock.before_restore` | action | 1 |  |
| `sirsoft-ecommerce.temp_order.after_cleanup` | action | 1 |  |
| `sirsoft-ecommerce.temp_order.after_create` | action | 1 |  |
| `sirsoft-ecommerce.temp_order.after_delete` | action | 2 |  |
| `sirsoft-ecommerce.temp_order.after_update` | action | 1 |  |
| `sirsoft-ecommerce.temp_order.before_cleanup` | action | 0 |  |
| `sirsoft-ecommerce.temp_order.before_create` | action | 3 |  |
| `sirsoft-ecommerce.temp_order.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.temp_order.before_update` | action | 4 |  |
| `sirsoft-ecommerce.user_address.after_bulk_delete` | action | 3 |  |
| `sirsoft-ecommerce.user_address.after_create` | action | 1 |  |
| `sirsoft-ecommerce.user_address.after_delete` | action | 1 |  |
| `sirsoft-ecommerce.user_address.after_set_default` | action | 1 |  |
| `sirsoft-ecommerce.user_address.after_update` | action | 1 |  |
| `sirsoft-ecommerce.user_address.before_bulk_delete` | action | 1 |  |
| `sirsoft-ecommerce.user_address.before_create` | action | 1 |  |
| `sirsoft-ecommerce.user_address.before_delete` | action | 1 |  |
| `sirsoft-ecommerce.user_address.before_set_default` | action | 1 |  |
| `sirsoft-ecommerce.user_address.before_update` | action | 2 |  |
| `sirsoft-ecommerce.user_address.store_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.user_address.update_validation_rules` | filter | 2 |  |
| `sirsoft-ecommerce.user_coupon.after_available` | action | 3 |  |
| `sirsoft-ecommerce.user_coupon.after_download` | action | 3 |  |
| `sirsoft-ecommerce.user_coupon.after_downloadable_list` | action | 2 |  |
| `sirsoft-ecommerce.user_coupon.after_list` | action | 3 |  |
| `sirsoft-ecommerce.user_coupon.before_available` | action | 2 |  |
| `sirsoft-ecommerce.user_coupon.before_download` | action | 2 |  |
| `sirsoft-ecommerce.user_coupon.before_downloadable_list` | action | 1 |  |
| `sirsoft-ecommerce.user_coupon.before_list` | action | 2 |  |
| `sirsoft-ecommerce.user_coupon.filter_available_result` | filter | 3 |  |
| `sirsoft-ecommerce.user_coupon.filter_downloadable_result` | filter | 2 |  |
| `sirsoft-ecommerce.user_coupon.filter_list_result` | filter | 3 |  |
| `sirsoft-ecommerce.user_coupon.filter_product_downloadable_result` | filter | 3 |  |
| `sirsoft-ecommerce.user_mileage.after_balance` | action | 2 |  |
| `sirsoft-ecommerce.user_mileage.before_balance` | action | 1 |  |
| `sirsoft-ecommerce.user_mileage.filter_balance` | filter | 2 |  |
| `sirsoft-ecommerce.wishlist.after_bulk_delete` | action | 3 |  |
| `sirsoft-ecommerce.wishlist.after_toggle` | action | 3 |  |
| `sirsoft-ecommerce.wishlist.before_bulk_delete` | action | 1 |  |
| `sirsoft-ecommerce.wishlist.before_toggle` | action | 2 |  |
| `sirsoft-ecommerce.wishlist.toggle_validation_rules` | filter | 2 |  |

## sirsoft-gdpr

| 훅 | 유형 | 인자 개수 | 비고 |
|----|------|-----------|------|
| `core.privacy.notice_sections` | filter | 2 |  |
| `sirsoft-gdpr.consent.before_update` | action | 3 |  |
| `sirsoft-gdpr.consent.filter_update_data` | filter | 3 |  |
| `sirsoft-gdpr.consent.granted` | action | 2 |  |
| `sirsoft-gdpr.policy_version.after_publish` | action | 1 |  |
| `sirsoft-gdpr.policy_version.before_publish` | action | 1 |  |
| `sirsoft-gdpr.policy_version.filter_publish_data` | filter | 1 |  |
| `sirsoft-gdpr.privacy.annual_notice_due` | action | 2 |  |
| `sirsoft-gdpr.settings.after_save` | action | 1 |  |
| `sirsoft-gdpr.settings.before_save` | action | 1 |  |
| `sirsoft-gdpr.settings.filter_save_payload` | filter | 1 |  |

## sirsoft-koreaexim

| 훅 | 유형 | 인자 개수 | 비고 |
|----|------|-----------|------|
| `sirsoft-koreaexim.update.failed` | action | 1 |  |

## sirsoft-marketing

| 훅 | 유형 | 인자 개수 | 비고 |
|----|------|-----------|------|
| `sirsoft-marketing.filter_consent_data` | filter | 3 |  |
| `sirsoft-marketing.user.consent_changed` | action | 2 |  |
| `sirsoft-marketing.user.subscribed` | action | 2 |  |
| `sirsoft-marketing.user.unsubscribed` | action | 2 |  |

## sirsoft-message_bizppurio

| 훅 | 유형 | 인자 개수 | 비고 |
|----|------|-----------|------|
| `sirsoft-message_bizppurio.balance.low` | action | 2 |  |

## sirsoft-page

| 훅 | 유형 | 인자 개수 | 비고 |
|----|------|-----------|------|
| `sirsoft-page.attachment.after_delete` | action | 1 |  |
| `sirsoft-page.attachment.after_reorder` | action | 1 |  |
| `sirsoft-page.attachment.after_upload` | action | 1 |  |
| `sirsoft-page.attachment.before_delete` | action | 1 |  |
| `sirsoft-page.attachment.before_reorder` | action | 1 |  |
| `sirsoft-page.attachment.before_upload` | action | 2 |  |
| `sirsoft-page.attachment.filter_upload_file` | filter | 1 |  |
| `sirsoft-page.page.after_create` | action | 2 |  |
| `sirsoft-page.page.after_delete` | action | 1 |  |
| `sirsoft-page.page.after_publish` | action | 2 |  |
| `sirsoft-page.page.after_restore` | action | 2 |  |
| `sirsoft-page.page.after_update` | action | 3 |  |
| `sirsoft-page.page.before_create` | action | 1 |  |
| `sirsoft-page.page.before_delete` | action | 1 |  |
| `sirsoft-page.page.before_publish` | action | 2 |  |
| `sirsoft-page.page.before_update` | action | 2 |  |
| `sirsoft-page.page.filter_content_thumbnail` | filter | 3 |  |
| `sirsoft-page.page.filter_create_data` | filter | 1 |  |
| `sirsoft-page.page.filter_list_query` | filter | 1 |  |
| `sirsoft-page.page.filter_update_data` | filter | 2 |  |
| `sirsoft-page.search.page.index_should_update` | filter | 2 |  |

## sirsoft-pay_kginicis

| 훅 | 유형 | 인자 개수 | 비고 |
|----|------|-----------|------|
| `sirsoft-pay_kginicis.payment.after_authorize` | action | 2 |  |
| `sirsoft-pay_kginicis.payment.after_cancel` | action | 2 |  |
| `sirsoft-pay_kginicis.payment.after_cbt_refund` | action | 2 |  |
| `sirsoft-pay_kginicis.payment.before_authorize` | action | 2 |  |
| `sirsoft-pay_kginicis.payment.before_cancel` | action | 4 |  |
| `sirsoft-pay_kginicis.payment.before_cbt_refund` | action | 3 |  |

## sirsoft-pay_nhnkcp

| 훅 | 유형 | 인자 개수 | 비고 |
|----|------|-----------|------|
| `sirsoft-pay_nhnkcp.escrow.delivery_started` | action | 2 |  |
| `sirsoft-pay_nhnkcp.escrow.denial_confirmed` | action | 2 |  |
| `sirsoft-pay_nhnkcp.escrow.purchase_cancelled` | action | 2 |  |
| `sirsoft-pay_nhnkcp.escrow.purchase_confirmed` | action | 2 |  |
| `sirsoft-pay_nhnkcp.payment.after_cancel` | action | 2 |  |
| `sirsoft-pay_nhnkcp.payment.after_confirm` | action | 2 |  |
| `sirsoft-pay_nhnkcp.payment.before_cancel` | action | 4 |  |
| `sirsoft-pay_nhnkcp.payment.before_confirm` | action | 2 |  |

## sirsoft-pay_nicepayments

| 훅 | 유형 | 인자 개수 | 비고 |
|----|------|-----------|------|
| `sirsoft-pay_nicepayments.payment.after_authorize` | action | 2 |  |
| `sirsoft-pay_nicepayments.payment.after_cancel` | action | 2 |  |
| `sirsoft-pay_nicepayments.payment.before_authorize` | action | 2 |  |
| `sirsoft-pay_nicepayments.payment.before_cancel` | action | 4 |  |
| `sirsoft-pay_nicepayments.payment.refund_failed` | action | 3 |  |

## sirsoft-tosspayments

| 훅 | 유형 | 인자 개수 | 비고 |
|----|------|-----------|------|
| `sirsoft-tosspayments.payment.after_cancel` | action | 2 |  |
| `sirsoft-tosspayments.payment.after_confirm` | action | 2 |  |
| `sirsoft-tosspayments.payment.before_cancel` | action | 3 |  |
| `sirsoft-tosspayments.payment.before_confirm` | action | 3 |  |

## sirsoft-totp

| 훅 | 유형 | 인자 개수 | 비고 |
|----|------|-----------|------|
| `sirsoft-totp.credential.admin_reset` | action | 2 |  |

## 사용 중단

`app/Extension/HookContract/data/deprecated.php` 가 원본이다. 구독하면 등록 시점에 경고가 남는다.

| 훅 | 대체 | 공개 배포 발화 이력 | 사유 |
|----|------|--------------------|------|
| `core.modules.installed` | `core.modules.after_install` | 있음 | 식별자 1개만 전달하는 구훅 |
| `core.modules.activated` | `core.modules.after_activate` | 있음 | 식별자 1개만 전달하는 구훅 |
| `core.modules.updated` | `core.modules.after_update` | 있음 | 식별자 1개만 전달하는 구훅 |
| `core.plugins.installed` | `core.plugins.after_install` | 있음 | 식별자 1개만 전달하는 구훅 |
| `core.plugins.activated` | `core.plugins.after_activate` | 있음 | 식별자 1개만 전달하는 구훅 |
| `core.plugins.updated` | `core.plugins.after_update` | 있음 | 식별자 1개만 전달하는 구훅 |
| `core.templates.installed` | `core.templates.after_install` | 있음 | 식별자 1개만 전달하는 구훅 |
| `core.templates.activated` | `core.templates.after_activate` | 있음 | 식별자 1개만 전달하는 구훅 |
| `core.templates.updated` | `core.templates.after_version_update` | 있음 | 식별자 1개만 전달하는 구훅 (레코드 수정용 after_update 와 다르다) |
| `core.auth.register` | `core.auth.after_register` | 없음 | 발화 이름은 처음부터 after_register 였다 |
| `core.auth.forgot_password` | `core.auth.after_reset_password_request` | 없음 | 발화 이름은 after_reset_password_request 다 |
| `core.auth.reset_password` | `core.auth.after_password_changed` | 없음 | 발화 이름은 after_password_changed 다 |
| `core.module.after_activate` | `core.modules.after_activate` | 없음 | 생명주기 훅은 복수형(core.modules.*)이다 |
| `core.module.after_deactivate` | `core.modules.after_deactivate` | 없음 | 생명주기 훅은 복수형(core.modules.*)이다 |
| `core.plugin.after_activate` | `core.plugins.after_activate` | 없음 | 생명주기 훅은 복수형(core.plugins.*)이다 |
| `core.plugin.after_deactivate` | `core.plugins.after_deactivate` | 없음 | 생명주기 훅은 복수형(core.plugins.*)이다 |
| `sirsoft-daum_postcode.address.selected` | 없음 | 없음 | 선언만 있고 발화처가 없었다 — 선언에서 뺐다 |
| `sirsoft-daum_postcode.filter_address_data` | 없음 | 없음 | 선언만 있고 발화처가 없었다 — 선언에서 뺐다 |

## 이름을 조립하는 발화처

스캐너가 이름을 풀지 못하는 발화처와 그 이름 패턴이다(`*` 는 점을 포함한 아무 문자열). 원본은 `app/Extension/HookContract/data/dynamic.php`.

| 발화처 | 이름 패턴 | 설명 |
|--------|----------|------|
| `app/Analytics/ExtensionFilter.php` | (호출부에서 수집) | 래퍼 — 호출부(ExtensionFilter::apply 리터럴)를 스캐너가 따로 수집한다 |
| `app/Concerns/Seeder/HasTranslatableSeeder.php` | `seed.*.*.translations`<br>`*.translations` | 시더별 번역 필터 — 확장·엔티티 식별자로 조립 |
| `app/Extension/ModuleManager.php` | `module.*.manifest.translations` | 문자열 보간 조립 |
| `app/Extension/ModuleManager.php` | `module.*.admin_menus.translations` | 문자열 보간 조립 |
| `app/Extension/ModuleManager.php` | `module.*.manifest.translations` | 문자열 보간 조립 |
| `app/Extension/ModuleManager.php` | `module.*.roles.translations` | 문자열 보간 조립 |
| `app/Extension/ModuleManager.php` | `module.*.permissions.translations` | 문자열 보간 조립 |
| `app/Extension/ModuleManager.php` | `seed.*.identity_messages.translations` | 문자열 보간 조립 |
| `app/Extension/ModuleManager.php` | `seed.*.notifications.translations` | 문자열 보간 조립 |
| `app/Extension/PluginManager.php` | `plugin.*.manifest.translations` | 문자열 보간 조립 |
| `app/Extension/PluginManager.php` | `plugin.*.admin_menus.translations` | 문자열 보간 조립 |
| `app/Extension/PluginManager.php` | `plugin.*.manifest.translations` | 문자열 보간 조립 |
| `app/Extension/PluginManager.php` | `plugin.*.roles.translations` | 문자열 보간 조립 |
| `app/Extension/PluginManager.php` | `plugin.*.permissions.translations` | 문자열 보간 조립 |
| `app/Extension/PluginManager.php` | `seed.*.identity_messages.translations` | 문자열 보간 조립 |
| `app/Extension/PluginManager.php` | `seed.*.notifications.translations` | 문자열 보간 조립 |
| `app/Extension/TemplateManager.php` | `template.*.manifest.translations` | 문자열 보간 조립 |
| `app/Extension/TemplateManager.php` | `template.*.manifest.translations` | 문자열 보간 조립 |
| `app/Listeners/NotificationHookListener.php` | `*.notification.extract_data` | 문자열 보간 조립 |
| `app/Listeners/NotificationHookListener.php` | `*.notification.personalize_data` | 문자열 보간 조립 |
| `app/Notifications/BaseNotification.php` | `*.notification.channels` | 문자열 보간 조립 |
| `app/Notifications/GenericNotification.php` | `*.notification.channels` | 문자열 보간 조립 |
| `app/Notifications/GenericNotification.php` | `*.notification.to_*` | 문자열 보간 조립 |
| `app/Seo/Editor/SeoOgPreviewService.php` | `core.seo.filter_og_data`<br>`core.seo.filter_twitter_data` | buildKeyCascade() 호출부가 리터럴로 넘긴다 |
| `app/Services/Analytics/ReportMailService.php` | `core.analytics.weekly_report_ready`<br>`core.analytics.monthly_report_ready` | 기간(weekly·monthly)별 리포트 |
| `app/Services/DriverRegistryService.php` | `core.settings.available_*_drivers` | 드라이버 카테고리별 필터 |
| `app/Services/IdentityMessageDispatcher.php` | `core.identity.message.send_*` | 본인인증 메시지 채널별 발송 |
| `modules/_bundled/sirsoft-ecommerce/src/Http/Requests/Public/CartKeyRequest.php` | `sirsoft-ecommerce.cart.*_validation_rules`<br>`sirsoft-ecommerce.checkout.*_validation_rules` | 하위 FormRequest 가 hookFilterName() 으로 리터럴을 돌려준다 |
| `modules/_bundled/sirsoft-ecommerce/src/Listeners/ClaimStatusNotificationListener.php` | `sirsoft-ecommerce.claim.*.notify_*` | ClaimNotificationHooks::forStatus() 조립 |
| `modules/_bundled/sirsoft-ecommerce/src/Listeners/ClaimStatusNotificationListener.php` | `sirsoft-ecommerce.claim.exchange.notify_collection_overdue` | forStatus() 조립 |
| `modules/_bundled/sirsoft-ecommerce/src/Services/AdminClaimService.php` | `sirsoft-ecommerce.claim.*.amounts_updated` | 문자열 보간 조립 |
| `modules/_bundled/sirsoft-ecommerce/src/Services/AdminClaimService.php` | `sirsoft-ecommerce.claim.*.after_stock_restore` | 문자열 보간 조립 |
| `modules/_bundled/sirsoft-ecommerce/src/Services/ClaimPaymentService.php` | `sirsoft-ecommerce.claim.*.payment_completed` | 클레임 유형별 |
| `modules/_bundled/sirsoft-ecommerce/src/Services/ClaimPaymentService.php` | `sirsoft-ecommerce.claim.*.payment_refunded` | 클레임 유형별 |
| `modules/_bundled/sirsoft-ecommerce/src/Services/Concerns/RecordsClaimTracking.php` | `sirsoft-ecommerce.claim.*.after_tracking_update` | 문자열 보간 조립 |
| `modules/_bundled/sirsoft-ecommerce/src/Services/Concerns/RecordsClaimTracking.php` | `sirsoft-ecommerce.claim.*.before_tracking_update` | 문자열 보간 조립 |
| `modules/_bundled/sirsoft-ecommerce/src/Services/Concerns/RecordsClaimTracking.php` | `sirsoft-ecommerce.claim.*.filter_tracking_update_data` | 문자열 보간 조립 |
| `plugins/_bundled/sirsoft-gdpr/src/Services/GdprConsentService.php` | `sirsoft-gdpr.consent.granted`<br>`sirsoft-gdpr.consent.revoked` | 동의·철회 삼항 — PLUGIN_ID 상수 연결 |
