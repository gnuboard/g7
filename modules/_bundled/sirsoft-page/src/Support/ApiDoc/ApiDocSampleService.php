<?php

namespace Modules\Sirsoft\Page\Support\ApiDoc;

use App\Contracts\ApiDoc\ApiDocSampleSeeder;
use App\Models\User;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Models\PageVersion;

/**
 * sirsoft-page API 문서 실측용 완전 샘플 시더
 *
 * 페이지 도메인 대표 엔티티(발행된 완전 샘플 페이지 + 버전 이력 + 첨부)를
 * 멱등하게 생성하고, docgen 이 상세 GET 실측(`/admin/pages/{page}`,
 * `/admin/pages/{page}/versions`)에 사용할 대표 route key 맵을 제공합니다.
 *
 * `api:docgen --scope=module:sirsoft-page --seed` 실행 시 커맨드가 규약 위치
 * (`Modules\Sirsoft\Page\Support\ApiDoc\ApiDocSampleService`)로 자동 발견합니다.
 */
class ApiDocSampleService implements ApiDocSampleSeeder
{
    /**
     * @var string 샘플 레코드 식별용 슬러그 마커
     */
    private const SAMPLE_SLUG = 'apidoc-sample-page';

    /**
     * 페이지 도메인 완전 샘플을 멱등 생성하고 대표 route key 맵을 반환합니다.
     *
     * @return array<string, array{model: class-string, key: string, value: string}> 도메인 => 대표 레코드 정보
     */
    public function seed(): array
    {
        $map = [];

        $page = $this->seedPage();
        $map['pages'] = ['model' => Page::class, 'key' => $page->getRouteKeyName(), 'value' => (string) $page->getRouteKey()];

        return $map;
    }

    /**
     * 완전한 발행 페이지 샘플(버전 이력 + 첨부 포함)을 생성합니다.
     *
     * @return Page 대표 페이지 레코드
     */
    private function seedPage(): Page
    {
        $page = Page::query()->where('slug', self::SAMPLE_SLUG)->first();

        if ($page) {
            return $page;
        }

        $actor = $this->sampleActor();

        $page = Page::factory()->published()->withSeoMeta()->create([
            'slug' => self::SAMPLE_SLUG,
            'title' => ['ko' => 'API 문서 샘플 페이지', 'en' => 'API Doc Sample Page'],
            'content' => [
                'ko' => '<p>API 레퍼런스 실측용 완전 샘플 페이지 본문입니다.</p>',
                'en' => '<p>Complete sample page body for API reference probing.</p>',
            ],
            'content_mode' => 'html',
            'current_version' => 2,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);

        // 버전 이력 2건 — versions 조회 실측이 배열 항목 필드를 확보하도록.
        PageVersion::factory()->create([
            'page_id' => $page->id,
            'version' => 1,
            'title' => ['ko' => 'API 문서 샘플 페이지 (v1)', 'en' => 'API Doc Sample Page (v1)'],
            'content' => ['ko' => '<p>초기 버전 본문.</p>', 'en' => '<p>Initial version body.</p>'],
            'content_mode' => 'html',
            'changes_summary' => '최초 작성',
            'created_by' => $actor?->id,
        ]);

        PageVersion::factory()->create([
            'page_id' => $page->id,
            'version' => 2,
            'title' => ['ko' => 'API 문서 샘플 페이지 (v2)', 'en' => 'API Doc Sample Page (v2)'],
            'content' => ['ko' => '<p>수정 버전 본문.</p>', 'en' => '<p>Revised version body.</p>'],
            'content_mode' => 'html',
            'changes_summary' => '본문 보강',
            'created_by' => $actor?->id,
        ]);

        // 첨부 1건 — 첨부 임베드 응답 필드가 채워지도록.
        PageAttachment::factory()->image()->create([
            'page_id' => $page->id,
            'created_by' => $actor?->id,
        ]);

        return $page->refresh();
    }

    /**
     * 샘플 소유자/작성자로 쓸 사용자를 반환합니다.
     *
     * 코어 완전 샘플 사용자(먼저 시드됨)를 우선하고, 없으면 첫 사용자로 폴백합니다.
     *
     * @return User|null 샘플 사용자 (없으면 null)
     */
    private function sampleActor(): ?User
    {
        return User::query()->where('email', 'apidoc-sample-user@example.com')->first()
            ?? User::query()->orderBy('id')->first();
    }
}
