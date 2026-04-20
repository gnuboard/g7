<?php

namespace App\Http\Controllers\Api\Public;

use App\Extension\HookManager;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Requests\Public\SearchRequest;
use Illuminate\Http\JsonResponse;

/**
 * 통합 검색 API 컨트롤러
 *
 * 코어에서 검색 기반 구조를 제공하고,
 * 각 모듈이 Filter Hook을 통해 검색 결과를 추가합니다.
 */
class PublicSearchController extends PublicBaseController
{
    /**
     * 통합 검색 수행
     *
     * @param SearchRequest $request HTTP 요청
     * @return JsonResponse 검색 결과
     */
    public function search(SearchRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $q = trim($validated['q'] ?? '');
        $type = $validated['type'] ?? 'all';
        $sort = $validated['sort'] ?? 'relevance';
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 10);

        // 검색어가 비어있으면 빈 결과 반환
        if (empty($q)) {
            return $this->success(__('search.empty_keyword'), $this->buildEmptyResponse());
        }

        // 검색 컨텍스트 구성 (모듈별 파라미터는 request 전체를 전달하여 각 모듈이 직접 처리)
        $context = [
            'q' => $q,
            'type' => $type,
            'sort' => $sort,
            'page' => $page,
            'per_page' => $perPage,
            'all_tab_limit' => 5,
            'user' => $request->user(),
            'request' => $request,
        ];

        // Filter Hook을 통해 각 모듈이 검색 결과 추가
        // 모듈들은 자신의 카테고리를 ['category' => ['total' => N, 'items' => [...]]] 형식으로 추가
        // 빈 배열로 시작하여 모듈이 자유롭게 카테고리 추가 가능
        $results = HookManager::applyFilters('core.search.results', [], $context);

        // 프론트엔드용 응답 구조로 변환 (훅을 통해 모듈이 처리)
        $response = $this->buildResponse($q, $results, $context);

        return $this->success(
            __('search.results_found', ['count' => number_format($response['total'])]),
            $response
        );
    }

    /**
     * 빈 검색 결과 응답 구조 생성
     *
     * @return array 빈 결과 응답
     */
    private function buildEmptyResponse(): array
    {
        return [
            'q' => '',
            'total' => 0,
        ];
    }

    /**
     * Hook 결과를 프론트엔드용 응답 구조로 변환
     *
     * 코어는 기본 응답 구조만 생성하고,
     * 각 모듈이 core.search.build_response 훅을 통해 자신의 카테고리 응답을 구성합니다.
     *
     * @param string $q 검색어
     * @param array $results Hook에서 반환된 검색 결과
     * @param array $context 검색 컨텍스트
     * @return array 프론트엔드용 응답 구조
     */
    private function buildResponse(string $q, array $results, array $context): array
    {
        $response = [
            'q' => $q,
            'total' => $this->calculateTotal($results),
        ];

        // 각 모듈이 자신의 카테고리 응답 구조를 추가할 수 있도록 훅 제공
        // 모듈은 $results에서 자신의 데이터를 가져와 $response에 추가
        $response = HookManager::applyFilters('core.search.build_response', $response, $results, $context);

        // 전체 합계를 항상 보존 (탭 UI에서 전체 건수 표시용)
        $response['all_count'] = $response['total'];

        // 특정 탭 조회 시 total을 해당 탭의 count로 설정
        $type = $context['type'] ?? 'all';
        if ($type !== 'all') {
            $countKey = $type.'_count';
            if (isset($response[$countKey])) {
                $response['total'] = $response[$countKey];
            }
        }

        return $response;
    }

    /**
     * 전체 검색 결과 수 계산
     *
     * @param array $results Hook에서 반환된 검색 결과
     * @return int 전체 결과 수
     */
    private function calculateTotal(array $results): int
    {
        $total = 0;
        foreach ($results as $categoryData) {
            $total += $categoryData['total'] ?? 0;
        }

        return $total;
    }
}
