@extends('layouts.shared')

@section('title', 'Mega Stationery Katalog')

@section('content')
    <div class="page">
        <header class="topbar">
            <div class="brand">
                <img class="logo" src="{{ asset('images/ms_logo.png') }}" alt="MS">
                Mega Stationery Katalog
            </div>
            <div class="scope">
                {{ $selectedCategoryName }}
                @if ($share->search)
                    · Pencarian: “{{ $share->search }}”
                @endif
                · Berlaku sampai {{ $share->expires_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
            </div>
            @if (! $share->category_id)
                <nav class="chips">
                    <button class="chip active" data-category="">Semua</button>
                    @foreach ($categories as $category)
                        <button class="chip" data-category="{{ $category->id }}">{{ $category->name }}</button>
                    @endforeach
                </nav>
            @endif
        </header>
        <main id="grid" class="grid">
            <div class="empty muted">Memuat katalog…</div>
        </main>
    </div>
@endsection

@push('scripts')
    <script>
        let page = 1, loading = false, finished = false, category = '';
        const grid = document.getElementById('grid');
        const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;',
        }[character]));

        async function load() {
            if (loading || finished) {
                return;
            }

            loading = true;

            try {
                const response = await fetch(@json(route('shared-catalog.videos', $token)) + '?page=' + page + '&category_id=' + category);

                if (! response.ok) {
                    throw 0;
                }

                const payload = await response.json();

                if (page === 1) {
                    grid.innerHTML = '';
                }


                for (const video of payload.data) {
                    const card = document.createElement('a');
                    card.className = 'card';
                    card.href = video.detail_url;
                    card.innerHTML = (video.cover_url
                        ? `<img src="${esc(video.cover_url)}" alt="">`
                        : `<video src="${esc(video.preview_url)}" muted playsinline preload="metadata"></video>`)
                        + `
                            <!-- <span class="fade"></span> -->
                            <span class="info">
                                <span class="code">${esc(video.code)}</span>
                                <span class="name">${esc(video.name)}</span>
                                <span class="price">${esc(video.price)}</span>
                                ${video.description ? `<span class="desc">${esc(video.description)}</span>` : ''}
                            </span>`;
                    grid.appendChild(card);
                }

                finished = payload.meta.current_page >= payload.meta.last_page;

                if (! payload.data.length && page === 1) {
                    grid.innerHTML = '<div class="empty muted">Tidak ada produk dalam katalog ini.</div>';
                }

                page++;
            } catch (error) {
                if (page === 1) {
                    grid.innerHTML = '<div class="empty muted">Katalog tidak dapat dimuat.</div>';
                }
            } finally {
                loading = false;
            }
        }

        document.querySelectorAll('[data-category]').forEach((button) => {
            button.onclick = () => {
                document.querySelectorAll('[data-category]').forEach((chip) => chip.classList.remove('active'));
                button.classList.add('active');
                category = button.dataset.category;
                page = 1;
                finished = false;
                grid.innerHTML = '<div class="empty muted">Memuat katalog…</div>';
                load();
            };
        });

        addEventListener('scroll', () => {
            if (innerHeight + scrollY > document.body.offsetHeight - 500) {
                load();
            }
        });

        load();
    </script>
@endpush
