<?php

namespace Modules\Sirsoft\Page\Http\Controllers\Admin;

use App\Exceptions\ContentMediaLimitExceededException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Controllers\Concerns\RespondsWithContentMediaTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Page\Http\Requests\UploadPageContentMediaRequest;
use Modules\Sirsoft\Page\Services\PageContentMediaService;

/**
 * 관리자 페이지 본문 미디어 컨트롤러 — 업로드·편집기 업로드 계약 (#883 4단계, #887 표준 계약 엔드포인트)
 *
 * 등록 화면과 수정 화면이 같이 쓰므로 라우트 권한은 등록·수정 어느 쪽이든(OR)이다. 서빙은 공개 경로
 * (`GET pages/content-media/{hash}`)가 맡는다.
 */
class PageContentMediaController extends AdminBaseController
{
    use RespondsWithContentMediaTarget;

    /**
     * @param  PageContentMediaService  $contentMediaService  본문 미디어 서비스
     */
    public function __construct(private PageContentMediaService $contentMediaService)
    {
        parent::__construct();
    }

    /**
     * 편집기 본문 미디어 업로드 계약 — 표준 계약 엔드포인트 `GET admin/content-media/target` (#887).
     *
     * 페이지 편집 화면이 `html_editor` 확장점의 `mediaUpload` 로 넘길 계약을 돌려준다. 값은 페이지 모듈 설정(`content_media`,
     * 비면 첨부 정책)이고, 받는 종류가 없으면 `data: null` 이다. 옛 길(`GET admin/attachments/settings` 의 `content_media`)도 같은
     * 값을 싣는다.
     *
     * @return JsonResponse 200 `{data: ContentMediaUploadTarget|null}` / 403
     */
    public function target(): JsonResponse
    {
        return $this->contentMediaTargetResponse(
            $this->contentMediaService->flow(),
            $this->contentMediaService->context(),
            'sirsoft-page::messages.content_media.target_success',
            admin: true,
        );
    }

    /**
     * 본문 미디어 업로드 — 응답 `data.url` 은 서명된 서빙 주소(편집 중 미리보기)다.
     *
     * @param  UploadPageContentMediaRequest  $request  업로드 요청
     * @return JsonResponse 201 `{data: {id, hash, kind, url}}`
     *
     * @throws ValidationException 업로드 파일 필터(`core.content_media.filter_upload_file`)가 돌려준 파일이 정책에 맞지 않을 때 (422)
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
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.content_media.upload_failed', 500, $e->getMessage());
        }
    }
}
