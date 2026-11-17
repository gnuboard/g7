<?php
/**
 * 403 화면 (CSRF 토큰 불일치 등) — index.php 의 화면 틀 없이 혼자 출력된다.
 *
 * 스타일은 파일로 싣는다. <style> 블록은 웹서버가 `Content-Security-Policy: style-src 'self'` 를 걸면 막힌다.
 * 주소 도우미는 functions.php 에 있다(session.php 만 로드한 진입에서도 쓰도록 여기서 불러온다).
 */
require_once __DIR__.'/../includes/functions.php';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden</title>
    <?= function_exists('installer_favicon_tags') ? installer_favicon_tags() : '' ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(installerStandaloneCssUrl()) ?>">
</head>
<body class="standalone-forbidden">
    <div class="error-container">
        <h1>403 Forbidden</h1>
        <p><?= htmlspecialchars($message) ?></p>
        <a href="/" class="back-button">
            <?= $lang === 'ko' ? '뒤로 가기' : 'Go Back' ?>
        </a>
    </div>
</body>
</html>
