{{-- The exports of a run or a change (ReportController): the report in a new tab (to read, print, or save as PDF from the
     browser's print dialog), the Word document and the ZIP archive downloaded. $exportRoute names the export route,
     $exportId the run or change, $exportZip what the archive holds. --}}
<div class="ans-export" id="ans-export">
    <span class="ans-muted">Export:</span>
    <a class="btn btn-xs btn-default" href="{{ route($exportRoute, [$exportId, 'html']) }}" target="_blank" rel="noopener" id="ans-export-html"
        title="The report in a new tab: print it, or save it as PDF from the browser's print dialog"><i class="fa fa-file-text-o"></i> Report</a>
    <a class="btn btn-xs btn-default" href="{{ route($exportRoute, [$exportId, 'docx']) }}" id="ans-export-docx" title="The report as a Word document (.docx)"><i class="fa fa-file-word-o"></i> Word</a>
    <a class="btn btn-xs btn-default" href="{{ route($exportRoute, [$exportId, 'zip']) }}" id="ans-export-zip" title="{{ $exportZip }}"><i class="fa fa-file-archive-o"></i> ZIP</a>
    <span class="ans-help">the report prints to PDF from the browser; the ZIP holds {{ $exportZip }}</span>
</div>
