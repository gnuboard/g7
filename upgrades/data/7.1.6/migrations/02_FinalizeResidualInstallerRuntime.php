<?php

namespace App\Upgrades\Data\V7_1_6\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;

/**
 * 설치가 끝난 사이트에 남은 인스톨러 런타임 파일(`storage/installer/runtime.php`)을 정리합니다.
 *
 * 웹 설치는 진행 중 DB 계정과 APP_KEY 를 `.env` 가 아니라 이 파일에 잠깐 둔다. 설치가 끝나면 `.env` 에 합치고
 * 지우는데, 지우지 못한 채 끝난 사이트는 이 파일이 `.env` 를 이겨 서버를 옮기며 `.env` 를 고쳐도 반영되지 않았다.
 * `.env` 의 DB 칸과 APP_KEY 가 비어 있고 이 파일만으로 돌던 사이트도 있다 — 파일만 지우면 그 사이트는 멈춘다.
 *
 * 그래서 지우기 전에 이 파일의 값을 `.env` 로 옮긴다:
 *   - `.env` 칸이 비어 있으면 runtime 값을 채운다.
 *   - 둘 다 있고 다르면 — DB 는 실제로 접속해 본다(`.env` 값으로 되면 `.env` 유지, 안 되고 runtime 값으로 되면
 *     runtime 채택, 둘 다 안 되면 `.env` 유지 + 경고). APP_KEY 는 지금까지 쓰인 값(runtime)을 채택한다 —
 *     키가 바뀌면 암호화된 설정·세션을 읽지 못한다.
 *   - 바꾼 것이 있으면 `.env` 를 `.env.bak-7.1.6-<시각>` 으로 남긴 뒤 원래 권한 그대로 바꿔 쓴다.
 * 그 뒤 runtime 파일을 지운다. 지우지 못하면 내용을 비워 비밀값을 없앤다.
 *
 * 기록에는 **키 이름과 결정만** 남긴다. 값(계정·비밀번호·키)과 서버 경로는 남기지 않는다.
 *
 * V-1 판단: 인스톨러 파일을 불러오지 않고 앱 서비스도 해석하지 않는다 — 이 파일 안의 함수와 PHP 내장만 쓴다.
 *
 * idempotent: runtime 파일이 없으면 아무것도 하지 않는다. 설치가 끝나지 않은 사이트(설치 중)는 건드리지 않는다.
 */
class FinalizeResidualInstallerRuntime implements DataMigration
{
    private const RUNTIME_RELATIVE = 'storage/installer/runtime.php';

    /**
     * 연결 묶음별 `.env` 키 접미사 → runtime 필드.
     */
    private const CONNECTION_FIELDS = [
        'HOST' => 'host',
        'PORT' => 'port',
        'DATABASE' => 'database',
        'USERNAME' => 'username',
        'PASSWORD' => 'password',
    ];

    /**
     * DB 접속 시험 함수 (시험에서 바꿔 끼운다).
     *
     * @var (callable(array{host: string, port: string, database: string, username: string, password: string}): bool)|null
     */
    private $connector = null;

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'FinalizeResidualInstallerRuntime';
    }

    /**
     * DB 접속 시험 함수를 바꿉니다 (시험용).
     *
     * @param  callable(array{host: string, port: string, database: string, username: string, password: string}): bool  $connector  접속되면 true 를 돌려주는 함수
     * @return void 반환값 없음
     */
    public function useConnector(callable $connector): void
    {
        $this->connector = $connector;
    }

    /**
     * 남은 runtime 파일의 값을 `.env` 로 옮기고 파일을 지웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $basePath = base_path();
        $runtimePath = $basePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, self::RUNTIME_RELATIVE);

        if (! is_file($runtimePath)) {
            $context->logger->info('[7.1.6] 인스톨러 런타임 파일 없음 — 건너뜀');

            return;
        }

        $envPath = $basePath.DIRECTORY_SEPARATOR.'.env';

        if (! $this->installCompleted($basePath, $envPath)) {
            $context->logger->info('[7.1.6] 설치가 끝나지 않은 사이트 — 인스톨러 런타임 파일을 그대로 둠(설치 중일 수 있음)');

            return;
        }

        if (! is_file($envPath)) {
            $context->logger->warning('[7.1.6] .env 가 없어 인스톨러 런타임 값을 옮길 곳이 없음 — 런타임 파일을 그대로 둠');

            return;
        }

        $runtime = @include $runtimePath;
        $fromRuntime = is_array($runtime) ? $this->runtimeToEnvMap($runtime) : [];
        $env = $this->parseEnvFile($envPath);

        [$changes, $decisions] = $this->decide($env, $fromRuntime, $context);

        foreach ($decisions as $key => $decision) {
            $context->logger->info("[7.1.6] 인스톨러 런타임 → .env: {$key} = {$decision}");
        }

        if ($changes !== []) {
            if (! $this->writeEnv($envPath, $changes, $context)) {
                $context->logger->warning('[7.1.6] .env 를 고치지 못함 — 인스톨러 런타임 파일을 그대로 둠(다음 업데이트에서 다시 시도)');

                return;
            }
            $this->refreshProcessEnv($changes);
        } else {
            $context->logger->info('[7.1.6] .env 에 옮길 값 없음');
        }

        $this->removeRuntime($runtimePath, $context);
    }

    /**
     * 키마다 `.env` 를 유지할지 runtime 값으로 바꿀지 정합니다.
     *
     * @param  array<string, string>  $env  `.env` 의 키-값
     * @param  array<string, string>  $fromRuntime  runtime 의 값을 `.env` 키로 펼친 것
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return array{0: array<string, string>, 1: array<string, string>} [바꿀 키 => 새 값, 키 => 결정 글자]
     */
    private function decide(array $env, array $fromRuntime, UpgradeContext $context): array
    {
        $changes = [];
        $decisions = [];
        $blank = static fn (string $key): bool => trim((string) ($env[$key] ?? '')) === '';

        foreach (['DB_WRITE_', 'DB_READ_'] as $prefix) {
            $conflict = [];
            foreach (array_keys(self::CONNECTION_FIELDS) as $suffix) {
                $key = $prefix.$suffix;
                if (! array_key_exists($key, $fromRuntime)) {
                    continue;
                }
                if ($blank($key)) {
                    // 주소가 .env 에 있으면 빈 포트는 기본 포트, 계정이 있으면 빈 비밀번호는 그 계정의 것이다
                    if (($suffix === 'PORT' && ! $blank($prefix.'HOST')) || ($suffix === 'PASSWORD' && ! $blank($prefix.'USERNAME'))) {
                        $decisions[$key] = '유지(.env 의 빈 값)';

                        continue;
                    }
                    $changes[$key] = $fromRuntime[$key];
                    $decisions[$key] = '채움(.env 가 비어 있음)';
                } elseif ((string) $env[$key] !== $fromRuntime[$key]) {
                    $conflict[] = $key;
                } else {
                    $decisions[$key] = '유지(같은 값)';
                }
            }

            if ($conflict === []) {
                continue;
            }

            // 둘 다 있고 다르다 — 실제로 붙어 본다
            $envWorks = $this->canConnect($this->credentials($prefix, array_merge($env, $changes)));
            $runtimeWorks = $envWorks ? false : $this->canConnect($this->credentials($prefix, array_merge($env, $changes, $this->only($fromRuntime, $prefix))));

            foreach ($conflict as $key) {
                if ($envWorks) {
                    $decisions[$key] = '유지(.env 값으로 접속됨)';
                } elseif ($runtimeWorks) {
                    $changes[$key] = $fromRuntime[$key];
                    $decisions[$key] = '런타임 값 채택(.env 값으로는 접속되지 않음)';
                } else {
                    $decisions[$key] = '유지(두 값 모두 접속되지 않음)';
                }
            }
            if (! $envWorks && ! $runtimeWorks) {
                $context->logger->warning('[7.1.6] '.rtrim($prefix, '_').' 연결 정보가 .env 와 런타임 파일에서 다르고 둘 다 접속되지 않음 — .env 를 유지함. DB 연결 정보를 확인하세요');
            }
        }

        if (array_key_exists('DB_PREFIX', $fromRuntime)) {
            if ($blank('DB_PREFIX') && $fromRuntime['DB_PREFIX'] !== '') {
                $changes['DB_PREFIX'] = $fromRuntime['DB_PREFIX'];
                $decisions['DB_PREFIX'] = '채움(.env 가 비어 있음)';
            } else {
                $decisions['DB_PREFIX'] = '유지';
            }
        }

        if (array_key_exists('APP_KEY', $fromRuntime)) {
            if ((string) ($env['APP_KEY'] ?? '') === $fromRuntime['APP_KEY']) {
                $decisions['APP_KEY'] = '유지(같은 값)';
            } else {
                // 지금까지 암호화에 쓰인 키는 runtime 의 것이다
                $changes['APP_KEY'] = $fromRuntime['APP_KEY'];
                $decisions['APP_KEY'] = $blank('APP_KEY') ? '채움(.env 가 비어 있음)' : '런타임 값 채택(지금 쓰이는 키)';
            }
        }

        return [$changes, $decisions];
    }

    /**
     * 한 연결 묶음의 접속 정보를 모읍니다.
     *
     * @param  string  $prefix  `DB_WRITE_` 또는 `DB_READ_`
     * @param  array<string, string>  $values  키-값
     * @return array{host: string, port: string, database: string, username: string, password: string} 접속 정보
     */
    private function credentials(string $prefix, array $values): array
    {
        // 읽기 연결의 빈 칸은 쓰기 연결을 따른다 (config/database.php 와 같은 규칙)
        $pick = static function (string $suffix, string $default) use ($prefix, $values): string {
            $value = trim((string) ($values[$prefix.$suffix] ?? ''));
            if ($value === '' && $prefix === 'DB_READ_') {
                $value = trim((string) ($values['DB_WRITE_'.$suffix] ?? ''));
            }

            return $value === '' ? $default : $value;
        };

        return [
            'host' => $pick('HOST', '127.0.0.1'),
            'port' => $pick('PORT', '3306'),
            'database' => $pick('DATABASE', ''),
            'username' => $pick('USERNAME', ''),
            'password' => (string) ($values[$prefix.'PASSWORD'] ?? ($prefix === 'DB_READ_' ? ($values['DB_WRITE_PASSWORD'] ?? '') : '')),
        ];
    }

    /**
     * 접속 정보로 실제 DB 에 붙어 봅니다.
     *
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $credentials  접속 정보
     * @return bool 붙으면 true
     */
    private function canConnect(array $credentials): bool
    {
        if ($this->connector !== null) {
            return (bool) ($this->connector)($credentials);
        }

        if (! class_exists(\PDO::class)) {
            return false;
        }

        try {
            new \PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s', $credentials['host'], $credentials['port'], $credentials['database']),
                $credentials['username'],
                $credentials['password'],
                [\PDO::ATTR_TIMEOUT => 5, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 접두사로 시작하는 키만 고릅니다.
     *
     * @param  array<string, string>  $values  키-값
     * @param  string  $prefix  키 접두사
     * @return array<string, string> 고른 키-값
     */
    private function only(array $values, string $prefix): array
    {
        return array_filter($values, static fn (string $key): bool => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
    }

    /**
     * 설치가 끝난 사이트인지 디스크만 보고 판정합니다.
     *
     * @param  string  $basePath  앱 루트
     * @param  string  $envPath  .env 경로
     * @return bool 완료면 true
     */
    private function installCompleted(string $basePath, string $envPath): bool
    {
        // audit:allow raw-literal-db-prefix reason: 설치 완료 표시 파일의 이름이다 — DB 접두사가 아니다
        if (is_file($basePath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'g7_installed')) {
            return true;
        }
        if (! is_file($envPath)) {
            return false;
        }
        $flag = strtolower(trim((string) ($this->parseEnvFile($envPath)['INSTALLER_COMPLETED'] ?? ''), " \t\"'"));

        return in_array($flag, ['true', '1', 'yes'], true);
    }

    /**
     * runtime 배열을 `.env` 키 이름으로 펼칩니다.
     *
     * @param  array<string, mixed>  $runtime  runtime 파일의 내용
     * @return array<string, string> `.env` 키 => 값 (runtime 에 있는 것만)
     */
    private function runtimeToEnvMap(array $runtime): array
    {
        $map = [];
        foreach (['write' => 'DB_WRITE_', 'read' => 'DB_READ_'] as $role => $prefix) {
            $connection = $runtime['db'][$role] ?? null;
            if (! is_array($connection) || empty($connection['host'])) {
                continue;
            }
            foreach (self::CONNECTION_FIELDS as $suffix => $field) {
                if (array_key_exists($field, $connection) && is_scalar($connection[$field])) {
                    $map[$prefix.$suffix] = (string) $connection[$field];
                }
            }
        }
        if (isset($runtime['db']['prefix']) && is_scalar($runtime['db']['prefix'])) {
            $map['DB_PREFIX'] = (string) $runtime['db']['prefix'];
        }
        $key = $runtime['app']['key'] ?? null;
        if (is_string($key) && $key !== '') {
            $map['APP_KEY'] = $key;
        }

        return $map;
    }

    /**
     * `.env` 파일을 줄 단위로 읽어 키-값 배열로 돌려줍니다.
     *
     * @param  string  $envPath  .env 경로
     * @return array<string, string> 키-값
     */
    private function parseEnvFile(string $envPath): array
    {
        $content = @file_get_contents($envPath);
        if ($content === false) {
            return [];
        }

        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
                continue;
            }
            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false || $eqPos === 0) {
                continue;
            }
            $key = rtrim(substr($trimmed, 0, $eqPos));
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            $value = rtrim(substr($trimmed, $eqPos + 1), " \t");
            $length = strlen($value);
            if ($length >= 2 && (($value[0] === '"' && $value[$length - 1] === '"') || ($value[0] === "'" && $value[$length - 1] === "'"))) {
                $value = substr($value, 1, $length - 2);
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * `.env` 의 키 몇 개를 새 값으로 바꿔 씁니다 (사본을 남기고, 원래 권한 그대로, 한 번에 바꿔치기).
     *
     * @param  string  $envPath  .env 경로
     * @param  array<string, string>  $changes  바꿀 키 => 새 값
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return bool 성공이면 true
     */
    private function writeEnv(string $envPath, array $changes, UpgradeContext $context): bool
    {
        $content = @file_get_contents($envPath);
        if ($content === false) {
            return false;
        }

        $mode = @fileperms($envPath);
        $uid = @fileowner($envPath);
        $gid = @filegroup($envPath);

        // audit:allow datetime-display-user-timezone reason: 서버 파일 이름 — 사용자에게 보이는 시각이 아니다
        $backupPath = $envPath.'.bak-7.1.6-'.date('YmdHis');
        if (@copy($envPath, $backupPath) === false) {
            return false;
        }
        if ($mode !== false) {
            @chmod($backupPath, $mode & 0777);
        }
        $context->logger->info('[7.1.6] .env 사본을 남김 ('.basename($backupPath).')');

        $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        foreach ($changes as $key => $value) {
            $line = $key.'='.$this->escapeEnvValue($value);
            $pattern = '/^[ \t]*'.preg_quote($key, '/').'[ \t]*=.*$/m';
            if (preg_match($pattern, $content)) {
                $content = (string) preg_replace_callback($pattern, static fn (): string => $line, $content, 1);
            } else {
                $content = rtrim($content, "\r\n").$eol.$line.$eol;
            }
        }

        $tempPath = $envPath.'.tmp-'.bin2hex(random_bytes(4));
        if (@file_put_contents($tempPath, $content, LOCK_EX) === false) {
            return false;
        }
        if ($mode !== false) {
            @chmod($tempPath, $mode & 0777);
        }
        if ($uid !== false && function_exists('chown')) {
            @chown($tempPath, $uid);
        }
        if ($gid !== false && function_exists('chgrp')) {
            @chgrp($tempPath, $gid);
        }

        if (! @rename($tempPath, $envPath)) {
            // 바꿔치기가 막힌 환경(일부 Windows·공유 호스팅) — 그 자리에 덮어쓴다
            $written = @file_put_contents($envPath, $content, LOCK_EX) !== false;
            @unlink($tempPath);

            return $written;
        }

        return true;
    }

    /**
     * `.env` 에 쓸 값을 필요하면 따옴표로 감쌉니다.
     *
     * @param  string  $value  값
     * @return string `.env` 에 쓸 표기
     */
    private function escapeEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_\-.:\/+=@]+$/', $value)) {
            return $value;
        }

        return '"'.addcslashes($value, '\\"$').'"';
    }

    /**
     * 같은 업데이트의 뒤 프로세스가 새 값으로 부팅하도록 프로세스 환경변수도 맞춥니다.
     *
     * @param  array<string, string>  $changes  바꾼 키 => 값
     * @return void 반환값 없음
     */
    private function refreshProcessEnv(array $changes): void
    {
        foreach ($changes as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * runtime 파일을 지웁니다. 지우지 못하면 내용을 비워 비밀값을 없앱니다.
     *
     * @param  string  $runtimePath  runtime 파일 경로
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    private function removeRuntime(string $runtimePath, UpgradeContext $context): void
    {
        if (@unlink($runtimePath) || ! is_file($runtimePath)) {
            $context->logger->info('[7.1.6] 인스톨러 런타임 파일 삭제 완료');

            return;
        }

        if (@file_put_contents($runtimePath, "<?php\n\nreturn [];\n", LOCK_EX) !== false) {
            $context->logger->warning('[7.1.6] 인스톨러 런타임 파일(runtime.php)을 지우지 못해 내용을 비움 — 파일을 직접 지워 주세요');

            return;
        }

        $context->logger->warning('[7.1.6] 인스톨러 런타임 파일(runtime.php)을 지우지도 비우지도 못함 — 파일을 직접 지워 주세요');
    }
}
