<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>@yield('title', 'Mega Stationery Katalog')</title>
    <link rel="stylesheet" href="{{ asset('css/shared.css?v=1') }}">
</head>
<body>
    @yield('content')
    @stack('scripts')
</body>
</html>
