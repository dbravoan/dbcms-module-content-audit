@extends('admin.layout')
@section('title', 'Comparación de revisiones')
@section('content')
<div class="admin-page space-y-6">
    <a href="{{ route('admin.audit.index', ['tab' => 'changelog']) }}">← Volver al historial</a>
    <h1 class="admin-page-title">Comparación de revisiones</h1>
    @include('content_audit::admin.partials.rendered-comparison', ['type' => $type, 'id' => $id, 'previewParams' => ['revision' => $targetRevision->id, 'compare_with' => $compareRevision?->id]])
    <details><summary>Detalles técnicos de los cambios</summary>@include('content_audit::admin.partials.diff-viewer', ['diffResult' => $diffResult])</details>
</div>
@endsection
