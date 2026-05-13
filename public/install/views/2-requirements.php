<?php
/**
 * Step 2: 요구사항 검증
 *
 * 서버 요구사항을 검증하고 결과를 표시합니다.
 */
?>

<div class="installer-container installer-container-wide">
    <h1 class="installer-title"><?= htmlspecialchars(lang('step_2_requirements')) ?></h1>

    <div id="requirements-result" class="requirements-list">
        <!-- AJAX 로딩 중 -->
        <div class="loading-box">
            <div class="spinner"></div>
            <p class="loading-text"><?= htmlspecialchars(lang('checking_requirements')) ?></p>
        </div>
    </div>

    <div id="navigation-buttons" class="btn-group btn-group-spread hidden">
        <button type="button" onclick="goToStep(1)" class="btn btn-secondary">
            <?= htmlspecialchars(lang('previous')) ?>
        </button>

        <div class="btn-group-right">
            <button onclick="recheckRequirements()" id="recheck-btn" class="btn btn-danger hidden">
                <?= htmlspecialchars(lang('recheck')) ?>
            </button>

            <form method="POST" class="form-inline">
                <button type="submit" name="proceed" id="next-btn" disabled class="btn btn-primary hidden">
                    <?= htmlspecialchars(lang('next')) ?>
                </button>
            </form>
        </div>
    </div>
</div>