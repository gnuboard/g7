<?php

namespace Plugins\Sirsoft\Ckeditor5\Services;

use App\Contracts\Extension\StorageInterface;
use App\Helpers\InlineMediaPolicy;
use App\Thumbnails\DTO\ThumbnailSource;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CKEditor5 이미지 서빙 서비스
 *
 * 업로드된 이미지를 hash로 조회하여 StreamedResponse를 반환합니다.
 */
class ImageServeService
{
    /**
     * 본문 이미지 서빙의 캐시 유지 시간 (초) — 1년.
     *
     * 이 URL 에는 자산 버전(`?v=`)이 없어 `immutable` 은 붙이지 않는다 — 같은 주소의
     * 내용이 교체될 수 있는 자산이라, 붙이면 교체한 파일이 만료 전까지 옛것으로 남는다.
     */
    public const CACHE_MAX_AGE = 31536000;

    /**
     * @param  ImageUploadRepositoryInterface  $repository  이미지 업로드 리포지토리
     * @param  StorageInterface  $storage  플러그인 스토리지 드라이버
     */
    public function __construct(
        protected ImageUploadRepositoryInterface $repository,
        protected StorageInterface $storage
    ) {}

    /**
     * hash로 이미지 조회
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @return Ckeditor5ImageUpload|null
     */
    public function findByHash(string $hash): ?Ckeditor5ImageUpload
    {
        return $this->repository->findByHash($hash);
    }

    /**
     * 조건부 요청 판정에 쓰는 검증자 소스를 행 메타에서 만듭니다.
     *
     * 파일 stat 이 아니라 **행 메타**(disk·path·updated_at·size)로 만든다 — 원격 디스크
     * (S3 등)에서도 결정적이고, 코어 형제(`BaseApiController::streamedFileResponse()`)가
     * 쓰는 소스 구성과 같다. 컨트롤러가 이 값을 그 헬퍼에 넘겨 `If-None-Match` /
     * `If-Modified-Since` 를 판정한다.
     *
     * @param  Ckeditor5ImageUpload  $image  이미지 업로드 행
     * @return array{etag_source: string, last_modified: int|null} ETag 소스와 수정 시각(Unix time)
     */
    public function cacheValidators(Ckeditor5ImageUpload $image): array
    {
        $lastModifiedAt = $image->updated_at?->getTimestamp();

        return [
            'etag_source' => implode('|', [
                (string) $image->storage_disk,
                (string) $image->file_path,
                (string) $lastModifiedAt,
                (string) $image->file_size,
            ]),
            'last_modified' => $lastModifiedAt,
        ];
    }

    /**
     * 이미지 스트림 응답 생성
     *
     * 원본 응답만 만든다. `?size=`·`?w=` 썸네일은 컨트롤러(`ImageServeController::serve`)가 이 메서드를
     * 부르기 전에 `ThumbnailResponder` 로 먼저 판정한다 — 원본 응답의 304 판정도 컨트롤러가 하므로
     * 썸네일 응답이 그 판정에 다시 싸이지 않게 한 곳에서 갈라 둔다.
     *
     * @param  Ckeditor5ImageUpload  $image  이미지 업로드 모델
     * @return StreamedResponse|null 이미지 스트림 또는 스토리지 파일 없을 경우 null
     */
    public function serve(Ckeditor5ImageUpload $image): ?StreamedResponse
    {
        [$category, $relativePath] = $this->splitFilePath($image);

        if ($category === '' || $relativePath === '') {
            return null;
        }

        $storage = $this->storageFor($image);

        // 검증자(ETag · Last-Modified)를 함께 싣는다. 종전에는 `max-age=31536000` 만 있고
        // 검증자가 하나도 없었다 — 이 URL 에는 `?v=` 가 없으므로 **재검증 자체가 불가능**해
        // 운영자가 본문 이미지를 갈아 끼워도 방문자는 최대 1년간 옛것을 보고, 만료 뒤에는
        // 내용이 같아도 매 요청이 전문 200 이 된다(304 로 끝날 왕복이 하나도 없다).
        // 형제(ecommerce `ServesImageWithRowValidators`)는 같은 계열에서 `must-revalidate`
        // 까지 갖추고 있었다 — 한쪽만 갖추면 그것은 규율이 아니라 그 확장의 패치다.
        // ETag 는 큰따옴표로 감싼다 (RFC 9110 §8.8.3).
        //
        // 검증자의 **소스**는 `cacheValidators()` 한 곳이 만든다 — 조건부 요청 판정은
        // 컨트롤러가 코어 단일 지점(`streamedFileResponse()`)에 맡기는데, 그 지점이 같은
        // 소스로 ETag 를 다시 만들므로 여기서 실은 값과 어긋나면 안 된다.
        $validators = $this->cacheValidators($image);
        $lastModifiedAt = $validators['last_modified'];

        $headers = [
            'Content-Type' => $image->mime_type,
            'Cache-Control' => 'public, max-age='.self::CACHE_MAX_AGE.', must-revalidate',
            'ETag' => '"'.md5($validators['etag_source']).'"',
        ];

        if ($lastModifiedAt !== null) {
            $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $lastModifiedAt).' GMT';
        }

        $response = $storage->response(
            $category,
            $relativePath,
            $image->original_name,
            $headers
        );

        if (! $response) {
            Log::error('CKEditor5 이미지 스토리지에 없음', [
                'image_id' => $image->id,
                'hash' => $image->hash,
                'file_path' => $image->file_path,
                'disk' => $image->storage_disk,
            ]);

            return null;
        }

        // 인라인 표시는 래스터 이미지만, 그 외(SVG 등)는 다운로드로 — 모든 응답에 nosniff (KVE-2026-2366)
        InlineMediaPolicy::harden($response, $image->mime_type, $image->original_name);

        return $response;
    }

    /**
     * 행의 원본 파일이 저장소에 있는지 (원본 응답과 같은 스토리지로 확인한다).
     *
     * 썸네일 판정 앞에서 부른다 — 원격 저장소에서 원본만 지워져 파생본이 남은 경우, 확인 없이 썸네일로 가면
     * 무파라미터 요청은 404 인데 `?size=` 요청은 남은 파생본으로 200 이 된다.
     *
     * @param  Ckeditor5ImageUpload  $image  이미지 업로드 행
     * @return bool 있으면 true (경로가 깨졌으면 false)
     */
    public function originalExists(Ckeditor5ImageUpload $image): bool
    {
        [$category, $relativePath] = $this->splitFilePath($image);

        if ($category === '' || $relativePath === '') {
            return false;
        }

        return $this->storageFor($image)->exists($category, $relativePath);
    }

    /**
     * 썸네일 원본 정보를 행에서 만듭니다 (본문 이미지는 완전 공개 원본 — `gated=false`).
     *
     * 원본 응답과 **같은 스토리지**(행 disk, 고아 disk 면 주입 디스크)로 만든다 — 그래야 파생본 키
     * (`thumbnails/{disk}/{전체 경로}/…`)가 저장소 계층의 덮어쓰기·삭제 무효화와 같은 경로를 가리킨다.
     *
     * @param  Ckeditor5ImageUpload  $image  이미지 업로드 행
     * @return ThumbnailSource|null 원본 (경로가 깨졌거나 드라이버가 원본 정보를 못 주면 null — 원본을 낸다)
     */
    public function thumbnailSource(Ckeditor5ImageUpload $image): ?ThumbnailSource
    {
        [$category, $relativePath] = $this->splitFilePath($image);

        if ($category === '' || $relativePath === '') {
            return null;
        }

        return ThumbnailSource::fromStorage(
            $this->storageFor($image),
            $category,
            $relativePath,
            (string) $image->mime_type,
            gated: false
        );
    }

    /**
     * 행 `file_path` 를 카테고리와 카테고리 하위 경로로 나눕니다.
     *
     * file_path 형태: "images/2026/04/06/{uuid}.jpg" (외부 생성 행은 "ckeditor5/..." 등).
     * 첫 세그먼트 = 카테고리 일반형으로 분해 — PluginStorageDriver::response(category, path)
     *
     * @param  Ckeditor5ImageUpload  $image  이미지 업로드 행
     * @return array{0: string, 1: string} [카테고리, 하위 경로] (깨진 경로면 빈 문자열이 섞인다)
     */
    private function splitFilePath(Ckeditor5ImageUpload $image): array
    {
        return array_pad(explode('/', (string) $image->file_path, 2), 2, '');
    }

    /**
     * 행이 실제로 저장된 디스크의 스토리지를 돌려줍니다.
     *
     * 행에 기록된 disk 기준 서빙 — 디스크 전환 이전 행도 실제 저장 위치를 향한다.
     * 단 고아 disk(디스크를 제공하던 플러그인 비활성화로 config 에서 사라진 경우)는
     * 미등록 disk 로 withDisk 를 만들면 response 가 InvalidArgumentException 을 던져
     * 서빙이 500 이 되므로, 존재 검증 후 주입 스토리지로 폴백한다.
     *
     * @param  Ckeditor5ImageUpload  $image  이미지 업로드 행
     * @return StorageInterface 행 disk 스토리지 (고아·빈 disk 면 주입 스토리지)
     */
    private function storageFor(Ckeditor5ImageUpload $image): StorageInterface
    {
        $rowDisk = (string) ($image->storage_disk ?? '');
        $useRowDisk = $rowDisk !== ''
            && $rowDisk !== $this->storage->getDisk()
            && config("filesystems.disks.{$rowDisk}") !== null;

        return $useRowDisk ? $this->storage->withDisk($rowDisk) : $this->storage;
    }
}
