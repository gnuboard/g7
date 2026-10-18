<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background-color: #f5f5f5;
        }
        .error-container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            text-align: center;
        }
        h1 {
            color: #e74c3c;
            margin-top: 0;
        }
        p {
            color: #666;
            line-height: 1.6;
        }
        .back-button {
            display: inline-block;
            margin-top: 1rem;
            padding: 0.5rem 1rem;
            background-color: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        .back-button:hover {
            background-color: #2980b9;
        }
        @media (prefers-color-scheme: dark) {
            body { background-color: #1e293b; }
            .error-container { background: #334155; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4); }
            h1 { color: #f87171; }
            p { color: #cbd5e1; }
            .back-button { background-color: #60a5fa; }
            .back-button:hover { background-color: #3b82f6; }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>403 Forbidden</h1>
        <p><?= htmlspecialchars($message) ?></p>
        <a href="javascript:history.back()" class="back-button">
            <?= $lang === 'ko' ? '뒤로 가기' : 'Go Back' ?>
        </a>
    </div>
</body>
</html>
