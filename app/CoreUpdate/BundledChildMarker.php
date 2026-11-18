<?php

namespace App\CoreUpdate;

use App\Support\ExecutionEnvironment;

/**
 * 관리자 화면 코어 업데이트의 번들 항목 자식 프로세스 표식 (7.2.0).
 *
 * 요청 시간 제한으로 기다리던 요청이 죽어도 자식(`core:execute-bundled-updates`)은 끝까지 돌 수 있다. 다음 요청이 그 끝을
 * 확인하도록 자식은 실행 상태 디렉토리의 표식 파일(`bundled-child_*.lock`)을 **배타 잠금**한 채 돌고, 옆 정보 파일
 * (`….lock.json`)에 자기 PID·시작 시각·부팅 식별자를 적는다(잠근 파일은 Windows 에서 다른 핸들이 읽지 못한다).
 *
 * 판정은 잠금이 1차다 — 잠금은 프로세스가 끝나면(정상·강제 종료·재부팅) OS 가 놓으므로 PID 재사용과 무관하다. 잠금을 쓸 수
 * 없는 파일 시스템에서만 PID 생존 확인(Windows `tasklist`, 그 밖 `posix_kill`·`/proc`)과 부팅 식별자 비교로 내려간다.
 */
final class BundledChildMarker
{
    /** 표식이 없다 — 자식이 정상으로 끝나 치웠거나 뜨지 않았다 */
    public const FINISHED = 'finished';

    /** 자식이 아직 표식을 잡고 있다 */
    public const RUNNING = 'running';

    /** 표식은 남았는데 잡은 프로세스가 없고 완료도 적히지 않았다 (강제 종료·재부팅) */
    public const ORPHAN = 'orphan';

    /** 표식 파일 이름 규약 (실행 상태 디렉토리 안) */
    public const NAME_PATTERN = '/^bundled-child_[A-Za-z0-9_-]+\.lock$/';

    /** 정보 파일 접미사 */
    private const INFO_SUFFIX = '.json';

    /** 부모가 띄운 뒤 자식이 표식을 잡기까지 기다려 주는 시간(초) — 그 사이 표식이 없다고 끝났다고 보지 않는다 */
    public const START_GRACE_SECONDS = 60;

    /** 이 프로세스가 잡은 표식 (자식 쪽 — 잡은 작업 잠금의 소유자를 적는 곳) */
    private static ?string $activeMarker = null;

    /**
     * 표식의 정보 파일 경로.
     *
     * @param  string  $marker  표식 경로
     * @return string 정보 파일 경로
     */
    public static function infoPath(string $marker): string
    {
        return $marker.self::INFO_SUFFIX;
    }

    /**
     * 자식이 표식을 잡고 자기 정보를 적습니다.
     *
     * 이름 규약 밖의 경로는 잡지 않는다(매니페스트가 임의 경로의 파일을 만들고 지우게 하지 않는다). 잡지 못하면 null — 표식 없이
     * 도는 것은 종전 동작이고, 부모는 「표식 없음」을 끝난 것으로 본다.
     *
     * @param  string  $marker  표식 경로
     * @return resource|null 잠근 핸들 (못 잡으면 null)
     */
    public static function hold(string $marker)
    {
        if (preg_match(self::NAME_PATTERN, basename($marker)) !== 1 || ! is_dir(dirname($marker))) {
            return null;
        }

        $existed = is_file($marker);
        $handle = @fopen($marker, 'c');

        if ($handle === false) {
            return null;
        }

        if (! @flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            // 잠금을 쓸 수 없는 파일 시스템 — 정보 없는 빈 표식을 남기면 부모가 판정할 근거 없이 표식만 본다. 만든 것은 치운다.
            if (! $existed) {
                @unlink($marker);
            }

            return null;
        }

        self::writeInfo($marker, ['pid' => self::currentPid(), 'host' => self::hostName(), 'started_at' => time(), 'boot_id' => self::bootId()]);
        self::$activeMarker = $marker;

        return $handle;
    }

    /**
     * 자식이 끝나며 완료를 적고 표식을 놓습니다 (완료를 먼저 적는다 — 놓은 뒤 지우기 전 틈에 본 부모가 고아로 오판하지 않게).
     *
     * @param  resource|null  $handle  `hold()` 가 돌려준 핸들
     * @param  string  $marker  표식 경로
     * @return void 반환값 없음
     */
    public static function release($handle, string $marker): void
    {
        if (self::$activeMarker === $marker) {
            self::$activeMarker = null;
        }

        if (! is_resource($handle)) {
            return;
        }

        $info = self::readInfo($marker) ?? [];
        $info['done'] = true;
        self::writeInfo($marker, $info);

        @flock($handle, LOCK_UN);
        @fclose($handle);

        // 정보 파일(완료 표지)은 남긴다 — 부모가 「끝남」과 「아직 표식을 잡기 전」을 가르는 근거다. 부모의 판정과 실행 기록 정리가 치운다.
        if (is_file($marker)) {
            @unlink($marker);
        }
    }

    /**
     * 이 프로세스가 표식을 잡은 자식이면 방금 잡은 작업 잠금의 소유자를 표식에 적습니다 (자식이 죽으면 부모가 그 소유자로만 푼다).
     *
     * @param  string  $key  잠금 키
     * @param  string  $owner  소유자 토큰
     * @return void 반환값 없음
     */
    public static function noteLock(string $key, string $owner): void
    {
        if (self::$activeMarker === null || $owner === '') {
            return;
        }

        $info = self::readInfo(self::$activeMarker) ?? [];
        $locks = is_array($info['locks'] ?? null) ? $info['locks'] : [];
        $locks[$key] = $owner;
        $info['locks'] = $locks;
        self::writeInfo(self::$activeMarker, $info);
    }

    /**
     * 부모가 표식 상태를 봅니다. 끝났거나 고아면 표식을 치운다.
     *
     * @param  string  $marker  표식 경로
     * @param  int|null  $startedAt  부모가 자식을 띄운 시각 (표식이 아직 없을 때 부팅 유예 판정용)
     * @param  int|null  $parentPid  자식을 띄운 부모 요청의 PID (같은 프로세스에서 돈 실행은 PID 생존 확인에서 뺀다)
     * @return array{state: string, pid: int|null, locks: array<string, string>} 상태, 기록된 PID, 자식이 잡았던 작업 잠금(키 → 소유자)
     */
    public static function inspect(string $marker, ?int $startedAt = null, ?int $parentPid = null): array
    {
        if (! is_file($marker)) {
            $info = self::readInfo($marker);

            // 표식도 정보도 없고 막 띄운 자식이면 아직 부팅 중일 수 있다 — 곧바로 끝났다고 보지 않는다.
            if ($info === null && $startedAt !== null && time() - $startedAt < self::START_GRACE_SECONDS) {
                return self::result(self::RUNNING, null);
            }

            self::remove($marker);

            return self::result(self::FINISHED, $info);
        }

        // 'r' — 그새 지워진 표식을 다시 만들지 않는다.
        $handle = @fopen($marker, 'r');
        $wouldBlock = 0;

        if ($handle === false) {
            $info = self::readInfo($marker);
            self::remove($marker);

            return self::result(self::FINISHED, $info);
        }

        $locked = @flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);

        if ($locked) {
            // 잠금을 얻은 **뒤에** 읽는다 — 자식이 완료를 적고 놓는 사이에 읽으면 정상 종료를 고아로 판정한다.
            $info = self::readInfo($marker);
            @flock($handle, LOCK_UN);
            fclose($handle);

            if (($info['done'] ?? false) === true) {
                self::remove($marker);

                return self::result(self::FINISHED, $info);
            }

            // 잠금이 비었어도 죽었다고 단정할 수 없는 경우 — 다른 호스트의 자식(노드 사이에 잠금이 공유되지 않는 저장소),
            // 같은 호스트에서 그 PID 가 아직 살아 있음. 도는 중으로 두고 기다림 상한에 맡긴다(확실하지 않으면 정리하지 않는다).
            if (self::onOtherHost($info) || self::recordedProcessAlive($info, $parentPid)) {
                return self::result(self::RUNNING, $info);
            }

            self::remove($marker);

            return self::result(self::ORPHAN, $info);
        }

        fclose($handle);
        $info = self::readInfo($marker);

        // Windows 의 잠금 충돌은 $wouldBlock 을 채우지 않는 판이 있다 — 그 OS 에서는 잠금 실패 자체를 충돌로 본다.
        if ($wouldBlock === 1 || PHP_OS_FAMILY === 'Windows') {
            return self::result(self::RUNNING, $info);
        }

        // 잠금을 쓸 수 없다(파일 시스템·권한). 완료가 적혔으면 끝남, 같은 호스트에서 PID 가 죽은 것을 확인했거나 부팅이 바뀌었으면
        // 고아. 그 밖(PID 를 모름·확인 불가·다른 호스트)은 도는 중으로 두고 기다림 상한에 맡긴다.
        if (($info['done'] ?? false) === true) {
            self::remove($marker);

            return self::result(self::FINISHED, $info);
        }

        $pid = isset($info['pid']) && is_numeric($info['pid']) ? (int) $info['pid'] : 0;

        if (! self::onOtherHost($info) && $pid > 0 && (self::bootChanged($info) || self::pidAlive($pid) === false)) {
            self::remove($marker);

            return self::result(self::ORPHAN, $info);
        }

        return self::result(self::RUNNING, $info);
    }

    /**
     * 판정 결과를 만듭니다.
     *
     * @param  string  $state  상태
     * @param  array<string, mixed>|null  $info  정보 파일 내용
     * @return array{state: string, pid: int|null, locks: array<string, string>} 상태, 기록된 PID, 자식이 잡았던 작업 잠금(키 → 소유자)
     */
    private static function result(string $state, ?array $info): array
    {
        $pid = isset($info['pid']) && is_numeric($info['pid']) ? (int) $info['pid'] : null;
        $locks = array_filter(
            is_array($info['locks'] ?? null) ? $info['locks'] : [],
            static fn ($owner, $key): bool => is_string($key) && is_string($owner) && $owner !== '',
            ARRAY_FILTER_USE_BOTH,
        );

        return ['state' => $state, 'pid' => $pid, 'locks' => $locks];
    }

    /**
     * 정보에 적힌 호스트가 이 호스트와 다른지 (적히지 않았거나 알 수 없으면 false).
     *
     * @param  array<string, mixed>|null  $info  정보 파일 내용
     * @return bool 다른 호스트면 true
     */
    private static function onOtherHost(?array $info): bool
    {
        $recorded = is_string($info['host'] ?? null) ? $info['host'] : null;
        $current = self::hostName();

        return $recorded !== null && $recorded !== '' && $current !== null && $recorded !== $current;
    }

    /**
     * 정보에 적힌 프로세스가 같은 부팅에서 아직 살아 있는지 — 부모 자신의 PID(같은 프로세스에서 돈 실행)는 보지 않는다.
     *
     * @param  array<string, mixed>|null  $info  정보 파일 내용
     * @param  int|null  $parentPid  항목을 띄운 부모 요청의 PID
     * @return bool 살아 있음이 확인되면 true
     */
    private static function recordedProcessAlive(?array $info, ?int $parentPid): bool
    {
        $pid = isset($info['pid']) && is_numeric($info['pid']) ? (int) $info['pid'] : 0;

        if ($pid <= 0 || ($parentPid !== null && $pid === $parentPid) || self::bootChanged($info)) {
            return false;
        }

        return self::pidAlive($pid) === true;
    }

    /**
     * 정보에 적힌 부팅 식별자가 지금과 다른지 (어느 쪽이든 모르면 false).
     *
     * @param  array<string, mixed>|null  $info  정보 파일 내용
     * @return bool 재부팅됐으면 true
     */
    private static function bootChanged(?array $info): bool
    {
        $recorded = is_string($info['boot_id'] ?? null) ? $info['boot_id'] : null;
        $current = self::bootId();

        return $recorded !== null && $current !== null && $recorded !== $current;
    }

    /**
     * 이 호스트의 이름 (함수가 막혔으면 null).
     *
     * @return string|null 호스트 이름
     */
    private static function hostName(): ?string
    {
        if (! ExecutionEnvironment::hasFunction('gethostname')) {
            return null;
        }

        $name = @gethostname();

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * 프로세스가 살아 있는지 OS 에 묻습니다.
     *
     * @param  int  $pid  프로세스 ID
     * @return bool|null 살아 있으면 true, 없으면 false, 확인할 수 없으면 null
     */
    public static function pidAlive(int $pid): ?bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            if (! ExecutionEnvironment::hasFunction('exec')) {
                return null;
            }

            $output = [];
            $code = 1;
            @exec('tasklist /FI "PID eq '.$pid.'" /NH /FO CSV 2>NUL', $output, $code);

            if ($code !== 0) {
                return null;
            }

            foreach ($output as $line) {
                if (str_contains((string) $line, '"'.$pid.'"')) {
                    return true;
                }
            }

            return false;
        }

        // php-ext-guard: posix 확장이 없거나 함수가 막힌 서버는 ExecutionEnvironment::hasFunction 이 거르고 /proc 로 내려간다
        if (ExecutionEnvironment::hasFunction('posix_kill')) {
            if (@posix_kill($pid, 0)) {
                return true;
            }

            // EPERM(1): 있지만 신호를 보낼 권한이 없다 — 살아 있다.
            return ExecutionEnvironment::hasFunction('posix_get_last_error') && posix_get_last_error() === 1;
        }

        if (@is_dir('/proc/self')) {
            return @is_dir('/proc/'.$pid);
        }

        return null;
    }

    /**
     * 이 프로세스의 PID (함수가 막힌 서버는 0 — 부모는 잠금만으로 판정한다).
     *
     * @return int PID
     */
    private static function currentPid(): int
    {
        return ExecutionEnvironment::hasFunction('getmypid') ? (int) getmypid() : 0;
    }

    /**
     * 이 부팅의 식별자 (Linux 만 — 재부팅 뒤 같은 PID 를 다른 프로세스가 쓰는 경우를 가른다).
     *
     * @return string|null 식별자 (없으면 null)
     */
    private static function bootId(): ?string
    {
        $path = '/proc/sys/kernel/random/boot_id';

        if (PHP_OS_FAMILY === 'Windows' || ! @is_readable($path)) {
            return null;
        }

        $value = trim((string) @file_get_contents($path));

        return $value !== '' ? $value : null;
    }

    /**
     * 정보 파일을 읽습니다.
     *
     * @param  string  $marker  표식 경로
     * @return array<string, mixed>|null 정보 (없거나 깨졌으면 null)
     */
    private static function readInfo(string $marker): ?array
    {
        $path = self::infoPath($marker);

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 정보 파일을 씁니다 (임시 파일 + 교체).
     *
     * @param  string  $marker  표식 경로
     * @param  array<string, mixed>  $info  정보
     * @return void 반환값 없음
     */
    private static function writeInfo(string $marker, array $info): void
    {
        $path = self::infoPath($marker);
        $temp = $path.'.tmp.'.bin2hex(random_bytes(4));

        if (@file_put_contents($temp, (string) json_encode($info)) !== false && ! @rename($temp, $path)) {
            @unlink($temp);
        }
    }

    /**
     * 표식과 정보 파일을 지웁니다.
     *
     * @param  string  $marker  표식 경로
     * @return void 반환값 없음
     */
    private static function remove(string $marker): void
    {
        foreach ([$marker, self::infoPath($marker)] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
