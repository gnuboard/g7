# 공개 훅 목록 (Hook Catalog)

> 자동 생성 문서 — 손으로 고치지 않는다(소스 스캔).
> 원칙: [hooks.md 「공개 훅 호환 원칙」](hooks.md#공개-훅-호환-원칙) — 공개 훅의 이름·인자 위치는 마이너 버전에서 바꾸지 않는다.

코어가 실제로 발화하는 훅 전부다(소스 스캔). **인자 개수**는 훅 이름 뒤 인자 수이고(`*` = 전개 인자),
발화처가 여럿이면 관측된 개수를 모두 적는다. 훅별 서술은 그 훅을 소유한 도메인 문서를 본다.
기본 제공 확장의 훅은 각 확장의 확장 지점 문서에 있다 — [기본 제공 확장](#기본-제공-확장).

## 목차

- [코어](#코어) (611)
- [기본 제공 확장](#기본-제공-확장) (15)
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
| `core.auth.password_change_postpone_validation_rules` | filter | 2 |  |
| `core.auth.password_change_postponed` | action | 2 |  |
| `core.auth.password_change_recorded` | action | 2 |  |
| `core.auth.password_change_required` | action | 2 |  |
| `core.auth.password_change_validation_rules` | filter | 2 |  |
| `core.auth.password_expired` | action | 2 |  |
| `core.auth.password_expiry_notice` | action | 2 |  |
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
| `core.consent.changed` | action | 3 |  |
| `core.consent.registered_providers` | filter | 1 |  |
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
| `core.identity.claim_validation_rules` | filter | 2 |  |
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
| `core.member_notification.index_validation_attributes` | filter | 2 |  |
| `core.member_notification.index_validation_rules` | filter | 1 |  |
| `core.menu.after_bulk_update_roles` | action | 4 |  |
| `core.menu.after_create` | action | 1 |  |
| `core.menu.after_delete` | action | 1 |  |
| `core.menu.after_sync_roles` | action | 3 |  |
| `core.menu.after_toggle_status` | action | 1 |  |
| `core.menu.after_update` | action | 2 |  |
| `core.menu.after_update_order` | action | 1 |  |
| `core.menu.after_update_order_flat` | action | 1 |  |
| `core.menu.before_bulk_update_roles` | action | 3 |  |
| `core.menu.before_create` | action | 1 |  |
| `core.menu.before_delete` | action | 1 |  |
| `core.menu.before_toggle_status` | action | 2 |  |
| `core.menu.before_update` | action | 2 |  |
| `core.menu.before_update_order` | action | 1 |  |
| `core.menu.bulk_roles_validation_rules` | filter | 2 |  |
| `core.menu.create_validation_rules` | filter | 2 |  |
| `core.menu.filter_create_data` | filter | 1 |  |
| `core.menu.filter_update_data` | filter | 2 |  |
| `core.menu.list_validation_rules` | filter | 2 |  |
| `core.menu.update_order_validation_rules` | filter | 2 |  |
| `core.menu.update_validation_rules` | filter | 2 |  |
| `core.menus.config` | filter | 1 |  |
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
| `core.modules.activated` | action | 1 |  |
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
| `core.modules.installed` | action | 1 |  |
| `core.modules.updated` | action | 1 |  |
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
| `core.notification.channels` | filter | 4 |  |
| `core.notification.filter_available_channels` | filter | 1 |  |
| `core.notification.filter_default_definitions` | filter | 2 |  |
| `core.notification.filter_list_params` | filter | 2 |  |
| `core.notification.filter_seed_definitions` | filter | 2 |  |
| `core.notification.index_validation_rules` | filter | 1 |  |
| `core.notification_definition.after_create` | action | 2 |  |
| `core.notification_definition.after_delete` | action | 1 |  |
| `core.notification_definition.after_toggle_active` | action | 1 |  |
| `core.notification_definition.after_update` | action | 2 |  |
| `core.notification_definition.before_create` | action | 1 |  |
| `core.notification_definition.before_delete` | action | 1 |  |
| `core.notification_definition.before_toggle_active` | action | 1 |  |
| `core.notification_definition.before_update` | action | 2 |  |
| `core.notification_definition.filter_create_data` | filter | 1 |  |
| `core.notification_definition.filter_update_data` | filter | 2 |  |
| `core.notification_definition.index_validation_rules` | filter | 1 |  |
| `core.notification_definition.store_validation_rules` | filter | 2 |  |
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
| `core.permissions.config` | filter | 1 |  |
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
| `core.plugins.activated` | action | 1 |  |
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
| `core.plugins.installed` | action | 1 |  |
| `core.plugins.updated` | action | 1 |  |
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
| `core.role.list_validation_rules` | filter | 2 |  |
| `core.role.store_validation_rules` | filter | 2 |  |
| `core.role.update_validation_rules` | filter | 2 |  |
| `core.roles.config` | filter | 1 |  |
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
| `core.settings.password_expiry_preview_validation_rules` | filter | 2 |  |
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
| `core.templates.activated` | action | 1 |  |
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
| `core.templates.installed` | action | 1 |  |
| `core.templates.updated` | action | 1 |  |
| `core.unique_id.after_generate` | action | 2 |  |
| `core.unique_id.before_generate` | action | 1 |  |
| `core.user.after_bulk_update_roles` | action | 4 |  |
| `core.user.after_create` | action | 2 |  |
| `core.user.after_delete` | action | 1 |  |
| `core.user.after_list` | action | 1 |  |
| `core.user.after_search` | action | 2 |  |
| `core.user.after_show` | action | 1 |  |
| `core.user.after_update` | action | 3 |  |
| `core.user.after_update_roles` | action | 3 |  |
| `core.user.after_withdraw` | action | 1 |  |
| `core.user.before_bulk_update_roles` | action | 3 |  |
| `core.user.before_create` | action | 1 |  |
| `core.user.before_delete` | action | 1 |  |
| `core.user.before_unlock` | action | 1 |  |
| `core.user.before_update` | action | 2 |  |
| `core.user.before_withdraw` | action | 1 |  |
| `core.user.bulk_update_roles_validation_rules` | filter | 2 |  |
| `core.user.bulk_update_status_validation_rules` | filter | 2 |  |
| `core.user.change_password_validation_rules` | filter | 2 |  |
| `core.user.check_email_validation_rules` | filter | 2 |  |
| `core.user.create_validation_rules` | filter | 2 |  |
| `core.user.delete_validation_rules` | filter | 2 |  |
| `core.user.filter_create_data` | filter | 1 |  |
| `core.user.filter_list_params` | filter | 1 |  |
| `core.user.filter_resource_data` | filter | 2 |  |
| `core.user.filter_update_data` | filter | 2 |  |
| `core.user.list_query_extensions` | filter | 2 |  |
| `core.user.list_validation_attributes` | filter | 2 |  |
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

## 기본 제공 확장

확장이 발화하는 훅은 그 확장이 소유하고 문서화한다.

| 확장 | 종류 | 훅 목록 |
|------|------|---------|
| `gnuboard7-hello_module` | 모듈 | [확장 지점 문서](../../modules/_bundled/gnuboard7-hello_module/docs/extension-points.md#발행-훅) |
| `gnuboard7-hello_plugin` | 플러그인 | [확장 지점 문서](../../plugins/_bundled/gnuboard7-hello_plugin/docs/extension-points.md#발행-훅) |
| `sirsoft-board` | 모듈 | [확장 지점 문서](../../modules/_bundled/sirsoft-board/docs/extension-points.md#발행-훅) |
| `sirsoft-ckeditor5` | 플러그인 | [확장 지점 문서](../../plugins/_bundled/sirsoft-ckeditor5/docs/extension-points.md#발행-훅) |
| `sirsoft-ecommerce` | 모듈 | [확장 지점 문서](../../modules/_bundled/sirsoft-ecommerce/docs/extension-points.md#발행-훅) |
| `sirsoft-gdpr` | 플러그인 | [확장 지점 문서](../../plugins/_bundled/sirsoft-gdpr/docs/extension-points.md#발행-훅) |
| `sirsoft-koreaexim` | 플러그인 | [확장 지점 문서](../../plugins/_bundled/sirsoft-koreaexim/docs/extension-points.md#발행-훅) |
| `sirsoft-marketing` | 플러그인 | [확장 지점 문서](../../plugins/_bundled/sirsoft-marketing/docs/extension-points.md#발행-훅) |
| `sirsoft-message_bizppurio` | 플러그인 | [확장 지점 문서](../../plugins/_bundled/sirsoft-message_bizppurio/docs/extension-points.md#발행-훅) |
| `sirsoft-page` | 모듈 | [확장 지점 문서](../../modules/_bundled/sirsoft-page/docs/extension-points.md#발행-훅) |
| `sirsoft-pay_kginicis` | 플러그인 | [확장 지점 문서](../../plugins/_bundled/sirsoft-pay_kginicis/docs/extension-points.md#발행-훅) |
| `sirsoft-pay_nhnkcp` | 플러그인 | [확장 지점 문서](../../plugins/_bundled/sirsoft-pay_nhnkcp/docs/extension-points.md#발행-훅) |
| `sirsoft-pay_nicepayments` | 플러그인 | [확장 지점 문서](../../plugins/_bundled/sirsoft-pay_nicepayments/docs/extension-points.md#발행-훅) |
| `sirsoft-tosspayments` | 플러그인 | [확장 지점 문서](../../plugins/_bundled/sirsoft-tosspayments/docs/extension-points.md#발행-훅) |
| `sirsoft-totp` | 플러그인 | [확장 지점 문서](../../plugins/_bundled/sirsoft-totp/docs/extension-points.md#발행-훅) |

## 사용 중단

사용 중단된 코어 훅과 대체 이름은 [hooks.md 「사용 중단 훅」](hooks.md#사용-중단-훅) 에 있다. 기본 제공 확장의 사용 중단 훅은 그 확장 문서와 CHANGELOG 에 적는다.

## 이름을 조립하는 발화처

이름을 조립해 발화하는 코어 발화처와 그 이름 패턴이다(`*` 는 점을 포함한 아무 문자열).

| 발화처 | 이름 패턴 | 설명 |
|--------|----------|------|
| `app/Analytics/ExtensionFilter.php` | (호출부에서 수집) | 래퍼 — 호출부(ExtensionFilter::apply 리터럴)를 스캐너가 따로 수집한다 |
| `app/Concerns/Seeder/HasTranslatableSeeder.php` | `seed.*.*.translations`<br>`*.translations` | 시더별 번역 필터 — 확장·엔티티 식별자로 조립 |
| `app/Extension/ModuleManager.php` | `module.*.manifest.translations` | 문자열 보간 조립 |
| `app/Extension/ModuleManager.php` | `module.*.admin_menus.translations` | 문자열 보간 조립 |
| `app/Extension/ModuleManager.php` | `module.*.manifest.translations` | 문자열 보간 조립 |
| `app/Extension/ModuleManager.php` | `module.*.roles.translations` | 문자열 보간 조립 |
| `app/Extension/ModuleManager.php` | `module.*.permissions.translations` | 문자열 보간 조립 |
| `app/Extension/ModuleManager.php` | `seed.*.notifications.translations` | 문자열 보간 조립 |
| `app/Extension/PluginManager.php` | `plugin.*.manifest.translations` | 문자열 보간 조립 |
| `app/Extension/PluginManager.php` | `plugin.*.admin_menus.translations` | 문자열 보간 조립 |
| `app/Extension/PluginManager.php` | `plugin.*.manifest.translations` | 문자열 보간 조립 |
| `app/Extension/PluginManager.php` | `plugin.*.roles.translations` | 문자열 보간 조립 |
| `app/Extension/PluginManager.php` | `plugin.*.permissions.translations` | 문자열 보간 조립 |
| `app/Extension/PluginManager.php` | `seed.*.notifications.translations` | 문자열 보간 조립 |
| `app/Extension/TemplateManager.php` | `template.*.manifest.translations` | 문자열 보간 조립 |
| `app/Extension/TemplateManager.php` | `template.*.manifest.translations` | 문자열 보간 조립 |
| `app/Http/Middleware/RestrictIpAccess.php` | `core.security.ip_restriction_exempt_paths` | 상수 IpAccessMatcher::EXEMPT_PATHS_HOOK 의 값 |
| `app/Listeners/NotificationHookListener.php` | `*.notification.extract_data` | 문자열 보간 조립 |
| `app/Listeners/NotificationHookListener.php` | `*.notification.personalize_data` | 문자열 보간 조립 |
| `app/Notifications/GenericNotification.php` | `*.notification.to_*` | 문자열 보간 조립 |
| `app/Seo/Editor/SeoOgPreviewService.php` | `core.seo.filter_og_data`<br>`core.seo.filter_twitter_data` | buildKeyCascade() 호출부가 리터럴로 넘긴다 |
| `app/Services/Analytics/ReportMailService.php` | `core.analytics.weekly_report_ready`<br>`core.analytics.monthly_report_ready` | 기간(weekly·monthly)별 리포트 |
| `app/Services/DriverRegistryService.php` | `core.settings.available_*_drivers` | 드라이버 카테고리별 필터 |
| `app/Services/NotificationChannelService.php` | `*.notification.channels` | 도메인 채널 후보 필터 — 알림 정의의 hook_prefix 로 조립(코어 core.notification.channels 다음) |
| `database/seeders/Concerns/LoadsConfigSeedWithLangPackFilter.php` | `seed.*.translations` | 설정 시드 언어팩 필터 — 호출부가 키를 넘긴다(seed.notifications.translations 등) |
