{{-- A software status (Software\Status) as a label. --}}
<span class="label inv-status inv-status-{{ isset(\Agoodley\LibrenmsInventory\Software\Status::ALL[$status]) ? $status : 'unknown' }}"
    title="{{ \Agoodley\LibrenmsInventory\Software\Status::ALL[$status][2] ?? '' }}">{{ \Agoodley\LibrenmsInventory\Software\Status::label($status) }}</span>
