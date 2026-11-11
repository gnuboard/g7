{{--
    전용 화면이 없는 4xx 응답의 공용 오류 화면 (405·418·422 등).

    프레임워크는 `errors::{코드}` 가 없으면 `errors::4xx` 를 찾는다. 이 뷰가 없으면 프레임워크의 대체 페이지가
    나가는데, 그 페이지는 모양을 `<style>` 블록으로 실어 스타일 보안 정책을 건 사이트에서 무스타일로 보인다.
    프레임워크가 자체 뷰를 가진 402·419·429 는 그쪽이 먼저 잡히므로 같은 이름의 뷰가 이 화면을 끼워 넣는다.
--}}
@php
    $homeUrl = str_starts_with(request()->path(), 'admin') ? '/admin' : '/';
@endphp

@extends('errors.layout')

@section('code', isset($exception) ? $exception->getStatusCode() : '4xx')
@section('title', __('errors.4xx.title'))
@section('message', __('errors.4xx.message'))
