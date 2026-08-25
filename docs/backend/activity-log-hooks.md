# 활동 로그 훅 레퍼런스 (Activity Log Hooks Reference)

> 모든 ActivityLog 리스너가 구독하는 훅의 전체 목록 (코어 + 모듈)

## TL;DR (5초 요약)

```text
1. 코어 66훅 + 이커머스 111훅 + 게시판 32훅 + 페이지 8훅 = 총 217훅
2. Listener에서 Log::channel('activity')->info() 직접 호출 (Monolog → ActivityLogHandler → DB)
3. 스냅샷 패턴: before_update(priority 5) → 캡처, after_update → ChangeDetector로 비교
4. 사용자 행위: ActivityLogType::User (장바구니/위시리스트/쿠폰 다운로드/주문/결제)
5. 새 훅 추가 시: Listener에 구독 + lang 파일에 description_key 정의
```

---

## 목차

1. [아키텍처 개요](#1-아키텍처-개요)
2. [코어 훅 (CoreActivityLogListener)](#2-코어-훅-coreactivityloglistener)
3. [이커머스 모듈 훅](#3-이커머스-모듈-훅)
4. [게시판 모듈 훅 (BoardActivityLogListener)](#4-게시판-모듈-훅-boardactivityloglistener)
5. [페이지 모듈 훅 (PageActivityLogListener)](#5-페이지-모듈-훅-pageactivityloglistener)
6. [스냅샷/변경감지 패턴](#6-스냅샷변경감지-패턴)
7. [새 모듈에 ActivityLog 추가하기](#7-새-모듈에-activitylog-추가하기)

---

## 1. 아키텍처 개요

```text
Service → doAction('hook.name') → ActivityLogListener → Log::channel('activity') → ActivityLogHandler → DB
```

- **Listener**: `HookListenerInterface` 구현, `getSubscribedHooks()`로 훅-메서드 매핑 정의
- **로그 기록**: `Log::channel('activity')->info($action, $context)` 직접 호출
- **context 구조**: `log_type`, `loggable`, `description_key`, `description_params`, `properties`, `changes`
- **LogType**: `ActivityLogType::Admin` (관리자 행위) / `ActivityLogType::User` (사용자 행위)
- **변경 감지**: `ChangeDetector::detect($model, $snapshot)` — 스냅샷과 현재 상태 비교

---

## 2. 코어 훅 (CoreActivityLogListener)

**파일**: `app/Listeners/CoreActivityLogListener.php`
**총 66훅** (스냅샷 캡처용 before 훅 포함)

### User (9훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.user.before_update` | `captureUserSnapshot` | _(스냅샷 캡처)_ | - | - |
| `core.user.after_create` | `handleUserAfterCreate` | `user.create` | Admin | User |
| `core.user.after_update` | `handleUserAfterUpdate` | `user.update` | Admin | User |
| `core.user.after_delete` | `handleUserAfterDelete` | `user.delete` | Admin | User |
| `core.user.after_withdraw` | `handleUserAfterWithdraw` | `user.withdraw` | Admin | User |
| `core.user.after_show` | `handleUserAfterShow` | `user.show` | Admin | User |
| `core.user.after_list` | `handleUserAfterList` | `user.list` | Admin | - |
| `core.user.after_search` | `handleUserAfterSearch` | `user.search` | Admin | - |
| `sirsoft-core.user.after_bulk_update` | `handleUserAfterBulkUpdate` | `user.bulk_update` | Admin | - |

### Auth (6훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.auth.after_login` | `handleAuthAfterLogin` | `auth.login` | User | User |
| `core.auth.logout` | `handleAuthLogout` | `auth.logout` | User | User |
| `core.auth.register` | `handleAuthRegister` | `auth.register` | User | User |
| `core.auth.forgot_password` | `handleAuthForgotPassword` | `auth.forgot_password` | User | - |
| `core.auth.reset_password` | `handleAuthResetPassword` | `auth.reset_password` | User | - |
| `core.auth.record_consents` | `handleAuthRecordConsents` | `auth.record_consents` | User | User |

### Role (6훅, 스냅샷 포함)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.role.before_update` | `captureRoleSnapshot` | _(스냅샷 캡처)_ | - | - |
| `core.role.after_create` | `handleRoleAfterCreate` | `role.create` | Admin | Role |
| `core.role.after_update` | `handleRoleAfterUpdate` | `role.update` | Admin | Role |
| `core.role.after_delete` | `handleRoleAfterDelete` | `role.delete` | Admin | Role |
| `core.role.after_sync_permissions` | `handleRoleAfterSyncPermissions` | `role.sync_permissions` | Admin | Role |
| `core.role.after_toggle_status` | `handleRoleAfterToggleStatus` | `role.toggle_status` | Admin | Role |

### Menu (7훅, 스냅샷 포함)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.menu.before_update` | `captureMenuSnapshot` | _(스냅샷 캡처)_ | - | - |
| `core.menu.after_create` | `handleMenuAfterCreate` | `menu.create` | Admin | Menu |
| `core.menu.after_update` | `handleMenuAfterUpdate` | `menu.update` | Admin | Menu |
| `core.menu.after_delete` | `handleMenuAfterDelete` | `menu.delete` | Admin | Menu |
| `core.menu.after_update_order` | `handleMenuAfterUpdateOrder` | `menu.update_order` | Admin | - |
| `core.menu.after_toggle_status` | `handleMenuAfterToggleStatus` | `menu.toggle_status` | Admin | Menu |
| `core.menu.after_sync_roles` | `handleMenuAfterSyncRoles` | `menu.sync_roles` | Admin | Menu |

### Settings (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.settings.after_save` | `handleSettingsAfterSave` | `settings.save` | Admin | - |
| `core.settings.after_set` | `handleSettingsAfterSet` | `settings.set` | Admin | - |

### Schedule (7훅, 스냅샷 포함)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.schedule.before_update` | `captureScheduleSnapshot` | _(스냅샷 캡처)_ | - | - |
| `core.schedule.after_create` | `handleScheduleAfterCreate` | `schedule.create` | Admin | Schedule |
| `core.schedule.after_update` | `handleScheduleAfterUpdate` | `schedule.update` | Admin | Schedule |
| `core.schedule.after_delete` | `handleScheduleAfterDelete` | `schedule.delete` | Admin | Schedule |
| `core.schedule.after_run` | `handleScheduleAfterRun` | `schedule.run` | Admin | Schedule |
| `core.schedule.after_bulk_update` | `handleScheduleAfterBulkUpdate` | `schedule.bulk_update` | Admin | - |
| `core.schedule.after_bulk_delete` | `handleScheduleAfterBulkDelete` | `schedule.bulk_delete` | Admin | - |

### Attachment (3훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.attachment.after_upload` | `handleAttachmentAfterUpload` | `attachment.upload` | Admin | Attachment |
| `core.attachment.after_delete` | `handleAttachmentAfterDelete` | `attachment.delete` | Admin | Attachment |
| `core.attachment.after_bulk_delete` | `handleAttachmentAfterBulkDelete` | `attachment.bulk_delete` | Admin | - |

### Module (6훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.modules.after_install` | `handleModuleAfterInstall` | `module.install` | Admin | - |
| `core.modules.after_activate` | `handleModuleAfterActivate` | `module.activate` | Admin | - |
| `core.modules.after_deactivate` | `handleModuleAfterDeactivate` | `module.deactivate` | Admin | - |
| `core.modules.after_uninstall` | `handleModuleAfterUninstall` | `module.uninstall` | Admin | - |
| `core.modules.after_update` | `handleModuleAfterUpdate` | `module.update` | Admin | - |
| `core.modules.after_refresh_layouts` | `handleModuleAfterRefreshLayouts` | `module.refresh_layouts` | Admin | - |

### Plugin (5훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.plugins.after_install` | `handlePluginAfterInstall` | `plugin.install` | Admin | - |
| `core.plugins.after_activate` | `handlePluginAfterActivate` | `plugin.activate` | Admin | - |
| `core.plugins.after_deactivate` | `handlePluginAfterDeactivate` | `plugin.deactivate` | Admin | - |
| `core.plugins.after_uninstall` | `handlePluginAfterUninstall` | `plugin.uninstall` | Admin | - |
| `core.plugins.after_update` | `handlePluginAfterUpdate` | `plugin.update` | Admin | - |

### Template (6훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.templates.after_install` | `handleTemplateAfterInstall` | `template.install` | Admin | - |
| `core.templates.after_activate` | `handleTemplateAfterActivate` | `template.activate` | Admin | - |
| `core.templates.after_deactivate` | `handleTemplateAfterDeactivate` | `template.deactivate` | Admin | - |
| `core.templates.after_uninstall` | `handleTemplateAfterUninstall` | `template.uninstall` | Admin | - |
| `core.templates.after_version_update` | `handleTemplateAfterVersionUpdate` | `template.version_update` | Admin | - |
| `core.templates.after_refresh_layouts` | `handleTemplateAfterRefreshLayouts` | `template.refresh_layouts` | Admin | - |

### Layout (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.layout.after_update` | `handleLayoutAfterUpdate` | `layout.update` | Admin | - |
| `core.layout.after_version_restore` | `handleLayoutAfterVersionRestore` | `layout.version_restore` | Admin | - |

### Module Settings (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.module_settings.after_save` | `handleModuleSettingsAfterSave` | `module_settings.save` | Admin | - |
| `core.module_settings.after_reset` | `handleModuleSettingsAfterReset` | `module_settings.reset` | Admin | - |

### Plugin Settings (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.plugin_settings.after_save` | `handlePluginSettingsAfterSave` | `plugin_settings.save` | Admin | - |
| `core.plugin_settings.after_reset` | `handlePluginSettingsAfterReset` | `plugin_settings.reset` | Admin | - |

---

## 3. 이커머스 모듈 훅

**모듈**: `sirsoft-ecommerce`
**총 111훅** (8개 Listener)

### 3.1 OrderActivityLogListener (21훅)

**파일**: `modules/_bundled/sirsoft-ecommerce/src/Listeners/OrderActivityLogListener.php`

#### OrderService (8훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.order.before_update` | `captureOrderSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.order.after_update` | `handleOrderAfterUpdate` | `order.update` | Admin | Order |
| `sirsoft-ecommerce.order.after_delete` | `handleOrderAfterDelete` | `order.delete` | Admin | Order |
| `sirsoft-ecommerce.order.after_bulk_update` | `handleOrderAfterBulkUpdate` | `order.bulk_update` | Admin | - |
| `sirsoft-ecommerce.order.after_bulk_status_update` | `handleOrderAfterBulkStatusUpdate` | `order.bulk_status_update` | Admin | - |
| `sirsoft-ecommerce.order.after_bulk_shipping_update` | `handleOrderAfterBulkShippingUpdate` | `order.bulk_shipping_update` | Admin | - |
| `sirsoft-ecommerce.order.after_update_shipping_address` | `handleOrderAfterUpdateShippingAddress` | `order.update_shipping_address` | Admin | Order |
| `sirsoft-ecommerce.order.after_send_email` | `handleOrderAfterSendEmail` | `order.send_email` | Admin | - |

#### OrderOptionService (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.order_option.after_status_change` | `handleOrderOptionAfterStatusChange` | `order_option.status_change` | Admin | OrderOption |
| `sirsoft-ecommerce.order_option.after_bulk_status_change` | `handleOrderOptionAfterBulkStatusChange` | `order_option.bulk_status_change` | Admin | - |

#### OrderCancellationService (5훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.order.before_cancel` | `captureOrderCancelSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.order.after_cancel` | `handleOrderAfterCancel` | `order.cancel` | Admin | Order |
| `sirsoft-ecommerce.order.after_partial_cancel` | `handleOrderAfterPartialCancel` | `order.partial_cancel` | Admin | Order |
| `sirsoft-ecommerce.coupon.restore` | `handleCouponRestore` | `coupon.restore` | Admin | Order |
| `sirsoft-ecommerce.mileage.restore` | `handleMileageRestore` | `mileage.restore` | Admin | Order |

#### OrderProcessingService (6훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.order.after_create` | `handleOrderAfterCreate` | `order.create` | **User** | Order |
| `sirsoft-ecommerce.order.after_payment_complete` | `handleOrderAfterPaymentComplete` | `order.payment_complete` | **User** | Order |
| `sirsoft-ecommerce.order.payment_failed` | `handleOrderAfterPaymentFailed` | `order.payment_failed` | **User** | Order |
| `sirsoft-ecommerce.coupon.use` | `handleCouponUse` | `coupon.use` | **User** | Order |
| `sirsoft-ecommerce.mileage.use` | `handleMileageUse` | `mileage.use` | **User** | Order |
| `sirsoft-ecommerce.mileage.earn` | `handleMileageEarn` | `mileage.earn` | **User** | Order |

### 3.2 ProductActivityLogListener (10훅)

**파일**: `modules/_bundled/sirsoft-ecommerce/src/Listeners/ProductActivityLogListener.php`

> 이 리스너는 `ProductLogService`를 사용하는 별도 패턴입니다 (Log::channel 대신).

| 훅 이름 | Listener 메서드 | Priority | 비고 |
|---------|----------------|----------|------|
| `sirsoft-ecommerce.product.after_create` | `logCreated` | 50 | 상품 생성 로그 |
| `sirsoft-ecommerce.product.before_update` | `captureSnapshot` | 5 | 스냅샷 캡처 |
| `sirsoft-ecommerce.product.after_update` | `logUpdated` | 50 | 변경사항 비교 후 로그 |
| `sirsoft-ecommerce.product.before_delete` | `logDeleted` | 50 | 삭제 전 로그 기록 |
| `sirsoft-ecommerce.product.before_bulk_update` | `captureProductBulkUpdateSnapshot` | 5 | 일괄 수정 전 스냅샷 |
| `sirsoft-ecommerce.product.after_bulk_update` | `handleProductAfterBulkUpdate` | 20 | 일괄 수정 로그 |
| `sirsoft-ecommerce.product.before_bulk_price_update` | `captureProductBulkPriceSnapshot` | 5 | 일괄 가격 수정 전 스냅샷 |
| `sirsoft-ecommerce.product.after_bulk_price_update` | `handleProductAfterBulkPriceUpdate` | 20 | 일괄 가격 수정 로그 |
| `sirsoft-ecommerce.product.before_bulk_stock_update` | `captureProductBulkStockSnapshot` | 5 | 일괄 재고 수정 전 스냅샷 |
| `sirsoft-ecommerce.product.after_bulk_stock_update` | `handleProductAfterBulkStockUpdate` | 20 | 일괄 재고 수정 로그 |

### 3.3 CouponActivityLogListener (6훅)

**파일**: `modules/_bundled/sirsoft-ecommerce/src/Listeners/CouponActivityLogListener.php`

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.coupon.after_create` | `handleAfterCreate` | `coupon.create` | Admin | Coupon |
| `sirsoft-ecommerce.coupon.before_update` | `captureCouponSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.coupon.after_update` | `handleAfterUpdate` | `coupon.update` | Admin | Coupon |
| `sirsoft-ecommerce.coupon.after_delete` | `handleAfterDelete` | `coupon.delete` | Admin | - |
| `sirsoft-ecommerce.coupon.before_bulk_status` | `captureCouponBulkStatusSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.coupon.after_bulk_status` | `handleAfterBulkStatus` | `coupon.bulk_status` | Admin | - |

### 3.4 ShippingPolicyActivityLogListener (8훅)

**파일**: `modules/_bundled/sirsoft-ecommerce/src/Listeners/ShippingPolicyActivityLogListener.php`

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.shipping_policy.after_create` | `handleAfterCreate` | `shipping_policy.create` | Admin | ShippingPolicy |
| `sirsoft-ecommerce.shipping_policy.before_update` | `captureSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.shipping_policy.after_update` | `handleAfterUpdate` | `shipping_policy.update` | Admin | ShippingPolicy |
| `sirsoft-ecommerce.shipping_policy.after_delete` | `handleAfterDelete` | `shipping_policy.delete` | Admin | - |
| `sirsoft-ecommerce.shipping_policy.after_toggle_active` | `handleAfterToggleActive` | `shipping_policy.toggle_active` | Admin | ShippingPolicy |
| `sirsoft-ecommerce.shipping_policy.after_set_default` | `handleAfterSetDefault` | `shipping_policy.set_default` | Admin | ShippingPolicy |
| `sirsoft-ecommerce.shipping_policy.after_bulk_delete` | `handleAfterBulkDelete` | `shipping_policy.bulk_delete` | Admin | - |
| `sirsoft-ecommerce.shipping_policy.after_bulk_toggle_active` | `handleAfterBulkToggleActive` | `shipping_policy.bulk_toggle_active` | Admin | - |

### 3.5 CategoryActivityLogListener (6훅)

**파일**: `modules/_bundled/sirsoft-ecommerce/src/Listeners/CategoryActivityLogListener.php`

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.category.after_create` | `handleAfterCreate` | `category.create` | Admin | Category |
| `sirsoft-ecommerce.category.before_update` | `captureCategorySnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.category.after_update` | `handleAfterUpdate` | `category.update` | Admin | Category |
| `sirsoft-ecommerce.category.after_delete` | `handleAfterDelete` | `category.delete` | Admin | - |
| `sirsoft-ecommerce.category.after_toggle_status` | `handleAfterToggleStatus` | `category.toggle_status` | Admin | Category |
| `sirsoft-ecommerce.category.after_reorder` | `handleAfterReorder` | `category.reorder` | Admin | - |

### 3.6 EcommerceAdminActivityLogListener (51훅)

**파일**: `modules/_bundled/sirsoft-ecommerce/src/Listeners/EcommerceAdminActivityLogListener.php`

#### Brand (5훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.brand.after_create` | `handleBrandAfterCreate` | `brand.create` | Admin | Brand |
| `sirsoft-ecommerce.brand.before_update` | `captureBrandSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.brand.after_update` | `handleBrandAfterUpdate` | `brand.update` | Admin | Brand |
| `sirsoft-ecommerce.brand.after_delete` | `handleBrandAfterDelete` | `brand.delete` | Admin | Brand |
| `sirsoft-ecommerce.brand.after_toggle_status` | `handleBrandAfterToggleStatus` | `brand.toggle_status` | Admin | Brand |

#### ProductLabel (5훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.label.after_create` | `handleLabelAfterCreate` | `label.create` | Admin | ProductLabel |
| `sirsoft-ecommerce.label.before_update` | `captureLabelSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.label.after_update` | `handleLabelAfterUpdate` | `label.update` | Admin | ProductLabel |
| `sirsoft-ecommerce.label.after_delete` | `handleLabelAfterDelete` | `label.delete` | Admin | ProductLabel |
| `sirsoft-ecommerce.label.after_toggle_status` | `handleLabelAfterToggleStatus` | `label.toggle_status` | Admin | ProductLabel |

#### ProductCommonInfo (4훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.product-common-info.after_create` | `handleCommonInfoAfterCreate` | `common_info.create` | Admin | ProductCommonInfo |
| `sirsoft-ecommerce.product-common-info.before_update` | `captureCommonInfoSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.product-common-info.after_update` | `handleCommonInfoAfterUpdate` | `common_info.update` | Admin | ProductCommonInfo |
| `sirsoft-ecommerce.product-common-info.after_delete` | `handleCommonInfoAfterDelete` | `common_info.delete` | Admin | ProductCommonInfo |

#### ProductNoticeTemplate (5훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.product-notice-template.after_create` | `handleNoticeTemplateAfterCreate` | `notice_template.create` | Admin | ProductNoticeTemplate |
| `sirsoft-ecommerce.product-notice-template.before_update` | `captureNoticeTemplateSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.product-notice-template.after_update` | `handleNoticeTemplateAfterUpdate` | `notice_template.update` | Admin | ProductNoticeTemplate |
| `sirsoft-ecommerce.product-notice-template.after_delete` | `handleNoticeTemplateAfterDelete` | `notice_template.delete` | Admin | ProductNoticeTemplate |
| `sirsoft-ecommerce.product-notice-template.after_copy` | `handleNoticeTemplateAfterCopy` | `notice_template.copy` | Admin | ProductNoticeTemplate |

#### ExtraFeeTemplate (10훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.extra_fee_template.after_create` | `handleExtraFeeAfterCreate` | `extra_fee_template.create` | Admin | ExtraFeeTemplate |
| `sirsoft-ecommerce.extra_fee_template.before_update` | `captureExtraFeeSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.extra_fee_template.after_update` | `handleExtraFeeAfterUpdate` | `extra_fee_template.update` | Admin | ExtraFeeTemplate |
| `sirsoft-ecommerce.extra_fee_template.after_delete` | `handleExtraFeeAfterDelete` | `extra_fee_template.delete` | Admin | ExtraFeeTemplate |
| `sirsoft-ecommerce.extra_fee_template.after_toggle_active` | `handleExtraFeeAfterToggleActive` | `extra_fee_template.toggle_active` | Admin | ExtraFeeTemplate |
| `sirsoft-ecommerce.extra_fee_template.before_bulk_delete` | `captureExtraFeeBulkDeleteSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.extra_fee_template.after_bulk_delete` | `handleExtraFeeAfterBulkDelete` | `extra_fee_template.bulk_delete` | Admin | - |
| `sirsoft-ecommerce.extra_fee_template.before_bulk_toggle_active` | `captureExtraFeeBulkToggleSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.extra_fee_template.after_bulk_toggle_active` | `handleExtraFeeAfterBulkToggleActive` | `extra_fee_template.bulk_toggle_active` | Admin | - |
| `sirsoft-ecommerce.extra_fee_template.after_bulk_create` | `handleExtraFeeAfterBulkCreate` | `extra_fee_template.bulk_create` | Admin | - |

#### ShippingCarrier (5훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.shipping_carrier.after_create` | `handleCarrierAfterCreate` | `shipping_carrier.create` | Admin | ShippingCarrier |
| `sirsoft-ecommerce.shipping_carrier.before_update` | `captureCarrierSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.shipping_carrier.after_update` | `handleCarrierAfterUpdate` | `shipping_carrier.update` | Admin | ShippingCarrier |
| `sirsoft-ecommerce.shipping_carrier.after_delete` | `handleCarrierAfterDelete` | `shipping_carrier.delete` | Admin | ShippingCarrier |
| `sirsoft-ecommerce.shipping_carrier.after_toggle_status` | `handleCarrierAfterToggleStatus` | `shipping_carrier.toggle_status` | Admin | ShippingCarrier |

#### ProductImage (3훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.product-image.after_upload` | `handleImageAfterUpload` | `product_image.upload` | Admin | ProductImage |
| `sirsoft-ecommerce.product-image.after_delete` | `handleImageAfterDelete` | `product_image.delete` | Admin | ProductImage |
| `sirsoft-ecommerce.product-image.after_reorder` | `handleImageAfterReorder` | `product_image.reorder` | Admin | - |

#### ShippingPolicy Bulk (4훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.shipping_policy.before_bulk_delete` | `captureShippingPolicyBulkDeleteSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.shipping_policy.after_bulk_delete` | `handleShippingPolicyAfterBulkDelete` | `shipping_policy.bulk_delete` | Admin | - |
| `sirsoft-ecommerce.shipping_policy.before_bulk_toggle_active` | `captureShippingPolicyBulkToggleSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.shipping_policy.after_bulk_toggle_active` | `handleShippingPolicyAfterBulkToggleActive` | `shipping_policy.bulk_toggle_active` | Admin | - |

#### ProductOption (6훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.product_option.before_bulk_price_update` | `captureOptionBulkPriceSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.product_option.after_bulk_price_update` | `handleOptionAfterBulkPriceUpdate` | `product_option.bulk_price_update` | Admin | - |
| `sirsoft-ecommerce.product_option.before_bulk_stock_update` | `captureOptionBulkStockSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.product_option.after_bulk_stock_update` | `handleOptionAfterBulkStockUpdate` | `product_option.bulk_stock_update` | Admin | - |
| `sirsoft-ecommerce.option.before_bulk_update` | `captureOptionBulkUpdateSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.option.after_bulk_update` | `handleOptionAfterBulkUpdate` | `product_option.bulk_update` | Admin | - |

#### ProductReview (4훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.product-review.after_create` | `handleReviewAfterCreate` | `review.create` | Admin | ProductReview |
| `sirsoft-ecommerce.product-review.after_delete` | `handleReviewAfterDelete` | `review.delete` | Admin | ProductReview |
| `sirsoft-ecommerce.product-review.before_bulk_delete` | `captureReviewBulkDeleteSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-ecommerce.product-review.after_bulk_delete` | `handleReviewAfterBulkDelete` | `product_review.bulk_delete` | Admin | - |

### 3.7 EcommerceUserActivityLogListener (7훅)

**파일**: `modules/_bundled/sirsoft-ecommerce/src/Listeners/EcommerceUserActivityLogListener.php`

#### Cart (5훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.cart.after_add` | `handleCartAfterAdd` | `cart.add` | **User** | Cart |
| `sirsoft-ecommerce.cart.after_update_quantity` | `handleCartAfterUpdateQuantity` | `cart.update_quantity` | **User** | Cart |
| `sirsoft-ecommerce.cart.after_change_option` | `handleCartAfterChangeOption` | `cart.change_option` | **User** | Cart |
| `sirsoft-ecommerce.cart.after_delete` | `handleCartAfterDelete` | `cart.delete` | **User** | - |
| `sirsoft-ecommerce.cart.after_delete_all` | `handleCartAfterDeleteAll` | `cart.delete_all` | **User** | - |

#### Wishlist (1훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.wishlist.after_toggle` | `handleWishlistAfterToggle` | `wishlist.add` / `wishlist.remove` | **User** | Product |

#### User Coupon (1훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.user_coupon.after_download` | `handleUserCouponAfterDownload` | `user_coupon.download` | **User** | CouponIssue |

### 3.8 ClaimActivityLogListener (19훅)

**파일**: `modules/_bundled/sirsoft-ecommerce/src/Listeners/ClaimActivityLogListener.php`

교환·반품·환불 신청서와 클레임 추가결제·첨부의 전 상태 변경을 기록한다. 행위자(`user_id`)는 코어 `ActivityLogProcessor` 가 자동 주입하므로 신청서 테이블에 `*_by` 컬럼을 새로 만들지 않는다.

**전이는 `status_changed` 한 축으로만 기록한다.** 승인·반려·철회·완료는 `claim.{type}.approved` 같은 구체 훅도 함께 발화하는데, 양쪽을 구독하면 한 번의 전이가 두 줄로 남는다. 목표 상태는 `properties.new_status` 로 싣는다.

#### 신청 접수 (3훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.claim.exchange.requested` | `handleExchangeRequested` | `claim_exchange.request` | User | OrderExchange |
| `sirsoft-ecommerce.claim.return.requested` | `handleReturnRequested` | `claim_return.request` | User | OrderReturn |
| `sirsoft-ecommerce.claim.refund.requested` | `handleRefundRequested` | `claim_refund.request` | User | OrderRefund |

#### 상태 전이 (3훅 — 전 상태 전수)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.claim.exchange.status_changed` | `handleStatusChanged` | `claim_exchange.status_change` | Admin | OrderExchange |
| `sirsoft-ecommerce.claim.return.status_changed` | `handleStatusChanged` | `claim_return.status_change` | Admin | OrderReturn |
| `sirsoft-ecommerce.claim.refund.status_changed` | `handleStatusChanged` | `claim_refund.status_change` | Admin | OrderRefund |

#### 상품 단위 전이 (2훅 — D25)

헤더 전이와 **층이 다른 사실**이라 따로 남긴다. `syncHeaderFromOptions` 는 살아 있는 상품이 한 상태로 모일 때만 헤더를 옮기므로, 상품 상태가 갈리는 조합에서는 헤더 줄이 아예 생기지 않는다 — 이 훅이 없으면 운영자가 한 상태 변경의 흔적이 전혀 남지 않는다. 헤더가 따라 움직인 회차는 두 줄이 함께 남으며 그것은 이중 기록이 아니다.

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.claim.exchange.option_status_changed` | `handleOptionStatusChanged` | `claim_exchange.option_status_change` | Admin | OrderExchange |
| `sirsoft-ecommerce.claim.return.option_status_changed` | `handleOptionStatusChanged` | `claim_return.option_status_change` | Admin | OrderReturn |

#### 금액 수정 / 환불 실행 (4훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.claim.exchange.amounts_updated` | `handleAmountsUpdated` | `claim_exchange.update_amounts` | Admin | OrderExchange |
| `sirsoft-ecommerce.claim.return.amounts_updated` | `handleAmountsUpdated` | `claim_return.update_amounts` | Admin | OrderReturn |
| `sirsoft-ecommerce.claim.refund.amounts_updated` | `handleAmountsUpdated` | `claim_refund.update_amounts` | Admin | OrderRefund |
| `sirsoft-ecommerce.claim.refund.completed` | `handleRefundExecuted` | `claim_refund.execute` | Admin | OrderRefund |

#### 클레임 추가결제 (5훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.claim.exchange.payment_completed` | `handlePaymentCompleted` | `claim_payment.complete` | User | ClaimPayment |
| `sirsoft-ecommerce.claim.return.payment_completed` | `handlePaymentCompleted` | `claim_payment.complete` | User | ClaimPayment |
| `sirsoft-ecommerce.claim.exchange.payment_refunded` | `handlePaymentRefunded` | `claim_payment.refund` | Admin | ClaimPayment |
| `sirsoft-ecommerce.claim.return.payment_refunded` | `handlePaymentRefunded` | `claim_payment.refund` | Admin | ClaimPayment |
| `sirsoft-ecommerce.claim.payment_cancelled` | `handlePaymentCancelled` | `claim_payment.cancel` | Admin | ClaimPayment (유형 세그먼트 없음 — 신청서가 사라진 고아 건도 취소 대상이라 유형을 항상 알 수 없다) |

#### 첨부 (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-ecommerce.claim-image.after_upload` | `handleImageUploaded` | `claim_image.upload` | User | ClaimImage |
| `sirsoft-ecommerce.claim-image.after_delete` | `handleImageDeleted` | `claim_image.delete` | User | ClaimImage (`loggable_type`/`loggable_id` 직접 지정 — 행이 이미 삭제됨) |

#### 클레임 훅 페이로드 스키마

같은 훅을 확장이 구독할 수 있으므로 인자 순서와 형이 계약이다. `$previous`/`$target` 은 **전이 시점에 캡처된 스칼라**이며, 큐 지연 중 재전이된 모델의 현재 상태와 다를 수 있다 — 매핑·기록의 기준은 언제나 이 인자다.

| 훅 | 인자 |
|----|------|
| `claim.{exchange\|return\|refund}.requested` | `($claim, Order $order)` |
| `claim.{exchange\|return\|refund}.status_changed` | `($claim, ?string $previousStatus, ?string $targetStatus)` |
| `claim.{exchange\|return\|refund}.amounts_updated` | `($claim, array $before, array $after, ?int $processedBy)` — 스냅샷 키는 교환·반품 `claim_shipping_fee`/`total_charge_amount`/`charge_method`/`admin_memo`, 환불 `refund_amount`/`refund_points_amount`/`refund_method` |
| `claim.refund.completed` | `(OrderRefund $refund)` — 환불 **실행 확정**(돈이 나간 사건). 같은 순간의 `approved → completed` 전이는 `status_changed` 가 따로 낸다 |
| `claim.{exchange\|return}.payment_completed` | `($claim, ClaimPayment $payment)` — 청구액이 **전액 수납**됐을 때만, 그리고 신청서를 `paid` 로 옮긴 **뒤에** 발화한다 |
| `claim.{exchange\|return}.payment_refunded` | `($claim, ClaimPayment $payment, float $refundAmount)` |
| `claim.{exchange\|return}.option_status_changed` | `($claim, array $changes, ?string $targetStatus, ?int $processedBy)` — `$changes` 는 `{option_id, previous, new}` 목록이며 **실제로 옮겨진 상품만** 담는다(건너뛴 상품은 빠진다). 옮긴 것이 없으면 훅 자체가 발화하지 않는다 |
| `claim.payment_cancelled` | `(ClaimPayment $payment, OrderExchange\|OrderReturn\|null $claim, string $reason)` — `$reason` 은 `ClaimPaymentService::CANCEL_REASON_*`(`claim_finalized` / `charge_settled` / `orphan_reaped`). 대상은 언제나 **미수납 대기 행**이라 돈이 오간 사건이 아니다 |
| `claim-image.after_upload` / `after_delete` | `(ClaimImage $image)` |

#### 발행되지만 활동 로그가 구독하지 않는 클레임 훅

확장이 쓸 수 있도록 함께 적는다. 활동 로그가 구독하지 않는 이유는 위 "전이는 한 축으로만" 규칙이거나, 알림 전용 훅이기 때문이다.

| 훅 | 인자 | 발행 지점 / 용도 |
|----|------|-----------------|
| `claim.exchange.{approved,rejected,withdrawn,completed}` | `($exchange)` | `OrderExchangeService::fireStatusHooks` — 특정 전이만 구독하려는 확장용 |
| `claim.return.{approved,rejected,withdrawn,completed}` | `($return)` | `OrderReturnService::fireStatusHooks` |
| `claim.refund.{approved,rejected,withdrawn,on_hold}` | `($refund)` | `OrderRefundRequestService::transitionRefund` |
| `claim.{exchange\|return\|refund}.notify_{상태}` (29종) | `($claim)` | `ClaimStatusNotificationListener` — 알림 정의 전용. 상태마다 고유 훅이어야 그 상태의 알림만 나간다 |
| `claim.notify_withdrawn` | `($claim)` | 철회 관리자 알림(유형 3종 공용) |
| `claim-image.filter_upload_file` (filter) | `(UploadedFile $file)` | 업로드 직전 파일 가공 |
| `claim_payment.{approve,refund}` (filter) | 결제 플러그인 연동 계약 | `modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` "결제 플러그인 연동 계약" 이 정본 |

---

## 4. 게시판 모듈 훅 (BoardActivityLogListener)

**파일**: `modules/_bundled/sirsoft-board/src/Listeners/BoardActivityLogListener.php`
**총 32훅**

### Board (6훅, 스냅샷 포함)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-board.board.after_create` | `handleBoardAfterCreate` | `board.create` | Admin | Board |
| `sirsoft-board.board.before_update` | `captureBoardSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-board.board.after_update` | `handleBoardAfterUpdate` | `board.update` | Admin | Board |
| `sirsoft-board.board.after_delete` | `handleBoardAfterDelete` | `board.delete` | Admin | Board |
| `sirsoft-board.board.after_add_to_menu` | `handleBoardAfterAddToMenu` | `board.add_to_menu` | Admin | Board |
| `sirsoft-board.settings.after_bulk_apply` | `handleSettingsAfterBulkApply` | `board_settings.bulk_apply` | Admin | - |

### BoardType (4훅, 스냅샷 포함)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-board.board_type.after_create` | `handleBoardTypeAfterCreate` | `board_type.create` | Admin | BoardType |
| `sirsoft-board.board_type.before_update` | `captureBoardTypeSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-board.board_type.after_update` | `handleBoardTypeAfterUpdate` | `board_type.update` | Admin | BoardType |
| `sirsoft-board.board_type.after_delete` | `handleBoardTypeAfterDelete` | `board_type.delete` | Admin | BoardType |

### Post (6훅, 스냅샷 포함)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-board.post.after_create` | `handlePostAfterCreate` | `post.create` | Admin | Post |
| `sirsoft-board.post.before_update` | `capturePostSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-board.post.after_update` | `handlePostAfterUpdate` | `post.update` | Admin | Post |
| `sirsoft-board.post.after_delete` | `handlePostAfterDelete` | `post.delete` | Admin | Post |
| `sirsoft-board.post.after_blind` | `handlePostAfterBlind` | `post.blind` | Admin | Post |
| `sirsoft-board.post.after_restore` | `handlePostAfterRestore` | `post.restore` | Admin | Post |

### Comment (6훅, 스냅샷 포함)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-board.comment.after_create` | `handleCommentAfterCreate` | `comment.create` | Admin | Comment |
| `sirsoft-board.comment.before_update` | `captureCommentSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-board.comment.after_update` | `handleCommentAfterUpdate` | `comment.update` | Admin | Comment |
| `sirsoft-board.comment.after_delete` | `handleCommentAfterDelete` | `comment.delete` | Admin | Comment |
| `sirsoft-board.comment.after_blind` | `handleCommentAfterBlind` | `comment.blind` | Admin | Comment |
| `sirsoft-board.comment.after_restore` | `handleCommentAfterRestore` | `comment.restore` | Admin | Comment |

### Attachment (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-board.attachment.after_upload` | `handleAttachmentAfterUpload` | `attachment.upload` | Admin | Attachment |
| `sirsoft-board.attachment.after_delete` | `handleAttachmentAfterDelete` | `attachment.delete` | Admin | Attachment |

### Report (8훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-board.report.after_create` | `handleReportAfterCreate` | `report.create` | Admin | Report |
| `sirsoft-board.report.after_update_status` | `handleReportAfterUpdateStatus` | `report.update_status` | Admin | Report |
| `sirsoft-board.report.before_bulk_update_status` | `captureReportBulkStatusSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-board.report.after_bulk_update_status` | `handleReportAfterBulkUpdateStatus` | `report.bulk_update_status` | Admin | - |
| `sirsoft-board.report.after_delete` | `handleReportAfterDelete` | `report.delete` | Admin | Report |
| `sirsoft-board.report.after_restore_content` | `handleReportAfterRestoreContent` | `report.restore_content` | Admin | Report |
| `sirsoft-board.report.after_blind_content` | `handleReportAfterBlindContent` | `report.blind_content` | Admin | Report |
| `sirsoft-board.report.after_delete_content` | `handleReportAfterDeleteContent` | `report.delete_content` | Admin | Report |

---

## 5. 페이지 모듈 훅 (PageActivityLogListener)

**파일**: `modules/_bundled/sirsoft-page/src/Listeners/PageActivityLogListener.php`
**총 8훅**

### Page (6훅, 스냅샷 포함)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-page.page.after_create` | `handlePageAfterCreate` | `page.create` | Admin | Page |
| `sirsoft-page.page.before_update` | `capturePageSnapshot` | _(스냅샷 캡처)_ | - | - |
| `sirsoft-page.page.after_update` | `handlePageAfterUpdate` | `page.update` | Admin | Page |
| `sirsoft-page.page.after_delete` | `handlePageAfterDelete` | `page.delete` | Admin | Page |
| `sirsoft-page.page.after_publish` | `handlePageAfterPublish` | `page.publish` / `page.unpublish` | Admin | Page |
| `sirsoft-page.page.after_restore` | `handlePageAfterRestore` | `page.restore` | Admin | Page |

### PageAttachment (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-page.attachment.after_upload` | `handleAttachmentAfterUpload` | `page_attachment.upload` | Admin | PageAttachment |
| `sirsoft-page.attachment.after_delete` | `handleAttachmentAfterDelete` | `page_attachment.delete` | Admin | PageAttachment |

---

## 6. 스냅샷/변경감지 패턴

ActivityLog에서 수정(update) 작업의 변경 이력을 기록하려면 **스냅샷 패턴**을 사용합니다.

### 동작 흐름

```text
1. Service에서 before_update 훅 발행 (doAction)
2. Listener의 캡처 메서드 실행 (priority: 5, 다른 리스너보다 먼저)
   → 현재 모델 상태를 $snapshots 배열에 저장
3. Service에서 실제 DB 업데이트 수행
4. Service에서 after_update 훅 발행 (doAction)
5. Listener의 핸들러 메서드 실행 (priority: 20)
   → ChangeDetector::detect($model, $snapshot) 호출
   → 변경된 필드만 추출하여 로그 기록
   → 스냅샷 정리 (unset)
```

### 코드 예시

```php
// 1. getSubscribedHooks()에서 before/after 쌍 등록
public static function getSubscribedHooks(): array
{
    return [
        'my-module.entity.before_update' => ['method' => 'captureSnapshot', 'priority' => 5],
        'my-module.entity.after_update' => ['method' => 'handleAfterUpdate', 'priority' => 20],
    ];
}

// 2. 스냅샷 캡처 (before_update, priority 5)
public function captureSnapshot(Model $entity, array $data): void
{
    $this->snapshots['entity_' . $entity->id] = $entity->toArray();
}

// 3. 변경 감지 + 로그 기록 (after_update, priority 20)
public function handleAfterUpdate(Model $entity): void
{
    $snapshot = $this->snapshots['entity_' . $entity->id] ?? null;
    $changes = ChangeDetector::detect($entity, $snapshot);

    Log::channel('activity')->info('entity.update', [
        'log_type' => ActivityLogType::Admin,
        'loggable' => $entity,
        'description_key' => 'my-module::activity_log.description.entity_update',
        'description_params' => ['entity_id' => $entity->id],
        'changes' => $changes,
    ]);

    unset($this->snapshots['entity_' . $entity->id]);
}
```

### ChangeDetector

**파일**: `app/ActivityLog/ChangeDetector.php`

- 모델의 `$activityLogFields` 속성에 정의된 필드만 비교
- 각 필드의 `label_key` (다국어 키)를 포함한 구조화된 변경 이력 생성
- `BackedEnum` 자동 변환 지원
- 스냅샷이 `null`이면 `null` 반환 (변경 없음)

### ProductActivityLogListener의 별도 패턴

`ProductActivityLogListener`는 `ProductLogService`를 주입받아 사용하는 별도 패턴입니다:
- `ChangeDetector` 대신 자체 `detectChanges()` 메서드로 변경 감지
- 해시 비교로 옵션/추가옵션/이미지 변경 감지
- `Log::channel('activity')` 대신 `ProductLogService`를 통해 처리로그 테이블에 기록

---

## 7. 새 모듈에 ActivityLog 추가하기

### Step 1: Listener 클래스 생성

```php
<?php

namespace Modules\Vendor\MyModule\Listeners;

use App\ActivityLog\ChangeDetector;
use App\Contracts\Extension\HookListenerInterface;
use App\Enums\ActivityLogType;
use Illuminate\Support\Facades\Log;

class MyModuleActivityLogListener implements HookListenerInterface
{
    private array $snapshots = [];

    public static function getSubscribedHooks(): array
    {
        return [
            'vendor-mymodule.entity.after_create' => ['method' => 'handleAfterCreate', 'priority' => 20],
            'vendor-mymodule.entity.before_update' => ['method' => 'captureSnapshot', 'priority' => 5],
            'vendor-mymodule.entity.after_update' => ['method' => 'handleAfterUpdate', 'priority' => 20],
            'vendor-mymodule.entity.after_delete' => ['method' => 'handleAfterDelete', 'priority' => 20],
        ];
    }

    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    // ... 각 핸들러 메서드 구현
}
```

### Step 2: module.php에 Listener 등록

```php
// module.php의 listeners 배열에 추가
'listeners' => [
    MyModuleActivityLogListener::class,
],
```

### Step 3: 다국어 파일에 description_key 정의

```php
// lang/ko/activity_log.php
return [
    'description' => [
        'entity_create' => ':entity_name 엔티티가 생성되었습니다.',
        'entity_update' => ':entity_name 엔티티가 수정되었습니다.',
        'entity_delete' => ':entity_name 엔티티가 삭제되었습니다.',
    ],
];
```

### Step 4: 모델에 $activityLogFields 정의 (ChangeDetector 사용 시)

```php
// Model 클래스에 추가
protected array $activityLogFields = [
    'name' => ['label_key' => 'vendor-mymodule::activity_log.fields.name'],
    'status' => ['label_key' => 'vendor-mymodule::activity_log.fields.status'],
];
```

### Step 5: 테스트 작성

```php
// tests/Unit/Listeners/MyModuleActivityLogListenerTest.php
class MyModuleActivityLogListenerTest extends TestCase
{
    public function test_getSubscribedHooks_returns_expected_hooks(): void
    {
        $hooks = MyModuleActivityLogListener::getSubscribedHooks();

        $this->assertArrayHasKey('vendor-mymodule.entity.after_create', $hooks);
        $this->assertArrayHasKey('vendor-mymodule.entity.before_update', $hooks);
        // ...
    }

    public function test_handleAfterCreate_logs_activity(): void
    {
        Log::shouldReceive('channel')
            ->with('activity')
            ->andReturnSelf();
        Log::shouldReceive('info')
            ->once()
            ->with('entity.create', Mockery::type('array'));

        $listener = new MyModuleActivityLogListener();
        $listener->handleAfterCreate($entity, $data);
    }
}
```

### 훅 네이밍 규칙

| 패턴 | 용도 | Priority |
|------|------|----------|
| `{module}.{entity}.before_update` | 스냅샷 캡처 | 5 (높은 우선순위) |
| `{module}.{entity}.after_create` | 생성 로그 | 20 |
| `{module}.{entity}.after_update` | 수정 로그 (변경감지) | 20 |
| `{module}.{entity}.after_delete` | 삭제 로그 | 20 |
| `{module}.{entity}.after_bulk_*` | 일괄 작업 로그 | 20 |
| `{module}.{entity}.after_toggle_*` | 상태 전환 로그 | 20 |

### context 구조

| 키 | 타입 | 설명 | 필수 |
|----|------|------|------|
| `log_type` | `ActivityLogType` | Admin 또는 User | O |
| `loggable` | `Model` | 대상 모델 (polymorphic) | - |
| `description_key` | `string` | 다국어 설명 키 | O |
| `description_params` | `array` | 설명 파라미터 | - |
| `properties` | `array` | 추가 속성 (JSON) | - |
| `changes` | `array\|null` | ChangeDetector 결과 | - |
