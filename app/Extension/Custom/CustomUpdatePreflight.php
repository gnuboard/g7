<?php

namespace App\Extension\Custom;

use App\Extension\Helpers\UpdateReservedPaths;
use App\Extension\Helpers\ZipEntryIndex;
use Illuminate\Support\Facades\File;

/**
 * 코어 업데이트를 **시작하기 전에** 개발자 custom 자리와 새 코어가 부딪히는지 검사합니다.
 *
 *  - 배포본이 custom 예약 자리에 파일을 실었는가(배포 사고) — {@see UpdateReservedPaths}
 *  - 개발자 패키지(`custom/composer.lock`)가 새 코어 패키지와 맞지 않는가 — {@see CustomPackageConflictChecker}
 *
 * 하나라도 걸리면 업데이트는 유지보수 모드·백업·파일 적용 전에 멈춘다. 중단 결과는
 * `storage/app/core_update/custom_preflight.json` 에 남겨 관리자 「업데이트 확인」 결과 창이 보여 준다.
 *
 * @since 7.2.0
 */
class CustomUpdatePreflight
{
    /**
     * @param  CustomPackageConflictChecker  $conflictChecker  패키지 충돌 판정기
     */
    public function __construct(
        private readonly CustomPackageConflictChecker $conflictChecker,
    ) {}

    /**
     * 디렉터리 형태 배포본(_pending 추출본·--source)을 검사합니다.
     *
     * @param  string  $sourceRoot  배포본 루트
     * @return array{reserved_files: array<int, string>, conflicts: array<int, array<string, string>>} 검사 결과
     */
    public function inspectDirectory(string $sourceRoot): array
    {
        return [
            'reserved_files' => UpdateReservedPaths::findInSource($sourceRoot),
            'conflicts' => $this->conflictChecker->checkFiles($sourceRoot.DIRECTORY_SEPARATOR.'composer.lock'),
        ];
    }

    /**
     * zip 배포본을 풀지 않고 검사합니다.
     *
     * @param  string  $zipPath  zip 경로
     * @return array{reserved_files: array<int, string>, conflicts: array<int, array<string, string>>}|null 검사 결과, 읽지 못하면 null
     */
    public function inspectZip(string $zipPath): ?array
    {
        $reserved = UpdateReservedPaths::findInZip($zipPath);
        if ($reserved === null) {
            return null;
        }

        $conflicts = [];
        $customLock = CustomPackageConflictChecker::readLock(CustomPackageConflictChecker::customLockPath());
        if ($customLock !== null) {
            $coreLock = self::readLockFromZip($zipPath);
            if ($coreLock === null) {
                return null;
            }
            $conflicts = $this->conflictChecker->check($coreLock, $customLock);
        }

        return ['reserved_files' => $reserved, 'conflicts' => $conflicts];
    }

    /**
     * 검사 결과가 업데이트를 막아야 하는지 판정합니다.
     *
     * @param  array{reserved_files: array<int, string>, conflicts: array<int, array<string, string>>}  $result  검사 결과
     * @return bool 막아야 하면 true
     */
    public static function blocks(array $result): bool
    {
        return $result['reserved_files'] !== [] || $result['conflicts'] !== [];
    }

    /**
     * 중단 결과 기록 파일 경로.
     *
     * @return string 절대 경로
     */
    public static function recordPath(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'core_update'.DIRECTORY_SEPARATOR.'custom_preflight.json');
    }

    /**
     * 중단 결과를 기록합니다 — 관리자 업데이트 확인 결과 창이 읽는다.
     *
     * @param  string  $toVersion  시도한 새 코어 버전
     * @param  array{reserved_files: array<int, string>, conflicts: array<int, array<string, string>>}  $result  검사 결과
     * @return void 반환값 없음
     */
    public static function record(string $toVersion, array $result): void
    {
        $path = self::recordPath();
        File::ensureDirectoryExists(dirname($path), 0775, true);
        File::put($path, json_encode([
            'to_version' => $toVersion,
            'checked_at' => now()->toIso8601String(),
            'reserved_files' => array_values($result['reserved_files']),
            'conflicts' => array_values($result['conflicts']),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 기록을 지웁니다 — 검사를 통과해 업데이트를 시작하면 이전 중단 기록은 더 이상 유효하지 않다.
     *
     * @return void 반환값 없음
     */
    public static function clearRecord(): void
    {
        $path = self::recordPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * 마지막 중단 기록을 읽습니다.
     *
     * @return array{to_version:string, checked_at:string, reserved_files:array<int,string>, conflicts:array<int,array<string,string>>}|null 기록, 없으면 null
     */
    public static function lastRecord(): ?array
    {
        $path = self::recordPath();
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * zip 배포본 안의 새 코어 버전을 읽습니다 — --zip 은 풀기 전에 막힐 수 있어 중단 문구·기록에 쓸 버전이 필요하다.
     *
     * @param  string  $zipPath  zip 경로
     * @return string|null `config/app.php` 의 버전, 읽지 못하면 null
     */
    public static function readVersionFromZip(string $zipPath): ?string
    {
        $raw = self::readFromZip($zipPath, 'config/app.php');
        if ($raw === null) {
            return null;
        }

        return preg_match("/['\"]version['\"]\s*=>\s*env\s*\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $raw, $m) === 1
            ? $m[1]
            : null;
    }

    /**
     * zip 안의 composer.lock 을 읽습니다.
     *
     * @param  string  $zipPath  zip 경로
     * @return array<string, mixed>|null lock 내용, 없으면 null
     */
    private static function readLockFromZip(string $zipPath): ?array
    {
        $raw = self::readFromZip($zipPath, 'composer.lock');
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * zip 안의 파일 하나를 배포본 루트 기준으로 읽습니다 (최상위 폴더 하나로 감싼 zip·역슬래시 이름 zip 도 지원).
     *
     * 배포본 루트는 `config/app.php` 위치로 정하고, 그 표식이 없으면 모든 항목이 공유하는 감싼 폴더로 정한다.
     * 아무 1단계 폴더의 같은 이름 파일(`docs/config/app.php`)은 읽지 않는다.
     *
     * @param  string  $zipPath  zip 경로
     * @param  string  $relative  배포본 루트 기준 슬래시 경로
     * @return string|null 내용, 없으면 null
     */
    private static function readFromZip(string $zipPath, string $relative): ?string
    {
        if (! class_exists(\ZipArchive::class)) {
            return null;
        }
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return null;
        }

        try {
            $index = ZipEntryIndex::fromArchive($zip);
            $root = $index->prefixFor('config/app.php') ?? $index->wrapperPrefix();

            return $index->read($root.$relative);
        } finally {
            $zip->close();
        }
    }
}
