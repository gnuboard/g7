<?php

namespace App\Services;

use App\Exceptions\CustomAssetOperationException;
use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\HookManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Rules\AllowedTemplateFileType;
use App\Support\CustomAssets;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * 사용자 추가 에셋(`custom/`) 관리 서비스
 *
 * 운영자가 자기 CSS·JS·폰트·이미지를 확장의 `custom/` 디렉토리에 넣고 고칠 수 있게 한다.
 * 종전에는 FTP 나 서버 셸이 유일한 경로였다 — 그 접근이 없는 운영자에게는 기능 자체가
 * 없는 것과 같았고, 있는 운영자에게도 "고쳤는데 화면에 안 나온다"(정적 게시본 미갱신)가
 * 남았다.
 *
 * 쓰기 뒤에는 반드시 캐시 버전을 올린다. 그 단일 지점이 재게시까지 예약하므로, 편집한
 * 파일이 게시본에 반영되는 경로가 구조적으로 보장된다.
 *
 * @see docs/extension/module-assets.md "사용자 추가 에셋"
 */
class CustomAssetService
{
    use ClearsTemplateCaches;

    /**
     * 편집기가 본문을 직접 열고 고칠 수 있는 확장자
     *
     * 이 목록 밖(폰트·이미지)은 업로드·삭제만 가능하다 — 바이너리를 텍스트 편집기에
     * 열면 내용이 손상된 채 저장된다.
     */
    public const EDITABLE_EXTENSIONS = ['css', 'js', 'mjs', 'json'];

    /** 텍스트 편집 대상 파일의 최대 크기 (바이트) */
    public const MAX_TEXT_BYTES = 524288;

    /** 업로드 파일의 최대 크기 (바이트) */
    public const MAX_UPLOAD_BYTES = 5242880;

    /** 검색 — 파일당 매치 수 상한 */
    public const SEARCH_MAX_MATCHES_PER_FILE = 20;

    /** 검색 — 미리보기 문자열 길이 상한 */
    public const SEARCH_PREVIEW_LENGTH = 200;

    /**
     * 확장의 사용자 추가 에셋 목록을 돌려줍니다.
     *
     * 서빙 여부와 무관하게 디스크에 있는 파일을 전부 싣는다 — 규약 스캔이 자동으로
     * 싣지 않는 폰트·이미지도 운영자에게는 관리 대상이고, 목록에서 빠지면 지울 방법이
     * 없어진다.
     *
     * @param  string  $extensionType  `templates` | `modules` | `plugins`
     * @param  string  $identifier  확장 식별자
     * @return array<int, array<string, mixed>> 파일 목록 (상대 경로 오름차순)
     */
    public function list(string $extensionType, string $identifier): array
    {
        $directory = CustomAssets::directory($extensionType, $identifier);

        if ($directory === null || ! is_dir($directory)) {
            return [];
        }

        // 로드 대상 서술자를 미리 만들어, 목록의 각 파일이 실제로 페이지에 실리는지
        // (`loaded`) 알려준다. 규약 스캔·선언 파일 어느 쪽이든 결과는 같은 형태다.
        //
        // 서술자는 상대 경로 필드를 갖지 않는다 — 소비자가 출처에 의존하지 않도록 URL 과
        // id 만 노출하는 계약이다. 그래서 id 접두(`custom:{type}:{identifier}:`)를 떼어
        // 상대 경로를 얻는다. 훅이 더한 항목은 이 접두가 없어 자연히 제외되는데, 그것이
        // 옳다 — 디스크에 없는 항목을 파일 목록에 표시할 이유가 없다.
        $idPrefix = 'custom:'.$extensionType.':'.$identifier.':';
        $loadedPaths = [];

        foreach (CustomAssets::forExtension($extensionType, $identifier) as $asset) {
            $id = (string) ($asset['id'] ?? '');

            if (($asset['source'] ?? null) !== 'file' || ! str_starts_with($id, $idPrefix)) {
                continue;
            }

            $loadedPaths[substr($id, strlen($idPrefix))] = true;
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            $extension = strtolower($file->getExtension());

            $files[] = [
                'path' => $relative,
                'name' => $file->getFilename(),
                'extension' => $extension,
                'size' => $file->getSize(),
                'modified_at' => date('c', $file->getMTime()),
                'editable' => in_array($extension, self::EDITABLE_EXTENSIONS, true),
                'loaded' => isset($loadedPaths[$relative]),
            ];
        }

        usort($files, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        return $files;
    }

    /**
     * 텍스트 파일 본문을 읽습니다.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @param  string  $relative  `custom/` 기준 상대 경로
     * @return array{path: string, content: string, size: int} 본문
     *
     * @throws CustomAssetOperationException 파일 부재·비편집 대상·크기 초과 시
     */
    public function read(string $extensionType, string $identifier, string $relative): array
    {
        $absolute = $this->resolveExisting($extensionType, $identifier, $relative);

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

        if (! in_array($extension, self::EDITABLE_EXTENSIONS, true)) {
            throw new CustomAssetOperationException('custom_assets.errors.not_editable', ['extension' => $extension]);
        }

        $size = (int) filesize($absolute);

        if ($size > self::MAX_TEXT_BYTES) {
            throw new CustomAssetOperationException('custom_assets.errors.too_large_to_edit', [
                'limit' => (string) self::MAX_TEXT_BYTES,
            ]);
        }

        $content = file_get_contents($absolute);

        if ($content === false) {
            throw new CustomAssetOperationException('custom_assets.errors.read_failed', ['path' => $relative]);
        }

        return ['path' => $relative, 'content' => $content, 'size' => $size];
    }

    /**
     * 텍스트 파일 본문을 저장합니다 (없으면 생성).
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @param  string  $relative  `custom/` 기준 상대 경로
     * @param  string  $content  본문
     * @return array<string, mixed> 저장된 파일 정보
     *
     * @throws CustomAssetOperationException 경로 무효·쓰기 실패 시
     */
    public function save(string $extensionType, string $identifier, string $relative, string $content): array
    {
        $absolute = $this->resolveWritable($extensionType, $identifier, $relative);

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

        if (! in_array($extension, self::EDITABLE_EXTENSIONS, true)) {
            throw new CustomAssetOperationException('custom_assets.errors.not_editable', ['extension' => $extension]);
        }

        if (strlen($content) > self::MAX_TEXT_BYTES) {
            throw new CustomAssetOperationException('custom_assets.errors.too_large_to_edit', [
                'limit' => (string) self::MAX_TEXT_BYTES,
            ]);
        }

        $this->ensureDirectory(dirname($absolute));

        if (file_put_contents($absolute, $content) === false) {
            throw new CustomAssetOperationException('custom_assets.errors.write_failed', ['path' => $relative]);
        }

        $this->invalidate($extensionType, $identifier, 'save', $relative);

        return [
            'path' => $relative,
            'size' => strlen($content),
            'modified_at' => date('c'),
        ];
    }

    /**
     * 업로드 파일을 `custom/` 에 저장합니다.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @param  UploadedFile  $file  업로드 파일
     * @param  string|null  $directory  `custom/` 기준 하위 디렉토리 (선택)
     * @return array<string, mixed> 저장된 파일 정보
     *
     * @throws CustomAssetOperationException 경로 무효·확장자 불허·쓰기 실패 시
     */
    public function upload(
        string $extensionType,
        string $identifier,
        UploadedFile $file,
        ?string $directory = null
    ): array {
        $name = $this->sanitizeFileName($file->getClientOriginalName());
        $relative = $directory !== null && $directory !== '' ? trim($directory, '/').'/'.$name : $name;

        $absolute = $this->resolveWritable($extensionType, $identifier, $relative);

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (! in_array($extension, AllowedTemplateFileType::getAllowedExtensions(), true)) {
            throw new CustomAssetOperationException('custom_assets.errors.extension_not_allowed', [
                'extension' => $extension,
            ]);
        }

        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw new CustomAssetOperationException('custom_assets.errors.upload_too_large', [
                'limit' => (string) self::MAX_UPLOAD_BYTES,
            ]);
        }

        $this->ensureDirectory(dirname($absolute));
        $file->move(dirname($absolute), basename($absolute));

        $this->invalidate($extensionType, $identifier, 'upload', $relative);

        return [
            'path' => $relative,
            'size' => (int) @filesize($absolute),
            'modified_at' => date('c'),
        ];
    }

    /**
     * 파일을 삭제합니다.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @param  string  $relative  `custom/` 기준 상대 경로
     * @return void 반환값 없음
     *
     * @throws CustomAssetOperationException 파일 부재·삭제 실패 시
     */
    public function delete(string $extensionType, string $identifier, string $relative): void
    {
        $absolute = $this->resolveExisting($extensionType, $identifier, $relative);

        if (! @unlink($absolute)) {
            throw new CustomAssetOperationException('custom_assets.errors.delete_failed', ['path' => $relative]);
        }

        $this->pruneEmptyFolders($extensionType, $identifier, dirname($absolute));
        $this->invalidate($extensionType, $identifier, 'delete', $relative);
    }

    /**
     * 파일 이름을 바꿉니다 (= 이동).
     *
     * 원자 연산(`rename`)이다 — 복사 후 삭제로 구현하면 중간에 실패했을 때 같은 내용의 파일이
     * 두 벌 남거나 원본만 사라진다.
     *
     * 대상 경로에 이미 파일이 있으면 **덮어쓰지 않고 막는다**. `rename()` 은 대상이 있어도
     * 조용히 덮어쓰므로, 그대로 두면 운영자가 올린 다른 파일이 경고 없이 사라진다.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @param  string  $from  현재 `custom/` 기준 상대 경로
     * @param  string  $to  새 `custom/` 기준 상대 경로
     * @return array<string, mixed> 바뀐 파일 정보
     *
     * @throws CustomAssetOperationException 원본 부재·대상 충돌·쓰기 실패 시
     */
    public function rename(string $extensionType, string $identifier, string $from, string $to): array
    {
        $source = $this->resolveExisting($extensionType, $identifier, $from);
        $target = $this->resolveWritable($extensionType, $identifier, $to);

        if ($source === $target) {
            return ['path' => $to, 'size' => (int) @filesize($source), 'modified_at' => date('c')];
        }

        if (file_exists($target)) {
            throw new CustomAssetOperationException('custom_assets.errors.rename_target_exists', ['path' => $to]);
        }

        // 확장자가 바뀌면 서빙 규약과 편집 가능 여부가 함께 바뀐다 — `style.css` 를 `style.txt`
        // 로 바꾸면 그 파일은 더 이상 실리지 않는데 화면에는 그대로 보인다.
        $sourceExtension = strtolower(pathinfo($from, PATHINFO_EXTENSION));
        $targetExtension = strtolower(pathinfo($to, PATHINFO_EXTENSION));

        if ($sourceExtension !== $targetExtension) {
            throw new CustomAssetOperationException('custom_assets.errors.rename_extension_changed', [
                'from' => $sourceExtension,
                'to' => $targetExtension,
            ]);
        }

        $this->ensureDirectory(dirname($target));

        if (! @rename($source, $target)) {
            throw new CustomAssetOperationException('custom_assets.errors.write_failed', ['path' => $to]);
        }

        $this->pruneEmptyFolders($extensionType, $identifier, dirname($source));
        $this->invalidate($extensionType, $identifier, 'rename', $to);

        return [
            'path' => $to,
            'previous_path' => $from,
            'size' => (int) @filesize($target),
            'modified_at' => date('c'),
        ];
    }

    /**
     * 파일명·본문에서 검색어를 찾습니다.
     *
     * 본문을 여는 대상은 {@see EDITABLE_EXTENSIONS} 뿐이다 — 폰트·이미지를 텍스트로 훑으면
     * 바이너리가 우연히 검색어와 일치해 쓸모없는 결과가 섞이고, 큰 파일을 통째로 메모리에 올린다.
     * 파일명 매칭은 확장자와 무관하게 모든 파일을 대상으로 한다.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @param  string  $keyword  검색어
     * @return array<int, array<string, mixed>> 검색 결과 (파일별 매치)
     */
    public function search(string $extensionType, string $identifier, string $keyword): array
    {
        $needle = mb_strtolower($keyword);

        if ($needle === '') {
            return [];
        }

        $directory = CustomAssets::directory($extensionType, $identifier);

        if ($directory === null || ! is_dir($directory)) {
            return [];
        }

        $results = [];

        foreach ($this->list($extensionType, $identifier) as $file) {
            $relative = (string) ($file['path'] ?? '');

            if ($relative === '') {
                continue;
            }

            $nameMatched = str_contains(mb_strtolower($relative), $needle);
            $lines = [];

            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            $size = (int) ($file['size'] ?? 0);

            if (in_array($extension, self::EDITABLE_EXTENSIONS, true) && $size <= self::MAX_TEXT_BYTES) {
                $lines = $this->scanFileLines($directory.DIRECTORY_SEPARATOR.$relative, $needle);
            }

            if (! $nameMatched && $lines === []) {
                continue;
            }

            $results[] = [
                'path' => $relative,
                'name_matched' => $nameMatched,
                'match_count' => count($lines),
                'lines' => $lines,
            ];
        }

        return $results;
    }

    /**
     * 한 파일을 줄 단위로 훑어 매치를 모읍니다.
     *
     * @param  string  $absolute  절대 경로
     * @param  string  $needle  소문자 검색어
     * @return array<int, array{line: int, column: int, preview: string}> 매치 목록
     */
    private function scanFileLines(string $absolute, string $needle): array
    {
        if (! is_file($absolute) || ! is_readable($absolute)) {
            return [];
        }

        $content = @file_get_contents($absolute);

        if ($content === false) {
            return [];
        }

        $matches = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $content)) as $index => $line) {
            if (count($matches) >= self::SEARCH_MAX_MATCHES_PER_FILE) {
                break;
            }

            $column = mb_strpos(mb_strtolower($line), $needle);

            if ($column === false) {
                continue;
            }

            $preview = trim($line);

            if (mb_strlen($preview) > self::SEARCH_PREVIEW_LENGTH) {
                $preview = mb_substr($preview, 0, self::SEARCH_PREVIEW_LENGTH).'…';
            }

            $matches[] = ['line' => $index + 1, 'column' => $column + 1, 'preview' => $preview];
        }

        return $matches;
    }

    /**
     * 쓰기 뒤 캐시·게시본을 무효화합니다.
     *
     * 확장 캐시 버전을 올리면 그 단일 지점이 정적 재게시까지 예약한다 — 편집한 파일이
     * 게시본에 반영되는 경로가 여기 한 곳으로 모인다.
     *
     * 변경 감지 서명은 **지운다**. 뷰 컴포저가 다음 렌더에서 같은 변경을 다시 발견해
     * 버전을 한 번 더 올리는 것을 막기 위해서다 (서명이 없으면 그 관측은 기록만 한다).
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @param  string  $operation  수행한 작업 (로그용)
     * @param  string  $relative  대상 상대 경로 (로그용)
     * @return void 반환값 없음
     */
    private function invalidate(string $extensionType, string $identifier, string $operation, string $relative): void
    {
        CustomAssets::flushCache();

        try {
            // 서명을 쓰는 뷰 컴포저와 **같은 통로**(고정 스토어·코어 네임스페이스)로 지운다 —
            // 다른 통로로 지우면 그쪽 서명이 남아 다음 렌더가 같은 변경을 한 번 더 bump 한다.
            self::customSignatureCache()->forget(CustomAssets::SIGNATURE_CACHE_KEY);
        } catch (\Exception $e) {
            Log::warning('사용자 추가 에셋 서명 캐시 삭제 실패', ['error' => $e->getMessage()]);
        }

        $this->incrementExtensionCacheVersion();

        Log::info('사용자 추가 에셋 변경', [
            'extension_type' => $extensionType,
            'identifier' => $identifier,
            'operation' => $operation,
            'path' => $relative,
        ]);

        // 운영자가 올린 스크립트는 사이트 전 화면에서 실행된다 — 누가 언제 무엇을 바꿨는지
        // 남지 않으면 사후에 되짚을 수단이 없다. 활동 로그가 그 유일한 기록이다.
        HookManager::doAction('core.custom_assets.after_change', $extensionType, $identifier, $operation, $relative);
    }

    /**
     * 존재하는 파일의 절대 경로를 해석합니다.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @param  string  $relative  상대 경로
     * @return string 절대 경로
     *
     * @throws CustomAssetOperationException 경로 무효·파일 부재 시
     */
    private function resolveExisting(string $extensionType, string $identifier, string $relative): string
    {
        $absolute = $this->resolveWritable($extensionType, $identifier, $relative);

        if (! is_file($absolute)) {
            throw new CustomAssetOperationException('custom_assets.errors.not_found', ['path' => $relative]);
        }

        return $absolute;
    }

    /**
     * 쓰기 대상 절대 경로를 해석합니다 (아직 없어도 됩니다).
     *
     * 컨테인먼트는 문자열 접두 비교가 아니라 세그먼트 검사로 판정한다. 아직 없는
     * 파일은 `realpath` 가 실패하므로 실경로 정규화에 기댈 수 없고, 접두 비교만으로는
     * `custom-evil/` 같은 형제 디렉토리가 통과한다.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @param  string  $relative  상대 경로
     * @return string 절대 경로
     *
     * @throws CustomAssetOperationException 경로가 무효한 경우
     */
    private function resolveWritable(string $extensionType, string $identifier, string $relative): string
    {
        $directory = CustomAssets::directory($extensionType, $identifier);

        if ($directory === null) {
            throw new CustomAssetOperationException('custom_assets.errors.invalid_extension_target', [
                'identifier' => $identifier,
            ]);
        }

        $normalized = str_replace('\\', '/', trim($relative));

        if ($normalized === '' || str_starts_with($normalized, '/')) {
            throw new CustomAssetOperationException('custom_assets.errors.invalid_path', ['path' => $relative]);
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new CustomAssetOperationException('custom_assets.errors.invalid_path', ['path' => $relative]);
            }
        }

        return $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    }

    /**
     * 업로드 파일명을 안전한 형태로 정규화합니다.
     *
     * @param  string  $name  원본 파일명
     * @return string 정규화된 파일명
     */
    private function sanitizeFileName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));

        return preg_replace('/[^A-Za-z0-9._-]/', '_', $base) ?? '';
    }

    /**
     * 파일을 지우거나 옮겨 비게 된 폴더를 `custom/` 바로 아래까지 거슬러 올라가며 지웁니다.
     *
     * 목록은 파일만 보여 주므로 빈 폴더는 화면에 보이지 않은 채 디스크에 남는다(2026-10-06 실측). `custom/` 자체와
     * 그 바깥은 건드리지 않고, 무엇이든 들어 있는 폴더(숨김 파일 포함)에서 멈춘다. 지우지 못해도 파일 작업은 이미
     * 끝났으므로 예외를 내지 않는다.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @param  string  $directory  비었을 수 있는 폴더 절대 경로
     * @return void 반환값 없음
     */
    private function pruneEmptyFolders(string $extensionType, string $identifier, string $directory): void
    {
        $root = CustomAssets::directory($extensionType, $identifier);
        if ($root === null) {
            return;
        }

        $root = rtrim(str_replace('\\', '/', $root), '/');
        $current = rtrim(str_replace('\\', '/', $directory), '/');

        while ($current !== $root && str_starts_with($current, $root.'/') && is_dir($current)) {
            $entries = @scandir($current);
            if ($entries === false || array_diff($entries, ['.', '..']) !== []) {
                return;
            }

            if (! @rmdir($current)) {
                return;
            }

            $current = rtrim(str_replace('\\', '/', dirname($current)), '/');
        }
    }

    /**
     * 디렉토리를 보장합니다.
     *
     * @param  string  $directory  절대 경로
     * @return void 반환값 없음
     *
     * @throws CustomAssetOperationException 생성 실패 시
     */
    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        // 확보는 코어 공통 프리미티브가 맡는다 — 소유권 상속·그룹 쓰기까지 정합화하고, 실패는 예외가 아니라
        // 사유로 올라온다. `custom/` 은 지연 생성이라 `deploy:deploy 0755` 로 배포된 확장 디렉토리에서
        // 첫 저장이 여기서 실패하는데, 경로만 적으면 운영자가 무엇을 고쳐야 하는지 알 수 없다(#651 D6).
        // 사유·소유자·권한·실행 계정·조치 예시를 함께 싣는다 (정적 게시 프리플라이트와 같은 식).
        if (FilePermissionHelper::ensureWritableDirectory($directory, 0775, $failure)) {
            return;
        }

        $failedPath = (string) ($failure['path'] ?? $directory);
        $reason = (string) ($failure['reason'] ?? 'create_failed');

        throw new CustomAssetOperationException('custom_assets.errors.directory_failed', [
            'path' => $directory,
            'reason' => __('custom_assets.errors.reason.'.$reason),
            'owner' => (string) (@fileowner($failedPath) ?: 'unknown'),
            'perms' => file_exists($failedPath) ? substr(sprintf('%o', @fileperms($failedPath)), -4) : 'absent',
            'process_user' => ExtensionStaticCacheService::currentProcessUser(),
            'hint' => __('custom_assets.errors.directory_failed_hint', ['path' => $failedPath]),
        ]);
    }
}
