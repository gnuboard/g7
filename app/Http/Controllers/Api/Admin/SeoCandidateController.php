<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\SeoCandidateRequest;
use App\Seo\Editor\SeoCandidateService;
use Illuminate\Http\JsonResponse;

/**
 * SEO 후보 엔드포인트 — 레이아웃 편집기 [검색엔진] 탭 후보 공급.
 *
 * 편집기 전용 가드(`core.templates.layouts.edit`) 하에서만 노출된다(권한 후보 엔드포인트와
 * 동일 패턴 — admin 전역 G7Config broadcast 회피, Bearer fetch). page_type 후보·toggle_setting
 * 후보·유효 vars 후보를 한 번에 공급한다. 후보 미존재 시 빈 목록 → 편집기 자유 텍스트 폴백.
 *
 * 입력(query): `extensions`(JSON `[{type,id}]`), `page_type`(string|null).
 */
class SeoCandidateController extends AdminBaseController
{
    public function __construct(
        private readonly SeoCandidateService $candidateService,
    ) {
        parent::__construct();
    }

    /**
     * SEO 후보를 수집해 반환합니다.
     *
     * @param  SeoCandidateRequest  $request  편집 중 레이아웃의 extensions/page_type 을 query 로 전달
     * @param  string  $identifier  템플릿 식별자(라우트 일관성 — 후보는 활성 확장 기준)
     * @return JsonResponse page_types / toggle_settings / vars / extensions 후보 응답
     */
    public function index(SeoCandidateRequest $request, string $identifier): JsonResponse
    {
        $extensions = $this->parseExtensions($request->query('extensions'));
        $pageType = $request->query('page_type');
        $pageType = is_string($pageType) && $pageType !== '' ? $pageType : null;

        $candidates = $this->candidateService->collect(
            $extensions,
            $pageType,
            app()->getLocale(),
        );

        return $this->success(
            'common.success',
            array_merge(['identifier' => $identifier], $candidates),
        );
    }

    /**
     * extensions query 파라미터를 `[{type,id}]` 배열로 파싱합니다.
     *
     * JSON 문자열 또는 배열 모두 허용(잘못된 형태는 빈 배열로 폴백 — 가드는 라우트 미들웨어).
     *
     * @param  mixed  $raw  query('extensions')
     * @return array<int, array{type: string, id: string}>
     */
    private function parseExtensions(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $ext) {
            if (is_array($ext) && isset($ext['type'], $ext['id'])
                && is_string($ext['type']) && is_string($ext['id'])) {
                $out[] = ['type' => $ext['type'], 'id' => $ext['id']];
            }
        }

        return $out;
    }
}
