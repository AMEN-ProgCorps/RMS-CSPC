<td>{{ $r['doc_no'] }}</td>
<td>{{ $r['rev_no'] }}</td>
<td title="{{ $r['title'] }}">{{ $r['title'] }}</td>
<td>{{ $r['effectivity'] }}</td>
<td>{{ $r['originator'] }}</td>
<td style="text-align:center">{{ $r['pages'] }}</td>
<td style="text-align:center"><span class="db-status db-status-{{ strtolower($r['status'] ?? 'latest') }}">{{ $r['status'] }}</span></td>
<td style="text-align:center">
    @include('pages.dcs.database._scan', ['url' => $r['pdf_path'] ?? null])
</td>
<td class="db-offices-cell" title="{{ $r['source_unit'] }}">{{ $r['source_unit'] ?: '—' }}</td>
<td>
    @forelse($r['related'] ?? [] as $rel)
        <span class="db-related-tag">{{ $rel['title'] ?? $rel['doc_no'] }}</span>
    @empty
        <span class="db-na">—</span>
    @endforelse
</td>

<td class="col-group-summary-body col-group-summary-approval" x-show="!open.approval">
    @if($r['approval_no'] || $r['approval_date'])<span class="db-summary-check">✓</span>@else<span class="db-summary-x">✗</span>@endif
</td>
<td class="col-group-approval" x-show="open.approval">{{ $r['approval_no'] ?: '—' }}</td>
<td class="col-group-approval" x-show="open.approval">{{ $r['approval_date'] ?: '—' }}</td>

<td class="col-group-summary-body col-group-summary-deadline" x-show="!open.deadline">
    @if($r['deadline_date'] || $r['deadline_diff'])<span class="db-summary-check">✓</span>@else<span class="db-summary-x">✗</span>@endif
</td>
<td class="col-group-deadline" x-show="open.deadline">{{ $r['deadline_date'] ?: '—' }}</td>
<td class="col-group-deadline" x-show="open.deadline">{{ $r['deadline_diff'] ?: '—' }}</td>

<td class="col-group-summary-body col-group-summary-masterlist" x-show="!open.masterlist">
    @if($r['ml_receipt_date'] || $r['ml_receipt_time'] || $r['ml_register_date'] || $r['ml_register_time'] || $r['ml_time_spent'] !== null)<span class="db-summary-check">✓</span>@else<span class="db-summary-x">✗</span>@endif
</td>
<td class="col-group-masterlist" x-show="open.masterlist">{{ $r['ml_receipt_date'] ?: '—' }}</td>
<td class="col-group-masterlist" x-show="open.masterlist">{{ $r['ml_receipt_time'] ?: '—' }}</td>
<td class="col-group-masterlist" x-show="open.masterlist">{{ $r['ml_register_date'] ?: '—' }}</td>
<td class="col-group-masterlist" x-show="open.masterlist">{{ $r['ml_register_time'] ?: '—' }}</td>
<td class="col-group-masterlist" x-show="open.masterlist">{{ $r['ml_time_spent'] !== null ? $r['ml_time_spent'] : '—' }}</td>

<td class="col-group-summary-body col-group-summary-dcn" x-show="!open.dcn">
    @if($r['dcn_no'] || $r['dcn_date'] || $r['dcn_receipt_date'] || $r['dcn_receipt_time'] || $r['dcn_purpose'] || $r['dcn_scan'])<span class="db-summary-check">✓</span>@else<span class="db-summary-x">✗</span>@endif
</td>
<td class="col-group-dcn" x-show="open.dcn">{{ $r['dcn_no'] ?: '—' }}</td>
<td class="col-group-dcn" x-show="open.dcn">{{ $r['dcn_date'] ?: '—' }}</td>
<td class="col-group-dcn" x-show="open.dcn">{{ $r['dcn_receipt_date'] ?: '—' }}</td>
<td class="col-group-dcn" x-show="open.dcn">{{ $r['dcn_receipt_time'] ?: '—' }}</td>
<td class="col-group-dcn" x-show="open.dcn">{{ $r['dcn_purpose'] ?: '—' }}</td>
<td class="col-group-dcn" x-show="open.dcn">@include('pages.dcs.database._scan', ['url' => $r['dcn_scan'] ?? null])</td>

<td class="col-group-summary-body col-group-summary-drf" x-show="!open.drf">
    @if($r['drf_no'] || $r['drf_date'] || $r['drf_receipt_date'] || $r['drf_receipt_time'] || $r['drf_scan'])<span class="db-summary-check">✓</span>@else<span class="db-summary-x">✗</span>@endif
</td>
<td class="col-group-drf" x-show="open.drf">{{ $r['drf_no'] ?: '—' }}</td>
<td class="col-group-drf" x-show="open.drf">{{ $r['drf_date'] ?: '—' }}</td>
<td class="col-group-drf" x-show="open.drf">{{ $r['drf_receipt_date'] ?: '—' }}</td>
<td class="col-group-drf" x-show="open.drf">{{ $r['drf_receipt_time'] ?: '—' }}</td>
<td class="col-group-drf" x-show="open.drf">@include('pages.dcs.database._scan', ['url' => $r['drf_scan'] ?? null])</td>

<td class="col-group-summary-body col-group-summary-distribution" x-show="!open.distribution">
    @if($r['dist_onfile_date'] || $r['dist_onfile_time'] || $r['dist_actual_date'] || $r['dist_actual_time'] || $r['dist_offices'] || $r['dist_scan'])<span class="db-summary-check">✓</span>@else<span class="db-summary-x">✗</span>@endif
</td>
<td class="col-group-distribution" x-show="open.distribution">{{ $r['dist_onfile_date'] ?: '—' }}</td>
<td class="col-group-distribution" x-show="open.distribution">{{ $r['dist_onfile_time'] ?: '—' }}</td>
<td class="col-group-distribution" x-show="open.distribution">{{ $r['dist_actual_date'] ?: '—' }}</td>
<td class="col-group-distribution" x-show="open.distribution">{{ $r['dist_actual_time'] ?: '—' }}</td>
<td class="col-group-distribution db-offices-cell" x-show="open.distribution" title="{{ $r['dist_offices'] }}">{{ $r['dist_offices'] ?: '—' }}</td>
<td class="col-group-distribution" x-show="open.distribution">@include('pages.dcs.database._scan', ['url' => $r['dist_scan'] ?? null])</td>

<td class="col-group-summary-body col-group-summary-retrieval" x-show="!open.retrieval">
    @if($r['ret_onfile'] || $r['ret_actual'] || $r['ret_offices'] || $r['ret_scan'])<span class="db-summary-check">✓</span>@else<span class="db-summary-x">✗</span>@endif
</td>
<td class="col-group-retrieval" x-show="open.retrieval">{{ $r['ret_onfile'] ?: '—' }}</td>
<td class="col-group-retrieval" x-show="open.retrieval">{{ $r['ret_actual'] ?: '—' }}</td>
<td class="col-group-retrieval db-offices-cell" x-show="open.retrieval" title="{{ $r['ret_offices'] }}">{{ $r['ret_offices'] ?: '—' }}</td>
<td class="col-group-retrieval" x-show="open.retrieval">@include('pages.dcs.database._scan', ['url' => $r['ret_scan'] ?? null])</td>
