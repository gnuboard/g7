{{--
    전용 화면이 없는 5xx 응답의 공용 오류 화면 (502·504 등).

    프레임워크는 `errors::{코드}` 가 없으면 `errors::5xx` 를 찾는다. 이 뷰가 없으면 프레임워크의 대체 페이지가
    나가는데, 그 페이지는 모양을 `<style>` 블록으로 실어 스타일 보안 정책을 건 사이트에서 무스타일로 보인다.
--}}
@php
    $homeUrl = str_starts_with(request()->path(), 'admin') ? '/admin' : '/';
@endphp

@extends('errors.layout')

@section('code', isset($exception) ? $exception->getStatusCode() : '5xx')
@section('title', __('errors.5xx.title'))
@section('message', __('errors.5xx.message'))
