<?php
/**
 * Step 1: 라이선스 동의
 *
 * G7 라이선스를 표시하고 사용자 동의를 받습니다.
 */

// $error 변수는 index.php의 POST 처리에서 설정될 수 있음
if (!isset($error)) {
    $error = null;
}

// LICENSE 파일 읽기
$licenseContent = loadLicenseFile($currentLang);

// 라이선스 동의 여부 확인 (세션 기반)
$isLicenseAgreed = isset($_SESSION['license_agreed']) && $_SESSION['license_agreed'] === true;
?>

<div class="welcome-wrapper">
    <div class="installer-container installer-container-wide">
        <h1 class="installer-title"><?= htmlspecialchars(lang('license_title')) ?></h1>

        <!-- 라이선스 본문 -->
        <div class="license-box">
            <pre class="license-text"><?= htmlspecialchars($licenseContent) ?></pre>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="installer-form">
            <label class="form-checkbox" style="justify-content: flex-end;">
                <span><?= htmlspecialchars(lang('i_agree')) ?></span>
                <input type="checkbox" name="agree" value="1" id="license-agree" class="checkbox-input" <?= $isLicenseAgreed ? 'checked' : '' ?>>
            </label>

            <div id="navigation-buttons" class="btn-group btn-group-spread">
                <button type="button" onclick="goToStep(0)" class="btn btn-secondary">
                    <?= htmlspecialchars(lang('previous')) ?>
                </button>

                <button type="submit" id="next-btn" class="btn btn-primary">
                    <?= htmlspecialchars(lang('next')) ?>
                </button>
            </div>
        </form>
    </div>
</div>
