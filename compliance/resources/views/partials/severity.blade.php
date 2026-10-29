{{-- A severity as a label: critical, warning, info. --}}
<span class="label cmp-severity cmp-sev-{{ in_array($severity, \Agoodley\LibrenmsCompliance\Rules\Rule::SEVERITIES, true) ? $severity : 'info' }}">{{ $severity }}</span>
