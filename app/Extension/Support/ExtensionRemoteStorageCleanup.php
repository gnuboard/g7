<?php

namespace App\Extension\Support;

use App\Contracts\Extension\StorageInterface;
use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\Storage\ModuleStorageDriver;
use App\Extension\Storage\PluginStorageDriver;
use App\Support\ExtensionStoragePath;
use App\Support\PrivateAttachmentDisk;
use App\Support\PublicAssetDisk;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 확장 「데이터까지 삭제」 때 기본 디스크 밖 저장소의 확장 폴더 정리 (#916 D28)
 *
 * 매니저의 저장소 삭제는 기본 디스크(모듈 `modules`, 플러그인 `plugins`)의 로컬 폴더만 지운다. 비공개 원본은 관리자가 고른
 * 「비공개 첨부 저장소」(또는 확장의 `.env` 고정 디스크)에, 공개 이미지는 공개 자산 디스크에 쌓이므로 그 디스크가 기본과 다르면
 * 같은 경로 규칙(`{identifier}/{category}/…`)으로 거기 있는 확장 폴더도 지운다.
 *
 * - 디스크 판정은 지우기 전에 한다 — 확장 개별 공개 자산 설정은 확장 저장 폴더의 settings 에 있어 로컬 폴더를 지운 뒤에는 읽을 수 없다.
 * - 확장 인스턴스가 추상 기반 클래스가 아니어도(비활성·고아 확장의 최소 인스턴스) 코어 선택값과 기본 디스크 이름으로 판정한다.
 * - 같은 디스크는 한 번만 지우고, `filesystems.disks` 에 없는 디스크(디스크를 등록한 플러그인이 꺼짐)는 건너뛴다.
 * - 실패는 제거를 막지 않는다 — 디스크마다 수명주기 경고 1건(`cleanup`)을 남기고 다음 디스크로 넘어간다.
 *
 * @since 7.2.0
 */
final class ExtensionRemoteStorageCleanup
{
    /** 공개 자산 디스크를 따르는 것으로 알려진 카테고리 — 로컬 폴더에 없어도 판정한다 */
    private const KNOWN_CATEGORIES = ['images', 'attachments'];

    /**
     * 기본 디스크와 다른, 이 확장의 파일이 있을 수 있는 디스크 목록을 판정합니다.
     *
     * 후보: 확장의 비공개 원본 디스크(env 고정 포함), 관리자가 고른 비공개 첨부 저장소, 확장 카테고리별 디스크(로컬 폴더에 있는
     * 카테고리와 `images`·`attachments`), 코어 공개 자산 디스크.
     *
     * @param  object  $extension  확장 인스턴스 (ModuleInterface 또는 PluginInterface)
     * @param  string  $type  확장 종류 (`module` | `plugin`)
     * @return array<int, string> 지울 디스크 이름 (중복 없음, 기본 디스크 제외)
     */
    public static function resolveDisks(object $extension, string $type): array
    {
        $base = self::baseDisk($extension, $type);
        $candidates = [];

        $candidates[] = self::attempt(function () use ($extension, $base) {
            if ($extension instanceof AbstractModule) {
                return $extension->getStorageDiskFor(AbstractModule::PRIVATE_ATTACHMENT_BINDING);
            }
            if ($extension instanceof AbstractPlugin) {
                return $extension->getStorageDiskFor(AbstractPlugin::PRIVATE_ATTACHMENT_BINDING);
            }

            return PrivateAttachmentDisk::forExtension($base);
        });
        $candidates[] = self::attempt(fn () => PrivateAttachmentDisk::resolve());
        $candidates[] = self::attempt(fn () => PublicAssetDisk::resolve());

        if ($extension instanceof AbstractModule || $extension instanceof AbstractPlugin) {
            foreach (self::categories((string) $extension->getIdentifier(), $type) as $category) {
                $candidates[] = self::attempt(fn () => $extension->getStorageDiskFor($category));
            }
        }

        $disks = [];
        foreach ($candidates as $disk) {
            if (! is_string($disk) || $disk === '' || $disk === $base || in_array($disk, $disks, true)) {
                continue;
            }
            if (config("filesystems.disks.{$disk}") === null) {
                continue;
            }
            $disks[] = $disk;
        }

        return $disks;
    }

    /**
     * 판정한 디스크마다 확장 폴더를 지웁니다.
     *
     * 확장 스토리지 드라이버를 그 디스크로 바꿔(`withDisk`) 카테고리 단위로 `deleteAll` 한다 — 경로 규칙과 경로 검사가 업로드 때와 같다.
     * 카테고리를 다 지운 뒤 빈 확장 폴더도 치운다(디렉터리 개념이 있는 디스크). 남은 객체가 있거나 예외가 나면 경고 1건.
     *
     * @param  object  $extension  확장 인스턴스 (ModuleInterface 또는 PluginInterface)
     * @param  string  $type  확장 종류 (`module` | `plugin`)
     * @param  array<int, string>  $disks  {@see self::resolveDisks()} 결과
     * @return void 반환값 없음
     */
    public static function purge(object $extension, string $type, array $disks): void
    {
        if ($disks === []) {
            return;
        }

        $identifier = (string) $extension->getIdentifier();
        $driver = self::driver($extension, $type);

        foreach ($disks as $disk) {
            try {
                // audit:allow no-storage-disk-direct reason: 제거 정리는 확장이 쓴 카테고리를 미리 알 수 없어 디스크의 확장 폴더 목록을 직접 읽는다 — StorageInterface 에 카테고리 열거가 없고, 지우기는 아래 StorageInterface::deleteAll 로 한다
                $filesystem = Storage::disk($disk);
                $storage = $driver->withDisk($disk);

                foreach ($filesystem->directories($identifier) as $directory) {
                    $storage->deleteAll(basename($directory));
                }

                if ($filesystem->allFiles($identifier) !== []) {
                    Log::error('원격 디스크의 확장 폴더를 모두 삭제하지 못했습니다', [
                        $type => $identifier,
                        'disk' => $disk,
                    ]);
                    LifecycleWarnings::current()->add('cleanup', $identifier, __('extensions.warnings.storage_cleanup_incomplete'));

                    continue;
                }

                // 디렉터리가 실재하는 디스크(local 드라이버 등)에 빈 확장 폴더가 남지 않게 한다 — 객체 저장소에서는 할 일이 없다
                if ($filesystem->directories($identifier) === []) {
                    $filesystem->deleteDirectory($identifier);
                }

                Log::info('원격 디스크의 확장 폴더 삭제 완료', [$type => $identifier, 'disk' => $disk]);
            } catch (\Throwable $e) {
                Log::error('원격 디스크의 확장 폴더 삭제 실패', [
                    $type => $identifier,
                    'disk' => $disk,
                    'error' => $e->getMessage(),
                ]);
                LifecycleWarnings::current()->add('cleanup', $identifier, $e->getMessage());
            }
        }
    }

    /**
     * 확장의 기본 디스크 이름을 반환합니다.
     *
     * @param  object  $extension  확장 인스턴스
     * @param  string  $type  확장 종류
     * @return string 기본 디스크 이름
     */
    private static function baseDisk(object $extension, string $type): string
    {
        if ($extension instanceof AbstractModule || $extension instanceof AbstractPlugin) {
            $disk = self::attempt(fn () => $extension->getStorageDisk());
            if (is_string($disk) && $disk !== '') {
                return $disk;
            }
        }

        return $type === 'module' ? 'modules' : 'plugins';
    }

    /**
     * 판정할 카테고리 목록 — 로컬 저장 폴더의 1단계 하위 폴더와 알려진 카테고리.
     *
     * @param  string  $identifier  확장 식별자
     * @param  string  $type  확장 종류
     * @return array<int, string> 카테고리 이름
     */
    private static function categories(string $identifier, string $type): array
    {
        $root = $type === 'module' ? ExtensionStoragePath::module($identifier) : ExtensionStoragePath::plugin($identifier);
        $categories = self::KNOWN_CATEGORIES;

        if (File::isDirectory($root)) {
            foreach (File::directories($root) as $directory) {
                $categories[] = basename($directory);
            }
        }

        return array_values(array_unique($categories));
    }

    /**
     * 경로 규칙을 가진 확장 스토리지 드라이버를 반환합니다.
     *
     * @param  object  $extension  확장 인스턴스
     * @param  string  $type  확장 종류
     * @return StorageInterface 기본 디스크 드라이버 (호출측이 withDisk 로 바꾼다)
     */
    private static function driver(object $extension, string $type): StorageInterface
    {
        if ($extension instanceof AbstractModule || $extension instanceof AbstractPlugin) {
            return $extension->getStorage();
        }

        $identifier = (string) $extension->getIdentifier();

        return $type === 'module'
            ? new ModuleStorageDriver($identifier, 'modules')
            : new PluginStorageDriver($identifier, 'plugins');
    }

    /**
     * 판정 하나를 실행하고 실패하면 null 을 돌려줍니다 — 판정 실패가 제거를 막지 않게 한다.
     *
     * @param  \Closure  $resolver  판정
     * @return mixed 판정 결과 (실패 시 null)
     */
    private static function attempt(\Closure $resolver): mixed
    {
        try {
            return $resolver();
        } catch (\Throwable $e) {
            Log::warning('확장 원격 저장소 디스크 판정 실패', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
