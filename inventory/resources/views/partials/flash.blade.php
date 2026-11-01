{{-- A message after an action (session inventory_flash: [type, text]). --}}
@if($flash = session('inventory_flash'))
    <div class="alert alert-{{ in_array($flash[0] ?? '', ['success', 'warning', 'danger', 'info'], true) ? $flash[0] : 'info' }}" id="inv-flash">{{ $flash[1] ?? '' }}</div>
@endif
