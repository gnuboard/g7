<?php

/*
|--------------------------------------------------------------------------
| Core Update: APP_VERSION 정합 (bootstrap/app.php 전용)
|--------------------------------------------------------------------------
|
| 7.0.0~7.0.10 의 core:update 부모는 `.env` 의 APP_VERSION 만 바꾸고 자기 프로세스 env($_ENV·$_SERVER·
| getenv)는 업데이트 전 버전으로 둔다. 그 env 를 물려받은 새 코드 자식(번들 일괄 업데이트·언어팩 단계)과
| 부모 안에서 도는 config:cache 일회용 앱은 Laravel env 저장소가 immutable 이라 `.env` 의 새 값을 읽지 못하고,
| 옛 버전을 bootstrap/cache/config.php 에 굽는다. 업데이트가 끝난 뒤 첫 프로세스가 그 캐시로 부팅하면
| 새 코어를 요구하는 확장이 「코어 버전 호환성 문제」로 자동 비활성화된다(#145, #137).
|
| 옛 부모 코드는 고칠 수 없다. 그러나 config:cache 는 어느 프로세스에서 돌든 bootstrap/app.php 를 디스크에서
| 새로 require 하므로, 새 bootstrap/app.php 가 이 함수를 부르면 옛 부모 안과 모든 자식에서 env 가 바로잡힌다.
|
| 규칙:
|   - 올리기만 한다. 업그레이드 스텝 자식은 의도적으로 `.env`(fromVersion)보다 높은 toVersion 을 받는다.
|   - App\ 클래스를 참조하지 않는다. 옛 부모의 메모리·오토로더를 믿을 수 없다.
|   - 예외를 밖으로 던지지 않는다. 정합 실패가 부팅을 막아서는 안 된다.
*/

if (! function_exists('g7_reconcile_update_app_version')) {
    /**
     * 프로세스 env 의 APP_VERSION 이 디스크 `.env` 값보다 낮으면 세 채널을 디스크 값으로 올립니다.
     *
     * @param  string  $basePath  애플리케이션 루트 경로 (`.env` 가 있는 디렉터리)
     * @return string|null 올린 버전, 바꾸지 않았으면 null
     */
    function g7_reconcile_update_app_version(string $basePath): ?string
    {
        try {
            $envFile = rtrim($basePath, '/\\').DIRECTORY_SEPARATOR.'.env';
            if (! is_file($envFile) || ! is_readable($envFile)) {
                return null;
            }

            $lines = @file($envFile, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                return null;
            }

            // Dotenv 와 같이 마지막 줄이 이긴다.
            $disk = null;
            foreach ($lines as $line) {
                $line = trim($line);
                if (str_starts_with($line, 'export ')) {
                    $line = ltrim(substr($line, 7));
                }
                if (! preg_match('/^APP_VERSION\s*=\s*(.*)$/', $line, $m)) {
                    continue;
                }

                $value = trim($m[1]);
                if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                    $end = strpos($value, $value[0], 1);
                    $value = $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
                } else {
                    $value = (string) preg_replace('/\s+#.*$/', '', $value);
                }

                $disk = trim($value);
            }

            $current = $_ENV['APP_VERSION'] ?? $_SERVER['APP_VERSION'] ?? getenv('APP_VERSION');

            if (! is_string($disk) || $disk === '' || ! is_string($current) || $current === '') {
                return null;
            }

            if (! version_compare($disk, $current, '>')) {
                return null;
            }

            $_ENV['APP_VERSION'] = $disk;
            $_SERVER['APP_VERSION'] = $disk;
            putenv('APP_VERSION='.$disk);

            return $disk;
        } catch (Throwable) {
            return null;
        }
    }
}
