@php
    $homeUrl = str_starts_with(request()->path(), 'admin') ? '/admin' : '/';
@endphp

@extends('errors.layout')

@section('code', '404')
@section('title', __('errors.404.title'))
@section('message', __('errors.404.message'))
