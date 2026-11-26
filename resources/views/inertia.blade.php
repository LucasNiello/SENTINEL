<!DOCTYPE html>
<html lang="pt-BR"@if (rescue(fn () => auth()->id(), null, false)) data-usuario="{{ (int) auth()->id() }}"@endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ rescue(fn () => csrf_token(), '', false) }}">
    <meta name="theme-color" content="#0A0E13">
    <link rel="icon" href="/favicon-sentinel.svg" type="image/svg+xml">
    @vite(['resources/css/workspace.css', 'resources/js/react/app.jsx'])
    {{-- Raiz do front React (Inertia). Mesmo <head> do layouts/app: PWA e tamanho do texto valem nos dois modos. --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/sentinel-192.png">
    <script>if ('serviceWorker' in navigator) { window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {})); }</script>
    @if (rescue(fn () => auth()->id(), null, false))
        <script>try { var t = localStorage.getItem('sentinel:texto:{{ (int) auth()->id() }}'); if (t === '2' || t === '3') document.documentElement.dataset.texto = t; } catch (e) {}</script>
    @endif
    @inertiaHead
</head>
<body class="workspace{{ rescue(fn () => auth()->check(), false, false) ? '' : ' workspace--guest' }}">
    @inertia
</body>
</html>
