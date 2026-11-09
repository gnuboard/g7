<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Enums\PermissionType;
use App\Exceptions\ContentMediaLimitExceededException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ContentMediaTargetRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UploadContentMediaRequest;
use Modules\Sirsoft\Ecommerce\Services\ContentMediaService;

/**
 * 관리자 이커머스 본문 미디어 컨트롤러 — 상품 설명·공통 정보 편집기 업로드 계약과 업로드 (#883 4단계)
 *
 * 라우트 권한은 상품·공통 정보 등록·수정 중 하나(OR)이고, 소유 종류별 권한은 여기서 다시 본다 — 상품이면 상품 등록·수정,
 * 공통 정보면 공통 정보 등록·수정.
 */
class ContentMediaController extends AdminBaseController
{
    /**
     * @param  ContentMediaService  $contentMediaService  본문 미디어 서비스
     */
    public function __construct(private ContentMediaService $contentMediaService)
    {
        parent::__construct();
    }

    /**
     * 편집기에 넘길 업로드 계약 (`html_editor` 의 `mediaUpload`).
     *
     * @param  ContentMediaTargetRequest  $request  요청
     * @return JsonResponse 계약 (받는 종류가 없으면 data = null)
     */
    public function target(ContentMediaTargetRequest $request): JsonResponse
    {
        $ownerType = ContentMediaOwnerType::from((string) $request->validated('owner_type'));
        if (! $this->canUploadFor($ownerType)) {
            return $this->error('auth.scope_denied', 403);
        }

        return $this->success('sirsoft-ecommerce::messages.content_media.target_success', $this->contentMediaService->uploadTarget($ownerType));
    }

    /**
     * 본문 미디어 업로드 — 응답 `data.url` 은 서명된 서빙 주소다.
     *
     * @param  UploadContentMediaRequest  $request  업로드 요청
     * @return JsonResponse 201 `{data: {id, hash, kind, url}}`
     */
    public function upload(UploadContentMediaRequest $request): JsonResponse
    {
        $ownerType = ContentMediaOwnerType::from((string) $request->validated('owner_type'));
        if (! $this->canUploadFor($ownerType)) {
            return $this->error('auth.scope_denied', 403);
        }

        try {
            $media = $this->contentMediaService->upload($ownerType, $request->file('file'), (string) $request->validated('temp_key'));

            return $this->success('sirsoft-ecommerce::messages.content_media.upload_success', [
                'id' => $media->id,
                'hash' => $media->hash,
                'kind' => $media->kind,
                'url' => $this->contentMediaService->signedUrl($media->hash),
            ], 201);
        } catch (ContentMediaLimitExceededException $e) {
            return $this->error($e->getMessageKey(), 422, ['code' => 'content_media_limit_exceeded'], $e->getMessageParams());
        } catch (\Exception $e) {
            report($e);

            return $this->error('sirsoft-ecommerce::messages.content_media.upload_failed', 500, $e->getMessage());
        }
    }

    /**
     * 소유 종류별 업로드 권한 — 상품은 상품 등록·수정, 공통 정보는 공통 정보 등록·수정.
     *
     * @param  ContentMediaOwnerType  $ownerType  소유 종류
     * @return bool 권한이 있으면 true
     */
    private function canUploadFor(ContentMediaOwnerType $ownerType): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        $resource = $ownerType === ContentMediaOwnerType::Product ? 'products' : 'product-common-infos';

        return $user->hasPermission("sirsoft-ecommerce.{$resource}.create", PermissionType::Admin)
            || $user->hasPermission("sirsoft-ecommerce.{$resource}.update", PermissionType::Admin);
    }
}
