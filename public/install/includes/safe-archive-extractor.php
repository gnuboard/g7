<?php

/**
 * 설치 마법사용 안전한 ZIP 해제 함수.
 *
 * 설치 마법사는 라라벨 밖에서 동작하므로 코어 `App\Extension\Helpers\SafeArchiveExtractor` 를
 * 쓸 수 없다. 같은 규칙을 순수 PHP 함수로 둔다(설치 마법사 독립성 규정상 중복 허용 —
 * 두 구현은 같은 시험 벡터로 잠근다).
 *
 * 규칙: 항목 이름의 `\` 를 `/` 로 바꿔 정규화하고, 선행 `/`(UNC 포함)·드라이브 문자·`..` 조각·
 * 널 문자·심볼릭 링크 항목이 하나라도 있으면 아무것도 풀지 않고 거부한다.
 */

/**
 * ZIP 항목 이름을 정규화합니다.
 *
 * @param  string  $name  압축 안의 항목 이름
 * @return string|null 정규화된 상대 경로(루트 자신이면 빈 문자열). 허용되지 않는 이름이면 null
 */
function g7InstallNormalizeArchiveEntry(string $name): ?string
{
    if (strpos($name, "\0") !== false) {
        return null;
    }

    $slashed = str_replace('\\', '/', $name);

    if (strpos($slashed, '/') === 0 || preg_match('#^[A-Za-z]:#', $slashed)) {
        return null;
    }

    $segments = [];
    foreach (explode('/', $slashed) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            return null;
        }
        $segments[] = $segment;
    }

    return implode('/', $segments);
}

/**
 * ZIP 항목이 심볼릭 링크인지 확인합니다 (외부 속성의 유닉스 모드).
 *
 * @param  ZipArchive  $zip  열린 ZIP
 * @param  int  $index  항목 번호
 * @return bool 심볼릭 링크면 true
 */
function g7InstallIsZipSymlink(ZipArchive $zip, int $index): bool
{
    $opsys = 0;
    $attr = 0;
    if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
        return false;
    }

    if ($opsys !== ZipArchive::OPSYS_UNIX) {
        return false;
    }

    return (($attr >> 16) & 0170000) === 0120000;
}

/**
 * ZIP 항목을 풀지 않고 검사합니다.
 *
 * @param  string  $zipPath  ZIP 경로
 * @return string|null 거부된 항목 이름. 모두 안전하면 null. 열 수 없으면 'cannot open zip'
 */
function g7InstallFindUnsafeZipEntry(string $zipPath): ?string
{
    $zip = new ZipArchive;
    if ($zip->open($zipPath) !== true) {
        return 'cannot open zip';
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) {
            continue;
        }
        if (g7InstallIsZipSymlink($zip, $i) || g7InstallNormalizeArchiveEntry($name) === null) {
            $zip->close();

            return $name;
        }
    }

    $zip->close();

    return null;
}

/**
 * ZIP 을 안전하게 풉니다.
 *
 * @param  string  $zipPath  ZIP 경로
 * @param  string  $destination  풀 대상 디렉터리 (없으면 만든다)
 * @return array{success: bool, error?: string, unsafe_entry?: string, files?: array<int, string>, normalized?: int}
 */
function g7InstallSafeExtractZip(string $zipPath, string $destination): array
{
    $unsafe = g7InstallFindUnsafeZipEntry($zipPath);
    if ($unsafe === 'cannot open zip') {
        return ['success' => false, 'error' => 'cannot open zip'];
    }
    if ($unsafe !== null) {
        return ['success' => false, 'error' => 'unsafe_entry', 'unsafe_entry' => $unsafe];
    }

    if (! is_dir($destination) && ! @mkdir($destination, 0755, true) && ! is_dir($destination)) {
        return ['success' => false, 'error' => 'cannot create destination'];
    }

    $root = realpath($destination);
    if ($root === false) {
        return ['success' => false, 'error' => 'cannot create destination'];
    }
    $root = rtrim(str_replace('\\', '/', $root), '/');

    $zip = new ZipArchive;
    if ($zip->open($zipPath) !== true) {
        return ['success' => false, 'error' => 'cannot open zip'];
    }

    $files = [];
    $normalizedCount = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) {
            continue;
        }

        $relative = g7InstallNormalizeArchiveEntry($name);
        if ($relative === null) {
            $zip->close();

            return ['success' => false, 'error' => 'unsafe_entry', 'unsafe_entry' => $name];
        }
        if ($relative === '') {
            continue;
        }
        if (strpos($name, '\\') !== false) {
            $normalizedCount++;
        }

        $target = $root.'/'.$relative;
        $isDir = substr(str_replace('\\', '/', $name), -1) === '/';
        $dir = $isDir ? $target : dirname($target);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $zip->close();

            return ['success' => false, 'error' => 'cannot create directory: '.$relative];
        }

        // 대상 안에 미리 있던 링크를 따라 밖으로 나가는 경우 차단
        $realDir = rtrim(str_replace('\\', '/', (string) realpath($dir)), '/');
        if ($realDir !== $root && strpos(strtolower($realDir).'/', strtolower($root).'/') !== 0) {
            $zip->close();

            return ['success' => false, 'error' => 'unsafe_entry', 'unsafe_entry' => $name];
        }

        if ($isDir) {
            continue;
        }

        $in = $zip->getStream($name);
        $out = $in === false ? false : @fopen($target, 'wb');
        if ($in === false || $out === false) {
            if ($in !== false) {
                fclose($in);
            }
            $zip->close();

            return ['success' => false, 'error' => 'cannot write: '.$relative];
        }

        $copied = stream_copy_to_stream($in, $out);
        fclose($out);
        fclose($in);

        if ($copied === false) {
            $zip->close();

            return ['success' => false, 'error' => 'cannot write: '.$relative];
        }

        $files[] = $relative;
    }

    $zip->close();

    return ['success' => true, 'files' => $files, 'normalized' => $normalizedCount];
}
