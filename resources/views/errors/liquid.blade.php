<!DOCTYPE html>
<html lang="es" translate="no">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Error {{ $status }} · FIEA</title>
    @vite('resources/js/error.jsx')
</head>
<body class="font-sans antialiased">
    <main class="scene-root guest-scene text-app-text">
        <div class="ambient-canvas" aria-hidden="true"><div class="ambient-grid"></div><div class="ambient-orbit ambient-orbit-a"></div><div class="ambient-orbit ambient-orbit-b"></div></div>
        <section class="glass-panel lg-micro-glass relative z-10 max-w-lg p-8 text-center">
            <p class="login-eyebrow">FIEA · {{ $problem['errorCode'] }}</p>
            <h1 class="text-2xl font-bold">{{ $problem['error'] }}</h1>
            <p class="mt-3 text-app-muted">Consulta el diagnóstico y vuelve a una sección disponible.</p>
            <a class="glass-button mt-5" href="/dashboard">Volver al panel</a>
        </section>
    </main>
    <script type="application/json" id="error-diagnostic">{!! json_encode($problem, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>
</body>
</html>
