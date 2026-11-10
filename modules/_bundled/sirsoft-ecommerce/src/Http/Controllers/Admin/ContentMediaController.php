<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Exceptions\ContentMediaLimitExceededException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Controllers\Concerns\RespondsWithContentMediaTarget;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ContentMediaTargetRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UploadContentMediaRequest;
use Modules\Sirsoft\Ecommerce\Services\ContentMediaService;

/**
 * 관리자 이커머스 본문 미디어 컨트롤러 — 상품 설명·공통 정보 편집기 업로드 계약과 업로드 (#883 4단계)
 *
 * 라우트 권한은 상품·공통 정보 등록·수정 중 하나(OR)이고, 소유 종류별 권한은 어댑터(`EcommerceContentMediaStore::canUpload`)가 다시
 * 본다 — 상품이면 상품 등록·수정, 공통 정보면 공통 정보 등록·수정. 업로드 계약은 코어 표준 계약 엔드포인트 도우미
 * (`RespondsWithContentMediaTarget`)로 낸다(#887 — 게시판·페이지·플러그인과 같은 응답 모양).
 */
class ContentMediaController extends AdminBaseController
{
    use RespondsWithContentMediaTarget;

    /**
     * @param  ContentMediaService  $contentMediaService  본문 미디어 서비스
     */
    public function __construct(private ContentMediaService $contentMediaService)
    {
        parent::__construct();
    }

    /**
     * 편집기에 넘길 업로드 계약 (`html_editor` 의 `mediaUpload`) — 표준 계약 엔드포인트 `GET admin/content-media/target`.
     *
     * @param  ContentMediaTargetRequest  $request  요청 (`owner_type`)
     * @return JsonResponse 200 계약(받는 종류가 없으면 data = null) / 403 `auth.scope_denied`
     */
    public function target(ContentMediaTargetRequest $request): JsonResponse
    {
        $ownerType = ContentMediaOwnerType::from((string) $request->validated('owner_type'));
        $engine = $this->contentMediaService->engine();

        return $this->contentMediaTargetResponse(
            $engine,
            $engine->ecommerceStore()->context($ownerType),
            'sirsoft-ecommerce::messages.content_media.target_success',
            admin: true,
        );
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
        $engine = $this->contentMediaService->engine();
        if (! $engine->canUpload($engine->ecommerceStore()->context($ownerType))) {
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
}
