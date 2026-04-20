<?php

use App\Http\Controllers\Api\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Api\Admin\AttachmentController as AdminAttachmentController;
use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
// Admin Controllers
use App\Http\Controllers\Api\Admin\CoreUpdateController as AdminCoreUpdateController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\LayoutController as AdminLayoutController;
use App\Http\Controllers\Api\Admin\LicenseController as AdminLicenseController;
use App\Http\Controllers\Api\Admin\MenuController as AdminMenuController;
use App\Http\Controllers\Api\Admin\ModuleController as AdminModuleController;
use App\Http\Controllers\Api\Admin\NotificationChannelController as AdminNotificationChannelController;
use App\Http\Controllers\Api\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Api\Admin\NotificationDefinitionController as AdminNotificationDefinitionController;
use App\Http\Controllers\Api\Admin\NotificationLogController as AdminNotificationLogController;
use App\Http\Controllers\Api\Admin\NotificationTemplateController as AdminNotificationTemplateController;
use App\Http\Controllers\Api\Admin\PermissionController as AdminPermissionController;
use App\Http\Controllers\Api\Admin\PluginController as AdminPluginController;
use App\Http\Controllers\Api\Admin\PluginSettingsController as AdminPluginSettingsController;
use App\Http\Controllers\Api\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\Api\Admin\ScheduleController as AdminScheduleController;
use App\Http\Controllers\Api\Admin\SeoCacheController as AdminSeoCacheController;
use App\Http\Controllers\Api\Admin\GeoIpController as AdminGeoIpController;
use App\Http\Controllers\Api\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Api\Admin\TemplateController as AdminTemplateController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Auth\AuthController as UserAuthController;
// Auth Controllers (Authenticated Users)
use App\Http\Controllers\Api\Auth\NotificationController as UserNotificationController;
use App\Http\Controllers\Api\Auth\ProfileController as UserProfileController;
// Public Controllers
use App\Http\Controllers\Api\Public\LayoutPreviewController;
use App\Http\Controllers\Api\Public\PublicAttachmentController;
use App\Http\Controllers\Api\Public\PublicLayoutController;
use App\Http\Controllers\Api\Public\PublicModuleController;
use App\Http\Controllers\Api\Public\PublicPluginController;
use App\Http\Controllers\Api\Public\PublicProfileController;
use App\Http\Controllers\Api\Public\PublicSearchController;
use App\Http\Controllers\Api\Public\PublicTemplateController;
use App\Http\Middleware\RefreshTokenExpiration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// 공개 API 라우트 (인증 불필요, 속도 제한 없음)
Route::group([], function () {
    // 템플릿 라우트 정보 조회
    Route::prefix('templates')->group(function () {
        Route::get('{identifier}/routes.json', [PublicTemplateController::class, 'getRoutes'])->name('api.public.templates.routes');

        // 템플릿 설정 파일 서빙 (error_config 등)
        Route::get('{identifier}/config.json', [PublicTemplateController::class, 'serveConfig'])->name('api.public.templates.config');

        // 템플릿 정적 파일 서빙
        Route::get('assets/{identifier}/{path}', [PublicTemplateController::class, 'serveAsset'])
            ->where('path', '.*')
            ->name('api.public.templates.assets');

        // 컴포넌트 정의 파일 서빙
        Route::get('{identifier}/components.json', [PublicTemplateController::class, 'serveComponents'])
            ->name('api.public.templates.components');

        // 다국어 파일 서빙
        Route::get('{identifier}/lang/{locale}.json', [PublicTemplateController::class, 'serveLanguage'])
            ->where('locale', '[a-z]{2}(-[A-Z]{2})?')
            ->name('api.public.templates.language');
    });

    // 레이아웃 서빙 API (Optional Sanctum 인증 - 토큰 있으면 인증, 없으면 guest)
    Route::prefix('layouts')
        ->middleware('optional.sanctum')
        ->group(function () {
            // 레이아웃 미리보기 서빙 (토큰 기반, 인증 불필요)
            // 주의: 일반 레이아웃 서빙보다 먼저 정의 (preview가 templateIdentifier로 매칭되는 것 방지)
            Route::get('preview/{token}.json', [LayoutPreviewController::class, 'serve'])
                ->where('token', '[a-f0-9\-]{36}')
                ->name('api.public.layouts.preview.serve');

            Route::get('{templateIdentifier}/{layoutName}.json', [PublicLayoutController::class, 'serve'])
                ->where('layoutName', '[a-zA-Z0-9_/\.-]+')
                ->name('api.public.layouts.serve');
        });

    // 모듈 에셋 서빙 API
    Route::prefix('modules')->group(function () {
        Route::get('assets/{identifier}/{path}', [PublicModuleController::class, 'serveAsset'])
            ->where('path', '.*')
            ->name('api.public.modules.assets');
    });

    // 플러그인 에셋 서빙 API
    Route::prefix('plugins')->group(function () {
        Route::get('assets/{identifier}/{path}', [PublicPluginController::class, 'serveAsset'])
            ->where('path', '.*')
            ->name('api.public.plugins.assets');
    });

    // 사용자 공개 프로필 API
    // 게시글 관련 API는 게시판 모듈(sirsoft-board)에서 제공:
    // - GET /api/modules/sirsoft-board/users/{userId}/posts
    // - GET /api/modules/sirsoft-board/users/{userId}/posts/stats
    Route::prefix('users')->group(function () {
        Route::get('{user}/profile', [PublicProfileController::class, 'show'])
            ->name('api.public.users.profile');
    });
});

// 브로드캐스팅 인증 (Sanctum 토큰 사용)
Route::middleware(['auth:sanctum'])->post('broadcasting/auth', function (Request $request) {
    return Broadcast::auth($request);
})->name('api.broadcasting.auth');

// 인증 관련 라우트 (인증 불필요, 속도 제한 없음 - 공개 API)
// start.api.session: 로그인 시 세션 생성 (/dev 대시보드 인증용)
Route::prefix('auth')->group(function () {
    // 로그인 라우트 (세션 생성 필요)
    Route::middleware('start.api.session')->group(function () {
        Route::post('login', [UserAuthController::class, 'login'])->name('api.auth.login');
    });

    // 공개 인증 라우트 (세션 불필요)
    Route::post('register', [UserAuthController::class, 'register'])->name('api.auth.register');
    Route::post('forgot-password', [UserAuthController::class, 'forgotPassword'])->name('api.auth.forgot-password');
    Route::post('reset-password', [UserAuthController::class, 'resetPassword'])->name('api.auth.reset-password');
    Route::post('validate-reset-token', [UserAuthController::class, 'validateResetToken'])->name('api.auth.validate-reset-token');

    // 일반 사용자 인증 (인증 필요 - 공용 경로)
    Route::middleware(['auth:sanctum', 'check.user_status'])->group(function () {
        Route::get('user', [UserAuthController::class, 'user'])->name('api.auth.user');
        Route::post('logout', [UserAuthController::class, 'logout'])->middleware('start.api.session')->name('api.auth.logout');
    });

    // 관리자 인증 (로그인: 세션 생성 필요)
    Route::prefix('admin')->group(function () {
        Route::post('login', [AdminAuthController::class, 'login'])->middleware('start.api.session')->name('api.auth.admin.login');
    });
});

// 사용자 API (권한 기반 인증, 속도 제한 적용)
// optional.sanctum: Bearer 토큰이 있으면 인증, 없으면 guest로 통과
Route::prefix('user')->middleware(['optional.sanctum', 'check.user_status', 'throttle:'.config('auth.throttle.user'), RefreshTokenExpiration::class])->group(function () {
    // 사용자 인증
    Route::prefix('auth')->group(function () {
        Route::post('logout', [UserAuthController::class, 'logout'])
            ->middleware(['start.api.session', 'permission:user,core.auth.logout'])
            ->name('api.user.auth.logout');
        Route::post('logout-all-devices', [UserAuthController::class, 'logoutFromAllDevices'])
            ->middleware('permission:user,core.auth.logout')
            ->name('api.user.auth.logout-all-devices');
        Route::get('user', [UserAuthController::class, 'user'])
            ->middleware('permission:user,core.auth.user')
            ->name('api.user.auth.user');
        Route::post('refresh', [UserAuthController::class, 'refresh'])
            ->middleware('permission:user,core.auth.refresh')
            ->name('api.user.auth.refresh');
    });

    // 사용자 알림
    Route::prefix('notifications')->group(function () {
        Route::get('/', [UserNotificationController::class, 'index'])
            ->middleware('permission:user,core.user-notifications.read')
            ->name('api.user.notifications.index');
        Route::get('unread-count', [UserNotificationController::class, 'unreadCount'])
            ->middleware('permission:user,core.user-notifications.read')
            ->name('api.user.notifications.unread-count');
        Route::patch('{notification}/read', [UserNotificationController::class, 'markAsRead'])
            ->middleware('permission:user,core.user-notifications.update')
            ->name('api.user.notifications.read');
        Route::post('read-batch', [UserNotificationController::class, 'markBatchAsRead'])
            ->middleware('permission:user,core.user-notifications.update')
            ->name('api.user.notifications.read-batch');
        Route::post('read-all', [UserNotificationController::class, 'markAllAsRead'])
            ->middleware('permission:user,core.user-notifications.update')
            ->name('api.user.notifications.read-all');
        Route::delete('all', [UserNotificationController::class, 'destroyAll'])
            ->middleware('permission:user,core.user-notifications.delete')
            ->name('api.user.notifications.destroy-all');
        Route::delete('{notification}', [UserNotificationController::class, 'destroy'])
            ->middleware('permission:user,core.user-notifications.delete')
            ->name('api.user.notifications.destroy');
    });

    // 사용자 프로필
    Route::prefix('profile')->group(function () {
        Route::get('/', [UserProfileController::class, 'show'])
            ->middleware('permission:user,core.profile.read')
            ->name('api.user.profile.show');
        Route::put('/', [UserProfileController::class, 'update'])
            ->middleware('permission:user,core.profile.update')
            ->name('api.user.profile.update');
        Route::post('update-language', [UserProfileController::class, 'updateLanguage'])
            ->name('api.user.profile.update-language');
        Route::get('activity-log', [UserProfileController::class, 'activityLog'])
            ->middleware('permission:user,core.profile.read')
            ->name('api.user.profile.activity-log');
    });
});

// 마이페이지 API (/api/me) - 프로필 관리용 단축 엔드포인트
Route::prefix('me')->middleware(['auth:sanctum', 'check.user_status', 'throttle:'.config('auth.throttle.user'), RefreshTokenExpiration::class])->group(function () {
    Route::get('/', [UserProfileController::class, 'show'])->name('api.me.show');
    Route::put('/', [UserProfileController::class, 'update'])->name('api.me.update');
    Route::delete('/', [UserProfileController::class, 'destroy'])->name('api.me.destroy');
    Route::post('avatar', [UserProfileController::class, 'uploadAvatar'])->name('api.me.avatar.upload');
    Route::delete('avatar', [UserProfileController::class, 'deleteAvatar'])->name('api.me.avatar.delete');
    Route::post('verify-password', [UserProfileController::class, 'verifyPassword'])->name('api.me.verify-password');
    Route::put('password', [UserProfileController::class, 'changePassword'])->name('api.me.password');
});

// 첨부파일 다운로드 (공개 - 권한 정책에 따라 접근 제어)
Route::get('attachment/{hash}', [PublicAttachmentController::class, 'download'])
    ->where('hash', '[a-zA-Z0-9]{12}')
    ->name('api.attachment.download');

// 통합 검색 API (공개)
Route::get('search', [PublicSearchController::class, 'search'])->name('api.search');

// 관리자 API (인증 + 관리자 권한 필요, 속도 제한 적용)
Route::prefix('admin')->middleware(['auth:sanctum', 'check.user_status', 'admin', 'throttle:'.config('auth.throttle.admin')])->group(function () {
    // 관리자 인증
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout'])->middleware('start.api.session')->name('api.admin.auth.logout');
        Route::get('user', [AdminAuthController::class, 'user'])->name('api.admin.auth.user');
        Route::post('refresh', [AdminAuthController::class, 'refresh'])->name('api.admin.auth.refresh');
    });

    // 관리자 알림
    Route::prefix('notifications')->group(function () {
        Route::get('/', [AdminNotificationController::class, 'index'])
            ->middleware('permission:admin,core.notifications.read')
            ->name('api.admin.notifications.index');
        Route::get('unread-count', [AdminNotificationController::class, 'unreadCount'])
            ->middleware('permission:admin,core.notifications.read')
            ->name('api.admin.notifications.unread-count');
        Route::post('read-batch', [AdminNotificationController::class, 'markBatchAsRead'])
            ->middleware('permission:admin,core.notifications.update')
            ->name('api.admin.notifications.read-batch');
        Route::post('read-all', [AdminNotificationController::class, 'markAllAsRead'])
            ->middleware('permission:admin,core.notifications.update')
            ->name('api.admin.notifications.read-all');
        Route::patch('{notification}/read', [AdminNotificationController::class, 'markAsRead'])
            ->middleware('permission:admin,core.notifications.update')
            ->name('api.admin.notifications.read');
        Route::delete('all', [AdminNotificationController::class, 'destroyAll'])
            ->middleware('permission:admin,core.notifications.delete')
            ->name('api.admin.notifications.destroy-all');
        Route::delete('{notification}', [AdminNotificationController::class, 'destroy'])
            ->middleware('permission:admin,core.notifications.delete')
            ->name('api.admin.notifications.destroy');
    });

    // 대시보드 API
    Route::prefix('dashboard')->group(function () {
        Route::get('stats', [AdminDashboardController::class, 'stats'])
            ->middleware('permission:admin,core.dashboard.read')
            ->name('api.admin.dashboard.stats');
        Route::get('resources', [AdminDashboardController::class, 'resources'])
            ->middleware('permission:admin,core.dashboard.read')
            ->name('api.admin.dashboard.resources');
        Route::get('activities', [AdminDashboardController::class, 'activities'])
            ->middleware('permission:admin,core.dashboard.activities')
            ->name('api.admin.dashboard.activities');
        Route::get('alerts', [AdminDashboardController::class, 'alerts'])
            ->middleware('permission:admin,core.dashboard.read')
            ->name('api.admin.dashboard.alerts');
    });

    // 코어 라이선스
    Route::get('license', [AdminLicenseController::class, 'core'])->name('api.admin.license');
    Route::get('changelog', [AdminLicenseController::class, 'changelog'])->name('api.admin.changelog');

    // 현재 사용자 정보 업데이트
    Route::patch('users/me/language', [AdminUserController::class, 'updateMyLanguage'])->name('api.admin.users.me.language');

    // 모듈 관리
    Route::prefix('modules')->group(function () {
        Route::get('/', [AdminModuleController::class, 'index'])->middleware('permission:admin,core.modules.read|core.menus.read,false')->name('api.admin.modules.index');
        Route::get('installed', [AdminModuleController::class, 'installed'])->name('api.admin.modules.installed');
        Route::get('uninstalled', [AdminModuleController::class, 'uninstalled'])->middleware('permission:admin,core.modules.read')->name('api.admin.modules.uninstalled');
        Route::get('{moduleName}', [AdminModuleController::class, 'show'])->middleware('permission:admin,core.modules.read')->name('api.admin.modules.show');
        Route::get('{identifier}/dependent-templates', [AdminModuleController::class, 'dependentTemplates'])->middleware('permission:admin,core.modules.read')->name('api.admin.modules.dependent-templates');
        Route::get('{identifier}/changelog', [AdminModuleController::class, 'changelog'])->middleware('permission:admin,core.modules.read')->name('api.admin.modules.changelog');
        Route::get('{identifier}/license', [AdminModuleController::class, 'license'])->middleware('permission:admin,core.modules.read')->name('api.admin.modules.license');
        Route::get('{moduleName}/uninstall-info', [AdminModuleController::class, 'uninstallInfo'])->middleware('permission:admin,core.modules.uninstall')->name('api.admin.modules.uninstall-info');
        Route::post('install', [AdminModuleController::class, 'install'])->middleware('permission:admin,core.modules.install')->name('api.admin.modules.install');
        Route::post('install-from-file', [AdminModuleController::class, 'installFromFile'])->middleware('permission:admin,core.modules.install')->name('api.admin.modules.install-from-file');
        Route::post('install-from-github', [AdminModuleController::class, 'installFromGithub'])->middleware('permission:admin,core.modules.install')->name('api.admin.modules.install-from-github');
        Route::post('activate', [AdminModuleController::class, 'activate'])->middleware('permission:admin,core.modules.activate')->name('api.admin.modules.activate');
        Route::post('deactivate', [AdminModuleController::class, 'deactivate'])->middleware('permission:admin,core.modules.activate')->name('api.admin.modules.deactivate');
        Route::post('check-updates', [AdminModuleController::class, 'checkUpdates'])->middleware('permission:admin,core.modules.install')->name('api.admin.modules.check-updates');
        Route::get('{moduleName}/check-modified-layouts', [AdminModuleController::class, 'checkModifiedLayouts'])->middleware('permission:admin,core.modules.read')->name('api.admin.modules.check-modified-layouts');
        Route::post('{moduleName}/update', [AdminModuleController::class, 'performUpdate'])->middleware('permission:admin,core.modules.install')->name('api.admin.modules.update');
        Route::post('refresh-layouts', [AdminModuleController::class, 'refreshLayouts'])->middleware('permission:admin,core.modules.activate')->name('api.admin.modules.refresh-layouts');
        Route::delete('uninstall', [AdminModuleController::class, 'uninstall'])->middleware('permission:admin,core.modules.uninstall')->name('api.admin.modules.uninstall');
    });

    // 플러그인 관리
    Route::prefix('plugins')->group(function () {
        Route::get('/', [AdminPluginController::class, 'index'])->middleware('permission:admin,core.plugins.read')->name('api.admin.plugins.index');
        Route::get('installed', [AdminPluginController::class, 'installed'])->name('api.admin.plugins.installed');
        Route::get('{pluginName}', [AdminPluginController::class, 'show'])->middleware('permission:admin,core.plugins.read')->name('api.admin.plugins.show');
        Route::get('{identifier}/dependent-templates', [AdminPluginController::class, 'dependentTemplates'])->middleware('permission:admin,core.plugins.read')->name('api.admin.plugins.dependent-templates');
        Route::get('{identifier}/changelog', [AdminPluginController::class, 'changelog'])->middleware('permission:admin,core.plugins.read')->name('api.admin.plugins.changelog');
        Route::get('{identifier}/license', [AdminPluginController::class, 'license'])->middleware('permission:admin,core.plugins.read')->name('api.admin.plugins.license');
        Route::get('{pluginName}/uninstall-info', [AdminPluginController::class, 'uninstallInfo'])->middleware('permission:admin,core.plugins.uninstall')->name('api.admin.plugins.uninstall-info');
        Route::post('install', [AdminPluginController::class, 'install'])->middleware('permission:admin,core.plugins.install')->name('api.admin.plugins.install');
        Route::post('install-from-file', [AdminPluginController::class, 'installFromFile'])->middleware('permission:admin,core.plugins.install')->name('api.admin.plugins.install-from-file');
        Route::post('install-from-github', [AdminPluginController::class, 'installFromGithub'])->middleware('permission:admin,core.plugins.install')->name('api.admin.plugins.install-from-github');
        Route::post('activate', [AdminPluginController::class, 'activate'])->middleware('permission:admin,core.plugins.activate')->name('api.admin.plugins.activate');
        Route::post('deactivate', [AdminPluginController::class, 'deactivate'])->middleware('permission:admin,core.plugins.activate')->name('api.admin.plugins.deactivate');
        Route::post('check-updates', [AdminPluginController::class, 'checkUpdates'])->middleware('permission:admin,core.plugins.install')->name('api.admin.plugins.check-updates');
        Route::get('{pluginName}/check-modified-layouts', [AdminPluginController::class, 'checkModifiedLayouts'])->middleware('permission:admin,core.plugins.read')->name('api.admin.plugins.check-modified-layouts');
        Route::post('{pluginName}/update', [AdminPluginController::class, 'performUpdate'])->middleware('permission:admin,core.plugins.install')->name('api.admin.plugins.update');
        Route::post('refresh-layouts', [AdminPluginController::class, 'refreshLayouts'])->middleware('permission:admin,core.plugins.activate')->name('api.admin.plugins.refresh-layouts');
        Route::delete('uninstall', [AdminPluginController::class, 'uninstall'])->middleware('permission:admin,core.plugins.uninstall')->name('api.admin.plugins.uninstall');

        // 플러그인 설정
        Route::get('{identifier}/settings', [AdminPluginSettingsController::class, 'show'])->middleware('permission:admin,core.plugins.read')->name('api.admin.plugins.settings.show');
        Route::put('{identifier}/settings', [AdminPluginSettingsController::class, 'update'])->middleware('permission:admin,core.plugins.update')->name('api.admin.plugins.settings.update');
        Route::get('{identifier}/settings/layout', [AdminPluginSettingsController::class, 'layout'])->middleware('permission:admin,core.plugins.read')->name('api.admin.plugins.settings.layout');
    });

    // 환경설정 관리
    Route::prefix('settings')->group(function () {
        Route::get('/', [AdminSettingsController::class, 'index'])->middleware('permission:admin,core.settings.read')->name('api.admin.settings.index');
        Route::post('/', [AdminSettingsController::class, 'store'])->middleware('permission:admin,core.settings.update')->name('api.admin.settings.store');
        Route::get('system-info', [AdminSettingsController::class, 'systemInfo'])->middleware('permission:admin,core.settings.read')->name('api.admin.settings.system-info');
        Route::get('app-key', [AdminSettingsController::class, 'getAppKey'])->middleware('permission:admin,core.settings.read')->name('api.admin.settings.app-key');
        Route::post('regenerate-app-key', [AdminSettingsController::class, 'regenerateAppKey'])->middleware('permission:admin,core.settings.update')->name('api.admin.settings.regenerate-app-key');
        Route::post('clear-cache', [AdminSettingsController::class, 'clearCache'])->middleware('permission:admin,core.settings.update')->name('api.admin.settings.clear-cache');
        Route::post('optimize-system', [AdminSettingsController::class, 'optimizeSystem'])->middleware('permission:admin,core.settings.update')->name('api.admin.settings.optimize-system');
        Route::post('backup-database', [AdminSettingsController::class, 'backupDatabase'])->middleware('permission:admin,core.settings.update')->name('api.admin.settings.backup-database');
        Route::post('backup', [AdminSettingsController::class, 'backup'])->middleware('permission:admin,core.settings.update')->name('api.admin.settings.backup');
        Route::post('restore', [AdminSettingsController::class, 'restore'])->middleware('permission:admin,core.settings.update')->name('api.admin.settings.restore');
        Route::post('test-mail', [AdminSettingsController::class, 'testMail'])->middleware('permission:admin,core.settings.update')->name('api.admin.settings.test-mail');
        Route::post('test-driver', [AdminSettingsController::class, 'testDriverConnection'])->middleware('permission:admin,core.settings.update')->name('api.admin.settings.test-driver');
        Route::post('geoip/update', [AdminGeoIpController::class, 'update'])->middleware('permission:admin,core.settings.update')->name('api.admin.settings.geoip.update');
        Route::get('{key}', [AdminSettingsController::class, 'show'])->middleware('permission:admin,core.settings.read')->name('api.admin.settings.show');
        Route::put('{key}', [AdminSettingsController::class, 'update'])->middleware('permission:admin,core.settings.update')->name('api.admin.settings.update');
    });

    // 코어 업데이트
    Route::prefix('core-update')->group(function () {
        Route::post('check', [AdminCoreUpdateController::class, 'checkForUpdates'])
            ->middleware('permission:admin,core.settings.update')
            ->name('api.admin.core-update.check');
        Route::get('changelog', [AdminCoreUpdateController::class, 'changelog'])
            ->middleware('permission:admin,core.settings.read')
            ->name('api.admin.core-update.changelog');
    });

    // 메뉴 관리
    Route::prefix('menus')->group(function () {
        Route::get('/', [AdminMenuController::class, 'index'])->middleware('permission:admin,core.menus.read')->name('api.admin.menus.index');
        Route::get('hierarchy', [AdminMenuController::class, 'hierarchy'])->middleware('permission:admin,core.menus.read')->name('api.admin.menus.hierarchy');
        Route::get('active', [AdminMenuController::class, 'active'])->middleware('permission:admin,core.menus.read')->name('api.admin.menus.active');
        Route::post('/', [AdminMenuController::class, 'store'])->middleware('permission:admin,core.menus.create')->name('api.admin.menus.store');
        Route::put('order', [AdminMenuController::class, 'updateOrder'])->middleware('permission:admin,core.menus.update')->name('api.admin.menus.update-order');
        Route::get('extension/{type}/{identifier}', [AdminMenuController::class, 'getByExtension'])->middleware('permission:admin,core.menus.read')->name('api.admin.menus.by-extension');
        Route::get('{menu}', [AdminMenuController::class, 'show'])->middleware('permission:admin,core.menus.read')->name('api.admin.menus.show');
        Route::put('{menu}', [AdminMenuController::class, 'update'])->middleware('permission:admin,core.menus.update')->name('api.admin.menus.update');
        Route::delete('{menu}', [AdminMenuController::class, 'destroy'])->middleware('permission:admin,core.menus.delete')->name('api.admin.menus.destroy');
        Route::patch('{menu}/toggle-status', [AdminMenuController::class, 'toggleStatus'])->middleware('permission:admin,core.menus.update')->name('api.admin.menus.toggle-status');
    });

    // 역할 관리
    Route::prefix('roles')->group(function () {
        Route::get('/', [AdminRoleController::class, 'index'])->middleware('permission:admin,core.permissions.read')->name('api.admin.roles.index');
        Route::get('active', [AdminRoleController::class, 'active'])->name('api.admin.roles.active');
        Route::post('/', [AdminRoleController::class, 'store'])->middleware('permission:admin,core.permissions.create')->name('api.admin.roles.store');
        Route::get('{role}', [AdminRoleController::class, 'show'])->middleware('permission:admin,core.permissions.read')->name('api.admin.roles.show');
        Route::put('{role}', [AdminRoleController::class, 'update'])->middleware('permission:admin,core.permissions.update')->name('api.admin.roles.update');
        Route::delete('{role}', [AdminRoleController::class, 'destroy'])->middleware('permission:admin,core.permissions.delete')->name('api.admin.roles.destroy');
        Route::patch('{role}/toggle-status', [AdminRoleController::class, 'toggleStatus'])->middleware('permission:admin,core.permissions.update')->name('api.admin.roles.toggle-status');
    });

    // 권한 목록 (역할 폼에서 사용)
    Route::prefix('permissions')->group(function () {
        Route::get('/', [AdminPermissionController::class, 'index'])->middleware('permission:admin,core.permissions.read')->name('api.admin.permissions.index');
    });

    // 활동 로그 조회/삭제
    Route::prefix('activity-logs')->group(function () {
        Route::get('/', [AdminActivityLogController::class, 'index'])->middleware('permission:admin,core.activities.read')->name('api.admin.activity-logs.index');
        Route::delete('/{activityLog}', [AdminActivityLogController::class, 'destroy'])->middleware('permission:admin,core.activities.delete')->name('api.admin.activity-logs.destroy');
        Route::post('/bulk-delete', [AdminActivityLogController::class, 'bulkDestroy'])->middleware('permission:admin,core.activities.delete')->name('api.admin.activity-logs.bulk-destroy');
    });

    // 첨부파일 관리
    Route::prefix('attachments')->group(function () {
        Route::post('/', [AdminAttachmentController::class, 'upload'])
            ->middleware('permission:admin,core.attachments.create')
            ->name('api.admin.attachments.upload');
        Route::post('batch', [AdminAttachmentController::class, 'uploadBatch'])
            ->middleware('permission:admin,core.attachments.create')
            ->name('api.admin.attachments.upload_batch');
        Route::delete('{attachment}', [AdminAttachmentController::class, 'destroy'])
            ->where('attachment', '[0-9]+')
            ->middleware('permission:admin,core.attachments.delete')
            ->name('api.admin.attachments.destroy');
        Route::patch('reorder', [AdminAttachmentController::class, 'reorder'])
            ->middleware('permission:admin,core.attachments.update')
            ->name('api.admin.attachments.reorder');
    });

    // 사용자 관리
    Route::prefix('users')->group(function () {
        Route::get('/', [AdminUserController::class, 'index'])->middleware('permission:admin,core.users.read')->name('api.admin.users.index');
        Route::post('/', [AdminUserController::class, 'store'])->middleware('permission:admin,core.users.create')->name('api.admin.users.store');
        Route::get('statistics', [AdminUserController::class, 'statistics'])->middleware('permission:admin,core.users.read')->name('api.admin.users.statistics');
        Route::get('recent', [AdminUserController::class, 'recent'])->middleware('permission:admin,core.users.read')->name('api.admin.users.recent');
        Route::get('search', [AdminUserController::class, 'search'])->middleware('permission:admin,core.users.read')->name('api.admin.users.search');
        Route::post('check-email', [AdminUserController::class, 'checkEmail'])->middleware('permission:admin,core.users.read')->name('api.admin.users.check-email');
        Route::patch('bulk-status', [AdminUserController::class, 'bulkUpdateStatus'])->middleware('permission:admin,core.users.update')->name('api.admin.users.bulk-status');
        Route::get('{user}', [AdminUserController::class, 'show'])->middleware('permission:admin,core.users.read')->name('api.admin.users.show');
        Route::put('{user}', [AdminUserController::class, 'update'])->middleware('permission:admin,core.users.update')->name('api.admin.users.update');
        Route::delete('{user}', [AdminUserController::class, 'destroy'])->middleware('permission:admin,core.users.delete')->name('api.admin.users.destroy');
    });

    // 스케줄 관리
    Route::prefix('schedules')->group(function () {
        Route::get('/', [AdminScheduleController::class, 'index'])->middleware('permission:admin,core.schedules.read')->name('api.admin.schedules.index');
        Route::post('/', [AdminScheduleController::class, 'store'])->middleware('permission:admin,core.schedules.create')->name('api.admin.schedules.store');
        Route::get('statistics', [AdminScheduleController::class, 'statistics'])->middleware('permission:admin,core.schedules.read')->name('api.admin.schedules.statistics');
        Route::patch('bulk-status', [AdminScheduleController::class, 'bulkUpdateStatus'])->middleware('permission:admin,core.schedules.update')->name('api.admin.schedules.bulk-status');
        Route::delete('bulk', [AdminScheduleController::class, 'bulkDelete'])->middleware('permission:admin,core.schedules.delete')->name('api.admin.schedules.bulk-delete');
        Route::get('{schedule}', [AdminScheduleController::class, 'show'])->middleware('permission:admin,core.schedules.read')->name('api.admin.schedules.show');
        Route::put('{schedule}', [AdminScheduleController::class, 'update'])->middleware('permission:admin,core.schedules.update')->name('api.admin.schedules.update');
        Route::delete('{schedule}', [AdminScheduleController::class, 'destroy'])->middleware('permission:admin,core.schedules.delete')->name('api.admin.schedules.destroy');
        Route::post('{schedule}/run', [AdminScheduleController::class, 'run'])->middleware('permission:admin,core.schedules.run')->name('api.admin.schedules.run');
        Route::post('{schedule}/duplicate', [AdminScheduleController::class, 'duplicate'])->middleware('permission:admin,core.schedules.create')->name('api.admin.schedules.duplicate');
        Route::get('{schedule}/history', [AdminScheduleController::class, 'history'])->middleware('permission:admin,core.schedules.read')->name('api.admin.schedules.history');
        Route::delete('history/{historyId}', [AdminScheduleController::class, 'deleteHistory'])->middleware('permission:admin,core.schedules.delete')->name('api.admin.schedules.delete-history');
    });

    // SEO 캐시 관리
    Route::prefix('seo')->group(function () {
        Route::get('stats', [AdminSeoCacheController::class, 'stats'])->middleware('permission:admin,core.settings.read')->name('api.admin.seo.stats');
        Route::post('clear-cache', [AdminSeoCacheController::class, 'clearCache'])->middleware('permission:admin,core.settings.update')->name('api.admin.seo.clear-cache');
        Route::post('warmup', [AdminSeoCacheController::class, 'warmup'])->middleware('permission:admin,core.settings.update')->name('api.admin.seo.warmup');
        Route::get('cached-urls', [AdminSeoCacheController::class, 'cachedUrls'])->middleware('permission:admin,core.settings.read')->name('api.admin.seo.cached-urls');
    });

    // 알림 정의 관리
    Route::prefix('notification-definitions')->group(function () {
        Route::get('/', [AdminNotificationDefinitionController::class, 'index'])->middleware('permission:admin,core.settings.read')->name('api.admin.notification-definitions.index');
        Route::get('{definition}', [AdminNotificationDefinitionController::class, 'show'])->middleware('permission:admin,core.settings.read')->name('api.admin.notification-definitions.show');
        Route::put('{definition}', [AdminNotificationDefinitionController::class, 'update'])->middleware('permission:admin,core.settings.update')->name('api.admin.notification-definitions.update');
        Route::patch('{definition}/toggle-active', [AdminNotificationDefinitionController::class, 'toggleActive'])->middleware('permission:admin,core.settings.update')->name('api.admin.notification-definitions.toggle-active');
        Route::post('{definition}/reset', [AdminNotificationDefinitionController::class, 'reset'])->middleware('permission:admin,core.settings.update')->name('api.admin.notification-definitions.reset');
    });

    // 알림 템플릿 관리
    Route::prefix('notification-templates')->group(function () {
        Route::put('{template}', [AdminNotificationTemplateController::class, 'update'])->middleware('permission:admin,core.settings.update')->name('api.admin.notification-templates.update');
        Route::patch('{template}/toggle-active', [AdminNotificationTemplateController::class, 'toggleActive'])->middleware('permission:admin,core.settings.update')->name('api.admin.notification-templates.toggle-active');
        Route::post('preview', [AdminNotificationTemplateController::class, 'preview'])->middleware('permission:admin,core.settings.read')->name('api.admin.notification-templates.preview');
        Route::post('{template}/reset', [AdminNotificationTemplateController::class, 'reset'])->middleware('permission:admin,core.settings.update')->name('api.admin.notification-templates.reset');
    });

    // 알림 채널 관리
    Route::get('notification-channels', [AdminNotificationChannelController::class, 'index'])->middleware('permission:admin,core.settings.read')->name('api.admin.notification-channels.index');

    // 알림 발송 이력
    Route::prefix('notification-logs')->group(function () {
        Route::get('/', [AdminNotificationLogController::class, 'index'])->middleware('permission:admin,core.notification-logs.read')->name('api.admin.notification-logs.index');
        Route::delete('{notificationLog}', [AdminNotificationLogController::class, 'destroy'])->middleware('permission:admin,core.notification-logs.delete')->name('api.admin.notification-logs.destroy');
        Route::post('bulk-delete', [AdminNotificationLogController::class, 'bulkDestroy'])->middleware('permission:admin,core.notification-logs.delete')->name('api.admin.notification-logs.bulk-destroy');
    });

    Route::prefix('templates')->group(function () {
        Route::get('/', [AdminTemplateController::class, 'index'])->middleware('permission:admin,core.templates.read')->name('api.admin.templates.index');
        Route::get('{templateName}', [AdminTemplateController::class, 'show'])->middleware('permission:admin,core.templates.read')->name('api.admin.templates.show');
        Route::post('install', [AdminTemplateController::class, 'install'])->middleware('permission:admin,core.templates.install')->name('api.admin.templates.install');
        Route::post('install-from-file', [AdminTemplateController::class, 'installFromFile'])->middleware('permission:admin,core.templates.install')->name('api.admin.templates.install-from-file');
        Route::post('install-from-github', [AdminTemplateController::class, 'installFromGithub'])->middleware('permission:admin,core.templates.install')->name('api.admin.templates.install-from-github');
        Route::post('activate', [AdminTemplateController::class, 'activate'])->middleware('permission:admin,core.templates.activate')->name('api.admin.templates.activate');
        Route::post('deactivate', [AdminTemplateController::class, 'deactivate'])->middleware('permission:admin,core.templates.activate')->name('api.admin.templates.deactivate');
        Route::post('refresh-layouts', [AdminTemplateController::class, 'refreshLayouts'])->middleware('permission:admin,core.templates.activate')->name('api.admin.templates.refresh-layouts');
        Route::post('check-updates', [AdminTemplateController::class, 'checkUpdates'])->middleware('permission:admin,core.templates.install')->name('api.admin.templates.check-updates');
        Route::get('{identifier}/changelog', [AdminTemplateController::class, 'changelog'])->middleware('permission:admin,core.templates.read')->name('api.admin.templates.changelog');
        Route::get('{identifier}/license', [AdminTemplateController::class, 'license'])->middleware('permission:admin,core.templates.read')->name('api.admin.templates.license');
        Route::get('{templateName}/check-modified-layouts', [AdminTemplateController::class, 'checkModifiedLayouts'])->middleware('permission:admin,core.templates.read')->name('api.admin.templates.check-modified-layouts');
        Route::post('{templateName}/update', [AdminTemplateController::class, 'performUpdate'])->middleware('permission:admin,core.templates.install')->name('api.admin.templates.update');
        Route::get('{templateName}/uninstall-info', [AdminTemplateController::class, 'uninstallInfo'])->middleware('permission:admin,core.templates.uninstall')->name('api.admin.templates.uninstall-info');
        Route::delete('uninstall', [AdminTemplateController::class, 'uninstall'])->middleware('permission:admin,core.templates.uninstall')->name('api.admin.templates.uninstall');

        // 레이아웃 관리
        Route::get('{templateName}/layouts', [AdminLayoutController::class, 'index'])->middleware('permission:admin,core.templates.read')->name('api.admin.templates.layouts.index');

        // 레이아웃 버전 관리 (show보다 먼저 등록 — {name}의 / 허용으로 인한 라우트 충돌 방지)
        Route::get('{templateName}/layouts/{name}/versions', [AdminLayoutController::class, 'versions'])->middleware('permission:admin,core.templates.read')->name('api.admin.templates.layouts.versions.index')->where('name', '[a-zA-Z0-9_/\.\-]+');
        Route::get('{templateName}/layouts/{name}/versions/{version}', [AdminLayoutController::class, 'showVersion'])->middleware('permission:admin,core.templates.read')->name('api.admin.templates.layouts.versions.show')->where('name', '[a-zA-Z0-9_/\.\-]+');
        Route::post('{templateName}/layouts/{name}/versions/{versionId}/restore', [AdminLayoutController::class, 'restoreVersion'])->middleware('permission:admin,core.templates.layouts.edit')->name('api.admin.templates.layouts.versions.restore')->where('name', '[a-zA-Z0-9_/\.\-]+');

        // 레이아웃 미리보기
        Route::post('{templateName}/layouts/{name}/preview', [AdminLayoutController::class, 'storePreview'])->middleware('permission:admin,core.templates.read')->name('api.admin.templates.layouts.preview.store')->where('name', '[a-zA-Z0-9_/\.\-]+');

        // 레이아웃 CRUD (범용 {name} 라우트 — 가장 마지막에 등록)
        Route::get('{templateName}/layouts/{name}', [AdminLayoutController::class, 'show'])->middleware('permission:admin,core.templates.read')->name('api.admin.templates.layouts.show')->where('name', '[a-zA-Z0-9_/\.\-]+');
        Route::put('{templateName}/layouts/{name}', [AdminLayoutController::class, 'update'])->middleware('permission:admin,core.templates.layouts.edit')->name('api.admin.templates.layouts.update')->where('name', '[a-zA-Z0-9_/\.\-]+');
    });
});
