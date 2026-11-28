{{--
  DCS PARTIAL: Syllabi course sub-row aligned to main Inventory table columns
  FILE: resources/views/pages/dcs/database/_course-row.blade.php
--}}
<td class="db-course-sub-num">{{ $cIdx + 1 }}</td>
<td></td>
<td></td>
<td class="db-course-sub-title">
    <div class="db-ct-course">
        @if(!empty($cd['code']))
            <span class="db-course-code-badge">{{ $cd['code'] }}</span>
        @endif
        <span class="db-ct-course-name">{{ $cd['name'] ?: ($cd['code'] ?? '—') }}</span>
    </div>
</td>
<td></td>
<td class="rpt-originator db-course-sub-orig">
    @if(!empty($cd['originators']))
        <x-dcs.originator-lines :name="$cd['originators']" />
    @endif
</td>
<td></td>
<td></td>
<td></td>
<td class="db-offices-cell db-source-unit-col"></td>
<td class="db-related-col"></td>

<td class="col-group-summary-body col-group-summary-approval"></td>
<td class="col-group-approval"></td>
<td class="col-group-approval"></td>

<td class="col-group-summary-body col-group-summary-deadline"></td>
<td class="col-group-deadline"></td>
<td class="col-group-deadline"></td>

<td class="col-group-summary-body col-group-summary-masterlist"></td>
<td class="col-group-masterlist"></td>
<td class="col-group-masterlist"></td>
<td class="col-group-masterlist"></td>
<td class="col-group-masterlist"></td>
<td class="col-group-masterlist"></td>

<td class="col-group-summary-body col-group-summary-dcn"></td>
<td class="col-group-dcn"></td>
<td class="col-group-dcn"></td>
<td class="col-group-dcn"></td>
<td class="col-group-dcn"></td>
<td class="col-group-dcn db-offices-cell"></td>
<td class="col-group-dcn"></td>

@php
    $hasCourseDrf = !empty($cd['drf_available']) || !empty($cd['drf_no']) || !empty($cd['drf_date']) || !empty($cd['drf_received']) || !empty($cd['drf_scan']);
@endphp
<td class="col-group-summary-body col-group-summary-drf">
    @if($hasCourseDrf)<span class="db-summary-check">✓</span>@else<span class="db-summary-x">—</span>@endif
</td>
<td class="col-group-drf">{{ !empty($cd['drf_no']) ? $cd['drf_no'] : '—' }}</td>
<td class="col-group-drf">{{ !empty($cd['drf_date']) ? $cd['drf_date'] : '—' }}</td>
<td class="col-group-drf">{{ !empty($cd['drf_received']) ? $cd['drf_received'] : '—' }}</td>
<td class="col-group-drf">—</td>
<td class="col-group-drf">@include('pages.dcs.database._scan', ['url' => $cd['drf_scan'] ?? null])</td>

<td class="col-group-summary-body col-group-summary-distribution"></td>
<td class="col-group-distribution"></td>
<td class="col-group-distribution"></td>
<td class="col-group-distribution"></td>
<td class="col-group-distribution"></td>
<td class="col-group-distribution db-offices-cell"></td>
<td class="col-group-distribution"></td>

<td class="col-group-summary-body col-group-summary-retrieval"></td>
<td class="col-group-retrieval db-offices-cell"></td>
