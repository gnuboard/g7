<?php

use App\Extension\HookContract\HookCatalogScanner;

/**
 * 훅 계약 — 스캐너가 이름을 풀지 못하는 동적 발화처와 그 이름 패턴 (손으로 관리).
 *
 * 키는 `파일|이름 인자 식`. 새 동적 발화처가 생기면 HookContractTest 가 실패하므로 여기에 패턴을 더한다.
 * 패턴의 `*` 는 점을 포함한 아무 문자열이다. 등록 시점 가드는 이 패턴과 맞으면 발화처가 있는 훅으로 본다.
 *
 * @see HookCatalogScanner
 */

return [
    'app/Analytics/ExtensionFilter.php|$hook' => [
        'patterns' => [
        ],
        'note' => '래퍼 — 호출부(ExtensionFilter::apply 리터럴)를 스캐너가 따로 수집한다',
    ],
    'app/Concerns/Seeder/HasTranslatableSeeder.php|$this->resolveTranslationFilterName()' => [
        'patterns' => [
            0 => 'seed.*.*.translations',
            1 => '*.translations',
        ],
        'note' => '시더별 번역 필터 — 확장·엔티티 식별자로 조립',
    ],
    'app/Extension/ModuleManager.php|"module.{$identifier}.manifest.translations"' => [
        'patterns' => [
            0 => 'module.*.manifest.translations',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Extension/ModuleManager.php|"module.{$module->getIdentifier()}.admin_menus.translations"' => [
        'patterns' => [
            0 => 'module.*.admin_menus.translations',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Extension/ModuleManager.php|"module.{$module->getIdentifier()}.manifest.translations"' => [
        'patterns' => [
            0 => 'module.*.manifest.translations',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Extension/ModuleManager.php|"module.{$module->getIdentifier()}.roles.translations"' => [
        'patterns' => [
            0 => 'module.*.roles.translations',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Extension/ModuleManager.php|"module.{$moduleIdentifier}.permissions.translations"' => [
        'patterns' => [
            0 => 'module.*.permissions.translations',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Extension/ModuleManager.php|"seed.{$module->getIdentifier()}.identity_messages.translations"' => [
        'patterns' => [
            0 => 'seed.*.identity_messages.translations',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Extension/ModuleManager.php|"seed.{$module->getIdentifier()}.notifications.translations"' => [
        'patterns' => [
            0 => 'seed.*.notifications.translations',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Extension/PluginManager.php|"plugin.{$identifier}.manifest.translations"' => [
        'patterns' => [
            0 => 'plugin.*.manifest.translations',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Extension/PluginManager.php|"plugin.{$plugin->getIdentifier()}.admin_menus.translations"' => [
        'patterns' => [
            0 => 'plugin.*.admin_menus.translations',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Extension/PluginManager.php|"plugin.{$plugin->getIdentifier()}.manifest.translations"' => [
        'patterns' => [
            0 => 'plugin.*.manifest.translations',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Extension/PluginManager.php|"plugin.{$plugin->getIdentifier()}.roles.translations"' => [
        'patterns' => [
            0 => 'plugin.*.roles.translations',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Extension/PluginManager.php|"plugin.{$pluginIdentifier}.permissions.translations"' => [
        'patterns' => [
            0 => 'plugin.*.permissions.translations',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Extension/PluginManager.php|"seed.{$plugin->getIdentifier()}.identity_messages.translations"' => [
        'patterns' => [
            0 => 'seed.*.identity_messages.translations',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Extension/PluginManager.php|"seed.{$plugin->getIdentifier()}.notifications.translations"' => [
        'patterns' => [
            0 => 'seed.*.notifications.translations',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Extension/TemplateManager.php|"template.{$identifier}.manifest.translations"' => [
        'patterns' => [
            0 => 'template.*.manifest.translations',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Extension/TemplateManager.php|"template.{$templateName}.manifest.translations"' => [
        'patterns' => [
            0 => 'template.*.manifest.translations',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Listeners/NotificationHookListener.php|"{$definition->hook_prefix}.notification.extract_data"' => [
        'patterns' => [
            0 => '*.notification.extract_data',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Listeners/NotificationHookListener.php|"{$definition->hook_prefix}.notification.personalize_data"' => [
        'patterns' => [
            0 => '*.notification.personalize_data',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Notifications/BaseNotification.php|"{$this->getHookPrefix()}.notification.channels"' => [
        'patterns' => [
            0 => '*.notification.channels',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Notifications/GenericNotification.php|"{$this->hookPrefix}.notification.channels"' => [
        'patterns' => [
            0 => '*.notification.channels',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Notifications/GenericNotification.php|"{$this->hookPrefix}.notification.to_{$channel}"' => [
        'patterns' => [
            0 => '*.notification.to_*',
        ],
        'note' => '문자열 보간 조립',
    ],
    'app/Seo/Editor/SeoOgPreviewService.php|$filterHook' => [
        'patterns' => [
            0 => 'core.seo.filter_og_data',
            1 => 'core.seo.filter_twitter_data',
        ],
        'note' => 'buildKeyCascade() 호출부가 리터럴로 넘긴다',
    ],
    'app/Services/Analytics/ReportMailService.php|\'core.analytics.\'.$period.\'_report_ready\'' => [
        'patterns' => [
            0 => 'core.analytics.weekly_report_ready',
            1 => 'core.analytics.monthly_report_ready',
        ],
        'note' => '기간(weekly·monthly)별 리포트',
    ],
    'app/Services/DriverRegistryService.php|$hookName' => [
        'patterns' => [
            0 => 'core.settings.available_*_drivers',
        ],
        'note' => '드라이버 카테고리별 필터',
    ],
    'app/Services/IdentityMessageDispatcher.php|\'core.identity.message.send_\'.$channel' => [
        'patterns' => [
            0 => 'core.identity.message.send_*',
        ],
        'note' => '본인인증 메시지 채널별 발송',
    ],
    'modules/_bundled/sirsoft-ecommerce/src/Http/Requests/Public/CartKeyRequest.php|$this->hookFilterName()' => [
        'patterns' => [
            0 => 'sirsoft-ecommerce.cart.*_validation_rules',
            1 => 'sirsoft-ecommerce.checkout.*_validation_rules',
        ],
        'note' => '하위 FormRequest 가 hookFilterName() 으로 리터럴을 돌려준다',
    ],
    'modules/_bundled/sirsoft-ecommerce/src/Listeners/ClaimStatusNotificationListener.php|$hook' => [
        'patterns' => [
            0 => 'sirsoft-ecommerce.claim.*.notify_*',
        ],
        'note' => 'ClaimNotificationHooks::forStatus() 조립',
    ],
    'modules/_bundled/sirsoft-ecommerce/src/Listeners/ClaimStatusNotificationListener.php|ClaimNotificationHooks::forStatus(ClaimTypeEnum::EXCHANGE->value,\'collection_overdue\')' => [
        'patterns' => [
            0 => 'sirsoft-ecommerce.claim.exchange.notify_collection_overdue',
        ],
        'note' => 'forStatus() 조립',
    ],
    'modules/_bundled/sirsoft-ecommerce/src/Services/AdminClaimService.php|"sirsoft-ecommerce.claim.{$hookType}.amounts_updated"' => [
        'patterns' => [
            0 => 'sirsoft-ecommerce.claim.*.amounts_updated',
        ],
        'note' => '문자열 보간 조립',
    ],
    'modules/_bundled/sirsoft-ecommerce/src/Services/AdminClaimService.php|"sirsoft-ecommerce.claim.{$type}.after_stock_restore"' => [
        'patterns' => [
            0 => 'sirsoft-ecommerce.claim.*.after_stock_restore',
        ],
        'note' => '문자열 보간 조립',
    ],
    'modules/_bundled/sirsoft-ecommerce/src/Services/ClaimPaymentService.php|\'sirsoft-ecommerce.claim.\'.$claim->claimType()->value.\'.payment_completed\'' => [
        'patterns' => [
            0 => 'sirsoft-ecommerce.claim.*.payment_completed',
        ],
        'note' => '클레임 유형별',
    ],
    'modules/_bundled/sirsoft-ecommerce/src/Services/ClaimPaymentService.php|\'sirsoft-ecommerce.claim.\'.$claim->claimType()->value.\'.payment_refunded\'' => [
        'patterns' => [
            0 => 'sirsoft-ecommerce.claim.*.payment_refunded',
        ],
        'note' => '클레임 유형별',
    ],
    'modules/_bundled/sirsoft-ecommerce/src/Services/Concerns/RecordsClaimTracking.php|"sirsoft-ecommerce.claim.{$type}.after_tracking_update"' => [
        'patterns' => [
            0 => 'sirsoft-ecommerce.claim.*.after_tracking_update',
        ],
        'note' => '문자열 보간 조립',
    ],
    'modules/_bundled/sirsoft-ecommerce/src/Services/Concerns/RecordsClaimTracking.php|"sirsoft-ecommerce.claim.{$type}.before_tracking_update"' => [
        'patterns' => [
            0 => 'sirsoft-ecommerce.claim.*.before_tracking_update',
        ],
        'note' => '문자열 보간 조립',
    ],
    'modules/_bundled/sirsoft-ecommerce/src/Services/Concerns/RecordsClaimTracking.php|"sirsoft-ecommerce.claim.{$type}.filter_tracking_update_data"' => [
        'patterns' => [
            0 => 'sirsoft-ecommerce.claim.*.filter_tracking_update_data',
        ],
        'note' => '문자열 보간 조립',
    ],
    'plugins/_bundled/sirsoft-gdpr/src/Services/GdprConsentService.php|$hookName' => [
        'patterns' => [
            0 => 'sirsoft-gdpr.consent.granted',
            1 => 'sirsoft-gdpr.consent.revoked',
        ],
        'note' => '동의·철회 삼항 — PLUGIN_ID 상수 연결',
    ],
];
