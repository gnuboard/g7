<?php

namespace App\Http\Controllers\Concerns;

use App\Support\ContentMedia\AbstractContentMediaService;
use App\Support\ContentMedia\ContentMediaContext;
use Illuminate\Http\JsonResponse;

/**
 * 표준 편집기 계약 엔드포인트 `GET …/content-media/target` 응답 도우미 (#887)
 *
 * 편집 화면이 「무엇을 올릴 수 있나」를 받는 길을 확장(모듈·플러그인)마다 같은 모양으로 낸다. 값(종류별 허용·크기·형식·
 * 문서당 최대)은 각 확장 설정이고, 코어는 동영상·오디오 업로드 허용 스위치로만 관여한다(`ContentMediaPolicy::acceptsUpload`).
 *
 * 응답: 성공 200 `{data: ContentMediaUploadTarget|null}`(지금 받는 종류가 없으면 `null`), 올릴 권한이 없으면 403
 * `auth.scope_denied`. `BaseApiController`(`success`·`error`) 계열 컨트롤러에서 쓴다.
 *
 * ```php
 * use RespondsWithContentMediaTarget;
 *
 * public function target(Request $request): JsonResponse
 * {
 *     $context = ContentMediaContext::plugin('vendor-notes', 'note')->withActor(Auth::id());
 *
 *     return $this->contentMediaTargetResponse($this->media, $context, 'vendor-notes::messages.content_media.target_success', admin: true);
 * }
 * ```
 *
 * @since 7.1.5
 */
trait RespondsWithContentMediaTarget
{
    /**
     * 편집기 업로드 계약 응답을 만듭니다.
     *
     * @param  AbstractContentMediaService  $service  확장의 본문 미디어 서비스
     * @param  ContentMediaContext  $context  문맥 (요청자 포함)
     * @param  string  $successMessageKey  성공 문구 키
     * @param  bool  $admin  관리자 화면 여부 (업로드 주소)
     * @param  array<string, string>  $headers  함께 보낼 헤더 (허용 목록만 실린다)
     * @return JsonResponse 200 계약(또는 null) / 403
     */
    protected function contentMediaTargetResponse(
        AbstractContentMediaService $service,
        ContentMediaContext $context,
        string $successMessageKey,
        bool $admin = false,
        array $headers = [],
    ): JsonResponse {
        if (! $service->canUpload($context)) {
            return $this->error('auth.scope_denied', 403);
        }

        return $this->success($successMessageKey, $service->uploadTarget($context, $admin, $headers));
    }
}
