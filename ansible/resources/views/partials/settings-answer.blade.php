{{-- The answer to a save on the settings page, shown at the top of the part that was saved: the page returns to
     that part's anchor. Each error is also shown next to its field. --}}
@php
    $answerStatus = session('ansible_status');
    $answerErrors = session('ansible_errors', []);
@endphp
@if($answerStatus)
    <div class="alert alert-success ans-answer" id="ans-settings-status" role="status">{{ $answerStatus }}</div>
@endif
@if($answerErrors)
    <div class="alert alert-danger ans-answer" id="ans-settings-errors" role="alert"><strong>{{ $failed }}</strong>
        <ul style="margin: 0">@foreach($answerErrors as $answerError)<li>{{ $answerError }}</li>@endforeach</ul></div>
@endif
