{{-- Health card of a diagnostics page: the worst severity and the findings per severity. --}}
<div class="topo-card topo-{{ $summary['status'] }}">
    <h4>Health</h4>
    <div class="topo-card-value"><span class="topo-sev topo-sev-{{ $summary['status'] }}" style="font-size: 16px">{{ $summary['status'] }}</span></div>
    <p>{{ number_format($summary['findings']['critical']) }} critical, {{ \Agoodley\LibrenmsTopology\Support\Text::count($summary['findings']['warning'], 'warning') }}, {{ number_format($summary['findings']['info']) }} info</p>
</div>
