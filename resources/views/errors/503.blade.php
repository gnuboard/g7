@php
    $homeUrl = str_starts_with(request()->path(), 'admin') ? '/admin' : '/';
@endphp

@extends('errors.layout')

@section('code', '503')
@section('title', __('errors.503.title'))
@section('message', __('errors.503.message'))
