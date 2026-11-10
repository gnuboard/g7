<?php

namespace Modules\Sirsoft\Page\Services;

use App\Exceptions\ContentMediaLimitExceededException;
use App\Support\ContentMedia\ContentMediaContext;
use App\Support\ContentMedia\ContentMediaPolicy;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Services\ContentMedia\PageContentMediaFlow;
use Modules\Sirsoft\Page\Services\ContentMedia\PageContentMediaStore;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 페이지 본문 미디어 서비스 — 관리자 페이지 편집기 본문의 이미지·동영상·오디오 (#883 4단계, #887 코어 골격으로 이전)
 *
 * 흐름(업로드·저장 동기화·서명·서빙·영구 삭제·정리)은 코어 골격을 상속한 `PageContentMediaFlow` 가 돌리고, 페이지 몫(행 표·게이트·
 * 제거 정책)은 어댑터 `PageContentMediaStore` 가 답한다. 이 클래스는 #883 부터 페이지 서비스·리소스·컨트롤러·시더가 부르던 공개
 * 메서드를 그대로 두고 페이지 문맥(`ContentMediaContext` — 확장 `sirsoft-page`, 문서 `page`, 요청자 = 로그인 회원)을 만들어 위임한다.
 *
 * 저장은 `page_attachments` 의 `collection = content_media` 다. 파일은 처음부터 최종 경로 `content-media/{Y/m/d}/{uuid}.ext`,
 * 행은 페이지에 잇기 전까지 `page_id = null` + 임시 키다. 페이지 저장 트랜잭션 안에서 본문(로케일마다)이 참조한 것만 잇고, 본문에서
 * 빠진 것은 **어느 버전 본문도 참조하지 않을 때만** 지운다(버전이 참조하면 페이지에 붙인 채 두고 페이지 삭제 때 정리 — #887).
 * 본문 미디어는 첨부 목록·첨부 개수에 들지 않고, 첨부 훅이 아니라 코어 공용 훅 `core.content_media.*` 를 발화한다.
 *
 * 서빙 게이트는 「발행 또는 열람권(`sirsoft-page.pages.read`) 또는 서명」. 응답 본문은 미발행 페이지에서 **그 페이지가 소유한
 * 해시만** 서명한다 — 발행된 페이지는 정적 캐시와 충돌하지 않게 정식 주소를 쓴다.
 *
 * 정책은 모듈 설정 `content_media`, 비어 있으면 페이지 첨부 정책(파일당 MB·허용 형식)으로 만든 대체 정책이다(D7).
 */
class PageContentMediaService
{
    /** 서빙 라우트 이름 */
    public const SERVE_ROUTE = PageContentMediaStore::SERVE_ROUTE;

    /** 서빙 주소 앞부분 (정식 주소 = 이 값 + 해시) */
    public const SERVE_PATTERN_URL_PREFIX = PageContentMediaStore::SERVE_PATTERN_URL_PREFIX;

    /** 서빙 주소 정규식 본문 */
    public const SERVE_PATTERN = PageContentMediaStore::SERVE_PATTERN;

    /** 업로드 시점 상한 배수 */
    public const UPLOAD_HEADROOM = PageContentMediaFlow::UPLOAD_HEADROOM;

    /**
     * @param  PageContentMediaFlow  $flow  코어 골격을 상속한 페이지 본문 미디어 흐름
     */
    public function __construct(private PageContentMediaFlow $flow) {}

    /**
     * 코어 골격 인스턴스 — 표준 계약 엔드포인트처럼 골격을 받는 코어 도구에 넘길 때.
     *
     * @return PageContentMediaFlow 흐름
     */
    public function flow(): PageContentMediaFlow
    {
        return $this->flow;
    }

    /**
     * 페이지 본문 미디어 문맥 — 요청자는 지금 로그인한 회원이다.
     *
     * @param  int|null  $pageId  페이지 ID (저장 전·페이지와 무관하면 null)
     * @param  string|null  $tempKey  임시 키
     * @return ContentMediaContext 문맥
     */
    public function context(?int $pageId = null, ?string $tempKey = null): ContentMediaContext
    {
        $actorId = Auth::id();

        return ContentMediaContext::module(PageContentMediaStore::EXTENSION, PageContentMediaStore::DOCUMENT_TYPE, $pageId)
            ->withTempKey($tempKey)
            ->withActor($actorId !== null ? (int) $actorId : null);
    }

    /**
     * 페이지 본문 미디어 정책 — 모듈 설정 `content_media`, 없으면 첨부 정책으로 만든 대체 정책.
     *
     * @return ContentMediaPolicy 정책
     */
    public function resolvePolicy(): ContentMediaPolicy
    {
        return $this->flow->policy($this->context());
    }

    /**
     * 페이지 첨부 정책으로 만든 대체 정책 — 허용 MIME 을 확장자로 바꾼다. 동영상·오디오는 코어 「본문 콘텐츠」 스위치를 따른다.
     *
     * @return ContentMediaPolicy 대체 정책
     */
    public function fallbackPolicy(): ContentMediaPolicy
    {
        return $this->flow->store()->fallbackPolicy($this->context());
    }

    /**
     * 본문 미디어를 임시로 올립니다 — 파일은 최종 경로, 행은 임시(page_id=null + 임시 키).
     *
     * 코어 공용 훅 `core.content_media.before_upload` → `filter_upload_file` → `after_upload` 가 발화한다.
     *
     * @param  UploadedFile  $file  파일 (요청 계층이 정책으로 검증함)
     * @param  string  $tempKey  임시 키
     * @return PageAttachment 본문 미디어 행
     *
     * @throws ContentMediaLimitExceededException 같은 임시 키 업로드가 상한(문서당 최대 × 2)을 넘을 때
     * @throws ValidationException 업로드 파일 필터가 돌려준 파일이 정책에 맞지 않을 때
     */
    public function upload(UploadedFile $file, string $tempKey): PageAttachment
    {
        /** @var PageAttachment $row */
        $row = $this->flow->upload($this->context(null, $tempKey), $file);

        return $row;
    }

    /**
     * 페이지 본문(로케일마다)과 본문 미디어를 맞춥니다 — 페이지 저장 트랜잭션 안에서 부른다.
     *
     * 참조된 임시 본문 미디어 중 같은 임시 키 + 올린 사람이 같은 것만 잇고(연결), 다른 페이지 소유 본문 미디어는 요청자가 원본을
     * 볼 수 있으면 파일을 복사해 이 페이지 소유의 새 행·새 해시로 만들고(복제 — 반환값 `content` 에 바뀐 본문, 호출자가 다시 저장),
     * 이 페이지에 이어져 있었는데 어느 로케일 본문에도 없는 것은 어느 버전도 참조하지 않을 때만 지운다(제거). 문서당 최대 개수는
     * 로케일마다 세고 복제할 것도 센다.
     *
     * @param  Page  $page  저장된 페이지
     * @param  string|null  $tempKey  요청의 임시 키
     * @return array{linked: int, removed: int, cloned: int, content: mixed} 처리 결과 (`content` = 복제 해시로 바꾼 본문)
     *
     * @throws ContentMediaLimitExceededException 문서당 최대 개수 초과
     */
    public function syncWithContent(Page $page, ?string $tempKey): array
    {
        return $this->flow->syncWithContent(
            $this->context((int) $page->id, $tempKey),
            $page->content,
            ($page->content_mode ?? 'html') === 'html'
        );
    }

    /**
     * 저장 전 본문(로케일 배열)의 서명 주소를 정식 주소로 되돌립니다.
     *
     * @param  mixed  $content  본문 (로케일 => HTML)
     * @return mixed 정규화한 본문
     */
    public function normalizeContent(mixed $content): mixed
    {
        return $this->flow->normalizeContent($this->context(), $content);
    }

    /**
     * 응답 본문의 정식 주소를 서명 주소로 바꿉니다 — 미발행 페이지에서 그 페이지가 소유한 해시만(#887).
     *
     * 발행 페이지와 봇 렌더는 정식 주소 그대로다. 다른 페이지 소유 해시는 서명하지 않는다 — 서명은 「이 페이지 본문을 받을 자격」의
     * 위임이므로, 남의 미발행 페이지 해시를 자기 페이지에 넣어 서명 주소를 받아 가지 못하게 한다(원본 페이지의 게이트를 따른다).
     *
     * `$pageId` 를 주지 않는 옛 호출(#883)은 소유를 판정할 문서가 없어 미발행이면 모든 서빙 주소를 서명한다 — 응답 리소스는 늘
     * 페이지 ID 를 넘긴다.
     *
     * @param  mixed  $content  본문 (로케일 => HTML 또는 HTML)
     * @param  bool  $published  발행 여부
     * @param  int|null  $pageId  본문을 가진 페이지 ID (소유 해시 판정)
     * @return mixed 바꾼 본문
     */
    public function presentContent(mixed $content, bool $published, ?int $pageId = null): mixed
    {
        if ($published) {
            return $content;
        }

        if ($pageId === null) {
            return $this->flow->presentContent($this->context(), $content, signAll: true);
        }

        return $this->flow->presentContent($this->context($pageId), $content, documentPublic: false);
    }

    /**
     * 본문 미디어 서명 주소.
     *
     * @param  string  $hash  해시
     * @param  DateTimeInterface|null  $expires  만료 (null 이면 설정값)
     * @return string 서명 주소 (상대 경로)
     */
    public function signedUrl(string $hash, ?DateTimeInterface $expires = null): string
    {
        return $this->flow->signedUrl($this->context(), $hash, $expires);
    }

    /**
     * 관리자 편집기에 넘길 업로드 계약 — 받는 종류가 없으면 null.
     *
     * 표준 계약 엔드포인트(`GET admin/content-media/target`)와 옛 첨부 설정 응답(`GET admin/attachments/settings` 의
     * `content_media`)이 같은 값을 싣는다.
     *
     * @return array<string, mixed>|null 계약
     */
    public function uploadTarget(): ?array
    {
        return $this->flow->uploadTarget($this->context(), true);
    }

    /**
     * 서빙할 본문 미디어를 찾고 게이트를 겁니다 — 발행 또는 열람권 또는 서명.
     *
     * @param  string  $hash  해시
     * @param  bool  $signatureVerified  서명 검증 여부
     * @return PageAttachment 본문 미디어
     *
     * @throws NotFoundHttpException 없을 때
     * @throws AccessDeniedHttpException 게이트를 통과하지 못할 때
     */
    public function findServable(string $hash, bool $signatureVerified): PageAttachment
    {
        /** @var PageAttachment $row */
        $row = $this->flow->findServable($this->context(), $hash, $signatureVerified);

        return $row;
    }

    /**
     * 본문 미디어 서빙 응답 — 파일은 행에 기록된 디스크에서 읽는다. 발행 페이지는 public 3600초, 그 밖은 private 300초.
     *
     * @param  PageAttachment  $attachment  본문 미디어
     * @param  Request  $request  요청
     * @return Response|null 응답 (파일이 없으면 null)
     */
    public function respond(PageAttachment $attachment, Request $request): ?Response
    {
        return $this->flow->respond($this->context(), $attachment, $request);
    }

    /**
     * 페이지에 이어진 본문 미디어를 모두 영구 삭제합니다 — 페이지 삭제 트랜잭션 안에서 부른다 (#887).
     *
     * 첨부 목록 조회는 본문 미디어(예약 컬렉션)를 빼므로 페이지 삭제가 이 경로를 따로 지나야 한다. 빠뜨리면 행은 외래키 연쇄로
     * 사라지지만 파일은 저장소에 영구히 남는다. 행마다 `core.content_media.before_delete` 를 발화하고, 파일은 행 디스크에서 커밋된
     * 뒤에 지운다(롤백되면 남는다). 첨부 삭제 훅은 발화하지 않는다.
     *
     * @param  int  $pageId  페이지 ID
     * @return int 지운 본문 미디어 수
     */
    public function deleteContentMediaForPage(int $pageId): int
    {
        $context = $this->context($pageId);

        return $this->flow->purgeRows($context, $this->flow->store()->ownedRows($context));
    }

    /**
     * 보존기간이 지나도록 페이지에 잇지 않은 임시 본문 미디어를 파일과 함께 영구 삭제합니다 — 임시 첨부 정리 명령이 부른다 (#887).
     *
     * 행마다 `core.content_media.before_delete` 를 발화하고 행 디스크에서 파일을 지운 뒤 행을 지운다. 파일 삭제에 실패하면 행을
     * 남기고 `failed` 로 센다(다음 실행이 다시 시도). 정리로 비워진 날짜 디렉터리(`content-media/Y/m/d`)는 그 행의 디스크에서 지운다.
     *
     * @param  int  $days  보존기간(일)
     * @param  int  $limit  한 회차에 처리할 최대 건수
     * @param  bool  $dryRun  true 면 대상만 세고 삭제하지 않음
     * @return array{scanned: int, deleted: int, failed: int} 처리 결과
     */
    public function pruneStaleTemp(int $days, int $limit, bool $dryRun = false): array
    {
        return $this->flow->pruneStale($days, $limit, $dryRun);
    }
}
