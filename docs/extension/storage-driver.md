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
- `string|null`: 파일 URL (private disk인 경우 null)

**예시**:
```php
// public disk인 경우
$url = $this->storage->url('images', 'product/2024/01/19/uuid.jpg');
// → http://example.com/storage/modules/sirsoft-ecommerce/images/product/2024/01/19/uuid.jpg

// local (private) disk인 경우
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
- private disk (local, s3 등)를 사용 중입니다. URL 메서드는 public disk에서만 직접 URL을 반환합니다.

**해결**:
```php
// public disk를 사용하거나
$module->getStorageDisk(); // → 'public'

// 또는 별도 API 엔드포인트를 사용
Route::get('/api/attachments/{id}/download', [AttachmentController::class, 'download']);
```

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

**A**: 다음 3단계만 수행하면 됩니다.

1. `.env` 파일에 S3 설정 추가:
   ```env
   AWS_ACCESS_KEY_ID=your-key
   AWS_SECRET_ACCESS_KEY=your-secret
   AWS_DEFAULT_REGION=ap-northeast-2
   AWS_BUCKET=your-bucket
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

---

### Q3: 여러 disk를 동시에 사용할 수 있나요?

**A**: 네, 카테고리별로 다른 disk를 사용할 수 있습니다.

```php
<?php

namespace Modules\Sirsoft\Ecommerce;

use App\Extension\AbstractModule;
use App\Contracts\Extension\StorageInterface;
use App\Extension\Storage\ModuleStorageDriver;

class EcommerceModule extends AbstractModule
{
    /**
     * 카테고리별 Storage 인스턴스 캐시
     */
    private array $storageCache = [];

    /**
     * 카테고리별로 다른 Storage를 반환합니다.
     */
    public function getStorage(string $category = 'default'): StorageInterface
    {
        if (isset($this->storageCache[$category])) {
            return $this->storageCache[$category];
        }

        // 카테고리별 disk 설정
        $disk = match ($category) {
            'images' => config('sirsoft-ecommerce.image_disk', 's3'),
            'attachments' => config('sirsoft-ecommerce.attachment_disk', 'local'),
            default => $this->getStorageDisk(),
        };

        $this->storageCache[$category] = new ModuleStorageDriver($this->getIdentifier(), $disk);

        return $this->storageCache[$category];
    }
}
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

## 관련 문서

- [모듈 개발 가이드](module-basics.md)
- [플러그인 개발 가이드](plugin-development.md)
- [Service-Repository 패턴](../backend/service-repository.md)
- [테스트 작성 가이드](../testing-guide.md)

---

**작성일**: 2024-01-19
**최종 수정**: 2024-01-19
**버전**: 1.0.0
