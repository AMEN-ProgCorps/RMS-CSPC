{{--
  DCS PARTIAL: Admin dashboard document search & detail/checklist modal
  FILE: resources/views/pages/dcs/partials/admin-dashboard/search.blade.php
--}}
<section class="dash-search-section" x-data="dcsDashboardSearch()" @keydown.escape.window="detailModal ? closeDetailModal() : close()">
    <div class="section-header">
        <h2>Search Documents</h2>
        <span class="section-subtitle">Find by document no., title, or keywords</span>
    </div>

    <div class="dash-search-card" :class="{ 'has-results': open && query.trim().length > 0 }">
        <div class="dash-search-bar">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input
                type="text"
                class="dash-search-input"
                x-model="query"
                @input="search()"
                @focus="onFocus()"
                placeholder="Search by document no., title, or keywords..."
                autocomplete="off"
                aria-label="Search documents"
            >
            <span class="dash-search-hint" x-show="query.trim().length === 1" x-cloak>Type at least 2 characters</span>
            <button type="button" class="dash-search-clear" x-show="query.length > 0" x-cloak @click="clear()">
                Clear
            </button>
        </div>

        <div class="dash-search-results" x-show="open && query.trim().length > 0" x-cloak>
            <div class="dash-search-results-head">
                <span x-show="loading">Searching...</span>
                <span x-show="!loading && searchError">Search took too long or failed</span>
                <span x-show="!loading && !searchError && results.length > 0" x-text="results.length + (results.length === 1 ? ' document found' : ' documents found')"></span>
                <span x-show="!loading && !searchError && results.length === 0">No matching documents</span>
            </div>

            <template x-if="loading && results.length === 0">
                <div class="dash-search-state">
                    <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
                    <span>Searching masterlist records...</span>
                </div>
            </template>

            <template x-if="!loading && searchError && results.length === 0">
                <div class="dash-search-state">
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <span>Could not load search results.</span>
                    <button type="button" class="dash-search-clear" @click="search(true)">Retry</button>
                </div>
            </template>

            <template x-if="!loading && !searchError && results.length === 0">
                <div class="dash-search-state">
                    <i class="fa-regular fa-folder-open" aria-hidden="true"></i>
                    <span x-text="query.trim().length < 2 ? 'Type at least 2 characters to search.' : 'No documents matched your search.'"></span>
                </div>
            </template>

            <template x-if="results.length > 0">
                <div class="dash-search-list" :class="{ 'is-refreshing': loading }">
                    <template x-for="doc in results" :key="doc.masterlist_id">
                        <article class="dash-search-item dash-search-item-clickable" @click="openDetail(doc)" role="button" tabindex="0" @keydown.enter.prevent="openDetail(doc)">
                            <div class="dash-search-item-top">
                                <div class="dash-search-item-main">
                                    <code class="dash-search-docno" x-text="doc.doc_no || 'No number'"></code>
                                    <h3 class="dash-search-title" x-text="doc.doc_title || 'Untitled'"></h3>
                                    <div class="dash-search-meta" x-show="doc.type_name || doc.sub_type_name">
                                        <span class="dash-search-meta-item" x-show="doc.type_name">
                                            <span class="dash-search-meta-label">Type</span>
                                            <span x-text="doc.type_name"></span>
                                        </span>
                                        <span class="dash-search-meta-item" x-show="doc.sub_type_name">
                                            <span class="dash-search-meta-label">Sub type</span>
                                            <span x-text="doc.sub_type_name"></span>
                                        </span>
                                    </div>
                                </div>
                                <span class="dash-search-rev" x-text="'Rev ' + (doc.revise_no ?? 0) + (doc.revision_status === 'obsolete' ? ' · Obsolete' : '')"></span>
                            </div>
                            <div class="dash-search-item-hint">
                                <span>Click to view checklists and revisions</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </div>
                        </article>
                    </template>
                </div>
            </template>
        </div>
    </div>

    <template x-teleport="body">
        <div
            class="dash-cl-overlay dash-detail-overlay"
            x-show="detailModal"
            x-cloak
            @click.self="closeDetailModal()"
            style="display:none;"
            :style="detailModal ? 'display:flex' : 'display:none'"
        >
            <div class="dash-detail-modal" @click.stop role="dialog" aria-modal="true">
                <div class="dash-cl-modal-head">
                    <div class="dash-cl-modal-head-text">
                        <p class="dash-cl-modal-kicker">Document Detail</p>
                        <h3 x-text="detailDoc?.doc_title || 'Untitled'"></h3>
                        <p class="dash-cl-modal-doc" x-show="detailDocLabel" x-text="detailDocLabel"></p>
                    </div>
                    <button type="button" class="dash-cl-modal-close" @click="closeDetailModal()" aria-label="Close">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="dash-detail-body">
                    <div class="dash-detail-main">
                        <div class="dash-detail-checklist-tabs">
                            <template x-for="cl in detailChecklistOptions()" :key="cl.key">
                                <button
                                    type="button"
                                    class="dash-cl-btn"
                                    :class="{ 'is-active': activeChecklistKey === cl.key }"
                                    @click="loadChecklist(cl.key)"
                                    x-text="cl.label"
                                ></button>
                            </template>
                        </div>

                        <div class="dash-detail-preview">
                            <template x-if="checklistLoading">
                                <div class="dash-cl-loading">
                                    <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
                                    <span>Loading checklist data...</span>
                                </div>
                            </template>

                            <template x-if="!checklistLoading && checklistPreview">
                                <div class="dash-cl-body">
                                    <template x-if="activeChecklistKey === 'file' && checklistPreview && checklistPreview.preview_url">
                                        <div class="dash-file-preview">
                                            <iframe
                                                class="dash-file-preview-frame"
                                                :src="checklistPreview.preview_url"
                                                title="Document scan preview"
                                            ></iframe>
                                        </div>
                                    </template>

                                    <template x-if="activeChecklistKey === 'file' && checklistPreview && checklistPreview.can_verify">
                                        <div class="dash-file-verify">
                                            <button
                                                type="button"
                                                class="dash-file-verify-btn"
                                                :disabled="fileVerifyLoading"
                                                @click="verifyFileChecksum()"
                                            >
                                                <i class="fa-solid" :class="fileVerifyLoading ? 'fa-spinner fa-spin' : 'fa-shield-halved'" aria-hidden="true"></i>
                                                <span x-text="fileVerifyLoading ? 'Verifying…' : 'Verify checksum'"></span>
                                            </button>
                                            <p
                                                class="dash-file-verify-msg"
                                                :class="{
                                                    'is-ok': fileVerifyState === 'match',
                                                    'is-bad': fileVerifyState === 'mismatch' || fileVerifyState === 'missing'
                                                }"
                                                x-show="fileVerifyMessage"
                                                x-text="fileVerifyMessage"
                                            ></p>
                                        </div>
                                    </template>

                                    <table class="dash-cl-table">
                                        <tbody>
                                            <template x-for="field in checklistPreview.fields" :key="field.label">
                                                <tr>
                                                    <th x-text="field.label"></th>
                                                    <td x-text="field.value"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>

                                    <template x-for="(section, idx) in (checklistPreview.sections || [])" :key="idx">
                                        <div class="dash-cl-section">
                                            <h4 x-text="section.heading"></h4>
                                            <template x-if="section.offices && section.offices.length">
                                                <div class="dash-cl-office-wrap" :class="{ 'has-copies': section.with_copies }">
                                                    <table class="dash-cl-office-table">
                                                        <thead>
                                                            <tr>
                                                                <th class="dash-cl-office-col-name">Office</th>
                                                                <th class="dash-cl-office-col-copies" x-show="section.with_copies">Copies</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <template x-for="(office, officeIdx) in visibleSectionOffices(section)" :key="officeIdx">
                                                                <tr>
                                                                    <td class="dash-cl-office-col-name" x-text="office.office"></td>
                                                                    <td class="dash-cl-office-col-copies" x-show="section.with_copies" x-text="office.copies"></td>
                                                                </tr>
                                                            </template>
                                                        </tbody>
                                                    </table>
                                                    <button
                                                        type="button"
                                                        class="dash-cl-office-more"
                                                        x-show="sectionOfficesNeedMore(section)"
                                                        @click="toggleSectionOffices(section)"
                                                        x-text="officeListExpanded[section.heading] ? 'See less' : ('See more (' + (section.offices.length - 4) + ' more)')"
                                                    ></button>
                                                </div>
                                            </template>
                                            <template x-if="section.items">
                                                <ul class="dash-cl-list">
                                                    <template x-for="item in section.items" :key="item">
                                                        <li x-text="item"></li>
                                                    </template>
                                                </ul>
                                            </template>
                                            <template x-if="section.revisions">
                                                <div class="dash-cl-revisions">
                                                    <template x-for="(rev, revIdx) in section.revisions" :key="revIdx">
                                                        <div class="dash-cl-rev-card">
                                                            <div class="dash-cl-rev-title">
                                                                <strong x-text="rev.document_no"></strong>
                                                                <span x-text="rev.title"></span>
                                                            </div>
                                                            <div class="dash-cl-rev-meta">
                                                                <span x-text="'Rev ' + rev.revision_no"></span>
                                                                <span x-text="rev.effectivity_date_label || rev.effectivity_date"></span>
                                                            </div>
                                                            <div class="dash-cl-rev-purpose">
                                                                <span class="dash-cl-rev-purpose-label">Justification</span>
                                                                <p x-text="(rev.brief_purpose && rev.brief_purpose !== '—') ? rev.brief_purpose : '—'"></p>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>

                    <aside class="dash-detail-revisions">
                        <h4>Revisions</h4>
                        <template x-if="revisionsLoading">
                            <div class="dash-detail-rev-loading">Loading...</div>
                        </template>
                        <template x-if="!revisionsLoading && revisions.length === 0">
                            <p class="dash-detail-rev-empty">No other revisions</p>
                        </template>
                        <template x-if="!revisionsLoading && revisions.length > 0">
                            <div class="dash-detail-rev-list">
                                <template x-for="rev in revisions" :key="rev.request_id">
                                    <button
                                        type="button"
                                        class="dash-detail-rev-btn"
                                        :class="{ 'is-active': activeRequestId === rev.request_id }"
                                        @click="selectRevision(rev)"
                                    >
                                        <span class="dash-detail-rev-no" x-text="'Rev ' + rev.revise_no"></span>
                                        <span
                                            class="dash-detail-rev-status"
                                            :class="{
                                                'is-latest': !rev.revision_status || rev.revision_status === 'latest',
                                                'is-superseded': rev.revision_status === 'superseded',
                                                'is-obsolete': rev.revision_status === 'obsolete'
                                            }"
                                            x-text="rev.revision_status === 'obsolete' ? 'Obsolete' : (rev.revision_status === 'superseded' ? 'Superseded' : 'Latest')"
                                        ></span>
                                        <span class="dash-detail-rev-date" x-text="rev.effectivity_date_label || rev.effectivity_date || '—'"></span>
                                    </button>
                                </template>
                            </div>
                        </template>
                    </aside>
                </div>

                <div class="dash-cl-modal-foot">
                    <a class="dash-cl-close-btn" :href="checklistInventoryUrl" x-show="checklistInventoryUrl">Open in Database</a>
                    <a class="dash-cl-close-btn" :href="checklistEditUrl" x-show="checklistEditUrl">Edit document</a>
                    <a class="dash-cl-close-btn" :href="checklistStampUrl" x-show="checklistStampUrl">Stamp</a>
                    <button type="button" class="dash-cl-close-btn" @click="closeDetailModal()">Close</button>
                </div>
            </div>
        </div>
    </template>
</section>
