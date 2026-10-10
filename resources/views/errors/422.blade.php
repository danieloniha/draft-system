@extends('errors.layout')

@section('code', '422')
@section('title', 'We could not process that')
@section('message', $exception->getMessage() ?: 'Something about that request was not valid.')

