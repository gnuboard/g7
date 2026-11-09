<?php

namespace Modules\Sirsoft\Page\Http\Controllers\Admin;

use App\Exceptions\ContentMediaLimitExceededException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Page\Http\Requests\UploadPageContentMediaRequest;
use Modules\Sirsoft\Page\Services\PageContentMediaService;

/**
 * 관리자 페이지 본문 미디어 업로드 컨트롤러 (#883 4단계)
 *
 * 등록 화면과 수정 화면이 같이 쓰므로 라우트 권한은 등록·수정 어느 쪽이든(OR)이다. 서빙은 공개 경로
 * (`GET pages/content-media/{hash}`)가 맡는다.
 */
class PageContentMediaController extends AdminBaseController
{
    /**
     * @param  PageContentMediaService  $contentMediaService  본문 미디어 서비스
     */
    public function __construct(private PageContentMediaService $contentMediaService)
    {
        parent::__construct();
    }

    /**
     * 본문 미디어 업로드 — 응답 `data.url` 은 서명된 서빙 주소(편집 중 미리보기)다.
     *
     * @param  UploadPageContentMediaRequest  $request  업로드 요청
     * @return JsonResponse 201 `{data: {id, hash, kind, url}}`
     */
    public function upload(UploadPageContentMediaRequest $request): JsonResponse
    {
        try {
            $attachment = $this->contentMediaService->upload($request->file('file'), (string) $request->validated('temp_key'));

            return $this->success('sirsoft-page::messages.content_media.upload_success', [
                'id' => $attachment->id,
                'hash' => $attachment->hash,
                'kind' => $attachment->meta['kind'] ?? null,
                'url' => $this->contentMediaService->signedUrl($attachment->hash),
            ], 201);
        } catch (ContentMediaLimitExceededException $e) {
            return $this->error($e->getMessageKey(), 422, ['code' => 'content_media_limit_exceeded'], $e->getMessageParams());
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.content_media.upload_failed', 500, $e->getMessage());
        }
    }
}
