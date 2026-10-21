<?php

namespace Tests\Feature\Analytics\Api;

/**
 * 방문 분석 응답의 키-값 맵 필드가 비었을 때 `[]` 가 아니라 `{}` 로 나가는지 (7.1.1 출시 전)
 *
 * API 문서(`docs/backend/api/analytics.md`)는 이 필드들을 object 로 약속한다. 서비스가 만든 빈 PHP 배열이 그대로
 * 직렬화되면 `[]` 가 되어, 확장이 값을 붙이지 않은 평범한 사이트에서만 모양이 달라진다(이번에는
 * 응답 재인코드가 아니라 서비스가 빈 배열을 만드는 경로). 응답 원문으로 본다.
 */
class EmptyMapFieldsTest extends AnalyticsApiTestCase
{
    /**
     * 실시간 방문자(`/realtime`·`/overview` 의 realtime)의 `visitors[].extensions` 가 비면 `{}` 다.
     *
     * @scenario surface=analytics_realtime_visitor
     *
     * @effects analytics_visitor_extensions_empty_is_object
     *
     * @return void 반환값 없음
     */
    public function test_realtime_visitor_extensions_are_an_empty_map(): void
    {
        $this->visit();
        $admin = $this->readAdmin();

        foreach (['/api/admin/analytics/realtime', '/api/admin/analytics/overview?preset=today'] as $uri) {
            $raw = (string) $this->actingAs($admin)->getJson($uri)->assertOk()->getContent();

            $this->assertStringContainsString('"extensions":{}', $raw, $uri);
            $this->assertStringNotContainsString('"extensions":[]', $raw, $uri);
        }
    }

    /**
     * 진단의 `extensions`·`collection.excluded_reason_labels` 가 비면 `{}` 다.
     *
     * @scenario surface=analytics_diagnostics
     *
     * @effects analytics_diagnostics_maps_empty_are_objects
     *
     * @return void 반환값 없음
     */
    public function test_diagnostics_maps_are_empty_objects(): void
    {
        $raw = (string) $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/diagnostics')->assertOk()->getContent();

        $this->assertStringContainsString('"extensions":{}', $raw);
        $this->assertStringNotContainsString('"extensions":[]', $raw);
        $this->assertStringContainsString('"excluded_reason_labels":{}', $raw);
    }

    /**
     * 등록 목록(목표 정의)의 `goals[].attributes` 가 비면 `{}` 다.
     *
     * @scenario surface=analytics_registered_goal
     *
     * @effects analytics_goal_attributes_empty_is_object
     *
     * @return void 반환값 없음
     */
    public function test_registered_goal_attributes_are_empty_objects(): void
    {
        $raw = (string) $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/registered')->assertOk()->getContent();

        $this->assertStringNotContainsString('"attributes":[]', $raw);
    }

    /**
     * 정보주체 삭제 응답의 `deleted.extensions` 와 열람 파일의 `extensions` 가 비면 `{}` 다.
     *
     * @scenario surface=analytics_privacy_subject
     *
     * @effects analytics_privacy_extension_maps_empty_are_objects
     *
     * @return void 반환값 없음
     */
    public function test_subject_delete_and_export_extension_maps_are_empty_objects(): void
    {
        $this->visit(['user_id' => 77]);
        $admin = $this->privacyAdmin();

        $export = $this->actingAs($admin)->postJson('/api/admin/analytics/privacy/subjects/export', ['user_id' => 77])->assertOk();
        $this->assertStringContainsString('"extensions": {}', $export->streamedContent());

        $raw = (string) $this->actingAs($admin)
            ->deleteJson('/api/admin/analytics/privacy/subjects', ['user_id' => 77, 'confirm' => true])
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('"extensions":{}', $raw);
    }
}
