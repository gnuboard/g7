<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            color: #2d3748;
            background-color: #f7fafc;
        }
        .email-wrapper {
            max-width: 600px;
            margin: 0 auto;
            padding: 32px 24px;
            background-color: #ffffff;
        }
        h1 { font-size: 24px; font-weight: 700; margin: 0 0 16px; color: #1a202c; }
        h2 { font-size: 20px; font-weight: 600; margin: 0 0 12px; color: #1a202c; }
        p { margin: 0 0 16px; }
        a { color: #3182ce; }
    </style>
</head>
<body>
    <div class="email-wrapper">
        {!! $body !!}
    </div>
</body>
</html>
