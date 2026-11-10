<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Exceptions\ContentMediaLimitExceededException;
use App\Support\ContentMedia\ContentMediaPolicy;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Models\ContentMedia;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\ContentMedia\EcommerceContentMediaService;
use Modules\Sirsoft\Ecommerce\Services\ContentMedia\EcommerceContentMediaStore;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 이커머스 본문 미디어 서비스 — 상품 설명·상품 공통 정보 편집기 본문의 이미지·동영상·오디오 (#883 4단계)
 *
 * 상품·공통 정보 서비스, 리소스, 컨트롤러, 정리 명령이 쓰는 종전 호출 모양(소유 종류 + 소유자 ID)을 유지하는 얇은 진입점이다.
 * 흐름은 코어 골격(`EcommerceContentMediaService` → `AbstractContentMediaService`)이 돌리고, 저장소·권한은 이 모듈 어댑터
 * (`EcommerceContentMediaStore`)가 답한다(#887).
 *
 * - 저장: 이 모듈 범용 첨부 표 `ecommerce_attachments` 의 `content_media` 컬렉션(`ContentMedia`). 파일은 처음부터 모듈 저장소
 *   `content-media` 카테고리의 최종 경로 `{owner_type}/{Y/m/d}/{uuid}.ext`, 행은 저장 전까지 임시(소유자 null + 임시 키)다.
 * - 저장 동기화: 상품·공통 정보 저장 트랜잭션 안에서 본문(로케일마다)이 참조한 것을 **연결 → 복제 → 제거** 순서로 맞춘다. 빠진 것은
 *   소프트 삭제하고, 빠진 지 보존기간(기본 2일)이 지나면 임시 상품 이미지 정리 명령이 파일째 지운다(`pruneTempUploads`).
 * - 서빙 게이트: 상품은 「전시 중 또는 관리자(상품 읽기 권한) 또는 서명」, 공통 정보는 공개. 캐시는 아무나 볼 수 있을 때(공통 정보·
 *   전시 중 상품)만 public, 미공개 상품·임시 행은 private.
 * - 응답 본문 서명: 공개되지 않은 상품일 때 **그 상품이 소유한 해시만** 서명한다(다른 상품 해시는 정식 주소 — 원본 게이트를 따른다).
 * - 정책: 모듈 설정 `product_display.content_media`, 비어 있으면 상품 이미지 규칙(동영상·오디오는 코어 「본문 콘텐츠」 스위치).
 */
class ContentMediaService
{
    /** 서빙 라우트 이름 */
    public const SERVE_ROUTE = EcommerceContentMediaStore::SERVE_ROUTE;

    /** 서빙 주소 앞부분 (정식 주소 = 이 값 + 해시) */
    public const SERVE_URL_PREFIX = EcommerceContentMediaStore::SERVE_URL_PREFIX;

    /** 서빙 주소 정규식 본문 */
    public const SERVE_PATTERN = EcommerceContentMediaStore::SERVE_PATTERN;

    /** 업로드 시점 상한 배수 */
    public const UPLOAD_HEADROOM = EcommerceContentMediaService::UPLOAD_HEADROOM;

    /** 저장소 카테고리 */
    public const CATEGORY = EcommerceContentMediaStore::CATEGORY;

    /**
     * @param  EcommerceContentMediaService  $media  골격 서비스
     */
    public function __construct(
        private EcommerceContentMediaService $media,
    ) {}

    /**
     * 코어 골격 서비스 — 표준 계약 엔드포인트(`RespondsWithContentMediaTarget`)·소유자 삭제가 쓴다.
     *
     * @return EcommerceContentMediaService 골격 서비스
     */
    public function engine(): EcommerceContentMediaService
    {
        return $this->media;
    }

    /**
     * 본문 미디어 정책 — 모듈 설정 `product_display.content_media`, 없으면 상품 이미지 규칙(동영상·오디오는 코어 「본문 콘텐츠」 스위치).
     *
     * @return ContentMediaPolicy 정책 (소유 종류와 무관)
     */
    public function resolvePolicy(): ContentMediaPolicy
    {
        return $this->media->policy($this->store()->context(ContentMediaOwnerType::Product));
    }

    /**
     * 본문 미디어를 임시로 올립니다 — 공용 훅 `core.content_media.before_upload`·`filter_upload_file`·`after_upload` 가 발화한다.
     *
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @param  UploadedFile  $file  파일 (요청 계층이 정책으로 검증함)
     * @param  string  $tempKey  임시 키
     * @return ContentMedia 본문 미디어 행
     *
     * @throws ContentMediaLimitExceededException 같은 임시 키 업로드가 상한을 넘을 때
     */
    public function upload(ContentMediaOwnerType $ownerType, UploadedFile $file, string $tempKey): ContentMedia
    {
        /** @var ContentMedia $media */
        $media = $this->media->upload($this->store()->context($ownerType, null, $tempKey), $file);

        return $media;
    }

    /**
     * 본문(로케일 배열 또는 HTML)과 본문 미디어를 맞춥니다 — 저장 트랜잭션 안에서 부른다.
     *
     * 순서는 연결 → 복제 → 제거다(#887 — 세 확장 공통 골격). 다른 소유자(다른 상품·공통 정보, 또는 요청자가 다른 임시 키로 올린 것)
     * 본문 미디어는 요청자가 원본을 볼 수 있을 때만 이 소유자로 복제하고, 복제분도 문서당 최대 개수에 센다.
     *
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @param  int  $ownerId  소유자 ID
     * @param  mixed  $content  저장된 본문
     * @param  bool  $isHtml  HTML 모드 여부
     * @param  string|null  $tempKey  요청의 임시 키
     * @return array{linked: int, removed: int, cloned: int, content: mixed} 처리 결과 — 복제했으면 `content` 가 새 해시로 바꾼 본문이고,
     *                                                                       호출자가 다시 저장한다
     *
     * @throws ContentMediaLimitExceededException 문서당 최대 개수 초과
     */
    public function syncWithContent(ContentMediaOwnerType $ownerType, int $ownerId, mixed $content, bool $isHtml, ?string $tempKey): array
    {
        return $this->media->syncWithContent($this->store()->context($ownerType, $ownerId, $tempKey), $content, $isHtml);
    }

    /**
     * 저장 전 본문(로케일 배열 또는 HTML)의 서명 주소를 정식 주소로 되돌립니다.
     *
     * @param  mixed  $content  본문
     * @return mixed 정규화한 본문
     */
    public function normalizeContent(mixed $content): mixed
    {
        return $this->media->normalizeContent($this->store()->context(ContentMediaOwnerType::Product), $content);
    }

    /**
     * 응답 본문의 정식 주소를 서명 주소로 바꿉니다 — 공개되지 않은 소유자일 때 그 소유자가 가진 해시만(#887). 공개면 정식 주소,
     * 봇 렌더도 정식 주소다.
     *
     * @param  mixed  $content  본문
     * @param  bool  $public  공개 소유자 여부
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @param  int|null  $ownerId  소유자 ID (저장 전이면 null — 서명할 해시 없음)
     * @return mixed 바꾼 본문
     */
    public function presentContent(mixed $content, bool $public, ContentMediaOwnerType $ownerType, ?int $ownerId): mixed
    {
        return $this->media->presentContent($this->store()->context($ownerType, $ownerId), $content, false, $public);
    }

    /**
     * 상품이 공개(전시 중)인지.
     *
     * @param  Product  $product  상품
     * @return bool 공개면 true
     */
    public static function isPublicProduct(Product $product): bool
    {
        return EcommerceContentMediaStore::isPublicProduct($product);
    }

    /**
     * 서명 주소.
     *
     * @param  string  $hash  해시
     * @param  DateTimeInterface|null  $expires  만료 (null 이면 설정값)
     * @return string 서명 주소 (상대 경로)
     */
    public function signedUrl(string $hash, ?DateTimeInterface $expires = null): string
    {
        return $this->media->signedUrl($this->store()->context(ContentMediaOwnerType::Product), $hash, $expires);
    }

    /**
     * 편집기에 넘길 업로드 계약 — 받는 종류가 없으면 null.
     *
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @return array<string, mixed>|null 계약
     */
    public function uploadTarget(ContentMediaOwnerType $ownerType): ?array
    {
        return $this->media->uploadTarget($this->store()->context($ownerType), true);
    }

    /**
     * 서빙할 본문 미디어를 찾고 게이트를 겁니다.
     *
     * @param  string  $hash  해시
     * @param  bool  $signatureVerified  서명 검증 여부
     * @return ContentMedia 본문 미디어
     *
     * @throws NotFoundHttpException 없을 때
     * @throws AccessDeniedHttpException 게이트를 통과하지 못할 때
     */
    public function findServable(string $hash, bool $signatureVerified): ContentMedia
    {
        /** @var ContentMedia $media */
        $media = $this->media->findServable($this->store()->context(ContentMediaOwnerType::Product), $hash, $signatureVerified);

        return $media;
    }

    /**
     * 서빙 응답 — 행에 기록된 디스크에서 읽는다. 캐시는 아무나 볼 수 있을 때만 public.
     *
     * @param  ContentMedia  $media  본문 미디어
     * @param  Request  $request  요청
     * @return Response|null 응답
     */
    public function respond(ContentMedia $media, Request $request): ?Response
    {
        $actor = Auth::id();
        $context = $this->store()->contextForRow($media)->withActor($actor !== null ? (int) $actor : null);

        return $this->media->respond($context, $media, $request);
    }

    /**
     * 오래된 본문 미디어를 파일과 함께 영구 삭제합니다 (임시 상품 이미지 정리 명령이 함께 부른다).
     *
     * 1. 끝내 잇지 않은 임시 행 — 올린 지 보존기간이 지난 것(상품·공통 정보 모두).
     * 2. 본문에서 빠져 소프트 삭제된 행 — 빠진 지 보존기간이 지난 것(#887).
     *
     * 행마다 `core.content_media.before_delete` 가 발화한다. `limit` 은 두 종류 각각에 적용한다.
     *
     * @param  int  $days  보존기간(일)
     * @param  int  $limit  최대 건수
     * @param  bool  $dryRun  true 면 세기만
     * @return array{scanned: int, deleted: int, failed: int} 결과
     */
    public function pruneTempUploads(int $days, int $limit, bool $dryRun = false): array
    {
        return $this->media->pruneStale($days, $limit, $dryRun);
    }

    /**
     * 이커머스 어댑터.
     *
     * @return EcommerceContentMediaStore 어댑터
     */
    private function store(): EcommerceContentMediaStore
    {
        return $this->media->ecommerceStore();
    }
}
