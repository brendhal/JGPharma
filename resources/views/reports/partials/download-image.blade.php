{{--
    A "Download JPG" button for a printable report's toolbar.

    $filename — what the saved file is called, without the extension.
    $target   — optional CSS selector for what to capture. Defaults to .page,
                the white sheet every report draws itself on. A report that
                renders more than one sheet — supplier claims prints one per
                supplier — saves a numbered image for each.
--}}
<button type="button"
    class="btn btn-ghost"
    data-download-image
    data-filename="{{ $filename ?? 'report' }}"
    @isset($target) data-target="{{ $target }}" @endisset>
    Download JPG
</button>

<script src="{{ asset('js/report-image.js') }}" defer></script>
