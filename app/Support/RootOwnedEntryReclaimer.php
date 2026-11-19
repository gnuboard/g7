<?php

namespace App\Support;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputInterface;

/**
 * root 로 돈 업데이트·설치 계열 명령이 설치 트리에 남긴 root 소유 폴더·파일을 상위 폴더 소유·그룹 권한으로 되돌립니다.
 *
 * 업데이트 전 소유권 스냅샷은 **그때 있던** 경로만 기록한다. 업데이트가 처음 만든 경로는 스냅샷에도 복원 목록에도 없어
 * root 소유로 남았다 — 7.1.6 → 7.2.0 실측: 템플릿 환경설정 저장소 `storage/app/templates`(root 700, 웹서버가 읽지도 쓰지도
 * 못함), 코어 업데이트 기록 폴더 `storage/app/core-update`(관리자 화면 코어 업데이트가 진행 상태를 못 씀), 확장 정리 대기
 * 잠금 파일. 경로마다 목록에 더하면 다음에 새 경로가 생길 때 재발하므로, 명령이 끝날 때 설치 트리 전체를 훑어
 * **root 소유 항목만** 일반 규칙으로 되돌린다:
 *
 *  - 소유자·그룹: 바로 위 폴더의 것 (위에서부터 고치므로 새로 생긴 폴더 트리 전체가 사이트 소유로 이어진다)
 *  - 권한: 지금 권한에 위 폴더의 그룹·기타 권한을 더한다 — 폴더는 그룹 rwx·기타 r-x·setgid, 파일은 그룹 rw·기타 r 까지.
 *    빼지는 않는다(업데이트 코드가 0700·0600 으로 만든 것을 웹서버 그룹이 쓸 수 있게 하는 것이 목적)
 *  - root 가 아닌 소유 항목(웹서버가 만든 비공개 설정 폴더 등)은 손대지 않는다
 *  - 설치 루트 자체가 root 소유면 그 구성을 존중해 아무것도 하지 않는다
 *  - 링크는 따라가지 않는다(링크 자신의 소유만 고친다)
 *
 * root 프로세스에서만 동작한다. 웹 업데이트·CLI 를 못 쓰는 호스팅·일반 계정 CLI 는 root 파일을 만들 수 없으니 할 일이
 * 없고, `chown`·`posix_geteuid` 등이 막힌 서버에서도 아무 일 없이 지나간다(셸 명령을 쓰지 않는다).
 *
 * 7.1.x → 7.2.0 업데이트의 부모는 옛 코드라 이 정리를 모르지만, 새 코드로 뜨는 자식(업그레이드 스텝·번들 일괄 갱신)이
 * 각자 끝날 때 정리한다.
 */
final class RootOwnedEntryReclaimer
{
    /** 정리하는 명령의 이름 앞부분 */
    private const COMMAND_PREFIXES = ['core', 'extension', 'module', 'plugin', 'template', 'language-pack'];

    /** 정리하는 명령의 동작 이름 (파일·폴더를 만들 수 있는 업데이트·설치 계열) */
    private const COMMAND_ACTIONS = [
        'install', 'uninstall', 'update', 'activate', 'deactivate', 'refresh-layout', 'composer-install', 'seed', 'provision',
        'execute-upgrade-steps', 'execute-bundled-updates', 'deploy-ledger-seed', 'resync-declarations',
        'apply-deferred-cleanup', 'reset-files-from-bundled', 'sync-files', 'update-autoload',
    ];

    /** 훑지 않는 폴더 이름 */
    private const EXCLUDED_NAMES = ['.git', 'node_modules'];

    /** 실패 경로 기록 상한 */
    private const MAX_FAILED_PATHS = 50;

    /** root 판정 강제값 (시험용) */
    private static ?bool $fakeRoot = null;

    /** @var (\Closure(string): (array{uid: int, gid: int, mode: int}|null))|null stat 대체 함수 (시험용) */
    private static ?\Closure $statReader = null;

    /** @var (\Closure(string, int, int, int, bool): bool)|null 적용 대체 함수 (시험용) */
    private static ?\Closure $applier = null;

    /** 정리할 루트 (시험용, null 이면 base_path()) */
    private static ?string $rootOverride = null;

    /** 이번 프로세스에서 종료 정리를 이미 예약했는지 */
    private static bool $scheduled = false;

    /**
     * 명령 시작 이벤트 — root 로 시작한 업데이트 계열 명령이면 앱이 끝날 때 한 번 정리하도록 예약합니다.
     *
     * @param  CommandStarting  $event  명령 시작 이벤트
     * @return void 반환값 없음
     */
    public static function handleCommandStarting(CommandStarting $event): void
    {
        if (self::$scheduled || ! self::shouldReclaimAfter($event->command, $event->input) || ! self::isRootProcess()) {
            return;
        }

        self::$scheduled = true;

        app()->terminating(static function (): void {
            try {
                self::reclaim(self::$rootOverride ?? base_path());
            } catch (\Throwable $e) {
                // 명령 결과는 이미 정해졌다 — 정리 실패로 종료 코드를 바꾸지 않는다
                Log::channel('upgrade')->warning('root 소유 항목 정리 실패', ['error' => $e->getMessage()]);
            }
        });
    }

    /**
     * 끝난 뒤 정리할 명령인지 판정합니다.
     *
     * 번들 일괄 갱신이 항목마다 띄우는 프로세스(`--item-process`)는 제외한다 — 일괄 프로세스가 끝에 한 번 한다.
     *
     * @param  string|null  $command  명령 이름
     * @param  InputInterface|null  $input  명령 입력
     * @return bool 정리할 명령이면 true
     */
    public static function shouldReclaimAfter(?string $command, ?InputInterface $input): bool
    {
        if ($command === null || ! str_contains($command, ':')) {
            return false;
        }

        [$prefix, $action] = explode(':', $command, 2);

        if (! in_array($prefix, self::COMMAND_PREFIXES, true) || ! in_array($action, self::COMMAND_ACTIONS, true)) {
            return false;
        }

        if ($command === 'core:execute-bundled-updates' && $input !== null) {
            try {
                if ($input->hasOption('item-process') && $input->getOption('item-process')) {
                    return false;
                }
            } catch (\Throwable) {
                // 입력 정의가 아직 묶이지 않았다 — 원시 인자로 판정한다
            }

            if ($input->hasParameterOption('--item-process', true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 설치 트리의 root 소유 항목을 상위 폴더 소유·그룹 권한으로 되돌립니다.
     *
     * @param  string  $root  설치 루트 절대 경로
     * @return array{changed: int, failed: int, failed_paths: list<string>} 결과
     */
    public static function reclaim(string $root): array
    {
        $report = ['changed' => 0, 'failed' => 0, 'failed_paths' => []];

        if (! self::isRootProcess() || ! is_dir($root)) {
            return $report;
        }

        if (self::$applier === null && ! ExecutionEnvironment::hasFunction('chown')) {
            return $report;
        }

        $rootStat = self::stat($root);
        if ($rootStat === null || $rootStat['uid'] === 0) {
            return $report;
        }

        /** @var array<string, array{uid: int, gid: int, mode: int}> 폴더 경로 → 정리 뒤 상태 */
        $directories = [self::normalize($root) => $rootStat];

        foreach (self::walk($root) as $path => $info) {
            $stat = self::stat($path);
            if ($stat === null) {
                continue;
            }

            $isLink = $info->isLink();
            $isDirectory = ! $isLink && $info->isDir();
            $parent = $directories[self::normalize(dirname($path))] ?? self::stat(dirname($path));

            if ($stat['uid'] !== 0) {
                if ($isDirectory) {
                    $directories[self::normalize($path)] = $stat;
                }

                continue;
            }

            if ($parent === null || $parent['uid'] === 0) {
                self::recordFailure($report, $path);

                continue;
            }

            $mode = $stat['mode'] & 07777;
            if (! $isLink) {
                $mode |= $isDirectory
                    ? ($parent['mode'] & 02075)
                    : ($parent['mode'] & 0064);
            }

            if (self::apply($path, $parent['uid'], $parent['gid'], $mode, $isLink)) {
                $report['changed']++;
                $stat = ['uid' => $parent['uid'], 'gid' => $parent['gid'], 'mode' => $mode];
            } else {
                self::recordFailure($report, $path);
            }

            if ($isDirectory) {
                $directories[self::normalize($path)] = $stat;
            }
        }

        if ($report['changed'] > 0 || $report['failed'] > 0) {
            Log::channel('upgrade')->info('root 로 만들어진 항목의 소유·권한을 상위 폴더 기준으로 되돌림', $report);
        }

        return $report;
    }

    /**
     * 이 프로세스가 root 인지 판정합니다.
     *
     * @return bool root 면 true
     */
    public static function isRootProcess(): bool
    {
        if (self::$fakeRoot !== null) {
            return self::$fakeRoot;
        }

        // php-ext-guard: posix 확장이 없거나 함수가 막힌 서버(공유 호스팅)는 hasFunction 이 false — root 가 아닌 것으로 본다
        return ExecutionEnvironment::hasFunction('posix_geteuid') && posix_geteuid() === 0;
    }

    /**
     * root 판정을 강제합니다 (시험용).
     *
     * @param  bool|null  $isRoot  강제값 (null 이면 실제 판정)
     * @return void 반환값 없음
     */
    public static function fakeRootProcessForTesting(?bool $isRoot): void
    {
        self::$fakeRoot = $isRoot;
    }

    /**
     * stat 함수를 바꿉니다 (시험용 — Windows 에는 소유자 개념이 없다).
     *
     * @param  (\Closure(string): (array{uid: int, gid: int, mode: int}|null))|null  $reader  대체 함수
     * @return void 반환값 없음
     */
    public static function useStatReaderForTesting(?\Closure $reader): void
    {
        self::$statReader = $reader;
    }

    /**
     * 소유·권한 적용 함수를 바꿉니다 (시험용 — 실제 chown 을 하지 않는다).
     *
     * @param  (\Closure(string, int, int, int, bool): bool)|null  $applier  대체 함수
     * @return void 반환값 없음
     */
    public static function useApplierForTesting(?\Closure $applier): void
    {
        self::$applier = $applier;
    }

    /**
     * 종료 정리의 루트를 바꿉니다 (시험용).
     *
     * @param  string|null  $root  루트 (null 이면 base_path())
     * @return void 반환값 없음
     */
    public static function useRootForTesting(?string $root): void
    {
        self::$rootOverride = $root;
    }

    /**
     * 시험 사이에 내부 상태를 비웁니다.
     *
     * @return void 반환값 없음
     */
    public static function resetForTesting(): void
    {
        self::$fakeRoot = null;
        self::$statReader = null;
        self::$applier = null;
        self::$rootOverride = null;
        self::$scheduled = false;
    }

    /**
     * 설치 트리를 위에서부터 훑습니다 — 제외 폴더와 링크 너머는 들어가지 않습니다.
     *
     * @param  string  $root  루트
     * @return \Iterator<string, \SplFileInfo> 경로 → 정보
     */
    private static function walk(string $root): \Iterator
    {
        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filtered = new \RecursiveCallbackFilterIterator(
            $directory,
            static fn (\SplFileInfo $info): bool => ! in_array($info->getFilename(), self::EXCLUDED_NAMES, true),
        );

        return new \RecursiveIteratorIterator($filtered, \RecursiveIteratorIterator::SELF_FIRST, \RecursiveIteratorIterator::CATCH_GET_CHILD);
    }

    /**
     * 경로의 소유자·그룹·권한을 읽습니다 (링크는 링크 자신).
     *
     * @param  string  $path  경로
     * @return array{uid: int, gid: int, mode: int}|null 읽지 못하면 null
     */
    private static function stat(string $path): ?array
    {
        if (self::$statReader !== null) {
            return (self::$statReader)($path);
        }

        $stat = @lstat($path);

        return $stat === false ? null : ['uid' => (int) $stat['uid'], 'gid' => (int) $stat['gid'], 'mode' => (int) $stat['mode']];
    }

    /**
     * 소유자·그룹·권한을 적용합니다.
     *
     * @param  string  $path  경로
     * @param  int  $uid  소유자
     * @param  int  $gid  그룹
     * @param  int  $mode  권한 (링크는 쓰지 않음)
     * @param  bool  $isLink  링크인지
     * @return bool 소유자를 바꿨으면 true
     */
    private static function apply(string $path, int $uid, int $gid, int $mode, bool $isLink): bool
    {
        if (self::$applier !== null) {
            return (self::$applier)($path, $uid, $gid, $mode, $isLink);
        }

        if ($isLink) {
            if (! ExecutionEnvironment::hasFunction('lchown') || ! @lchown($path, $uid)) {
                return false;
            }
            if (ExecutionEnvironment::hasFunction('lchgrp')) {
                @lchgrp($path, $gid);
            }

            return true;
        }

        if (! @chown($path, $uid)) {
            return false;
        }
        if (ExecutionEnvironment::hasFunction('chgrp')) {
            @chgrp($path, $gid);
        }
        if (ExecutionEnvironment::hasFunction('chmod')) {
            @chmod($path, $mode);
        }

        return true;
    }

    /**
     * 실패를 기록합니다.
     *
     * @param  array{changed: int, failed: int, failed_paths: list<string>}  $report  결과
     * @param  string  $path  실패 경로
     * @return void 반환값 없음
     */
    private static function recordFailure(array &$report, string $path): void
    {
        $report['failed']++;
        if (count($report['failed_paths']) < self::MAX_FAILED_PATHS) {
            $report['failed_paths'][] = $path;
        }
    }

    /**
     * 경로 비교용으로 구분자를 맞춥니다.
     *
     * @param  string  $path  경로
     * @return string 정규화된 경로
     */
    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
