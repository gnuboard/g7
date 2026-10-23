<?php

namespace App\Extension\Helpers;

use App\Extension\Exceptions\UnsafeArchiveException;
use App\Extension\Support\LifecycleWarnings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 안전한 압축 해제 도우미.
 *
 * `ZipArchive::extractTo()` 는 항목 이름을 그대로 써서 두 가지 문제가 있다.
 * - Windows PowerShell 5.1 이 만든 zip 의 `dir\file.php` 항목이 리눅스에서는 역슬래시가 든
 *   파일 하나가 된다(폴더가 생기지 않음).
 * - `../../public/x.php` 같은 항목이 압축을 푸는 폴더 밖에 써질 수 있다(Zip Slip).
 *
 * 이 도우미는 항목 이름의 `\` 를 `/` 로 바꿔 정규화한 뒤, 압축 밖을 가리키는 항목이
 * 하나라도 있으면 아무것도 풀지 않고 전체를 거부한다. 통과한 항목은 정규화한 경로에
 * 직접 쓴다. 코어의 모든 압축 해제 지점이 이 도우미를 쓴다(설치 마법사는 라라벨 밖이라
 * `public/install/includes/safe-archive-extractor.php` 에 같은 규칙의 함수를 둔다).
 */
class SafeArchiveExtractor
{
    /**
     * 유닉스 파일 종류 비트 마스크 (`S_IFMT`).
     */
    private const S_IFMT = 0170000;

    /**
     * 유닉스 심볼릭 링크 종류 (`S_IFLNK`).
     */
    private const S_IFLNK = 0120000;

    /**
     * ZIP 파일을 안전하게 풉니다.
     *
     * @param  string  $zipPath  ZIP 파일 경로
     * @param  string  $destination  풀 대상 디렉터리 (없으면 만든다)
     * @param  string|null  $displayName  경고에 보일 이름 — 업로드 임시 파일이면 사용자가 올린 원래 파일 이름 (없으면 경로의 파일 이름)
     * @return array<int, string> 풀어 쓴 파일의 정규화된 상대 경로 목록 (디렉터리 항목 제외)
     *
     * @throws UnsafeArchiveException 허용되지 않는 항목이 하나라도 있을 때 (아무것도 풀지 않음)
     * @throws \RuntimeException ZIP 을 열 수 없거나 항목을 쓰지 못했을 때
     */
    public static function extractZip(string $zipPath, string $destination, ?string $displayName = null): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException(__('settings.core_update.no_extract_method_available'));
        }

        if (! is_file($zipPath)) {
            throw new \RuntimeException(__('common.errors.zip_file_not_found'));
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException(__('common.errors.zip_open_failed'));
        }

        try {
            // 1차: 전 항목 검사 — 하나라도 거부되면 아무것도 쓰지 않는다
            $plan = [];
            $normalizedCount = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    continue;
                }

                if (self::isZipSymlink($zip, $i)) {
                    throw new UnsafeArchiveException($name);
                }

                $normalized = self::normalizeEntryName($name);
                if ($normalized === '') {
                    continue;
                }

                if (str_contains($name, '\\')) {
                    $normalizedCount++;
                }

                $isDir = str_ends_with(str_replace('\\', '/', $name), '/');
                $plan[] = ['index' => $i, 'path' => $normalized, 'dir' => $isDir];
            }

            // 2차: 정규화된 경로에 직접 쓴다
            $root = self::prepareDestination($destination);
            $written = [];
            foreach ($plan as $entry) {
                $target = $root.'/'.$entry['path'];

                if ($entry['dir']) {
                    self::ensureDirectoryInside($root, $target);

                    continue;
                }

                self::ensureDirectoryInside($root, dirname($target));

                $stream = $zip->getStream($zip->getNameIndex($entry['index']));
                if ($stream === false) {
                    throw new \RuntimeException(__('common.errors.zip_open_failed'));
                }

                self::writeStream($stream, $target);
                $written[] = $entry['path'];
            }
        } finally {
            $zip->close();
        }

        self::reportNormalized($normalizedCount, $displayName ?? $zipPath);

        return $written;
    }

    /**
     * ZIP 을 풀지 않고 항목만 검사합니다 — 기존 파일을 옮기기 전에 미리 거를 때 씁니다.
     *
     * @param  string  $zipPath  ZIP 파일 경로
     * @return void 반환값 없음
     *
     * @throws UnsafeArchiveException 허용되지 않는 항목이 하나라도 있을 때
     * @throws \RuntimeException ZIP 을 열 수 없을 때
     */
    public static function assertZipSafe(string $zipPath): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException(__('common.errors.zip_open_failed'));
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    continue;
                }
                if (self::isZipSymlink($zip, $i)) {
                    throw new UnsafeArchiveException($name);
                }
                self::normalizeEntryName($name);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * tar.gz(또는 tar) 아카이브를 안전하게 풉니다.
     *
     * @param  string  $path  아카이브 경로
     * @param  string  $destination  풀 대상 디렉터리 (없으면 만든다)
     * @return array<int, string> 풀어 쓴 파일의 정규화된 상대 경로 목록
     *
     * @throws UnsafeArchiveException 허용되지 않는 항목이 하나라도 있을 때 (아무것도 풀지 않음)
     * @throws \RuntimeException 아카이브를 열 수 없거나 항목을 쓰지 못했을 때
     */
    public static function extractTarGz(string $path, string $destination): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException(__('common.errors.zip_file_not_found'));
        }

        // PharData 는 링크 항목을 일반 파일로 보여 준다(isLink 거짓, 안쪽 링크는 대상 내용이 복사됨) — 헤더에서 직접 거부
        $linkEntry = self::findTarLinkEntry($path);
        if ($linkEntry !== null) {
            throw new UnsafeArchiveException($linkEntry);
        }

        $phar = new \PharData($path);
        $prefix = 'phar://'.str_replace('\\', '/', $phar->getPath()).'/';

        $plan = [];
        $normalizedCount = 0;
        $iterator = new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            /** @var \PharFileInfo $file */
            $pathname = str_replace('\\', '/', $file->getPathname());
            $name = str_starts_with($pathname, $prefix) ? substr($pathname, strlen($prefix)) : $file->getFilename();

            $normalized = self::normalizeEntryName($name);
            if ($normalized === '') {
                continue;
            }

            if (str_contains($name, '\\')) {
                $normalizedCount++;
            }

            $plan[] = ['source' => $file->getPathname(), 'path' => $normalized, 'dir' => $file->isDir()];
        }

        $root = self::prepareDestination($destination);
        $written = [];
        foreach ($plan as $entry) {
            $target = $root.'/'.$entry['path'];

            if ($entry['dir']) {
                self::ensureDirectoryInside($root, $target);

                continue;
            }

            self::ensureDirectoryInside($root, dirname($target));

            $stream = @fopen($entry['source'], 'rb');
            if ($stream === false) {
                throw new \RuntimeException(__('common.errors.zip_open_failed'));
            }

            self::writeStream($stream, $target);
            $written[] = $entry['path'];
        }

        self::reportNormalized($normalizedCount, $path);

        return $written;
    }

    /**
     * 항목 이름을 정규화합니다.
     *
     * `\` 를 `/` 로 바꾸고 빈 조각·`.` 조각을 지운다. 그 뒤 다음이면 거부한다:
     * 선행 `/`(UNC `//` 포함), 드라이브 문자(`C:`), 경로 조각 `..`, 널 문자.
     *
     * @param  string  $name  압축 안의 항목 이름
     * @return string 정규화된 상대 경로 (끝 `/` 없음). 루트 자신(`./`)이면 빈 문자열
     *
     * @throws UnsafeArchiveException 허용되지 않는 이름일 때
     */
    public static function normalizeEntryName(string $name): string
    {
        if (str_contains($name, "\0")) {
            throw new UnsafeArchiveException($name);
        }

        $slashed = str_replace('\\', '/', $name);

        if (str_starts_with($slashed, '/') || preg_match('#^[A-Za-z]:#', $slashed)) {
            throw new UnsafeArchiveException($name);
        }

        $segments = [];
        foreach (explode('/', $slashed) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new UnsafeArchiveException($name);
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * tar 헤더를 훑어 심볼릭 링크·하드 링크 항목의 이름을 찾습니다.
     *
     * 내용은 읽지 않고 512바이트 헤더만 따라가므로 큰 아카이브(GeoIP DB)도 메모리를 쓰지 않는다.
     * gzopen 은 압축하지 않은 tar 도 그대로 읽는다.
     *
     * @param  string  $path  tar 또는 tar.gz 경로
     * @return string|null 처음 만난 링크 항목 이름, 없으면 null
     *
     * @throws \RuntimeException 아카이브를 열 수 없을 때
     */
    private static function findTarLinkEntry(string $path): ?string
    {
        $fp = @gzopen($path, 'rb');
        if ($fp === false) {
            throw new \RuntimeException(__('common.errors.zip_open_failed'));
        }

        try {
            while (true) {
                $header = gzread($fp, 512);
                if ($header === false || strlen($header) < 512 || trim($header, "\0") === '') {
                    return null;
                }

                $type = $header[156];
                if ($type === '1' || $type === '2') {
                    $name = rtrim(substr($header, 0, 100), "\0");
                    $prefix = rtrim(substr($header, 345, 155), "\0");

                    return $prefix !== '' ? $prefix.'/'.$name : $name;
                }

                $size = octdec(trim(substr($header, 124, 12), "\0 "));
                $skip = (int) (ceil($size / 512) * 512);
                if ($skip > 0 && gzseek($fp, $skip, SEEK_CUR) === -1) {
                    return null;
                }
            }
        } finally {
            gzclose($fp);
        }
    }

    /**
     * ZIP 항목이 심볼릭 링크인지 확인합니다 (외부 속성의 유닉스 모드).
     *
     * @param  \ZipArchive  $zip  열린 ZIP
     * @param  int  $index  항목 번호
     * @return bool 심볼릭 링크면 true
     */
    private static function isZipSymlink(\ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;
        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        if ($opsys !== \ZipArchive::OPSYS_UNIX) {
            return false;
        }

        return (($attr >> 16) & self::S_IFMT) === self::S_IFLNK;
    }

    /**
     * 대상 디렉터리를 만들고 실제 경로(슬래시 구분)를 반환합니다.
     *
     * @param  string  $destination  대상 디렉터리
     * @return string 실제 경로
     */
    private static function prepareDestination(string $destination): string
    {
        File::ensureDirectoryExists($destination);

        $real = realpath($destination);
        if ($real === false) {
            throw new \RuntimeException(__('common.errors.zip_open_failed'));
        }

        return rtrim(str_replace('\\', '/', $real), '/');
    }

    /**
     * 디렉터리를 만들고, 그 실제 경로가 대상 디렉터리 아래인지 한 번 더 확인합니다.
     *
     * 정규화로 `..` 는 이미 걸렀지만, 대상 안에 미리 있던 링크를 따라 밖으로 나가는 경우를 막는다.
     *
     * @param  string  $root  대상 디렉터리 실제 경로
     * @param  string  $directory  만들 디렉터리
     * @return void 반환값 없음
     *
     * @throws UnsafeArchiveException 실제 경로가 대상 밖일 때
     */
    private static function ensureDirectoryInside(string $root, string $directory): void
    {
        File::ensureDirectoryExists($directory);

        $real = realpath($directory);
        $real = $real === false ? '' : rtrim(str_replace('\\', '/', $real), '/');

        if ($real !== $root && ! str_starts_with(strtolower($real).'/', strtolower($root).'/')) {
            throw new UnsafeArchiveException($directory);
        }
    }

    /**
     * 읽기 스트림을 대상 파일에 씁니다.
     *
     * @param  resource  $stream  읽기 스트림 (이 함수가 닫는다)
     * @param  string  $target  대상 파일 경로
     * @return void 반환값 없음
     */
    private static function writeStream($stream, string $target): void
    {
        $out = @fopen($target, 'wb');
        if ($out === false) {
            fclose($stream);
            throw new \RuntimeException(__('common.errors.zip_open_failed'));
        }

        try {
            if (stream_copy_to_stream($stream, $out) === false) {
                throw new \RuntimeException(__('common.errors.zip_open_failed'));
            }
        } finally {
            fclose($out);
            fclose($stream);
        }
    }

    /**
     * 역슬래시 경로를 정규화한 항목이 있으면 경고 목록·로그에 남깁니다.
     *
     * @param  int  $count  정규화한 항목 수
     * @param  string  $archivePath  아카이브 경로
     * @return void 반환값 없음
     */
    private static function reportNormalized(int $count, string $archivePath): void
    {
        if ($count === 0) {
            return;
        }

        $message = __('extensions.warnings.archive_backslash_normalized', ['count' => $count]);
        Log::warning('[SafeArchiveExtractor] 역슬래시 경로 정규화', [
            'archive' => basename($archivePath),
            'count' => $count,
        ]);

        // 정규화는 실패가 아니다 — 안내로 남겨 결과 창이 「실패」 제목을 쓰지 않게 한다
        LifecycleWarnings::current()->notice('extract', basename($archivePath), $message);
    }
}
