<?php

namespace App\Http\Controllers\Api\Public;

use App\Enums\TotalRelation;
use App\Extension\HookManager;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Requests\Public\SearchRequest;
use App\Support\Query\PaginationLimits;
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
     * @param  SearchRequest  $request  HTTP 요청
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

        // 특정 탭을 보는 중이라면, 다른 탭의 배지 건수를 아예 세지 않을 수 있다.
        // 기본값은 세는 쪽이다 — 탭 배지가 화면 요소라 끄면 숫자가 사라진다. 다만 배지가
        // 필요 없는 화면(더 보기 페이지 등)은 with_counts=false 로 그 비용을 없앤다.
        // 전체 탭은 배지가 화면의 주 정보라 이 값과 무관하게 항상 센다.
        $includeInactiveCounts = $type === 'all'
            ? true
            : $request->boolean('with_counts', true);

        // 검색 컨텍스트 구성 (모듈별 파라미터는 request 전체를 전달하여 각 모듈이 직접 처리)
        $context = [
            'q' => $q,
            'type' => $type,
            'sort' => $sort,
            'page' => $page,
            'per_page' => $perPage,
            'all_tab_limit' => 5,
            // 커서는 특정 탭을 볼 때만 의미가 있다. 전체 탭은 카테고리마다 몇 건씩만
            // 보여 주므로 깊은 페이지 자체가 없고, 카테고리별 커서를 하나로 합칠 수도 없다.
            'cursor' => $type === 'all' ? null : ($validated['cursor'] ?? null),
            'include_inactive_counts' => $includeInactiveCounts,
            'user' => $request->user(),
            'request' => $request,
        ];

        // Filter Hook을 통해 각 모듈이 검색 결과 추가
        // 모듈들은 자신의 카테고리를 ['category' => ['total' => N, 'items' => [...]]] 형식으로 추가
        // 빈 배열로 시작하여 모듈이 자유롭게 카테고리 추가 가능
        $results = HookManager::applyFilters('core.search.results', [], $context);

        // 프론트엔드용 응답 구조로 변환 (훅을 통해 모듈이 처리)
        $response = $this->buildResponse($q, $results, $context);

        // 총 건수가 상한에 걸렸으면 "N건" 이 아니라 "N건 이상" 으로 알린다.
        // 세지 않은 값을 정확한 것처럼 말하지 않는다.
        $messageKey = ($response['total_is_exact'] ?? true)
            ? 'search.results_found'
            : 'search.results_found_at_least';

        return $this->success(
            __($messageKey, ['count' => number_format($response['total'])]),
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
            'total_relation' => TotalRelation::Exact->value,
            'total_is_exact' => true,
            'result_cap' => null,
        ];
    }

    /**
     * Hook 결과를 프론트엔드용 응답 구조로 변환
     *
     * 코어는 기본 응답 구조만 생성하고,
     * 각 모듈이 core.search.build_response 훅을 통해 자신의 카테고리 응답을 구성합니다.
     *
     * @param  string  $q  검색어
     * @param  array  $results  Hook에서 반환된 검색 결과
     * @param  array  $context  검색 컨텍스트
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

        // 전체 합계를 항상 보존 (탭 UI에서 전체 건수 표시용).
        // "전체" 탭 배지도 잘린 합계인지 알아야 하므로 정확도를 함께 붙인다.
        $response['all_count'] = $response['total'];
        $response['all_count_is_exact'] = $this->resolveTotalAccuracy($results)['total_is_exact'];

        // 탭 배지는 카테고리마다 하나씩 그려지므로 정확도도 카테고리마다 필요하다.
        // 모듈이 각자 자기 키를 내보내면 어떤 배지는 정확도를 받고 어떤 배지는 못 받아,
        // 상한에 걸린 숫자가 그 배지에서만 정확한 값처럼 보인다. 코어가 일괄로 붙인다.
        $response['counts_are_exact'] = $this->resolveCategoryAccuracy($results);

        // 카테고리 검색 실패도 같은 이유로 코어가 일괄 조립한다 — 화면은 이 키로
        // "검색 결과 없음" 과 "검색 중 오류" 를 구분해 그린다. 모듈별 복사에 맡기면
        // 빠지는 카테고리가 생겨 그 카테고리의 실패만 0건으로 위장된다.
        $response['categories_failed'] = $this->resolveCategoryFailures($results);
        $response['search_failed'] = in_array(true, $response['categories_failed'], true);

        // 특정 탭 조회 시 total을 해당 탭의 count로 설정
        $type = $context['type'] ?? 'all';
        if ($type !== 'all') {
            $countKey = $type.'_count';
            if (isset($response[$countKey])) {
                $response['total'] = $response[$countKey];
            }
        }

        // 정확도 메타 — 전체 탭은 카테고리 합집합, 특정 탭은 그 카테고리 하나만 본다.
        return array_merge($response, $this->resolveTotalAccuracy($results, $type));
    }

    /**
     * 전체 검색 결과 수 계산
     *
     * @param  array  $results  Hook에서 반환된 검색 결과
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

    /**
     * 카테고리별 총 건수 정확도를 모읍니다.
     *
     * 탭 배지는 카테고리 수만큼 그려지므로 정확도도 그 수만큼 있어야 합니다. 하나라도
     * 빠지면 그 배지에서만 상한에 걸린 숫자가 정확한 값처럼 보이고, 오류로는 드러나지
     * 않은 채 그냥 틀린 숫자로 남습니다.
     *
     * @param  array  $results  Hook에서 반환된 검색 결과
     * @return array<string, bool> 카테고리 => 정확 여부
     */
    private function resolveCategoryAccuracy(array $results): array
    {
        $accuracy = [];

        foreach ($results as $category => $categoryData) {
            $accuracy[$category] = ($categoryData['total_is_exact'] ?? true) === true;
        }

        return $accuracy;
    }

    /**
     * 카테고리별 검색 실패 여부를 모읍니다.
     *
     * 실패 카테고리 페이로드(`SearchCategoryPayload::failed()`)의 `failed` 플래그를
     * 카테고리 전수에 대해 수집합니다. 키가 없는 카테고리는 실패하지 않은 것으로
     * 봅니다 — 구버전 모듈이 플래그를 싣지 않아도 종전 렌더가 유지되어야 합니다.
     *
     * @param  array  $results  Hook에서 반환된 검색 결과
     * @return array<string, bool> 카테고리 => 실패 여부
     */
    private function resolveCategoryFailures(array $results): array
    {
        $failures = [];

        foreach ($results as $category => $categoryData) {
            $failures[$category] = ($categoryData['failed'] ?? false) === true;
        }

        return $failures;
    }

    /**
     * 응답 전체의 총 건수 정확도를 정합니다.
     *
     * 전체 탭에서는 카테고리 중 하나라도 상한에 걸리면 합계도 "이상" 이다 — 정확한
     * 카테고리 몇 개를 더해 봐야 전체가 정확해지지 않는다.
     *
     * 특정 탭을 보는 중이면 `total` 이 그 카테고리 건수로 바뀌므로 정확도도 그 카테고리
     * 하나만 본다. 다른 카테고리가 잘렸다는 이유로 정확한 값을 "이상" 이라 말하지 않는다.
     *
     * @param  array  $results  Hook에서 반환된 검색 결과
     * @param  string  $type  조회 탭 (all 이면 전체 합계)
     * @return array{total_relation: string, total_is_exact: bool, result_cap: int|null} 정확도 메타
     */
    private function resolveTotalAccuracy(array $results, string $type = 'all'): array
    {
        $scoped = ($type !== 'all' && array_key_exists($type, $results))
            ? [$results[$type]]
            : $results;

        $isExact = true;

        foreach ($scoped as $categoryData) {
            if (($categoryData['total_is_exact'] ?? true) === false) {
                $isExact = false;
                break;
            }
        }

        return [
            'total_relation' => $isExact ? TotalRelation::Exact->value : TotalRelation::AtLeast->value,
            'total_is_exact' => $isExact,
            'result_cap' => PaginationLimits::resultCap('search'),
        ];
    }
}
