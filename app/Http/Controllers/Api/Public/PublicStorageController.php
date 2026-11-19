<?php

namespace App\Http\Controllers\Api\Public;

use App\Helpers\InlineMediaPolicy;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Requests\Public\ServePublicStorageFileRequest;
use App\Services\PublicStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 공개 스토리지(`storage/app/public`) 읽기 전용 서빙 (B-3).
 *
 * `public/storage` 심볼릭 링크를 만들 수 없는 호스팅의 대체 통로다. URL 은 서버가
 * 정한다(SSoT) — 링크 부재 시 `ResolvesPublicUrl`(public 디스크 URL 생성)이
 * `AssetUrl::publicStorage()` 로 이 라우트를 자동 선택하고, 화면·레이아웃은 서버가
 * 준 URL 을 그대로 쓴다.
 *
 * 확장자로 끝나는 와일드카드 자산 라우트이므로 dualAsset 이중 등록이 의무다 (R33).
 *
 * 응답은 BinaryFileResponse **스트리밍**이다 (R35 — 전체 적재 금지). 그 계약은 응답을
 * 만드는 것만으로는 지켜지지 않는다 — 전역 gzip 미들웨어가 압축 대상 유형의
 * `BinaryFileResponse` 를 일반 Response 로 치환하며 파일을 통째로 읽기 때문이다. 이
 * 통로는 인증도 요청 상한도 없어 그 전체 적재가 동시 요청 수만큼 곱해지므로, 압축에서
 * 명시적으로 빠진다(`GzipEncodeResponse::SKIP_ATTRIBUTE`). 링크가 되는 서버에서 같은
 * 파일을 내주는 정적 서빙도 애플리케이션 메모리를 쓰지 않으므로 두 통로가 같아진다.
 */
class PublicStorageController extends PublicBaseController
{
    /**
     * 내려받기로 돌리는 유형 — 브라우저가 문서로 열어 스크립트를 실행할 수 있는 유형.
     *
     * 이 통로가 내주는 `storage/app/public` 은 업로드물이 놓이는 자리다(회원 아바타는 public 디스크에
     * 쓰이고, public 디스크를 고른 확장의 업로드도 여기 놓인다). 그래서 업로드 파일 응답 규정
     * (`InlineMediaPolicy::harden()`)의 대상이다. 다만 이 통로는 링크가 되는 서버의 정적 서빙을
     * 대신하는 자리라 텍스트·문서·미디어·글꼴·스타일은 inline 이 기존 계약이다 — 보정은 앱 오리진에서
     * 문서로 열려 스크립트가 도는 유형으로 좁힌다. XML 은 XHTML 이름공간 요소의 스크립트를 실행한다.
     *
     * @var list<string>
     */
    private const ACTIVE_DOCUMENT_MIME_TYPES = [
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
        'application/xml',
    ];

    /**
     * @param  PublicStorageService  $publicStorageService  공개 스토리지 경로 해석 서비스
     */
    public function __construct(
        private readonly PublicStorageService $publicStorageService,
    ) {}

    /**
     * 공개 스토리지 파일을 서빙합니다.
     *
     * @param  ServePublicStorageFileRequest  $request  검증된 요청 (path)
     * @return BinaryFileResponse|JsonResponse|Response 파일 스트리밍 또는 오류 응답
     */
    // thumbnail-exempt: 링크가 되는 서버의 정적 /storage 서빙을 대신하는 통로다. 썸네일은 등록된 서빙 경로(/api/…)에만 붙는다(정적 경로는 대상 아님)
    public function serve(ServePublicStorageFileRequest $request): BinaryFileResponse|JsonResponse|Response
    {
        $path = (string) $request->validated('path');

        // 이 통로는 압축하지 않는다 (제품 결정 2026-08-25 · 6차 검증 H5).
        //
        // 압축 경로는 파일을 통째로 메모리에 읽는데, 여기는 무인증·무상한이라 그 적재가
        // 동시 요청 수만큼 곱해진다. 압축은 웹서버·CDN 의 몫이며, 링크가 되는 서버에서
        // 같은 파일을 내주는 정적 서빙도 애플리케이션 메모리를 쓰지 않는다 — 두 통로가
        // 같아야 한다는 것이 이 저장소의 파일 서빙 규율이다. 거부는 요청이 컨트롤러에
        // 들어온 시점에 선언한다(응답 종류·크기와 무관하게 일관되도록).
        //
        // 선언 수단은 헬퍼의 `skipCompression` 인자다 — 형제 스트림 통로(첨부·확장 이미지)와
        // 같은 자리. 종전에는 이 통로만 요청 속성을 직접 세웠다(헬퍼에 그 인자가 없었다).
        // 표식은 헬퍼가 **컨테이너에 바인딩된 요청**에 단다 — `ServePublicStorageFileRequest`
        // 는 `Request::createFrom()` 이 만든 별개 인스턴스라 그 객체에 달면 미들웨어에 닿지 않는다.
        $this->logApiUsage('system.public-storage', ['path' => $path]);

        $result = $this->publicStorageService->getFilePath($path);

        if (! $result['success']) {
            // 읽기 불가는 정적 서빙과 같은 403 — 존재는 이미 확정된 뒤의 판정이라 404 와 다르다.
            return match ($result['error']) {
                'path_not_allowed', 'file_not_readable' => $this->forbidden(),
                default => $this->notFound(),
            };
        }

        // 이 URL 에는 캐시 무효화 버전이 없다 — 같은 경로의 내용이 교체된다(첨부 재업로드 등).
        // 그래서 `immutable` 을 붙이지 않는다: 그 지시는 「만료 전까지 재확인하지 말라」는
        // 뜻이라, 붙이면 교체한 파일이 만료 시간만큼 옛것으로 남는다.
        //
        // 다만 `immutable` 을 뺐다고 재확인이 매번 일어나는 것은 아니다 — `max-age` 동안은
        // 여전히 캐시가 그대로 쓰이고 `must-revalidate` 는 **만료 후** 의 stale 사용을 막을
        // 뿐이다. 즉 교체 반영은 최대 1시간까지 늦을 수 있다. 그 값을 0 으로 낮추면 이
        // 통로로 나가는 모든 이미지가 매 요청 재검증을 하게 되어, 링크가 되는 서버의 정적
        // 서빙(웹서버 기본 만료)보다 오히려 무거워진다. 1시간은 그 절충이며, 즉시 반영이
        // 필요한 자산은 URL 에 `?v=` 를 붙이는 쪽이 정답이다.
        $response = $this->fileResponse($result['filePath'], $result['mimeType'], 3600, immutable: false, skipCompression: true);

        // 파일 이름 선언은 `fileResponse()` 가 한다 — 네 통로(공개 스토리지 · template ·
        // module · plugin)가 공유하는 자리다. 종전에는 이 통로에만 사본으로 들어가 있었고
        // 형제 셋은 빠져 있었다. 사유는 그 자리 주석에 있다.
        //
        // 실행 가능한 문서 유형은 내려받기 + 격리 CSP 로 돌린다(이름은 위 선언을 그대로 쓴다).
        // 종전에는 업로드된 `.html`·`.svg` 가 앱 오리진에서 inline 으로 열려 그 안의 스크립트가
        // 사이트 권한으로 돌았다.
        $baseMime = strtolower(trim(explode(';', (string) $result['mimeType'], 2)[0]));

        if (in_array($baseMime, self::ACTIVE_DOCUMENT_MIME_TYPES, true)) {
            return InlineMediaPolicy::harden($response, $result['mimeType']);
        }

        return $response;
    }
}
