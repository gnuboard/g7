# 스토리지 드라이버 시스템 (StorageInterface)

> **모듈/플러그인에서 파일을 저장하고 관리하기 위한 표준화된 인터페이스**

## TL;DR (5초 요약)

```text
1. 모든 파일 저장은 StorageInterface 사용 (Storage::disk() 직접 호출 금지)
2. BaseModuleServiceProvider에서 storageServices 배열에 Service 클래스 등록
3. Category로 파일 분류 (attachments, images, settings, cache, temp)
4. 경로: modules/{identifier}/{category}/{path} (기본 disk: 'modules')
5. Service 생성자에서 StorageInterface 타입힌트하면 자동 주입
6. response() 메서드로 StreamedResponse 생성 (파일 다운로드/표시)
```

---

## 📋 목차

- [개요](#개요)
- [주요 개념](#주요-개념)
- [모듈에서 사용하기](#모듈에서-사용하기)
- [카테고리 네이밍 규칙](#카테고리-네이밍-규칙)
- [예제 코드](#예제-코드)
- [마이그레이션 가이드](#마이그레이션-가이드)
- [API 레퍼런스](#api-레퍼런스)
- [트러블슈팅](#트러블슈팅)
- [S3 호환 스토리지 연결](#s3-호환-스토리지-연결)
- [FAQ](#faq)

---

## 개요

### 목적

스토리지 드라이버 시스템은 모듈/플러그인에서 파일을 저장하고 관리하기 위한 **표준화된 인터페이스**를 제공합니다.

### 해결하는 문제

**Before (❌ 문제점)**:
```php
// 각 모듈마다 다른 disk 사용
$disk = config('sirsoft-board.attachment.disk', 'local');

// 불일치한 경로 패턴
$path = "attachments/modules/sirsoft/board/{$slug}/{$date}/{$filename}";
$path = "attachments/modules/sirsoft/ecommerce/category-images/{$date}/{$filename}";

// Storage Facade 직접 호출
Storage::disk($disk)->put($path, $contents);
```

**After (✅ 해결)**:
```php
// 표준화된 인터페이스
private StorageInterface $storage;

// 일관된 경로 패턴
// modules/{identifier}/{category}/{path}
$this->storage->put('attachments', "{$slug}/{$date}/{$filename}", $contents);
```

### 핵심 장점

| 장점 | 설명 |
|------|------|
| **일관성** | 모든 모듈/플러그인이 동일한 API 사용 |
| **격리성** | 모듈별 디렉토리 자동 분리 (`modules/{identifier}/`) |
| **확장성** | S3, CDN 등 다른 백엔드로 전환 용이 |
| **테스트 용이** | Mock 인터페이스로 단위 테스트 작성 가능 |
| **표준 경로** | 예측 가능한 파일 경로 구조 |

---

## 주요 개념

### 1. StorageInterface

모든 파일 작업의 표준 인터페이스입니다.

```php
interface StorageInterface
{
    public function put(string $category, string $path, mixed $content): bool;
    public function get(string $category, string $path): ?string;
    public function exists(string $category, string $path): bool;
    public function delete(string $category, string $path): bool;
    public function url(string $category, string $path): ?string;
    public function files(string $category, string $directory = ''): array;
    public function deleteDirectory(string $category, string $directory = ''): bool;
    public function getBasePath(string $category): string;
    public function getDisk(): string;
    public function deleteAll(string $category): bool;
    public function response(string $category, string $path, string $filename, array $headers = []): ?\Symfony\Component\HttpFoundation\StreamedResponse;
}
```

### 2. 카테고리 시스템

파일을 **용도별로 분류**하여 관리합니다.

```
modules/{identifier}/
├── attachments/    # 첨부파일 (게시글, 댓글 등)
├── images/         # 이미지 파일 (상품, 카테고리 등)
├── settings/       # 환경설정 파일 (JSON, INI 등)
├── cache/          # 캐시 데이터
└── temp/           # 임시 파일
```

### 3. 경로 패턴

**표준 경로 구조**:
```
storage/app/modules/{identifier}/{category}/{path}
```

**예시**:
```
storage/app/modules/sirsoft-board/attachments/notice/2024/01/19/uuid.pdf
storage/app/modules/sirsoft-ecommerce/images/category/2024/01/19/uuid.jpg
storage/app/modules/sirsoft-ecommerce/settings/setting.json
```

### 4. ModuleStorageDriver

`StorageInterface`의 구현체로, 모듈별 격리된 저장소를 제공합니다.

```php
class ModuleStorageDriver implements StorageInterface
{
    public function __construct(
        string $identifier,  // 모듈 식별자
        string $disk = 'modules'  // 사용할 디스크 (기본: 'modules')
    ) {}
}
```

---

## 모듈에서 사용하기

### STEP 1: ServiceProvider 설정

`BaseModuleServiceProvider`를 상속받고 `$storageServices` 배열에 Service 클래스를 등록합니다.

```php
<?php

namespace Modules\Sirsoft\Board\Providers;

use App\Extension\BaseModuleServiceProvider;
use Modules\Sirsoft\Board\Services\AttachmentService;

class BoardServiceProvider extends BaseModuleServiceProvider
{
    /**
     * 모듈 식별자
     */
    protected string $moduleIdentifier = 'sirsoft-board';

    /**
     * StorageInterface가 필요한 서비스 목록
     *
     * 이 배열에 추가된 서비스는 자동으로 StorageInterface를 주입받습니다.
     */
    protected array $storageServices = [
        AttachmentService::class,
    ];

    /**
     * Repository 인터페이스와 구현체 매핑
     */
    protected array $repositories = [
        // ...
    ];
}
```

### STEP 2: Service에서 주입받기

Service 생성자에서 `StorageInterface`를 타입힌트하면 자동으로 주입됩니다.

```php
<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Http\UploadedFile;

class AttachmentService
{
    /**
     * AttachmentService 생성자
     */
    public function __construct(
        private AttachmentRepositoryInterface $repository,
        private StorageInterface $storage  // 자동 주입
    ) {}

    /**
     * 파일 업로드
     */
    public function upload(string $slug, UploadedFile $file, ?int $postId = null): DynamicAttachment
    {
        // 경로 생성
        $storedFilename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $datePath = date('Y/m/d');
        $path = "{$slug}/{$datePath}/{$storedFilename}";

        // 스토리지에 저장 (category: 'attachments')
        $this->storage->put('attachments', $path, file_get_contents($file->getRealPath()));

        // Disk 정보 가져오기
        $disk = $this->storage->getDisk();

        // DB에 레코드 생성
        return $this->repository->create($slug, [
            'post_id' => $postId,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => $disk,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
    }

    /**
     * 파일 삭제
     */
    public function delete(string $slug, int $id): bool
    {
        $attachment = $this->repository->findById($slug, $id);

        // 스토리지에서 파일 삭제
        if ($this->storage->exists('attachments', $attachment->path)) {
            $this->storage->delete('attachments', $attachment->path);
        }

        // DB에서 삭제
        return $this->repository->delete($slug, $id);
    }
}
```

### STEP 3: Disk 설정 (선택)

기본값은 `modules` disk를 사용하지만, 모듈 설정 파일에서 변경 가능합니다.

**모듈 설정 파일** (`config/sirsoft-board.php`):
```php
return [
    'attachment' => [
        'disk' => env('SIRSOFT_BOARD_ATTACHMENT_DISK', 'modules'),
    ],
];
```

**AbstractModule에서 오버라이드**:
```php
<?php

namespace Modules\Sirsoft\Board;

use App\Extension\AbstractModule;

class BoardModule extends AbstractModule
{
    /**
     * 스토리지 디스크를 반환합니다.
     */
    public function getStorageDisk(): string
    {
        return config('sirsoft-board.attachment.disk', 'modules');
    }
}
```

---

## 카테고리 네이밍 규칙

### 표준 카테고리

| 카테고리 | 용도 | 예시 |
|----------|------|------|
| `attachments` | 첨부파일 (게시글, 댓글 등) | PDF, DOCX, ZIP 등 |
| `images` | 이미지 파일 | 상품 이미지, 카테고리 이미지 |
| `settings` | 환경설정 파일 | JSON, INI 설정 |
| `cache` | 캐시 데이터 | 임시 계산 결과 |
| `temp` | 임시 파일 | 업로드 중인 파일 |

### 커스텀 카테고리

필요시 새로운 카테고리를 추가할 수 있습니다.

**예시**:
```php
// 로그 파일 저장
$this->storage->put('logs', 'error.log', $logContent);

// 백업 파일 저장
$this->storage->put('backups', 'backup-2024-01-19.sql', $backupData);
```

**네이밍 규칙**:
- **소문자**: 모두 소문자 사용
- **복수형**: 복수형 명사 사용 (logs, backups)
- **단어 구분**: 하이픈 사용 (user-uploads, product-images)

---

## 예제 코드

### 예제 1: 이미지 업로드 및 썸네일 생성

```php
<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Http\UploadedFile;
use Intervention\Image\Facades\Image;

class CategoryImageService
{
    public function __construct(
        private CategoryImageRepositoryInterface $repository,
        private StorageInterface $storage
    ) {}

    /**
     * 이미지 업로드 및 썸네일 생성
     */
    public function upload(UploadedFile $file, int $categoryId): CategoryImage
    {
        // 원본 이미지 저장
        $storedFilename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $datePath = date('Y/m/d');
        $path = "category/{$datePath}/{$storedFilename}";

        $this->storage->put('images', $path, file_get_contents($file->getRealPath()));

        // 썸네일 생성
        $thumbnail = Image::make($file)->fit(300, 300)->encode('jpg', 80);
        $thumbnailPath = "category/{$datePath}/thumb_{$storedFilename}";
        $this->storage->put('images', $thumbnailPath, (string) $thumbnail);

        // DB 레코드 생성
        $disk = $this->storage->getDisk();

        return $this->repository->create([
            'category_id' => $categoryId,
            'path' => $path,
            'thumbnail_path' => $thumbnailPath,
            'disk' => $disk,
            'original_filename' => $file->getClientOriginalName(),
            'width' => getimagesize($file->getRealPath())[0],
            'height' => getimagesize($file->getRealPath())[1],
        ]);
    }
}
```

### 예제 2: 환경설정 파일 저장

```php
<?php

namespace App\Services;

use App\Contracts\Extension\StorageInterface;

class ModuleSettingsService
{
    private const SETTINGS_FILENAME = 'setting.json';

    /**
     * 환경설정 저장
     */
    public function save(string $identifier, array $settings): bool
    {
        // 모듈 인스턴스 가져오기
        $module = $this->moduleManager->getModule($identifier);
        if (!$module) {
            return false;
        }

        $storage = $module->getStorage();

        // JSON 인코딩
        $content = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // 저장
        return $storage->put('settings', self::SETTINGS_FILENAME, $content);
    }

    /**
     * 환경설정 로드
     */
    public function load(string $identifier): array
    {
        $module = $this->moduleManager->getModule($identifier);
        if (!$module) {
            return [];
        }

        $storage = $module->getStorage();

        // 파일 존재 여부 확인
        if (!$storage->exists('settings', self::SETTINGS_FILENAME)) {
            return [];
        }

        // 파일 읽기
        $content = $storage->get('settings', self::SETTINGS_FILENAME);

        return json_decode($content, true) ?? [];
    }
}
```

### 예제 3: 임시 파일 관리

```php
<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Extension\StorageInterface;

class TempFileService
{
    public function __construct(
        private StorageInterface $storage
    ) {}

    /**
     * 임시 파일 저장
     */
    public function storeTempFile(string $sessionId, UploadedFile $file): string
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = "{$sessionId}/{$filename}";

        $this->storage->put('temp', $path, file_get_contents($file->getRealPath()));

        return $path;
    }

    /**
     * 세션의 모든 임시 파일 삭제
     */
    public function cleanupSession(string $sessionId): bool
    {
        return $this->storage->deleteDirectory('temp', $sessionId);
    }

    /**
     * 24시간 이상 된 임시 파일 정리
     */
    public function cleanupOldFiles(): int
    {
        $files = $this->storage->files('temp', '');
        $deletedCount = 0;
        $cutoffTime = now()->subDay()->timestamp;

        foreach ($files as $file) {
            $fullPath = $this->storage->getBasePath('temp') . '/' . $file;
            if (filemtime($fullPath) < $cutoffTime) {
                $this->storage->delete('temp', $file);
                $deletedCount++;
            }
        }

        return $deletedCount;
    }
}
```

### 예제 4: 파일 다운로드 컨트롤러

```php
<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Contracts\Extension\StorageInterface;
use Illuminate\Http\Response;

class AttachmentController extends BaseApiController
{
    public function __construct(
        private AttachmentService $attachmentService,
        private StorageInterface $storage
    ) {}

    /**
     * 첨부파일 다운로드
     */
    public function download(string $slug, int $id): Response
    {
        $attachment = $this->attachmentService->getById($slug, $id);

        // 권한 확인 로직...

        // 파일 존재 여부 확인
        if (!$this->storage->exists('attachments', $attachment->path)) {
            return response()->json(['message' => '파일을 찾을 수 없습니다.'], 404);
        }

        // 파일 내용 가져오기
        $content = $this->storage->get('attachments', $attachment->path);

        // 다운로드 응답
        return response($content, 200)
            ->header('Content-Type', $attachment->mime_type)
            ->header('Content-Disposition', 'attachment; filename="' . $attachment->original_filename . '"');
    }
}
```

### 예제 5: StreamedResponse를 사용한 이미지 다운로드

```php
<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductImageRepositoryInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 상품 이미지 서비스
 */
class ProductImageService
{
    public function __construct(
        protected ProductImageRepositoryInterface $repository,
        protected StorageInterface $storage
    ) {
        // StorageInterface는 EcommerceServiceProvider에서 자동 주입됨
    }

    /**
     * 해시로 이미지 조회
     */
    public function findByHash(string $hash): ?ProductImage
    {
        return $this->repository->findByHash($hash);
    }

    /**
     * 이미지 다운로드 응답 생성
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @return StreamedResponse|null 이미지 스트림 또는 없을 경우 null
     */
    public function download(string $hash): ?StreamedResponse
    {
        $image = $this->repository->findByHash($hash);

        if (! $image) {
            return null;
        }

        // ModuleStorageDriver가 자동으로 경로를 해결함
        // DB path: products/{id}/{filename}.jpg
        // 실제 경로: modules/sirsoft-ecommerce/images/products/{id}/{filename}.jpg
        $response = $this->storage->response(
            'images',
            $image->path,
            $image->original_filename,
            [
                'Content-Type' => $image->mime_type,
                'Cache-Control' => 'public, max-age=31536000',
            ]
        );

        if (! $response) {
            Log::error('상품 이미지 스토리지에 없음', [
                'product_image_id' => $image->id,
                'path' => $image->path,
                'disk' => $this->storage->getDisk(),
            ]);

            return null;
        }

        return $response;
    }
}
```

---

## 마이그레이션 가이드

### 기존 코드 전환하기

#### Before (기존 패턴)

```php
<?php

namespace Modules\Sirsoft\Board\Services;

use Illuminate\Support\Facades\Storage;

class AttachmentService
{
    public function upload(string $slug, UploadedFile $file, ?int $postId = null): DynamicAttachment
    {
        // Config에서 disk 가져오기
        $disk = config('sirsoft-board.attachment.disk', 'local');

        // 경로 생성
        $storedFilename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $datePath = date('Y/m/d');
        $path = "attachments/modules/sirsoft/board/{$slug}/{$datePath}/{$storedFilename}";

        // Storage Facade 직접 호출
        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));

        // DB 저장
        return $this->repository->create($slug, [
            'path' => $path,
            'disk' => $disk,
            // ...
        ]);
    }

    public function delete(string $slug, int $id): bool
    {
        $attachment = $this->repository->findById($slug, $id);

        // Storage Facade 직접 호출
        if (Storage::disk($attachment->disk)->exists($attachment->path)) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }

        return $this->repository->delete($slug, $id);
    }
}
```

#### After (StorageInterface 패턴)

```php
<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Extension\StorageInterface;

class AttachmentService
{
    /**
     * StorageInterface 주입
     */
    public function __construct(
        private AttachmentRepositoryInterface $repository,
        private StorageInterface $storage  // 추가
    ) {}

    public function upload(string $slug, UploadedFile $file, ?int $postId = null): DynamicAttachment
    {
        // 경로 생성 (모듈 prefix 제거)
        $storedFilename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $datePath = date('Y/m/d');
        $path = "{$slug}/{$datePath}/{$storedFilename}";  // 변경됨

        // StorageInterface 사용 (category 추가)
        $this->storage->put('attachments', $path, file_get_contents($file->getRealPath()));

        // Disk 정보 가져오기
        $disk = $this->storage->getDisk();

        // DB 저장
        return $this->repository->create($slug, [
            'path' => $path,
            'disk' => $disk,
            // ...
        ]);
    }

    public function delete(string $slug, int $id): bool
    {
        $attachment = $this->repository->findById($slug, $id);

        // StorageInterface 사용
        if ($this->storage->exists('attachments', $attachment->path)) {
            $this->storage->delete('attachments', $attachment->path);
        }

        return $this->repository->delete($slug, $id);
    }
}
```

### 전환 체크리스트

```
□ 1. ServiceProvider를 BaseModuleServiceProvider 상속으로 변경
□ 2. storageServices 배열에 Service 클래스 추가
□ 3. Service 생성자에 StorageInterface 파라미터 추가
□ 4. Storage::disk() 호출을 $this->storage-> 호출로 변경
□ 5. 경로에서 모듈 prefix 제거 (자동으로 추가됨)
□ 6. Category 파라미터 추가 (attachments, images 등)
□ 7. config()로 disk 가져오는 코드를 $this->storage->getDisk()으로 변경
□ 8. 단위 테스트 작성/수정 (StorageInterface Mock 사용)
□ 9. 테스트 실행 및 검증
```

### 주의 사항

**경로 패턴 변경**:
```php
// ❌ Before
"attachments/modules/sirsoft/board/{$slug}/{$date}/{$filename}"

// ✅ After
"{$slug}/{$date}/{$filename}"
// → 실제 저장 경로: modules/sirsoft-board/attachments/{$slug}/{$date}/{$filename}
```

**기존 파일 마이그레이션**:

기존 파일이 있는 경우 마이그레이션 스크립트를 작성해야 합니다.

```php
<?php

use Illuminate\Support\Facades\Storage;

// 기존 경로: attachments/modules/sirsoft/board/notice/2024/01/19/file.pdf
// 새 경로:   modules/sirsoft-board/attachments/notice/2024/01/19/file.pdf

$oldPath = "attachments/modules/sirsoft/board/notice/2024/01/19/file.pdf";
$newPath = "modules/sirsoft-board/attachments/notice/2024/01/19/file.pdf";

if (Storage::disk('local')->exists($oldPath)) {
    Storage::disk('local')->move($oldPath, $newPath);
}
```

---

## API 레퍼런스

### put()

파일을 저장합니다.

**시그니처**:
```php
public function put(string $category, string $path, mixed $content): bool
```

**파라미터**:
- `$category` (string): 카테고리 (attachments, images, settings 등)
- `$path` (string): 카테고리 하위 상대 경로
- `$content` (string|resource): 파일 내용

**반환값**:
- `bool`: 저장 성공 여부

**예시**:
```php
$this->storage->put('images', 'product/2024/01/19/uuid.jpg', $imageData);
```

---

### get()

파일 내용을 가져옵니다.

**시그니처**:
```php
public function get(string $category, string $path): ?string
```

**파라미터**:
- `$category` (string): 카테고리
- `$path` (string): 카테고리 하위 상대 경로

**반환값**:
- `string|null`: 파일 내용 (파일이 없으면 null)

**예시**:
```php
$content = $this->storage->get('settings', 'setting.json');
if ($content) {
    $settings = json_decode($content, true);
}
```

---

### exists()

파일이 존재하는지 확인합니다.

**시그니처**:
```php
public function exists(string $category, string $path): bool
```

**파라미터**:
- `$category` (string): 카테고리
- `$path` (string): 카테고리 하위 상대 경로

**반환값**:
- `bool`: 파일 존재 여부

**예시**:
```php
if ($this->storage->exists('images', 'product/2024/01/19/uuid.jpg')) {
    // 파일이 존재함
}
```

---

### delete()

파일을 삭제합니다.

**시그니처**:
```php
public function delete(string $category, string $path): bool
```

**파라미터**:
- `$category` (string): 카테고리
- `$path` (string): 카테고리 하위 상대 경로

**반환값**:
- `bool`: 삭제 성공 여부

**예시**:
```php
$this->storage->delete('temp', 'session-123/upload.tmp');
```

---

### url()

파일의 공개 URL을 반환합니다.

**시그니처**:
```php
public function url(string $category, string $path): ?string
```

**파라미터**:
- `$category` (string): 카테고리
- `$path` (string): 카테고리 하위 상대 경로

**반환값**:
- `string|null`: 파일 URL (직접 URL 불가 디스크이고 훅 공급도 없으면 null)

**직접 URL 판정**: `public` 디스크이거나 `filesystems.disks.{disk}.url` 이 비어 있지 않은
문자열로 설정된 디스크(S3+CDN 등)면 직접 URL 을 생성합니다. url 설정이 없거나
빈 문자열/공백이면 null 입니다 (AWS_URL 미설정 방어).

**필터 훅**: 생성 결과는 디스크 종류와 무관하게 `core.storage.filter_url` 필터 훅을
**항상** 통과합니다 (null 도 발화 대상). 확장은 URL 을 공급(서명 URL 등)/수정(도메인
교체)/차단(빈 문자열 반환 → 호출측 스트리밍 폴백)할 수 있습니다. 컨텍스트 6키:
`scope`(core|module|plugin) / `identifier`(확장 식별자, 코어는 null) / `disk` /
`category` / `path` / `full_path`.

**예시**:
```php
// public disk인 경우
$url = $this->storage->url('images', 'product/2024/01/19/uuid.jpg');
// → http://example.com/storage/modules/sirsoft-ecommerce/images/product/2024/01/19/uuid.jpg

// url 설정 디스크(S3+CDN)인 경우
$url = $this->storage->url('images', 'product/2024/01/19/uuid.jpg');
// → https://cdn.example.com/modules/sirsoft-ecommerce/images/product/2024/01/19/uuid.jpg

// url 미설정 디스크(local 등)인 경우
$url = $this->storage->url('attachments', 'notice/2024/01/19/file.pdf');
// → null (별도 API 엔드포인트 사용해야 함)
```

---

### files()

디렉토리 내 모든 파일 목록을 반환합니다.

**시그니처**:
```php
public function files(string $category, string $directory = ''): array
```

**파라미터**:
- `$category` (string): 카테고리
- `$directory` (string): 디렉토리 경로 (빈 문자열이면 카테고리 루트)

**반환값**:
- `array`: 파일 경로 배열

**예시**:
```php
$files = $this->storage->files('temp', 'session-123');
// → ['session-123/upload1.tmp', 'session-123/upload2.tmp']
```

---

### deleteDirectory()

디렉토리와 그 하위의 모든 파일을 삭제합니다.

**시그니처**:
```php
public function deleteDirectory(string $category, string $directory = ''): bool
```

**파라미터**:
- `$category` (string): 카테고리
- `$directory` (string): 디렉토리 경로 (빈 문자열이면 카테고리 루트)

**반환값**:
- `bool`: 삭제 성공 여부

**예시**:
```php
// 특정 디렉토리 삭제
$this->storage->deleteDirectory('temp', 'session-123');

// 카테고리 전체 삭제
$this->storage->deleteDirectory('temp', '');
```

---

### getBasePath()

카테고리의 전체 파일 시스템 경로를 반환합니다.

**시그니처**:
```php
public function getBasePath(string $category): string
```

**파라미터**:
- `$category` (string): 카테고리

**반환값**:
- `string`: 전체 경로

**예시**:
```php
$basePath = $this->storage->getBasePath('images');
// → /path/to/g7/storage/app/modules/sirsoft-ecommerce/images
```

---

### getDisk()

사용 중인 디스크 이름을 반환합니다.

**시그니처**:
```php
public function getDisk(): string
```

**반환값**:
- `string`: 디스크 이름 (local, public, s3 등)

**예시**:
```php
$disk = $this->storage->getDisk();
// → 'local'
```

---

### deleteAll()

카테고리의 모든 파일을 삭제합니다.

**시그니처**:
```php
public function deleteAll(string $category): bool
```

**파라미터**:
- `$category` (string): 카테고리

**반환값**:
- `bool`: 삭제 성공 여부

**예시**:
```php
$this->storage->deleteAll('temp');
```

---

### response()

파일을 스트리밍 응답으로 반환합니다.

**시그니처**:
```php
public function response(string $category, string $path, string $filename, array $headers = []): ?\Symfony\Component\HttpFoundation\StreamedResponse
```

**파라미터**:

- `$category` (string): 카테고리
- `$path` (string): 카테고리 하위 상대 경로
- `$filename` (string): 다운로드 시 표시될 파일명
- `$headers` (array): 추가 HTTP 헤더 (Content-Type, Cache-Control 등)

**반환값**:

- `StreamedResponse|null`: 파일 스트림 (파일이 없으면 null)

**예시**:
```php
// 이미지 다운로드
$response = $this->storage->response(
    'images',
    'products/123/image.jpg',
    'product-image.jpg',
    [
        'Content-Type' => 'image/jpeg',
        'Cache-Control' => 'public, max-age=31536000',
    ]
);

if ($response) {
    return $response;  // StreamedResponse 반환
}

// 첨부파일 다운로드
$response = $this->storage->response(
    'attachments',
    'notice/2024/01/19/document.pdf',
    'important-document.pdf',
    [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'attachment',
    ]
);
```

**장점**:

- 메모리 효율적 (대용량 파일도 스트리밍)
- Content-Type, Cache-Control 등 헤더 자동 설정 가능
- Laravel의 `Storage::response()` 메서드 활용

**주의사항**:

- 파일이 존재하지 않으면 `null` 반환
- null 체크 후 404 응답 처리 필요
- 권한 체크는 컨트롤러/서비스에서 별도 구현

### 파일 서빙 안티패턴 — 전체 메모리 적재 금지

파일 본문을 응답으로 내려줄 때는 항상 `response()` / `download()` 스트리밍(내부 `readStream`)을 사용한다. 아래처럼 파일 전체를 문자열로 읽어 `echo` 하는 패턴은 대용량 파일에서 워커 메모리를 붕괴시킨다.

```php
// ❌ 금지 — streamDownload 로 감쌌지만 콜백 안에서 파일 전체를 메모리에 적재
return response()->streamDownload(function () use ($image) {
    echo Storage::disk($image->disk)->get($image->path); // get() = 전체 문자열 로드
}, $image->original_filename, ['Content-Type' => $image->mime_type]);

// ✅ 올바름 — StorageInterface::response() 로 청크 스트리밍 (파일 전체 미적재)
$response = $this->storage->response('images', $image->path, $image->original_filename, [
    'Content-Type' => $image->mime_type,
    'Cache-Control' => 'public, max-age=31536000',
]);

return $response ?? ResponseHelper::notFound(...);
```

`StorageInterface::get()` 은 소형 메타 파일(manifest.json 등)의 전체 읽기 전용이며, 서빙 본문에는 사용하지 않는다.

---

## 트러블슈팅

### 문제 1: Service에서 StorageInterface가 주입되지 않음

**증상**:
```
Target [App\Contracts\Extension\StorageInterface] is not instantiable.
```

**원인**:
- `BaseModuleServiceProvider`의 `$storageServices` 배열에 Service 클래스가 등록되지 않았습니다.

**해결**:
```php
<?php

namespace Modules\Sirsoft\Board\Providers;

use App\Extension\BaseModuleServiceProvider;
use Modules\Sirsoft\Board\Services\AttachmentService;

class BoardServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleIdentifier = 'sirsoft-board';

    protected array $storageServices = [
        AttachmentService::class,  // 추가 필수
    ];
}
```

---

### 문제 2: 파일 경로가 잘못됨

**증상**:
- 파일이 `storage/app/modules/sirsoft-board/attachments/modules/sirsoft-board/...` 같은 중복된 경로에 저장됩니다.

**원인**:
- 경로에 모듈 prefix를 직접 포함했습니다.

**해결**:
```php
// ❌ 잘못된 코드
$path = "modules/sirsoft-board/attachments/{$slug}/{$date}/{$filename}";
$this->storage->put('attachments', $path, $content);

// ✅ 올바른 코드
$path = "{$slug}/{$date}/{$filename}";  // 모듈 prefix 제거
$this->storage->put('attachments', $path, $content);
```

---

### 문제 3: 테스트에서 Mock이 동작하지 않음

**증상**:
- 테스트 실행 시 실제 파일 시스템에 저장됩니다.

**원인**:
- StorageInterface를 Mock하지 않았습니다.

**해결**:
```php
<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

use App\Contracts\Extension\StorageInterface;
use Mockery;
use Tests\TestCase;

class AttachmentServiceTest extends TestCase
{
    private AttachmentService $service;
    private $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // StorageInterface Mock 생성
        $this->storage = Mockery::mock(StorageInterface::class);

        // Service 생성
        $this->service = new AttachmentService(
            $this->repository,
            $this->storage  // Mock 주입
        );
    }

    public function test_upload(): void
    {
        // Mock 동작 정의
        $this->storage
            ->shouldReceive('put')
            ->once()
            ->withArgs(function ($category, $path, $contents) {
                return $category === 'attachments'
                    && str_contains($path, 'notice/')
                    && str_ends_with($path, '.pdf');
            })
            ->andReturn(true);

        $this->storage
            ->shouldReceive('getDisk')
            ->andReturn('local');

        // 테스트 실행...
    }
}
```

---

### 문제 4: disk 설정이 적용되지 않음

**증상**:
- config 파일에서 disk를 변경했지만 항상 'local'을 사용합니다.

**원인**:
- `AbstractModule`에서 `getStorageDisk()` 메서드를 오버라이드하지 않았습니다.

**해결**:
```php
<?php

namespace Modules\Sirsoft\Board;

use App\Extension\AbstractModule;

class BoardModule extends AbstractModule
{
    /**
     * 스토리지 디스크를 반환합니다.
     */
    public function getStorageDisk(): string
    {
        return config('sirsoft-board.attachment.disk', 'local');
    }
}
```

---

### 문제 5: URL이 null을 반환함

**증상**:
- `$this->storage->url()` 호출 시 항상 null이 반환됩니다.

**원인**:
- 사용 중인 디스크에 `filesystems.disks.{disk}.url` 설정이 없습니다. url() 은
  `public` 디스크이거나 url 이 설정된 디스크에서만 직접 URL 을 반환합니다.
  s3 디스크라도 url(AWS_URL/관리자 S3 URL 설정)이 비어 있으면 null 입니다.

**해결**:
```php
// public disk 를 사용하거나
$module->getStorageDisk(); // → 'public'

// 디스크에 url 을 설정하거나 (config/filesystems.php 또는 관리자 S3 URL 설정)
'my_cdn' => ['driver' => 's3', ..., 'url' => 'https://cdn.example.com'],

// 또는 별도 API 엔드포인트를 사용
Route::get('/api/attachments/{id}/download', [AttachmentController::class, 'download']);
```

---

## S3 호환 스토리지 연결

코어 7.0.7+ 의 S3 드라이버는 AWS 뿐 아니라 S3 API 를 제공하는 호환 스토리지(Cloudflare R2, MinIO, 네이버 클라우드 등)에도 연결할 수 있다. 관리자 > 환경설정 > 드라이버 > 파일 스토리지의 두 항목이 이를 담당한다.

| 항목 | 의미 | 예시 |
| --- | --- | --- |
| 엔드포인트 URL | SDK 가 요청을 보내는 API 주소. 비워 두면 AWS 리전 도메인을 사용 | `https://<account-id>.r2.cloudflarestorage.com`, `http://minio.internal:9000` |
| Path-style 주소 사용 | 버킷을 호스트가 아닌 경로에 두는 주소 형식(`endpoint/bucket/...`). MinIO 등 path-style 전용 스토리지에서 켠다 | — |

- 리전은 자유 입력이다 — Cloudflare R2 는 `auto`, MinIO 는 관례상 `us-east-1` 을 쓴다.
- **S3 URL(공개 URL)** 은 파일 공개 URL 생성에 쓰는 CDN/커스텀 도메인이며 API 요청 주소가 아니다 — API 주소는 엔드포인트 URL 에만 넣는다.
- IP 주소 엔드포인트는 SDK 가 path-style 을 자동 적용하므로 토글과 무관하게 동작한다. 호스트명 엔드포인트에서는 토글이 주소 형식을 결정한다.
- 연결 테스트는 실제 저장 경로와 같은 설정(엔드포인트·path-style 포함)을 사용한다.

`.env` 로 설정하는 경우 대응 키는 `AWS_ENDPOINT` / `AWS_USE_PATH_STYLE_ENDPOINT` 다 (아래 FAQ Q2 의 ② 경로 참조).

---

## FAQ

### Q1: 기존 파일은 어떻게 되나요?

**A**: 새로운 경로 패턴으로 파일을 저장하므로, 기존 파일은 **마이그레이션 스크립트**로 이동해야 합니다.

```php
// 마이그레이션 예시
$oldPath = "attachments/modules/sirsoft/board/{$slug}/{$date}/{$filename}";
$newPath = "modules/sirsoft-board/attachments/{$slug}/{$date}/{$filename}";

if (Storage::disk('local')->exists($oldPath)) {
    Storage::disk('local')->move($oldPath, $newPath);

    // DB 업데이트
    DB::table('board_notice_attachments')->update([
        'path' => "{$slug}/{$date}/{$filename}",
    ]);
}
```

---

### Q2: S3로 전환하려면 어떻게 하나요?

**A**: 코어 7.0.7+ 는 S3 어댑터(`league/flysystem-aws-s3-v3`)를 기본 포함하므로 별도 패키지 설치가 필요 없습니다. 전환 경로는 두 가지입니다.

**① 관리자 설정 UI (권장)** — 관리자 > 환경설정 > 드라이버 > 파일 스토리지:

1. 스토리지 드라이버를 `Amazon S3` 로 선택
2. 버킷 / 리전 / Access Key / Secret Key 입력
3. S3 호환 스토리지(Cloudflare R2, MinIO, NCP 등)는 위 [S3 호환 스토리지 연결](#s3-호환-스토리지-연결) 절의 두 항목(엔드포인트 URL·Path-style 주소)을 함께 설정
4. 연결 테스트 성공 후 저장 — 코어 첨부 업로드 디스크가 s3 로 전환됩니다
   (`ATTACHMENT_DISK` env 를 명시한 경우 env 가 항상 우선. 기존 파일은 저장 당시의 disk 로 계속 서빙되므로 혼재 안전)

참고: **S3 URL(공개 URL)** 칸은 파일 공개 URL 생성에 쓰는 CDN/커스텀 도메인이며, API 요청 주소가 아닙니다.

**② `.env` (모듈별 커스텀)**:

1. `.env` 파일에 S3 설정 추가:
   ```env
   AWS_ACCESS_KEY_ID=your-key
   AWS_SECRET_ACCESS_KEY=your-secret
   AWS_DEFAULT_REGION=ap-northeast-2
   AWS_BUCKET=your-bucket
   # S3 호환 스토리지(R2/MinIO 등)만:
   AWS_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
   AWS_USE_PATH_STYLE_ENDPOINT=false
   ```

2. 모듈 설정 변경:
   ```php
   // config/sirsoft-board.php
   return [
       'attachment' => [
           'disk' => env('SIRSOFT_BOARD_ATTACHMENT_DISK', 's3'),
       ],
   ];
   ```

3. `AbstractModule`에서 오버라이드:
   ```php
   public function getStorageDisk(): string
   {
       return config('sirsoft-board.attachment.disk', 's3');
   }
   ```

**끝!** Service 코드는 변경할 필요가 없습니다.

**직접 URL(CDN) 서빙까지 원한다면**: `filesystems.disks.s3.url`(관리자 환경설정의
S3 URL — CDN 도메인)이 반드시 설정되어야 합니다. url 이 비어 있으면 `url()` 은
null 을 반환해 스트리밍 경로가 유지됩니다.

---

### Q3: 여러 disk를 동시에 사용할 수 있나요?

**A**: 네, `getStorageDiskFor(string $category)` 를 오버라이드하면 카테고리별로 다른
disk 를 사용할 수 있습니다. `getStorageFor($category)` 가 그 결정에 따라 디스크 단위로
memoize 된 인스턴스를 돌려주고, ServiceProvider 의 `$storageCategoryServices` 매핑이
해당 서비스에 카테고리 디스크 스토리지를 자동 주입합니다.

```php
<?php

namespace Modules\Sirsoft\Ecommerce;

use App\Extension\AbstractModule;

class Module extends AbstractModule
{
    /**
     * 카테고리별 디스크 결정 — 기본 구현은 getStorageDisk() 와 동일.
     *
     * 주의: 'settings' 카테고리에서 모듈 설정을 조회하면 설정 로드와 재귀 고리가
     * 생깁니다. 설정 조회는 설정 저장과 무관한 카테고리에서만 수행하세요.
     */
    public function getStorageDiskFor(string $category): string
    {
        if ($category !== 'images') {
            return $this->getStorageDisk();
        }

        // 공개 자산 디스크 해석 (확장 개별 설정 > 코어 전역 > 미설정 → 기본 디스크)
        $override = module_setting('sirsoft-ecommerce', 'basic_info.public_asset_disk', '');

        return $this->resolvePublicAssetDisk(is_string($override) ? $override : '')
            ?? $this->getStorageDisk();
    }
}
```

```php
// ServiceProvider — 카테고리 디스크가 필요한 서비스는 $storageCategoryServices 에 매핑
protected array $storageCategoryServices = [
    ProductImageService::class => 'images',
];
```

---

### Q4: 플러그인에서도 사용할 수 있나요?

**A**: 네, `PluginStorageDriver`를 사용하면 됩니다.

```php
<?php

namespace Plugins\Sirsoft\Payment;

use App\Extension\AbstractPlugin;
use App\Contracts\Extension\StorageInterface;
use App\Extension\Storage\PluginStorageDriver;

class PaymentPlugin extends AbstractPlugin
{
    public function getStorage(): StorageInterface
    {
        return new PluginStorageDriver($this->getIdentifier(), $this->getStorageDisk());
    }

    public function getStorageDisk(): string
    {
        return config('sirsoft-payment.storage_disk', 'local');
    }
}
```

경로 패턴만 다릅니다:
```
plugins/{identifier}/{category}/{path}
```

---

### Q5: 단위 테스트는 어떻게 작성하나요?

**A**: `StorageInterface`를 Mock하여 테스트합니다.

```php
<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

use App\Contracts\Extension\StorageInterface;
use Mockery;
use Tests\TestCase;

class AttachmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttachmentService $service;
    private $repository;
    private $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock 생성
        $this->repository = Mockery::mock(AttachmentRepositoryInterface::class);
        $this->storage = Mockery::mock(StorageInterface::class);

        // Service 생성
        $this->service = new AttachmentService($this->repository, $this->storage);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_upload_stores_file_and_creates_record(): void
    {
        // Arrange
        $file = UploadedFile::fake()->create('document.pdf', 100);

        // Storage Mock 설정
        $this->storage
            ->shouldReceive('put')
            ->once()
            ->withArgs(function ($category, $path, $contents) {
                return $category === 'attachments'
                    && str_contains($path, 'notice/')
                    && str_ends_with($path, '.pdf');
            })
            ->andReturn(true);

        $this->storage
            ->shouldReceive('getDisk')
            ->andReturn('local');

        // Repository Mock 설정
        $expectedAttachment = new DynamicAttachment(['id' => 1]);
        $this->repository->shouldReceive('create')->andReturn($expectedAttachment);

        // Act
        $result = $this->service->upload('notice', $file, 1);

        // Assert
        $this->assertEquals(1, $result->id);
    }
}
```

---

### Q6: 성능 최적화 팁이 있나요?

**A**: 다음 패턴을 권장합니다.

1. **대용량 파일은 스트리밍 사용**:
   ```php
   // ❌ 메모리에 모두 로드
   $content = file_get_contents($file->getRealPath());
   $this->storage->put('attachments', $path, $content);

   // ✅ 스트리밍 사용
   $resource = fopen($file->getRealPath(), 'r');
   $this->storage->put('attachments', $path, $resource);
   fclose($resource);
   ```

2. **배치 삭제**:
   ```php
   // ❌ 파일 하나씩 삭제
   foreach ($files as $file) {
       $this->storage->delete('temp', $file);
   }

   // ✅ 디렉토리 전체 삭제
   $this->storage->deleteDirectory('temp', $sessionId);
   ```

3. **URL 캐싱**:
   ```php
   // ✅ URL을 DB에 캐시
   $url = $this->storage->url('images', $image->path);
   $image->update(['cached_url' => $url]);
   ```

---

## 공개 자산 전용 디스크 분리 (직접 URL/CDN 서빙)

완전 공개 자산(상품/카테고리/리뷰/에디터 이미지 등)을 S3+CDN 등 원격 디스크에서
직접 URL 로 서빙하는 옵트인 기능입니다. 미설정 시 기존 PHP 스트리밍이 100% 보존됩니다.

### 설정 사슬

```text
관리자 환경설정 > 드라이버 탭 > 공개 자산 스토리지 (drivers.public_asset_disk)
  → SettingsServiceProvider 가 core.storage.public_asset_disk 로 주입
  → 확장의 getStorageDiskFor('images') 가 resolvePublicAssetDisk() 로 해석
     (우선순위: 확장 개별 설정 public_asset_disk > 코어 전역 > 미설정 → 기본 디스크)
  → ServiceProvider $storageCategoryServices 매핑으로 업로드 서비스에 주입
  → put() 이 그 디스크에 저장, 행 disk 컬럼에 기록
  → 모델 download_url accessor 가 행 disk 로 url() 시도 → null 이면 API 경로 폴백
```

- 선택지 카탈로그는 코어 3종(`none`/`public`/`s3`) + 플러그인이
  `core.settings.available_public_asset_drivers` 필터 훅으로 등록한 디스크입니다.
  플러그인은 자기 ServiceProvider 에서 `Config::set('filesystems.disks.{id}', [... 'url' => CDN])`
  로 디스크를 정의하고 훅에 `{id, label, provider}` 를 append 합니다.
- 확장 개별 오버라이드 화면의 카탈로그는 **그 확장의 설정 조회 응답에 부착**해 내립니다
  (`available_public_asset_disks` 키 — ecommerce 는 모듈 설정 컨트롤러가, 플러그인은 설정
  스키마에 `public_asset_disk` 선언 시 코어 플러그인 설정 API 가 자동 부착). 화면이 코어
  환경설정 API 를 교차 조회하면 화면 권한과 카탈로그 권한(core.settings.read)이 갈려
  커스텀 역할에서 선택지만 조용히 비게 됩니다.
- `none` 은 스트리밍 유지(확장 개별 설정에서는 전역이 CDN 이어도 강제 스트리밍)입니다.
- 플러그인 비활성화로 디스크가 config 에서 사라지면(고아 디스크) 자동으로 스트리밍
  폴백합니다 — 저장값은 보존되므로 재활성화 시 되살아납니다.

### 혼재 운용

디스크 전환 시 기존 파일 이동이 필요 없습니다. 행마다 기록된 disk 를 기준으로
서빙/삭제/이동이 동작하므로, 전환 이전 로컬 행은 스트리밍으로, 이후 원격 행은
직접 URL 로 각자 올바르게 서빙됩니다.

행에 기록된 disk 가 고아가 된 경우(그 디스크를 제공하던 플러그인을 비활성화한 뒤에도
그 disk 로 기록된 행이 남아 있는 경우)에도 서빙·삭제는 예외 없이 동작합니다. 행 disk
기준 스토리지를 만들 때 디스크 설정 존재 여부를 먼저 확인하고, 없으면 확장의 기본
디스크로 폴백하기 때문입니다. 파일 자체는 도달 불가이므로 그 이미지는 404 가 되지만,
목록·상세 화면과 삭제(상품/카테고리/리뷰 삭제 포함)는 정상 동작합니다. 이 검증을
생략하면 미등록 디스크 접근이 예외가 되어 서빙과 삭제가 모두 500 이 됩니다.

### 버킷/CDN 쪽 요구사항

직접 URL 서빙은 브라우저가 그 URL 을 **인증 없이** 읽는다는 전제 위에 있습니다.
따라서 대상 버킷/배포는 다음을 만족해야 합니다.

| 요구사항 | 미충족 시 증상 |
| --- | --- |
| 객체가 익명 읽기 가능 (버킷 정책 `s3:GetObject` 공개 또는 CDN 공개 배포) | 화면의 그 이미지들이 전부 깨짐 (S3 는 `403 AccessDenied` 를 XML 로 응답) |
| 공개 URL base(`S3 URL`)가 그 객체를 가리킴 | `url()` 이 null → 스트리밍 폴백(기능은 정상, CDN 이점만 없음) |

관리자 화면의 썸네일은 교차 출처 공개 URL 을 `<img>` 로 직접 사용하므로 CORS 설정은
필요하지 않습니다. 다만 그 URL 을 자바스크립트로 읽는 커스텀 확장을 만든다면 그때는
버킷/CDN 에 CORS 규칙이 필요합니다.

교차 출처 URL 에는 코어 API 클라이언트가 인증 토큰을 붙이지 않습니다 — 공개 자산은
인증이 필요 없고, 세션 토큰이 제3자 origin 으로 나가서는 안 되기 때문입니다.
같은 이유로 첨부 썸네일·다운로드도 교차 출처 URL 이면 인증 요청 대신 URL 을 직접 씁니다.

### 서명 URL 등 커스텀 URL 공급

`core.storage.filter_url` 필터 훅이 디스크 무관 항상 발화하므로, 확장이 서명 URL 을
공급하거나 생성된 URL 을 수정/차단할 수 있습니다 (url() 절 참조).

### 보안 전제

권한 검사가 걸린 첨부파일(비밀글/회원 전용 게시판 첨부 등)은 배선 자체가 없습니다 —
직접 URL 은 서버 스트리밍 경로의 권한 검사를 우회하므로, 완전 공개 자산 카테고리에만
공개 자산 디스크를 적용합니다.

---

## 관련 문서

- [모듈 개발 가이드](module-basics.md)
- [플러그인 개발 가이드](plugin-development.md)
- [Service-Repository 패턴](../backend/service-repository.md)
- [테스트 작성 가이드](../testing-guide.md)

---

**작성일**: 2024-01-19
**최종 수정**: 2024-01-19
**버전**: 1.0.0
