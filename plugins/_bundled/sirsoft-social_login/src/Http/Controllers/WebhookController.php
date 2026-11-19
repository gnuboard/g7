<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Controllers;

use App\Helpers\TimezoneHelper;
use App\Support\SiteUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Plugins\Sirsoft\SocialLogin\Enums\WebhookStatus;
use Plugins\Sirsoft\SocialLogin\Http\Requests\Webhooks\FacebookSignedRequest;
use Plugins\Sirsoft\SocialLogin\Http\Requests\Webhooks\KakaoWebhookRequest;
use Plugins\Sirsoft\SocialLogin\Http\Requests\Webhooks\NaverWebhookRequest;
use Plugins\Sirsoft\SocialLogin\Services\WebhookReceiver;
use Plugins\Sirsoft\SocialLogin\Services\WebhookStatusService;
use Plugins\Sirsoft\SocialLogin\Support\HtmlPage;

/**
 * 제공자 웹훅 수신 — 카카오 연결 끊기 · 네이버 연동 해제 · 페이스북 앱 권한 해제 · 페이스북 데이터 삭제 요청 · 삭제 상태 페이지.
 *
 * 제공자마다 기대하는 응답이 다르다:
 *  - 카카오: 검증에 실패해도 언제나 200 (실패 응답이 쌓이면 카카오가 알림을 멈춘다 — 거절은 수신 이력에 `ignored` 로 남긴다)
 *  - 네이버: 204 (다시 보내지 않으므로 검증·저장은 요청 안에서 끝낸다)
 *  - 페이스북 권한 해제: 200 / 데이터 삭제: `{url, confirmation_code}` — 서명이 틀리면 400
 * 응답 본문은 제공자 규약 모양 그대로다(사이트 API 봉투를 씌우면 제공자가 읽지 못한다).
 *
 * @since 1.0.0
 */
class WebhookController extends Controller
{
    /**
     * @param  WebhookReceiver  $receiver  수신 서비스
     * @param  WebhookStatusService  $status  삭제 상태 조회
     */
    public function __construct(
        private readonly WebhookReceiver $receiver,
        private readonly WebhookStatusService $status,
    ) {}

    /**
     * 카카오 연결 끊기 알림.
     *
     * @param  KakaoWebhookRequest  $request  알림 요청
     * @return Response 언제나 200
     */
    public function kakao(KakaoWebhookRequest $request): Response
    {
        $this->receiver->receive('kakao', $request);

        return response('', 200);
    }

    /**
     * 네이버 연동 해제 알림.
     *
     * @param  NaverWebhookRequest  $request  알림 요청
     * @return Response 204
     */
    public function naver(NaverWebhookRequest $request): Response
    {
        $this->receiver->receive('naver', $request);

        return response('', 204);
    }

    /**
     * 페이스북 앱 권한 해제 콜백.
     *
     * @param  FacebookSignedRequest  $request  콜백 요청
     * @return JsonResponse 200 또는 서명 오류 400
     */
    public function facebookDeauthorize(FacebookSignedRequest $request): JsonResponse
    {
        $request->attributes->set('webhook_kind', 'deauthorize');
        $result = $this->receiver->receive('facebook', $request);

        if ($result['result'] === WebhookReceiver::IGNORED) {
            // audit:allow response-helper-bypass reason: 페이스북 콜백 응답 — 제공자가 읽는 최소 본문(봉투 없음)
            return response()->json(['error' => $result['error']], 400);
        }

        // audit:allow response-helper-bypass reason: 페이스북 콜백 응답 — 제공자가 읽는 최소 본문(봉투 없음)
        return response()->json(['success' => true]);
    }

    /**
     * 페이스북 데이터 삭제 요청 콜백 — 회원이 삭제 진행을 확인할 주소와 확인 코드를 돌려줍니다.
     *
     * @param  FacebookSignedRequest  $request  콜백 요청
     * @return JsonResponse `{url, confirmation_code}` 또는 서명 오류 400
     */
    public function facebookDataDeletion(FacebookSignedRequest $request): JsonResponse
    {
        $request->attributes->set('webhook_kind', 'data_deletion');
        $result = $this->receiver->receive('facebook', $request);

        if ($result['result'] === WebhookReceiver::IGNORED || $result['event']?->confirmation_code === null) {
            // audit:allow response-helper-bypass reason: 페이스북 데이터 삭제 콜백 응답 — 제공자 규약 본문(봉투 없음)
            return response()->json(['error' => $result['error'] ?? 'webhook_payload_invalid'], 400);
        }

        $code = (string) $result['event']->confirmation_code;

        // audit:allow response-helper-bypass reason: 페이스북 데이터 삭제 콜백 규약 — 최상위 url·confirmation_code 를 읽는다
        return response()->json([
            'url' => SiteUrl::absolute(route('web.plugins.sirsoft-social_login.data-deletion.status', ['code' => $code], false)),
            'confirmation_code' => $code,
        ]);
    }

    /**
     * 데이터 삭제 진행 상태 페이지.
     *
     * @param  string  $code  확인 코드
     * @return Response HTML (없는 코드는 404)
     */
    public function dataDeletionStatus(string $code): Response
    {
        $title = __('sirsoft-social_login::messages.data_deletion.title');
        $event = $this->status->deletionRequest($code);

        if ($event === null) {
            return HtmlPage::render($title, '<p class="bad">'.e(__('sirsoft-social_login::messages.data_deletion.not_found')).'</p>', 404);
        }

        $done = $event->status === WebhookStatus::Processed->value || $event->status === WebhookStatus::Ignored->value;
        $statusText = __('sirsoft-social_login::messages.data_deletion.'.($done ? 'done' : ($event->status === WebhookStatus::Failed->value ? 'failed' : 'pending')));

        $body = '<p class="'.($done ? 'ok' : 'bad').'">'.e($statusText).'</p>'.HtmlPage::definitionList([
            __('sirsoft-social_login::messages.data_deletion.code') => $code,
            __('sirsoft-social_login::messages.data_deletion.received_at') => TimezoneHelper::toUserDateTimeString($event->received_at) ?? '',
            __('sirsoft-social_login::messages.data_deletion.processed_at') => TimezoneHelper::toUserDateTimeString($event->processed_at) ?? '-',
        ]).'<p>'.e(__('sirsoft-social_login::messages.data_deletion.note')).'</p>';

        return HtmlPage::render($title, $body);
    }
}
