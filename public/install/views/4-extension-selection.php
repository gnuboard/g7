<?php
/**
 * Step 4: 확장 기능 선택
 *
 * 설치할 템플릿, 모듈, 플러그인을 선택합니다.
 * 관리자 템플릿은 최소 1개 필수이며, 나머지는 선택 사항입니다.
 */
?>

<div class="installer-container installer-container-wide">
    <h1 class="installer-title"><?= htmlspecialchars(lang('step_4_extension_selection')) ?></h1>

    <p class="installer-description">
        <?= htmlspecialchars(lang('extension_selection_description')) ?>
    </p>

    <!-- 로딩 표시 -->
    <div id="extensions-loading" class="extension-loading">
        <div class="spinner"></div>
        <p><?= htmlspecialchars(lang('loading_extensions')) ?></p>
    </div>

    <!-- 에러 표시 -->
    <div id="extensions-error" class="alert alert-error hidden">
        <p id="extensions-error-message"></p>
        <button type="button" onclick="loadExtensions()" class="btn btn-sm btn-secondary">
            <?= htmlspecialchars(lang('retry')) ?>
        </button>
    </div>

    <!-- 확장 선택 폼 -->
    <form id="extension-form" class="installer-form hidden">
        <!-- 마스터 일괄 토글 (모든 다중 선택 섹션) -->
        <div class="bulk-toggle-master">
            <label class="bulk-toggle-label">
                <input type="checkbox" id="bulk-toggle-all" class="bulk-toggle-checkbox" />
                <span><?= htmlspecialchars(lang('bulk_select_all_optional')) ?></span>
            </label>
        </div>

        <!-- 관리자 템플릿 (필수) -->
        <div class="requirement-card">
            <div class="requirement-card-header">
                <h3 class="requirement-card-title">
                    <i class="fas fa-shield-alt"></i>
                    <?= htmlspecialchars(lang('admin_templates')) ?>
                    <span class="badge badge-required"><?= htmlspecialchars(lang('required')) ?></span>
                </h3>
            </div>
            <div class="requirement-card-body">
                <p class="card-description"><?= htmlspecialchars(lang('admin_templates_description')) ?></p>
                <div id="admin-templates-list" class="extension-grid">
                    <!-- JavaScript로 동적 렌더링 -->
                </div>
            </div>
        </div>

        <!-- 사용자 템플릿 (선택) -->
        <div class="requirement-card">
            <div class="requirement-card-header">
                <h3 class="requirement-card-title">
                    <i class="fas fa-palette"></i>
                    <?= htmlspecialchars(lang('user_templates')) ?>
                    <span class="badge badge-optional"><?= htmlspecialchars(lang('optional')) ?></span>
                </h3>
                <label class="bulk-toggle-section">
                    <input type="checkbox" class="bulk-toggle-checkbox" data-bulk-target="user_templates" />
                    <span><?= htmlspecialchars(lang('bulk_select_section')) ?></span>
                </label>
            </div>
            <div class="requirement-card-body">
                <p class="card-description"><?= htmlspecialchars(lang('user_templates_description')) ?></p>
                <div id="user-templates-list" class="extension-grid">
                    <!-- JavaScript로 동적 렌더링 -->
                </div>
                <div id="user-templates-empty" class="extension-empty hidden">
                    <?= htmlspecialchars(lang('no_user_templates')) ?>
                </div>
            </div>
        </div>

        <!-- 모듈 (선택) -->
        <div class="requirement-card">
            <div class="requirement-card-header">
                <h3 class="requirement-card-title">
                    <i class="fas fa-cube"></i>
                    <?= htmlspecialchars(lang('modules')) ?>
                    <span class="badge badge-optional"><?= htmlspecialchars(lang('optional')) ?></span>
                </h3>
                <label class="bulk-toggle-section">
                    <input type="checkbox" class="bulk-toggle-checkbox" data-bulk-target="modules" />
                    <span><?= htmlspecialchars(lang('bulk_select_section')) ?></span>
                </label>
            </div>
            <div class="requirement-card-body">
                <p class="card-description"><?= htmlspecialchars(lang('modules_description')) ?></p>
                <div id="modules-list" class="extension-grid">
                    <!-- JavaScript로 동적 렌더링 -->
                </div>
                <div id="modules-empty" class="extension-empty hidden">
                    <?= htmlspecialchars(lang('no_modules')) ?>
                </div>
            </div>
        </div>

        <!-- 플러그인 (선택) -->
        <div class="requirement-card">
            <div class="requirement-card-header">
                <h3 class="requirement-card-title">
                    <i class="fas fa-plug"></i>
                    <?= htmlspecialchars(lang('plugins')) ?>
                    <span class="badge badge-optional"><?= htmlspecialchars(lang('optional')) ?></span>
                </h3>
                <label class="bulk-toggle-section">
                    <input type="checkbox" class="bulk-toggle-checkbox" data-bulk-target="plugins" />
                    <span><?= htmlspecialchars(lang('bulk_select_section')) ?></span>
                </label>
            </div>
            <div class="requirement-card-body">
                <p class="card-description"><?= htmlspecialchars(lang('plugins_description')) ?></p>
                <div id="plugins-list" class="extension-grid">
                    <!-- JavaScript로 동적 렌더링 -->
                </div>
                <div id="plugins-empty" class="extension-empty hidden">
                    <?= htmlspecialchars(lang('no_plugins')) ?>
                </div>
            </div>
        </div>

        <!-- 언어팩 (선택) -->
        <div class="requirement-card">
            <div class="requirement-card-header">
                <h3 class="requirement-card-title">
                    <i class="fas fa-language"></i>
                    <?= htmlspecialchars(lang('language_packs')) ?>
                    <span class="badge badge-optional"><?= htmlspecialchars(lang('optional')) ?></span>
                </h3>
                <label class="bulk-toggle-section">
                    <input type="checkbox" class="bulk-toggle-checkbox" data-bulk-target="language_packs" />
                    <span><?= htmlspecialchars(lang('bulk_select_section')) ?></span>
                </label>
            </div>
            <div class="requirement-card-body">
                <p class="card-description"><?= htmlspecialchars(lang('language_packs_description')) ?></p>
                <div id="language-packs-locale-groups">
                    <!-- JavaScript로 locale 별 서브헤딩 + 카드 동적 렌더링 -->
                </div>
                <div id="language-packs-empty" class="extension-empty hidden">
                    <?= htmlspecialchars(lang('no_language_packs')) ?>
                </div>
            </div>
        </div>

        <!-- 선택 요약 -->
        <div class="requirement-card selection-summary">
            <div class="requirement-card-header">
                <h3 class="requirement-card-title">
                    <i class="fas fa-clipboard-check"></i>
                    <?= htmlspecialchars(lang('selection_summary')) ?>
                </h3>
            </div>
            <div class="requirement-card-body">
                <div class="summary-grid">
                    <div class="summary-item">
                        <span class="summary-label"><?= htmlspecialchars(lang('admin_templates')) ?></span>
                        <span class="summary-count" id="summary-admin-templates">0</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label"><?= htmlspecialchars(lang('user_templates')) ?></span>
                        <span class="summary-count" id="summary-user-templates">0</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label"><?= htmlspecialchars(lang('modules')) ?></span>
                        <span class="summary-count" id="summary-modules">0</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label"><?= htmlspecialchars(lang('plugins')) ?></span>
                        <span class="summary-count" id="summary-plugins">0</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label"><?= htmlspecialchars(lang('language_packs')) ?></span>
                        <span class="summary-count" id="summary-language-packs">0</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 의존성 경고 (누락 의존성 존재 시 표시) -->
        <div id="dependency-warning" class="alert alert-warning hidden">
            <h4 class="alert-title">
                <i class="fas fa-exclamation-triangle"></i>
                <?= htmlspecialchars(lang('dependency_warning_title')) ?>
            </h4>
            <p><?= htmlspecialchars(lang('dependency_warning_description')) ?></p>
            <ul id="dependency-warning-list" class="dependency-warning-list"></ul>
            <button type="button" onclick="autoSelectMissingDependencies()" class="btn btn-sm btn-primary">
                <?= htmlspecialchars(lang('dependency_auto_select')) ?>
            </button>
        </div>

        <!-- 네비게이션 -->
        <div class="btn-group btn-group-spread">
            <button type="button" onclick="goToStep(3)" class="btn btn-secondary">
                <?= htmlspecialchars(lang('previous')) ?>
            </button>

            <button type="submit" id="submit-btn" class="btn btn-primary" disabled>
                <?= htmlspecialchars(lang('next')) ?>
            </button>
        </div>
    </form>
</div>

<script>
// Step 4 번역 키 전달
window.EXTENSION_LABELS = {
    version: <?= json_encode(lang('version')) ?>,
    select: <?= json_encode(lang('select')) ?>,
    selected: <?= json_encode(lang('selected')) ?>,
    dependencies: <?= json_encode(lang('dependencies')) ?>,
    admin_template_required: <?= json_encode(lang('admin_template_required')) ?>,
    saving: <?= json_encode(lang('saving')) ?>,
    save_failed: <?= json_encode(lang('save_failed')) ?>,
    next: <?= json_encode(lang('next')) ?>,
    extension_load_failed: <?= json_encode(lang('extension_load_failed')) ?>,
    dep_auto_badge_label: <?= json_encode(lang('dep_auto_badge_label')) ?>,
    dep_lock_message: <?= json_encode(lang('dep_lock_message')) ?>,
    dep_version_required: <?= json_encode(lang('dep_version_required')) ?>,
    dep_version_available: <?= json_encode(lang('dep_version_available')) ?>,
    language_pack_disabled_by_extension: <?= json_encode(lang('language_pack_disabled_by_extension')) ?>,
    language_pack_scope_core: <?= json_encode(lang('language_pack_scope_core')) ?>,
    language_pack_scope_module: <?= json_encode(lang('language_pack_scope_module')) ?>,
    language_pack_scope_plugin: <?= json_encode(lang('language_pack_scope_plugin')) ?>,
    language_pack_scope_template: <?= json_encode(lang('language_pack_scope_template')) ?>
};
</script>