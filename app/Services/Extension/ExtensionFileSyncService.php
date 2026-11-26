<?php

namespace App\Services\Extension;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Exceptions\ExtensionOperationLockException;
use App\Extension\ExtensionManager;
use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Services\LanguagePackService;
use App\Support\RouteCacheHelper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 같은 판인데 파일이 다른 확장의 활성 폴더를 번들 폴더 내용으로 맞춥니다 (`extension:reset-files-from-bundled`).
 *
 * 활성 폴더(`modules/<id>` 등)가 옛 파일에 머무는 경우가 있다 — 판 번호를 올리지 않은 번들 변경, DB 를 함께 쓰는 두 번째 서버.
 * 종전 수단(`module:update --force`)은 같은 판 업그레이드 스텝 재실행·코어 호환 검사 생략이 함께 묶여 있었다. 이 동기화는 파일만
 * 맞춘다 — 판 번호·업그레이드 스텝·레이아웃은 건드리지 않는다.
 *
 * 대상: 설치된 확장(모듈·플러그인·템플릿·언어팩) 중 DB 판 = 번들 판이고 내용 해시가 다른 것. 해시는 상대경로 정렬 + 파일 sha256
 * 합성이며 `vendor/`·`node_modules/`·`.git`·`custom/`(운영자 자리)는 뺀다. `dist/` 는 넣는다. 언어팩은 `lang-packs/_bundled/<id>` →
 * `lang-packs/<id>` 다 — 검수 서버가 먼저 새 판을 올려 공유 DB 판이 새 판이면 운영 서버의 `language-pack:update` 는 「이미 최신」
 * 으로 끝나므로, 운영 서버는 이 동기화로 새 언어팩 파일을 받는다.
 *
 * 모듈·플러그인·템플릿은 교체 직전 활성 폴더(이전 판)의 레이아웃을 컴파일해, 병합 기준(`original_content`)이 비어 있고 원본 해시가
 * 맞는 레이아웃 행에만 기준을 채운다 — 이어지는 keep 레이아웃 갱신이 운영자 수정본에 새 판을 병합할 수 있게. 레이아웃 본문·해시·
 * 잠금 판은 바꾸지 않는다.
 *
 * @since 7.2.0
 */
class ExtensionFileSyncService
{
    use ClearsTemplateCaches;

    /**
     * 해시에서 뺄 최상위·하위 디렉터리 이름.
     */
    private const EXCLUDED_DIRECTORIES = ['vendor', 'node_modules', '.git', 'custom'];

    /**
     * 동기화 대상 종류 (판정 순서).
     */
    public const TYPES = ['module', 'plugin', 'template', 'language-pack'];

    /**
     * @param  ModuleRepositoryInterface  $modules  모듈 저장소
     * @param  PluginRepositoryInterface  $plugins  플러그인 저장소
     * @param  TemplateRepositoryInterface  $templates  템플릿 저장소
     * @param  LanguagePackRepositoryInterface  $languagePacks  언어팩 저장소
     */
    public function __construct(
        private readonly ModuleRepositoryInterface $modules,
        private readonly PluginRepositoryInterface $plugins,
        private readonly TemplateRepositoryInterface $templates,
        private readonly LanguagePackRepositoryInterface $languagePacks,
    ) {}

    /**
     * 동기화 대상을 판정합니다 — 쓰기 없음.
     *
     * @param  string|null  $type  종류 (module|plugin|template|language-pack, null 이면 전부)
     * @param  string|null  $identifier  식별자 (null 이면 전부)
     * @return array<int, array{type: string, identifier: string, installed_version: string, bundled_version: string|null, status: string, active_hash: string|null, bundled_hash: string|null}> 판정 목록
     */
    public function plan(?string $type = null, ?string $identifier = null): array
    {
        $rows = [];
        foreach (self::TYPES as $kind) {
            if ($type !== null && $type !== $kind) {
                continue;
            }
            foreach ($this->installed($kind) as $id => $installedVersion) {
                if ($identifier !== null && $identifier !== $id) {
                    continue;
                }
                $rows[] = $this->judge($kind, $id, $installedVersion);
            }
        }

        return $rows;
    }

    /**
     * 한 확장의 활성 폴더를 번들 내용으로 교체합니다.
     *
     * 순서: 작업 잠금 → 레이아웃 병합 기준 채우기(교체 전 파일 기준) → 원자 교체(`ExtensionPendingHelper::copyToActive()`,
     * `custom/` 보존) → 오토로드·훅·config 캐시 → 라우트 캐시 → opcache → 확장 캐시 버전. `$migrate` 면 그 확장의 미실행
     * 마이그레이션만 실행한다. 언어팩은 작업 잠금 → 원자 교체 → opcache → 언어 캐시 무효화({@see syncLanguagePack()})다.
     *
     * @param  string  $type  종류 (module|plugin|template|language-pack)
     * @param  string  $identifier  식별자
     * @param  bool  $migrate  그 확장의 마이그레이션도 실행할지 (언어팩은 무시)
     * @return array{migrated: bool, layout_bases_filled: int} 결과
     *
     * @throws ExtensionOperationLockException 같은 확장의 작업이 진행 중일 때
     */
    public function sync(string $type, string $identifier, bool $migrate = false): array
    {
        if ($type === 'language-pack') {
            return $this->syncLanguagePack($identifier);
        }

        $lock = ExtensionOperationLock::acquire($type, $identifier, 'update');

        try {
            $basesFilled = $this->stashLayoutBases($type, $identifier);

            // 같은 판 번호로 번들 내용이 바뀌어 리스너 클래스가 지워졌어도, 교체~훅 캐시 재생성 사이 요청이 옛 클래스를 찾을 수 있게 남겨 둔다
            $carried = ExtensionPendingHelper::copyToActive($this->bundledPath($type, $identifier), $this->activePath($type, $identifier), null, retainRemovedClassFiles: true);

            app(ExtensionManager::class)->updateComposerAutoload();
            app(ExtensionManager::class)->finishCarriedOverClassFiles($this->activePath($type, $identifier), $carried);
            RouteCacheHelper::rebuild();
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            $this->incrementExtensionCacheVersion();

            $migrated = false;
            if ($migrate && $type !== 'template') {
                $path = $this->activePath($type, $identifier).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
                if (File::isDirectory($path)) {
                    Artisan::call('migrate', ['--path' => $this->relativeToBase($path), '--force' => true]);
                    $migrated = true;
                }
            }

            Log::info('확장 활성 폴더 파일 동기화', ['type' => $type, 'identifier' => $identifier, 'migrated' => $migrated, 'layout_bases_filled' => $basesFilled]);

            return ['migrated' => $migrated, 'layout_bases_filled' => $basesFilled];
        } finally {
            $lock->release();
        }
    }

    /**
     * 언어팩 활성 폴더를 번들 내용으로 교체합니다 — 판 번호·DB 상태는 그대로다.
     *
     * 교체 뒤 opcache 를 비우고 `LanguagePackService::refreshCache()` 로 번역 레지스트리·번역기 적재분을 비우고 확장 캐시 버전을
     * 올린다 — 화면 언어 파일 캐시 키(`template.language.*.v{n}`)가 새 판 번호로 바뀌어 새 번역을 받는다.
     *
     * @param  string  $identifier  언어팩 식별자
     * @return array{migrated: bool, layout_bases_filled: int} 결과
     *
     * @throws ExtensionOperationLockException 같은 언어팩의 작업이 진행 중일 때
     */
    private function syncLanguagePack(string $identifier): array
    {
        $lock = ExtensionOperationLock::acquire('language_pack', $identifier, 'update');

        try {
            ExtensionPendingHelper::copyToActive($this->bundledPath('language-pack', $identifier), $this->activePath('language-pack', $identifier));

            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            app(LanguagePackService::class)->refreshCache();

            Log::info('언어팩 활성 폴더 파일 동기화', ['identifier' => $identifier]);

            return ['migrated' => false, 'layout_bases_filled' => 0];
        } finally {
            $lock->release();
        }
    }

    /**
     * 교체 직전 활성 폴더(이전 판)의 레이아웃으로 비어 있는 병합 기준을 채웁니다.
     *
     * DB 를 함께 쓰는 검수 서버가 먼저 업데이트하면 그 서버의 업데이트 백업이 채운 기준은 검수 서버 표에만 남는다. 운영 서버 표의
     * 오래된 행(`original_content` 가 빈 행)은 keep 갱신에서 기준이 없어 새 판을 받지 못했다(`kept_no_base`). 이 서버의 활성 폴더는
     * 교체 전까지 이전 판이므로 업데이트 백업과 같은 기준이 된다. 채우는 규칙은 꺼 둔 확장 업데이트와 같다
     * ({@see LayoutRowSynchronizer::stashBaseForInactiveUpdate()} — 원본 해시가 맞는 행에만, 본문·해시·잠금 판은 그대로).
     *
     * 레이아웃 폴더만 임시 폴더로 복사해 읽는다 — 백업 읽기 결과는 경로별로 프로세스에 남으므로 교체될 활성 경로를 직접 넘기지 않는다.
     * 실패해도 파일 교체는 막지 않는다(기준 없이 종전처럼 진행, 경고 로그).
     *
     * @param  string  $type  종류 (module|plugin|template)
     * @param  string  $identifier  식별자
     * @return int 기준을 채운 행 수
     */
    private function stashLayoutBases(string $type, string $identifier): int
    {
        $active = $this->activePath($type, $identifier);
        $relativeDirs = $type === 'template'
            ? ['layouts']
            : ['resources'.DIRECTORY_SEPARATOR.'layouts', 'resources'.DIRECTORY_SEPARATOR.'extensions'];
        $present = array_values(array_filter($relativeDirs, fn (string $dir) => File::isDirectory($active.DIRECTORY_SEPARATOR.$dir)));
        if ($present === []) {
            return 0;
        }

        $temp = storage_path('app/temp/layout_base_'.Str::uuid()->toString());

        try {
            ExtensionPendingHelper::ensureUpdateTempDirectory($temp);
            foreach ($present as $dir) {
                File::copyDirectory($active.DIRECTORY_SEPARATOR.$dir, $temp.DIRECTORY_SEPARATOR.$dir);
            }

            return app(LayoutRowSynchronizer::class)->stashBaseForInactiveUpdate($temp, $type.'s', $identifier);
        } catch (\Throwable $e) {
            Log::warning('파일 동기화 전 레이아웃 병합 기준 채우기 실패 — 기준 없이 진행합니다', ['type' => $type, 'identifier' => $identifier, 'error' => $e->getMessage()]);

            return 0;
        } finally {
            File::deleteDirectory($temp);
        }
    }

    /**
     * 디렉터리 내용 해시 — 상대경로 정렬 + 파일 sha256 합성.
     *
     * @param  string  $directory  디렉터리 절대 경로
     * @return string|null 해시 (디렉터리가 없으면 null)
     */
    public function contentHash(string $directory): ?string
    {
        if (! File::isDirectory($directory)) {
            return null;
        }

        $entries = [];
        $base = rtrim(str_replace('\\', '/', $directory), '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                fn (\SplFileInfo $file) => ! ($file->isDir() && in_array($file->getFilename(), self::EXCLUDED_DIRECTORIES, true)),
            ),
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($base) + 1);
            $entries[$relative] = (string) hash_file('sha256', $file->getPathname());
        }
        ksort($entries, SORT_STRING);

        return hash('sha256', implode("\n", array_map(fn ($path, $hash) => $path.':'.$hash, array_keys($entries), $entries)));
    }

    /**
     * 한 확장의 판정.
     *
     * @param  string  $type  종류
     * @param  string  $identifier  식별자
     * @param  string  $installedVersion  DB 판
     * @return array{type: string, identifier: string, installed_version: string, bundled_version: string|null, status: string, active_hash: string|null, bundled_hash: string|null} 판정
     */
    private function judge(string $type, string $identifier, string $installedVersion): array
    {
        $bundledVersion = $this->bundledVersion($type, $identifier);
        $row = ['type' => $type, 'identifier' => $identifier, 'installed_version' => $installedVersion, 'bundled_version' => $bundledVersion, 'status' => 'no_bundled', 'active_hash' => null, 'bundled_hash' => null];

        if ($bundledVersion === null) {
            return $row;
        }
        if ($bundledVersion !== $installedVersion) {
            return ['status' => 'version_differs'] + $row;
        }

        $row['active_hash'] = $this->contentHash($this->activePath($type, $identifier));
        $row['bundled_hash'] = $this->contentHash($this->bundledPath($type, $identifier));
        $row['status'] = $row['active_hash'] === $row['bundled_hash'] ? 'in_sync' : 'differs';

        return $row;
    }

    /**
     * 설치된 확장 식별자 → DB 판.
     *
     * @param  string  $type  종류
     * @return array<string, string> 식별자 → 판
     */
    private function installed(string $type): array
    {
        $rows = match ($type) {
            'module' => $this->modules->getAll(),
            'plugin' => $this->plugins->getAll(),
            'template' => $this->templates->getAll(),
            'language-pack' => $this->languagePacks->allForUpdateCheck(),
        };

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->identifier] = (string) $row->version;
        }
        ksort($out);

        return $out;
    }

    /**
     * 번들 manifest 의 판.
     *
     * @param  string  $type  종류
     * @param  string  $identifier  식별자
     * @return string|null 판 (번들이 없으면 null)
     */
    private function bundledVersion(string $type, string $identifier): ?string
    {
        $manifest = $this->bundledPath($type, $identifier).DIRECTORY_SEPARATOR.$type.'.json';
        if (! File::isFile($manifest)) {
            return null;
        }
        $decoded = json_decode((string) File::get($manifest), true);

        return is_array($decoded) && is_string($decoded['version'] ?? null) ? $decoded['version'] : null;
    }

    /**
     * 종류별 기본 경로.
     *
     * @param  string  $type  종류
     * @return string 절대 경로
     */
    private function basePath(string $type): string
    {
        return $type === 'language-pack' ? base_path('lang-packs') : base_path($type.'s');
    }

    /**
     * 번들 폴더 경로.
     *
     * @param  string  $type  종류
     * @param  string  $identifier  식별자
     * @return string 절대 경로
     */
    public function bundledPath(string $type, string $identifier): string
    {
        return ExtensionPendingHelper::getBundledPath($this->basePath($type), $identifier);
    }

    /**
     * 활성 폴더 경로.
     *
     * @param  string  $type  종류
     * @param  string  $identifier  식별자
     * @return string 절대 경로
     */
    public function activePath(string $type, string $identifier): string
    {
        return $this->basePath($type).DIRECTORY_SEPARATOR.$identifier;
    }

    /**
     * 저장소 기준 상대 경로.
     *
     * @param  string  $path  절대 경로
     * @return string 상대 경로
     */
    private function relativeToBase(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return ltrim(str_replace($base, '', str_replace('\\', '/', $path)), '/');
    }
}
