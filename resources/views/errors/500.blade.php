@php
    $homeUrl = str_starts_with(request()->path(), 'admin') ? '/admin' : '/';
@endphp

@extends('errors.layout')

@section('code', '500')
@section('title', __('errors.500.title'))
@section('message', __('errors.500.message'))
