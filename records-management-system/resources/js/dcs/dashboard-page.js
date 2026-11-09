/** DCS dashboard Alpine components (clock, search, calendar, admin attention). */
(() => {
    const register = () => {
    Alpine.data('ofiDashboardClock', (seed = {}) => ({
        nowClock: seed.headerTime || '',
        attentionUrl: seed.attentionUrl || '',
        attentionTotal: Number(seed.attentionTotal || 0),
        attentionItems: Array.isArray(seed.attentionItems) ? seed.attentionItems : [],
        _timer: null,
        _attentionTimer: null,
        _attentionBusy: false,
        tick() {
            try {
                this.nowClock = new Date().toLocaleTimeString([], {
                    hour: 'numeric',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: true,
                });
            } catch (e) {
                const n = new Date();
                this.nowClock = n.toLocaleTimeString();
            }
        },
        attentionKindClass(kind) {
            if (kind === 'incoming_receive' || kind === 'incoming_appraise') return 'incoming';
            return kind || 'incoming';
        },
        attentionIcon(kind) {
            if (kind === 'correction') return 'fa-solid fa-pen-to-square';
            if (kind === 'print_ready') return 'fa-solid fa-print';
            if (kind === 'incoming_appraise') return 'fa-solid fa-clipboard-check';
            return 'fa-solid fa-inbox';
        },
        attentionKindLabel(item) {
            const kind = item && item.kind ? item.kind : '';
            let label = 'Incoming';
            if (kind === 'correction') label = 'Needs correction';
            else if (kind === 'print_ready') label = 'Ready to print';
            else if (kind === 'incoming_receive') label = 'Needs to be received';
            else if (kind === 'incoming_appraise') label = 'Needs to be appraised';
            const type = item && item.type ? String(item.type).toLowerCase() : '';
            if (type === 'drf' || type === 'dcn') label += ' · ' + type.toUpperCase();
            return label;
        },
        async refreshAttention() {
            if (!this.attentionUrl || this._attentionBusy) return;
            if (typeof document !== 'undefined' && document.hidden) return;
            this._attentionBusy = true;
            try {
                const res = await fetch(this.attentionUrl, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if (!res.ok) return;
                const data = await res.json();
                this.attentionTotal = Number(data.total || 0);
                this.attentionItems = Array.isArray(data.items) ? data.items : [];
                const incoming = Number(data.incoming_total || 0);
                document.querySelectorAll('[data-ofi-incoming-stat]').forEach((el) => {
                    el.textContent = String(incoming);
                });
            } catch (e) {
                // Keep last known list when polling fails.
            } finally {
                this._attentionBusy = false;
            }
        },
        init() {
            this.tick();
            this._timer = setInterval(() => this.tick(), 1000);
            if (this.attentionUrl) {
                this.refreshAttention();
                this._attentionTimer = setInterval(() => this.refreshAttention(), 12000);
                this._attentionVisibility = () => {
                    if (!document.hidden) this.refreshAttention();
                };
                document.addEventListener('visibilitychange', this._attentionVisibility);
            }
        },
        destroy() {
            if (this._timer) clearInterval(this._timer);
            if (this._attentionTimer) clearInterval(this._attentionTimer);
            if (this._attentionVisibility) {
                document.removeEventListener('visibilitychange', this._attentionVisibility);
            }
        },
    }));

    
    Alpine.data('dcsDashAttention', (seed = {}) => ({
        attentionUrl: seed.attentionUrl || '',
        attentionTotal: Number(seed.attentionTotal || 0),
        attentionItems: Array.isArray(seed.attentionItems) ? seed.attentionItems : [],
        _timer: null,
        _busy: false,
        _onVis: null,
        async refresh() {
            if (!this.attentionUrl || this._busy) return;
            if (typeof document !== 'undefined' && document.hidden) return;
            this._busy = true;
            try {
                const url = this.attentionUrl + (this.attentionUrl.includes('?') ? '&' : '?') + 'fresh=1';
                const res = await fetch(url, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if (!res.ok) return;
                const data = await res.json();
                this.attentionTotal = Number(data.total || 0);
                this.attentionItems = Array.isArray(data.items) ? data.items : [];
            } catch (e) {
                // keep last known
            } finally {
                this._busy = false;
            }
        },
        init() {
            if (!this.attentionUrl) return;
            this.refresh();
            this._timer = setInterval(() => this.refresh(), 20000);
            this._onVis = () => { if (!document.hidden) this.refresh(); };
            document.addEventListener('visibilitychange', this._onVis);
            window.addEventListener('focus', this._onVis);
        },
        destroy() {
            if (this._timer) clearInterval(this._timer);
            if (this._onVis) {
                document.removeEventListener('visibilitychange', this._onVis);
                window.removeEventListener('focus', this._onVis);
            }
        },
    }));

    Alpine.data('dcsDashboardSearch', () => ({
        query: '',
        results: [],
        loading: false,
        open: false,
        timer: null,
        abortController: null,
        searchSeq: 0,
        detailModal: false,
        detailDoc: null,
        detailDocLabel: '',
        revisions: [],
        revisionsLoading: false,
        checklistLoading: false,
        checklistPreview: null,
        officeListExpanded: {},
        checklistEditUrl: '',
        checklistStampUrl: '',
        checklistInventoryUrl: '',
        activeChecklistKey: '',
        activeRequestId: null,
        fileVerifyLoading: false,
        fileVerifyState: '',
        fileVerifyMessage: '',
        checklistOptions: [
            { key: 'drf', label: 'DRF' },
            { key: 'dcn', label: 'DCN' },
            { key: 'masterlist', label: 'Masterlist' },
            { key: 'approval', label: 'Approving Body' },
            { key: 'distribution', label: 'Distribution' },
            { key: 'retrieval', label: 'Retrieval' },
            { key: 'file', label: 'Documented Information' },
        ],
        activeChecklists: null,
        search() {
            clearTimeout(this.timer);
            if (this.abortController) {
                try { this.abortController.abort(); } catch (e) {}
                this.abortController = null;
            }

            const q = this.query.trim();
            if (q.length < 2) {
                this.results = [];
                this.open = q.length > 0;
                this.loading = false;
                return;
            }

            this.loading = true;
            this.open = true;
            // Keep prior results only while the same query is still loading; never mix queries.
            const seq = ++this.searchSeq;
            const requested = q;
            // Short debounce so each typed character refreshes related matches quickly.
            this.timer = setTimeout(async () => {
                // Drop stale timers if the user kept typing.
                if (seq !== this.searchSeq || requested !== this.query.trim()) {
                    return;
                }

                const abort = new AbortController();
                this.abortController = abort;
                try {
                    const res = await fetch(
                        '/dcs/api/documents/search?q=' + encodeURIComponent(requested) + '&dashboard=1',
                        { signal: abort.signal, headers: { 'Accept': 'application/json' } }
                    );
                    if (seq !== this.searchSeq || requested !== this.query.trim()) {
                        return;
                    }
                    const payload = res.ok ? await res.json() : [];
                    // Re-check after JSON parse — older responses must never overwrite newer ones.
                    if (seq !== this.searchSeq || requested !== this.query.trim()) {
                        return;
                    }
                    this.results = Array.isArray(payload) ? payload : [];
                } catch (e) {
                    if (e && e.name === 'AbortError') {
                        return;
                    }
                    if (seq === this.searchSeq && requested === this.query.trim()) {
                        this.results = [];
                    }
                } finally {
                    if (seq === this.searchSeq && requested === this.query.trim()) {
                        this.loading = false;
                    }
                    if (this.abortController === abort) {
                        this.abortController = null;
                    }
                }
            }, 120);
        },
        clear() {
            clearTimeout(this.timer);
            if (this.abortController) {
                try { this.abortController.abort(); } catch (e) {}
                this.abortController = null;
            }
            this.searchSeq++;
            this.query = '';
            this.results = [];
            this.open = false;
            this.loading = false;
            this.closeDetailModal();
        },
        close() {
            this.open = false;
        },
        detailChecklistOptions() {
            const flags = this.activeChecklists || this.detailDoc?.checklists;
            if (!flags) return [];
            // Only tabs for checklists that were actually saved on this revision
            return this.checklistOptions.filter((cl) => !!flags[cl.key]);
        },
        resolveMatchedRevision(doc, revisions) {
            const targetId = doc.match_request_id || doc.request_id;
            if (!targetId) return null;
            const fromList = (revisions || []).find((r) => r.request_id === targetId);
            if (fromList) return fromList;
            if (targetId === doc.request_id) {
                return {
                    request_id: doc.request_id,
                    doc_no: doc.doc_no,
                    revise_no: doc.revise_no ?? 0,
                    checklists: doc.checklists || null,
                };
            }
            if (doc.match_request_id && doc.match_revise_no != null) {
                return {
                    request_id: doc.match_request_id,
                    doc_no: doc.doc_no,
                    revise_no: doc.match_revise_no,
                    checklists: null,
                };
            }
            return null;
        },
        applyActiveRevision(rev) {
            if (!rev?.request_id) return;
            this.activeRequestId = rev.request_id;
            this.detailDocLabel = (rev.doc_no || this.detailDoc?.doc_no || 'No number')
                + ' — Rev ' + (rev.revise_no ?? 0);
            this.checklistEditUrl = '/dcs/register/' + rev.request_id + '/edit';
            // Prefer per-revision checklist presence (excludes unchecked sections like DCN/Retrieval on first register)
            if (rev.checklists) {
                this.activeChecklists = rev.checklists;
            } else if (this.detailDoc?.request_id === rev.request_id && this.detailDoc?.checklists) {
                this.activeChecklists = this.detailDoc.checklists;
            } else {
                this.activeChecklists = { masterlist: true, file: true };
            }
        },
        scrollActiveRevisionIntoView() {
            this.$nextTick(() => {
                const el = document.querySelector('.dash-detail-rev-btn.is-active');
                el?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
        },
        async openDetail(doc) {
            this.detailDoc = doc;
            this.detailModal = true;
            this.revisions = [];
            this.checklistPreview = null;
            this.activeChecklistKey = '';
            this.activeChecklists = doc.checklists || null;
            this.checklistStampUrl = doc.stamp_url || '';
            this.checklistInventoryUrl = doc.inventory_url || '';
            document.body.classList.add('dash-cl-open');

            // Show matched revision + first checklist immediately; revisions load in parallel.
            this.applyActiveRevision(doc);
            const checklistPromise = this.loadFirstAvailableChecklist();

            this.revisionsLoading = true;
            try {
                const lineageId = doc.lineage_request_id || doc.request_id;
                const res = await fetch('/dcs/api/documents/revisions?request_id=' + lineageId);
                this.revisions = res.ok ? await res.json() : [];
            } catch (e) {
                this.revisions = [];
            } finally {
                this.revisionsLoading = false;
            }

            const matched = this.resolveMatchedRevision(doc, this.revisions);
            if (matched && matched.request_id && matched.request_id !== this.activeRequestId) {
                this.applyActiveRevision(matched);
                this.scrollActiveRevisionIntoView();
                await this.loadFirstAvailableChecklist();
            } else if (matched) {
                this.applyActiveRevision(matched);
            }

            await checklistPromise;
        },
        async selectRevision(rev) {
            if (!rev || rev.request_id === this.activeRequestId) return;
            this.applyActiveRevision(rev);
            await this.loadFirstAvailableChecklist();
        },
        async loadFirstAvailableChecklist() {
            const options = this.detailChecklistOptions();
            if (!options.length) {
                this.activeChecklistKey = '';
                this.checklistPreview = null;
                return;
            }

            // Keep current tab if it still exists on this revision; otherwise first available
            const current = this.activeChecklistKey;
            const preferred = options.find((cl) => cl.key === current) || options[0];
            await this.loadChecklist(preferred.key);
        },
        visibleSectionOffices(section) {
            const list = Array.isArray(section?.offices) ? section.offices : [];
            const heading = String(section?.heading || '');
            if (!/retrieval/i.test(heading) || this.officeListExpanded[heading]) return list;
            return list.slice(0, 4);
        },
        sectionOfficesNeedMore(section) {
            const list = Array.isArray(section?.offices) ? section.offices : [];
            return /retrieval/i.test(String(section?.heading || '')) && list.length > 4;
        },
        toggleSectionOffices(section) {
            const heading = String(section?.heading || '');
            this.officeListExpanded = {
                ...this.officeListExpanded,
                [heading]: !this.officeListExpanded[heading],
            };
        },
        resetFileVerify() {
            this.fileVerifyLoading = false;
            this.fileVerifyState = '';
            this.fileVerifyMessage = '';
        },
        async loadChecklist(type) {
            this.activeChecklistKey = type;
            this.checklistLoading = true;
            this.checklistPreview = null;
            this.officeListExpanded = {};
            this.resetFileVerify();
            try {
                const res = await fetch('/dcs/api/documents/' + this.activeRequestId + '/checklist/' + type);
                if (!res.ok) throw new Error('not found');
                this.checklistPreview = await res.json();
                this.resetFileVerify();
            } catch (e) {
                this.checklistPreview = {
                    title: type === 'file' ? 'Documented Information' : 'Checklist unavailable',
                    fields: [{ label: 'Message', value: type === 'file'
                        ? 'Documented information could not be loaded for this registration.'
                        : 'Could not load this checklist.' }],
                    sections: [],
                    has_file: false,
                    can_verify: false,
                };
            } finally {
                this.checklistLoading = false;
            }
        },
        async verifyFileChecksum() {
            if (!this.activeRequestId || this.fileVerifyLoading) return;
            this.fileVerifyLoading = true;
            this.fileVerifyMessage = '';
            this.fileVerifyState = '';
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const res = await fetch('/dcs/api/documents/' + this.activeRequestId + '/verify-checksum', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    this.fileVerifyState = 'missing';
                    this.fileVerifyMessage = data.message || 'Could not verify checksum.';
                    return;
                }
                if (data.match === true) {
                    this.fileVerifyState = 'match';
                } else if (data.match === false) {
                    this.fileVerifyState = 'mismatch';
                } else {
                    this.fileVerifyState = 'missing';
                }
                this.fileVerifyMessage = data.message || (
                    this.fileVerifyState === 'match'
                        ? 'Checksum matches the stored scan.'
                        : (this.fileVerifyState === 'mismatch'
                            ? 'Checksum does not match the stored scan.'
                            : 'Checksum could not be verified.')
                );
            } catch (e) {
                this.fileVerifyState = 'missing';
                this.fileVerifyMessage = 'Could not verify checksum.';
            } finally {
                this.fileVerifyLoading = false;
            }
        },
        closeDetailModal() {
            this.detailModal = false;
            this.detailDoc = null;
            this.revisions = [];
            this.checklistLoading = false;
            this.checklistPreview = null;
            this.detailDocLabel = '';
            this.checklistEditUrl = '';
            this.checklistStampUrl = '';
            this.checklistInventoryUrl = '';
            this.activeChecklistKey = '';
            this.activeRequestId = null;
            this.activeChecklists = null;
            this.resetFileVerify();
            document.body.classList.remove('dash-cl-open');
        },
    }));

    Alpine.data('dcsDashboardCalendar', (seed = {}) => ({
        months: ['January','February','March','April','May','June','July','August','September','October','November','December'],
        holidays: Array.isArray(seed.holidays) ? seed.holidays : (window.__DCS_DASH_BOOT && window.__DCS_DASH_BOOT.holidays) || [],
        calendarOpen: false,
        calendarReady: false,
        calendarPersistKey: 'dcs.dashboard.calendarOpen',
        eventCategoryFilter: '',
        nowClock: '',
        _clockTimer: null,
        year: new Date().getFullYear(),
        month: new Date().getMonth(),
        events: [],
        categories: [],
        modal: null,
        activeIso: '',
        editingId: null,
        pendingDeleteId: null,
        pendingDeleteTitle: '',
        saving: false,
        addingCategory: false,
        newCategory: '',
        newCategoryColor: '#0369a1',
        form: { title: '', category_id: '', date: '', startTime: '09:00', endTime: '10:00', description: '' },
        csrf() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        },
        headers(json) {
            const h = { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' };
            if (json) h['Content-Type'] = 'application/json';
            return h;
        },
        tickClock() {
            try {
                this.nowClock = new Date().toLocaleTimeString([], {
                    hour: 'numeric',
                    minute: '2-digit',
                    second: '2-digit',
                });
            } catch (e) {
                const n = new Date();
                this.nowClock = n.toTimeString().slice(0, 8);
            }
        },
        async init() {
            this.tickClock();
            this._clockTimer = setInterval(() => this.tickClock(), 1000);

            try {
                this.calendarOpen = localStorage.getItem(this.calendarPersistKey) === '1';
            } catch (e) {
                this.calendarOpen = false;
            }

            this.$watch('calendarOpen', (open) => {
                try {
                    localStorage.setItem(this.calendarPersistKey, open ? '1' : '0');
                } catch (e) {}
                if (open) {
                    this.loadAll();
                }
            });

            this.$nextTick(() => {
                requestAnimationFrame(() => {
                    this.calendarReady = true;
                });
            });

            // Defer calendar API work until the panel is opened (or was left open).
            if (this.calendarOpen) {
                await this.loadAll();
            }
        },
        destroy() {
            if (this._clockTimer) {
                clearInterval(this._clockTimer);
                this._clockTimer = null;
            }
        },
        toggleCalendar() {
            this.calendarOpen = !this.calendarOpen;
            if (this.calendarOpen && this.events.length === 0 && this.categories.length === 0) {
                this.loadAll();
            }
        },
        dismissCalendarPortal() {
            if (this.modal !== null) {
                this.modal = null;
            }
        },
        async loadAll() {
            try {
                const [cats, evs] = await Promise.all([
                    fetch('/dcs/api/calendar/categories', { headers: this.headers() }).then(r => r.json()),
                    fetch('/dcs/api/calendar/events', { headers: this.headers() }).then(r => r.json()),
                ]);
                this.categories = Array.isArray(cats) ? cats : [];
                this.events = Array.isArray(evs) ? evs : [];
            } catch (e) {
                this.categories = [];
                this.events = [];
            }
        },
        get title() { return this.months[this.month] + ' ' + this.year; },
        pad(n) { return String(n).padStart(2, '0'); },
        todayIso() {
            const t = new Date();
            return `${t.getFullYear()}-${this.pad(t.getMonth() + 1)}-${this.pad(t.getDate())}`;
        },
        toIso(y, m, d) { return `${y}-${this.pad(m + 1)}-${this.pad(d)}`; },
        occursOn(ev, iso) { return String(ev.date || '').slice(0, 10) === iso; },
        holidayName(iso) { return this.holidays[iso] || ''; },
        formatTime(time24) {
            if (!time24) return '';
            const [h, m] = String(time24).split(':').map(Number);
            return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h >= 12 ? 'PM' : 'AM'}`;
        },
        displayDate(iso) {
            return new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
        },
        displayDateShort(iso) {
            return new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        },
        eventListMeta(ev) {
            const d = String(ev.date || '').slice(0, 10);
            const t = this.todayIso();
            const datePart = d === t ? 'Today' : this.displayDateShort(d);
            const cat = ev.category_name ? ev.category_name + ' · ' : '';
            return cat + datePart + ' · ' + this.formatTime(ev.startTime) + ' — ' + this.formatTime(ev.endTime);
        },
        categoryColor(category) {
            const color = String(category?.color || '').trim();
            return /^#[0-9a-f]{6}$/i.test(color) ? color : '#0d2a7a';
        },
        changeMonth(dir) {
            this.month += dir;
            if (this.month > 11) { this.month = 0; this.year += 1; }
            else if (this.month < 0) { this.month = 11; this.year -= 1; }
        },
        get cells() {
            const first = new Date(this.year, this.month, 1).getDay();
            const days = new Date(this.year, this.month + 1, 0).getDate();
            const prevDays = new Date(this.year, this.month, 0).getDate();
            const today = this.todayIso();
            const out = [];
            for (let i = 0; i < 42; i++) {
                const offset = i - first + 1;
                let day = offset, m = this.month, y = this.year, outside = false;
                if (offset < 1) { day = prevDays + offset; m -= 1; outside = true; if (m < 0) { m = 11; y -= 1; } }
                else if (offset > days) { day = offset - days; m += 1; outside = true; if (m > 11) { m = 0; y += 1; } }
                const iso = this.toIso(y, m, day);
                const evs = this.events.filter(ev => this.occursOn(ev, iso));
                out.push({ iso, day, outside, today: iso === today, holiday: !!this.holidays[iso], colors: evs.slice(0, 3).map(e => e.color || '#0d2a7a') });
            }
            return out;
        },
        get dayEvents() { return this.events.filter(ev => this.occursOn(ev, this.activeIso)); },
        get allEventsList() {
            return (Array.isArray(this.events) ? this.events : [])
                .slice()
                .sort((a, b) => {
                    const da = String(b.date || '').slice(0, 10).localeCompare(String(a.date || '').slice(0, 10));
                    if (da !== 0) return da;
                    return (b.startTime || '').localeCompare(a.startTime || '');
                });
        },
        get filteredUpcoming() {
            const list = this.allEventsList;
            if (!this.eventCategoryFilter) return list;
            return list.filter(ev => String(ev.category_id) === String(this.eventCategoryFilter));
        },
        openDay(iso) {
            this.activeIso = iso;
            this.modal = 'day';
        },
        openAdd(iso) {
            this.editingId = null;
            const categoryId = this.categories[0]?.id != null ? String(this.categories[0].id) : '';
            this.form = {
                title: '',
                category_id: '',
                date: iso || this.todayIso(),
                startTime: '09:00',
                endTime: '10:00',
                description: '',
            };
            this.modal = 'form';
            // Select options render via x-for after the modal mounts; set value on next tick.
            this.$nextTick(() => { this.form.category_id = categoryId; });
        },
        openEdit(id) {
            const ev = this.events.find(e => String(e.id) === String(id));
            if (!ev || ev.readonly) return;
            this.editingId = id;
            const categoryId = ev.category_id != null ? String(ev.category_id) : '';
            this.form = {
                title: ev.title,
                category_id: '',
                date: String(ev.date).slice(0, 10),
                startTime: ev.startTime,
                endTime: ev.endTime,
                description: ev.description || '',
            };
            this.modal = 'form';
            // Select options render via x-for after the modal mounts; set value on next tick.
            this.$nextTick(() => { this.form.category_id = categoryId; });
        },
        toggleAddCategory() {
            this.addingCategory = !this.addingCategory;
            if (this.addingCategory) {
                this.$nextTick(() => this.$refs.newCatInput?.focus());
            } else {
                this.newCategory = '';
                this.newCategoryColor = '#0369a1';
            }
        },
        async addCategory() {
            const name = (this.newCategory || '').trim();
            if (!name) return;
            try {
                const res = await fetch('/dcs/api/calendar/categories', {
                    method: 'POST',
                    headers: this.headers(true),
                    body: JSON.stringify({ name, color: this.newCategoryColor }),
                });
                const data = await res.json();
                if (!res.ok) {
                    alert(data.message || data.errors?.name?.[0] || 'Could not add category.');
                    return;
                }
                this.categories.push(data);
                this.form.category_id = String(data.id);
                this.newCategory = '';
                this.newCategoryColor = '#0369a1';
                this.addingCategory = false;
            } catch (e) {
                alert('Could not add category.');
            }
        },
        get selectedCategoryColor() {
            const cat = this.categories.find(c => String(c.id) === String(this.form.category_id));
            return cat?.color || '#0369a1';
        },
        async updateSelectedCategoryColor(color) {
            const cat = this.categories.find(c => String(c.id) === String(this.form.category_id));
            if (!cat || !color) return;
            const previous = cat.color;
            cat.color = color;
            try {
                const res = await fetch('/dcs/api/calendar/categories/' + cat.id, {
                    method: 'PUT',
                    headers: this.headers(true),
                    body: JSON.stringify({ color }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    cat.color = previous;
                    alert(data.message || data.errors?.color?.[0] || 'Could not update category color.');
                    return;
                }
                if (data?.color) cat.color = data.color;
                this.events = this.events.map(ev => String(ev.category_id) === String(cat.id)
                    ? { ...ev, color: cat.color }
                    : ev);
            } catch (e) {
                cat.color = previous;
                alert('Could not update category color.');
            }
        },
        async removeSelectedCategory() {
            const cat = this.categories.find(c => String(c.id) === String(this.form.category_id));
            if (cat) await this.removeCategory(cat);
        },
        async removeCategory(cat) {
            if (!cat) return;
            if (!confirm('Delete the "' + cat.name + '" category?')) return;
            try {
                const res = await fetch('/dcs/api/calendar/categories/' + cat.id, {
                    method: 'DELETE',
                    headers: this.headers(),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    alert(data.message || 'Could not delete category.');
                    return;
                }
                this.categories = this.categories.filter(c => c.id !== cat.id);
                if (String(this.form.category_id) === String(cat.id)) {
                    this.form.category_id = this.categories[0]?.id != null ? String(this.categories[0].id) : '';
                }
            } catch (e) {
                alert('Could not delete category.');
            }
        },
        async saveForm() {
            if (this.form.endTime < this.form.startTime) { alert('End time cannot be earlier than start time.'); return; }
            if (!this.form.category_id) { alert('Please select a category.'); return; }
            this.saving = true;
            const payload = {
                title: this.form.title,
                category_id: Number(this.form.category_id),
                date: this.form.date,
                start_time: this.form.startTime,
                end_time: this.form.endTime,
                description: this.form.description,
            };
            try {
                const url = this.editingId
                    ? '/dcs/api/calendar/events/' + this.editingId
                    : '/dcs/api/calendar/events';
                const res = await fetch(url, {
                    method: this.editingId ? 'PUT' : 'POST',
                    headers: this.headers(true),
                    body: JSON.stringify(payload),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    const firstError = data.errors ? Object.values(data.errors).flat()[0] : null;
                    alert(firstError || data.message || 'Could not save event.');
                    return;
                }
                await this.loadAll();
                this.openDay(this.form.date);
            } catch (e) {
                alert('Could not save event.');
            } finally {
                this.saving = false;
            }
        },
        async removeEvent(id) {
            const ev = this.events.find(e => String(e.id) === String(id));
            if (ev?.readonly) return;
            this.askDeleteEvent(id);
        },
        askDeleteEvent(id) {
            const ev = this.events.find(e => String(e.id) === String(id));
            if (!ev || ev.readonly) return;
            this.pendingDeleteId = id;
            this.pendingDeleteTitle = ev.title || '';
            this.modal = 'delete';
        },
        cancelDeleteEvent() {
            this.pendingDeleteId = null;
            this.pendingDeleteTitle = '';
            if (this.activeIso) {
                this.modal = 'day';
            } else {
                this.modal = null;
            }
        },
        async confirmRemoveEvent() {
            const id = this.pendingDeleteId;
            if (id == null) return;
            this.saving = true;
            try {
                const res = await fetch('/dcs/api/calendar/events/' + id, {
                    method: 'DELETE',
                    headers: this.headers(),
                });
                if (!res.ok) {
                    alert('Could not delete event.');
                    return;
                }
                this.events = this.events.filter(e => e.id !== id);
                this.pendingDeleteId = null;
                this.pendingDeleteTitle = '';
                if (this.activeIso) {
                    this.modal = 'day';
                } else {
                    this.modal = null;
                }
            } catch (e) {
                alert('Could not delete event.');
            } finally {
                this.saving = false;
            }
        },
    }));
    };

    if (window.Alpine) {
        register();
    } else {
        document.addEventListener('alpine:init', register);
    }
})();
