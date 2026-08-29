{{-- Filament's logo component renders EITHER the brand image or the brand
     name, never both. Handing it Htmlable content lets the mark and the
     shop name sit side by side in the sidebar, topbar and login screen. --}}
<div style="display: flex; align-items: center; gap: 0.5rem; height: 100%;">
    <img
        src="{{ asset('logo.png') }}"
        alt=""
        style="height: 100%; width: auto; flex-shrink: 0; object-fit: contain;"
    />

    <span
        class="text-gray-950 dark:text-white"
        style="font-size: 0.9375rem; font-weight: 700; letter-spacing: 0.02em; line-height: 1.15; white-space: nowrap;"
    >
        JG RAMOS DRUGMART
    </span>
</div>
