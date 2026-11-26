{{--
  DCS PARTIAL: Office Intake partial (drf-syllabi-context-script.blade.php)
  FILE: resources/views/pages/dcs/office/partials/drf-syllabi-context-script.blade.php
  DEBUG: OfficeIntakeHelper + office-intake.js / office-intake.css
--}}
{{--
DCS file: Office DRF syllabi context cascade + Single/Batch mode (separate DRF per course in Batch) + auto title.
All course types use college + program (per-program catalog).
--}}
<script>
(function () {
    const catalog = window.__ofiSyllabiCatalog || {};
    const form = document.getElementById('ofiDrfForm');
    const block = document.getElementById('ofiDrfSyllabiContext');
    const modeInput = document.getElementById('ofiDrfSyllabiMode');
    const modeSwitch = document.getElementById('ofiDrfSyllabiModeSwitch');
    const courseTypeSel = document.getElementById('ofiDrfCourseType');
    const collegeSel = document.getElementById('ofiDrfCollegeId');
    const programSel = document.getElementById('ofiDrfProgramId');
    const programWrap = document.getElementById('ofiDrfProgramWrap');
    const semesterSel = document.getElementById('ofiDrfSemesterId');
    const singleCourseWrap = document.getElementById('ofiDrfSingleCourseWrap');
    const courseSel = document.getElementById('ofiDrfCourseId');
    const schoolYearSel = document.getElementById('ofiDrfSchoolYearId');
    const batchWrap = document.getElementById('ofiDrfBatchCoursesWrap');
    const batchList = document.getElementById('ofiDrfBatchCoursesList');
    const batchCountEl = document.getElementById('ofiDrfBatchCount');
    const batchSearchEl = document.getElementById('ofiDrfBatchSearch');
    const batchSelectAllBtn = document.getElementById('ofiDrfBatchSelectAll');
    const batchClearBtn = document.getElementById('ofiDrfBatchClear');
    const batchDrfsWrap = document.getElementById('ofiDrfBatchDrfsWrap');
    const batchDrfsSub = document.getElementById('ofiDrfBatchDrfsSub');
    const batchDrfCards = document.getElementById('ofiDrfBatchDrfCards');
    const batchCopyDescBtn = document.getElementById('ofiDrfBatchCopyDescBtn');

    const singleTitleWrap = document.getElementById('ofiDrfSingleTitleWrap');
    const singleOriginatorWrap = document.getElementById('ofiDrfSingleOriginatorWrap');
    const singleDescWrap = document.getElementById('ofiDrfSingleDescWrap');
    const titleInput = document.getElementById('drfTitle');
    const originatorInput = document.getElementById('drfOriginatorName');
    const descInput = document.getElementById('descriptionReason');
    const subSelect = document.getElementById('drfSubTypeId');
    if (!form || !block || !collegeSel) return;

    let titleManual = false;
    let lastAutoTitle = '';
    let seeding = false;
    let currentCourses = [];
    let selectedBatchIds = new Set();
    /** @type {Object<string, {title: string, titleManual: boolean, originator: string, description: string}>} */
    const batchItemState = {};

    function currentMode() {
        return modeSwitch && modeInput && String(modeInput.value || '').toLowerCase() === 'batch' ? 'batch' : 'single';
    }

    function isSyllabiLikeName(name) {
        const n = String(name || '').toLowerCase();
        return n.indexOf('syllab') !== -1 || n.indexOf('tos') !== -1 || n.indexOf('rubric') !== -1;
    }

    function selectedSubName() {
        if (subSelect && subSelect.tagName === 'SELECT') {
            const opt = subSelect.options[subSelect.selectedIndex];
            return opt ? String(opt.textContent || '') : '';
        }
        if (block && block.getAttribute('data-sub-name')) {
            return String(block.getAttribute('data-sub-name') || '');
        }
        return '';
    }

    function connectorForSub() {
        const n = selectedSubName().toLowerCase();
        if (n.indexOf('tos') !== -1 || n.indexOf('rubric') !== -1) return 'TOS/Rubrics';
        return 'Syllabus';
    }

    function selectedCourseType() {
        return courseTypeSel ? String(courseTypeSel.value || '').trim() : '';
    }

    function selectedSubTypeId() {
        if (!subSelect) return '';
        return String(subSelect.value || '').trim();
    }

    function sortCoursesByCode(list) {
        return (list || []).slice().sort(function (a, b) {
            const ca = String(a.course_code || '');
            const cb = String(b.course_code || '');
            const codeCmp = ca.localeCompare(cb, undefined, { sensitivity: 'base' });
            if (codeCmp !== 0) return codeCmp;
            return String(a.course_name || '').localeCompare(String(b.course_name || ''), undefined, { sensitivity: 'base' });
        });
    }

    function fillSelect(select, items, valueKey, labelKey, placeholder, keepValue) {
        if (!select) return;
        const previous = keepValue ? String(select.value || '') : '';
        select.innerHTML = '';
        const ph = document.createElement('option');
        ph.value = '';
        ph.textContent = placeholder;
        ph.disabled = true;
        ph.hidden = true;
        select.appendChild(ph);
        (items || []).forEach(function (item) {
            const opt = document.createElement('option');
            opt.value = String(item[valueKey]);
            opt.textContent = String(item[labelKey] || '');
            if (item.course_type) opt.dataset.courseType = item.course_type;
            if (item.year_level) opt.dataset.yearLevel = item.year_level;
            select.appendChild(opt);
        });
        if (previous && [...select.options].some(function (o) { return o.value === previous && !o.disabled; })) {
            select.value = previous;
        } else {
            ph.selected = true;
        }
    }

    function setEnabled(select, on, required) {
        if (!select) return;
        select.disabled = !on;
        select.required = !!on && !!required;
        if (!on) select.value = '';
    }

    function setProgramWrapVisible(show) {
        if (!programWrap) return;
        programWrap.hidden = !show;
        programWrap.classList.toggle('is-ofi-hidden', !show);
    }

    function toggleSingleOnlyFields(showSingle) {
        [
            { wrap: singleTitleWrap, input: titleInput },
            { wrap: singleOriginatorWrap, input: originatorInput },
            { wrap: singleDescWrap, input: descInput },
        ].forEach(function (item) {
            if (item.wrap) {
                item.wrap.hidden = !showSingle;
                item.wrap.style.display = showSingle ? '' : 'none';
            }
            if (item.input) {
                item.input.disabled = !showSingle;
                item.input.required = !!showSingle;
            }
        });
    }

    function selectText(select) {
        if (!select || select.selectedIndex < 0) return '';
        const opt = select.options[select.selectedIndex];
        return opt && opt.value ? String(opt.textContent || '').trim() : '';
    }

    function buildTitleForCourseName(courseName) {
        const college = selectText(collegeSel);
        const program = selectText(programSel);
        const semester = selectText(semesterSel);
        let schoolYear = selectText(schoolYearSel);
        const course = String(courseName || '').trim();
        if (!college || !program || !course || !semester || !schoolYear) return '';
        schoolYear = schoolYear.replace(/^\s*s\/?y\s*/i, '');
        return college + ' ' + connectorForSub() + ' for ' + program + ' in ' + course
            + ', ' + semester + ', S/Y ' + schoolYear;
    }

    function buildSingleTitle() {
        return buildTitleForCourseName(selectText(courseSel));
    }

    function takenSchoolYearIdsForCourseId(courseId) {
        const collegeId = collegeSel ? String(collegeSel.value || '') : '';
        const programId = programSel ? String(programSel.value || '') : '';
        const semesterId = semesterSel ? String(semesterSel.value || '') : '';
        const subTypeId = selectedSubTypeId();
        if (!collegeId || !programId || !semesterId || !courseId || !subTypeId) return [];
        const key = collegeId + ':' + programId + ':' + semesterId + ':' + String(courseId) + ':' + subTypeId;
        const raw = (catalog.takenSchoolYearsByCourse || {})[key] || [];
        return raw.map(function (id) { return String(id); });
    }

    function isCourseTakenForSelectedYear(courseId) {
        const syId = schoolYearSel ? String(schoolYearSel.value || '') : '';
        if (!syId) return false;
        return takenSchoolYearIdsForCourseId(courseId).indexOf(syId) !== -1;
    }

    function updateBatchCount() {
        if (!batchCountEl) return;
        const n = selectedBatchIds.size;
        batchCountEl.textContent = n + ' selected';
        batchCountEl.classList.toggle('has-items', n > 0);
        batchCountEl.classList.remove('is-error');
    }

    function renderBatchDrfCards() {
        if (!batchDrfCards || !batchDrfsWrap) return;
        const isBatch = !block.hidden && currentMode() === 'batch';
        batchDrfsWrap.hidden = !isBatch;
        batchDrfsWrap.classList.toggle('is-ofi-hidden', !isBatch);
        if (!isBatch) {
            batchDrfCards.innerHTML = '';
            if (batchCopyDescBtn) batchCopyDescBtn.hidden = true;
            return;
        }

        const picked = currentCourses.filter(function (c) { return selectedBatchIds.has(String(c.id)); });
        if (batchDrfsSub) {
            batchDrfsSub.textContent = picked.length > 0
                ? (picked.length + ' separate DRF' + (picked.length === 1 ? '' : 's') + ' will be created — fill in the title, originator, and description for each course.')
                : 'Select courses above — each course gets its own DRF with a separate title, originator, and description.';
        }
        if (batchCopyDescBtn) {
            batchCopyDescBtn.hidden = picked.length < 2;
        }

        batchDrfCards.innerHTML = '';
        if (!picked.length) {
            batchDrfCards.innerHTML = '<div class="ofi-batch-empty">No courses selected yet. Check courses above to generate individual DRF forms.</div>';
            return;
        }

        picked.forEach(function (course, idx) {
            const cid = String(course.id);
            const code = String(course.course_code || '').trim();
            const cname = String(course.course_name || '').trim();
            const year = String(course.year_level || '').trim();
            const autoTitle = buildTitleForCourseName(cname);

            if (!batchItemState[cid]) {
                batchItemState[cid] = {
                    title: autoTitle,
                    titleManual: false,
                    originator: '',
                    description: '',
                };
            } else if (!batchItemState[cid].titleManual && autoTitle) {
                batchItemState[cid].title = autoTitle;
            }
            const st = batchItemState[cid];

            const card = document.createElement('div');
            card.className = 'ofi-batch-drf-card';
            card.dataset.courseId = cid;

            const head = document.createElement('div');
            head.className = 'ofi-batch-drf-card-head';

            const badge = document.createElement('span');
            badge.className = 'ofi-batch-drf-badge';
            badge.textContent = 'DRF #' + (idx + 1);
            head.appendChild(badge);

            const courseTitle = document.createElement('span');
            courseTitle.className = 'ofi-batch-drf-course';
            courseTitle.textContent = (code ? (code + ' — ') : '') + cname;
            head.appendChild(courseTitle);

            if (year) {
                const yr = document.createElement('span');
                yr.className = 'ofi-batch-year';
                yr.textContent = year;
                head.appendChild(yr);
            }
            card.appendChild(head);

            const hiddenCid = document.createElement('input');
            hiddenCid.type = 'hidden';
            hiddenCid.name = 'batchItems[' + idx + '][course_id]';
            hiddenCid.value = cid;
            card.appendChild(hiddenCid);

            const fields = document.createElement('div');
            fields.className = 'ofi-batch-drf-fields';

            // Document Title
            const titleWrap = document.createElement('div');
            titleWrap.className = 'ofi-batch-drf-full';
            const titleLbl = document.createElement('label');
            titleLbl.innerHTML = 'Document Title <span class="ofi-req">*</span>';
            const titleIn = document.createElement('input');
            titleIn.type = 'text';
            titleIn.name = 'batchItems[' + idx + '][drfTitle]';
            titleIn.required = true;
            titleIn.placeholder = 'Enter document title';
            titleIn.value = st.title || autoTitle || '';
            titleIn.addEventListener('input', function () {
                st.title = this.value;
                st.titleManual = this.value.trim() !== '' && this.value.trim() !== buildTitleForCourseName(cname);
            });
            titleWrap.appendChild(titleLbl);
            titleWrap.appendChild(titleIn);
            fields.appendChild(titleWrap);

            // Originator
            const origWrap = document.createElement('div');
            origWrap.className = 'ofi-batch-drf-full';
            const origLbl = document.createElement('label');
            origLbl.innerHTML = 'Originator <span class="ofi-req">*</span>';
            const origIn = document.createElement('input');
            origIn.type = 'text';
            origIn.name = 'batchItems[' + idx + '][originatorName]';
            origIn.required = true;
            origIn.placeholder = 'Separate multiple originators with a comma';
            origIn.value = st.originator || '';
            origIn.addEventListener('input', function () {
                st.originator = this.value;
            });
            origWrap.appendChild(origLbl);
            origWrap.appendChild(origIn);
            fields.appendChild(origWrap);

            // Description
            const descWrap = document.createElement('div');
            descWrap.className = 'ofi-batch-drf-full';
            const descLbl = document.createElement('label');
            descLbl.innerHTML = 'Description / reason for request <span class="ofi-req">*</span>';
            const descIn = document.createElement('textarea');
            descIn.name = 'batchItems[' + idx + '][descriptionReason]';
            descIn.rows = 2;
            descIn.required = true;
            descIn.maxLength = 5000;
            descIn.placeholder = 'For document control and dissemination';
            descIn.value = st.description || '';
            descIn.addEventListener('input', function () {
                st.description = this.value;
            });
            descIn.addEventListener('keydown', function (e) {
                if (e.key !== 'Tab' || e.shiftKey || this.value.trim() !== '') return;
                e.preventDefault();
                this.value = 'For document control and dissemination';
                st.description = this.value;
                const end = this.value.length;
                this.setSelectionRange(end, end);
                this.dispatchEvent(new Event('input', { bubbles: true }));
            });
            descWrap.appendChild(descLbl);
            descWrap.appendChild(descIn);
            fields.appendChild(descWrap);

            card.appendChild(fields);
            batchDrfCards.appendChild(card);
        });
    }

    function syncBatchTitles() {
        if (block.hidden || currentMode() !== 'batch') return;
        currentCourses.forEach(function (c) {
            const cid = String(c.id);
            if (!selectedBatchIds.has(cid)) return;
            const auto = buildTitleForCourseName(c.course_name);
            if (!batchItemState[cid]) {
                batchItemState[cid] = { title: auto, titleManual: false, originator: '', description: '' };
            } else if (!batchItemState[cid].titleManual) {
                batchItemState[cid].title = auto;
            }
            const card = batchDrfCards && batchDrfCards.querySelector('.ofi-batch-drf-card[data-course-id="' + cid + '"]');
            if (card && !batchItemState[cid].titleManual) {
                const input = card.querySelector('input[name$="[drfTitle]"]');
                if (input && auto) input.value = auto;
            }
        });
    }

    function renderBatchCourses() {
        if (!batchList) return;
        const programId = programSel ? String(programSel.value || '') : '';
        const semesterId = semesterSel ? String(semesterSel.value || '') : '';
        const schoolYearId = schoolYearSel ? String(schoolYearSel.value || '') : '';
        if (!programId || !semesterId || !schoolYearId) {
            selectedBatchIds.clear();
            updateBatchCount();
            batchList.innerHTML = '<div class="ofi-batch-empty">Select course type, college, program, semester, and school year to load courses.</div>';
            renderBatchDrfCards();
            return;
        }
        if (!currentCourses.length) {
            selectedBatchIds.clear();
            updateBatchCount();
            batchList.innerHTML = '<div class="ofi-batch-empty">No courses in Settings for this program, course type, semester, and school year.</div>';
            renderBatchDrfCards();
            return;
        }

        const validIds = new Set(currentCourses.map(function (c) { return String(c.id); }));
        [...selectedBatchIds].forEach(function (id) {
            if (!validIds.has(id) || isCourseTakenForSelectedYear(id)) {
                selectedBatchIds.delete(id);
            }
        });

        const query = batchSearchEl ? String(batchSearchEl.value || '').trim().toLowerCase() : '';
        batchList.innerHTML = '';
        let visibleCount = 0;
        const isBatch = currentMode() === 'batch';

        currentCourses.forEach(function (course) {
            const id = String(course.id);
            const code = String(course.course_code || '').trim();
            const name = String(course.course_name || '').trim();
            const year = String(course.year_level || '').trim();
            const hay = (code + ' ' + name + ' ' + year).toLowerCase();
            if (query && hay.indexOf(query) === -1) return;
            visibleCount++;

            const blocked = isCourseTakenForSelectedYear(id);
            const checked = !blocked && selectedBatchIds.has(id);

            const row = document.createElement('label');
            row.className = 'ofi-batch-row' + (checked ? ' is-checked' : '') + (blocked ? ' is-blocked' : '');

            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.name = 'courseIds[]';
            cb.value = id;
            cb.checked = checked;
            cb.disabled = blocked || !isBatch || block.hidden;
            cb.addEventListener('change', function () {
                if (this.checked) {
                    selectedBatchIds.add(id);
                } else {
                    selectedBatchIds.delete(id);
                }
                row.classList.toggle('is-checked', this.checked);
                updateBatchCount();
                renderBatchDrfCards();
            });
            row.appendChild(cb);

            if (code) {
                const codeChip = document.createElement('span');
                codeChip.className = 'ofi-batch-code';
                codeChip.textContent = code;
                row.appendChild(codeChip);
            }

            const nameSpan = document.createElement('span');
            nameSpan.className = 'ofi-batch-name';
            nameSpan.textContent = name;
            row.appendChild(nameSpan);

            if (year) {
                const yearChip = document.createElement('span');
                yearChip.className = 'ofi-batch-year';
                yearChip.textContent = year;
                row.appendChild(yearChip);
            }

            if (blocked) {
                const takenTag = document.createElement('span');
                takenTag.className = 'ofi-batch-taken';
                takenTag.textContent = 'Already requested';
                row.appendChild(takenTag);
            }

            batchList.appendChild(row);
        });

        if (visibleCount === 0) {
            batchList.innerHTML = '<div class="ofi-batch-empty">No courses match your filter.</div>';
        }
        updateBatchCount();
        renderBatchDrfCards();
    }

    function applyModeUI(mode) {
        const next = (modeSwitch && mode === 'batch') ? 'batch' : 'single';
        if (modeInput) modeInput.value = next;
        if (modeSwitch) {
            modeSwitch.querySelectorAll('.ofi-mode-btn').forEach(function (btn) {
                const active = btn.dataset.syllabiMode === next;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        }
        const isBatch = !block.hidden && next === 'batch';
        if (singleCourseWrap) {
            singleCourseWrap.hidden = isBatch;
            singleCourseWrap.classList.toggle('is-ofi-hidden', isBatch);
        }
        if (batchWrap) {
            batchWrap.hidden = !isBatch;
            batchWrap.classList.toggle('is-ofi-hidden', !isBatch);
        }
        toggleSingleOnlyFields(!isBatch);

        const ready = !block.hidden && !!(programSel && programSel.value && semesterSel && semesterSel.value && schoolYearSel && schoolYearSel.value);
        if (isBatch) {
            setEnabled(courseSel, false, false);
            renderBatchCourses();
        } else {
            if (batchList) {
                batchList.querySelectorAll('input[name="courseIds[]"]').forEach(function (cb) { cb.disabled = true; });
            }
            setEnabled(courseSel, ready && currentCourses.length > 0, true);
            renderBatchDrfCards();
        }
        syncTitle();
    }

    function applyCourseTypeLayout(keep) {
        const type = selectedCourseType();
        setProgramWrapVisible(!!type);
        if (!type) {
            setEnabled(collegeSel, false, false);
            setEnabled(programSel, false, false);
            setEnabled(semesterSel, false, false);
            setEnabled(courseSel, false, false);
            setEnabled(schoolYearSel, false, false);
            currentCourses = [];
            renderBatchCourses();
            return;
        }
        setEnabled(collegeSel, true, true);
        fillSelect(collegeSel, catalog.colleges || [], 'college_id', 'college_name', 'Select college…', keep);
        rebuildPrograms(keep);
    }

    function rebuildPrograms(keep) {
        const collegeId = String(collegeSel.value || '');
        const programs = collegeId ? ((catalog.programsByCollege || {})[collegeId] || []) : [];
        fillSelect(programSel, programs, 'program_id', 'program_name', 'Select program…', keep);
        setEnabled(programSel, !!collegeId, true);
    }

    function rebuildSemesters(keep) {
        const ready = !!(programSel && programSel.value);
        fillSelect(semesterSel, catalog.semesters || [], 'semester_id', 'semester_name', 'Select semester…', keep);
        setEnabled(semesterSel, ready, true);
    }

    function rebuildSchoolYears(keep) {
        const semReady = !!(programSel && programSel.value && semesterSel && semesterSel.value);
        const available = catalog.schoolYears || [];
        let placeholder = 'Select school year…';
        if (!semReady) {
            placeholder = 'Select semester first…';
        } else if (!available.length) {
            placeholder = 'No school year in Settings…';
        }
        fillSelect(schoolYearSel, available, 'school_year_id', 'school_year', placeholder, keep);
        setEnabled(schoolYearSel, semReady && available.length > 0, true);
    }

    function rebuildCourses(keep) {
        const type = selectedCourseType();
        const programId = programSel ? String(programSel.value || '') : '';
        const semesterId = semesterSel ? String(semesterSel.value || '') : '';
        const schoolYearId = schoolYearSel ? String(schoolYearSel.value || '') : '';
        let placeholder = 'Select course…';
        const key = programId && semesterId ? (programId + ':' + semesterId) : '';
        const raw = (key && schoolYearId) ? ((catalog.coursesByProgramSemester || {})[key] || []) : [];
        currentCourses = sortCoursesByCode(raw.filter(function (c) {
            const year = String(c.year_level || '').trim();
            const ct = String(c.course_type || '').trim();
            if (year === '' || ct === '' || (type && ct !== type)) return false;
            const cSy = c.school_year_id !== undefined && c.school_year_id !== null ? String(c.school_year_id) : '';
            if (cSy && cSy !== '0' && cSy !== schoolYearId) return false;
            return true;
        }));
        if (!programId || !semesterId || !schoolYearId) {
            placeholder = 'Select semester and school year first…';
        } else if (!currentCourses.length) {
            placeholder = 'No courses in Settings for this program, type, semester, and school year…';
        }
        fillSelect(courseSel, currentCourses, 'id', 'course_name', placeholder, keep);
        if (courseSel && schoolYearId) {
            [...courseSel.options].forEach(function (opt) {
                if (!opt.value) return;
                if (isCourseTakenForSelectedYear(opt.value)) {
                    opt.disabled = true;
                    opt.textContent = opt.textContent + ' (Already requested)';
                    if (courseSel.value === opt.value) courseSel.value = '';
                }
            });
        }
        const isBatch = currentMode() === 'batch';
        setEnabled(courseSel, !isBatch && !!(programId && semesterId && schoolYearId && currentCourses.length > 0), !isBatch);
        if (!keep) selectedBatchIds.clear();
        renderBatchCourses();
        syncBatchTitles();
    }

    function syncTitle() {
        if (currentMode() === 'batch') {
            syncBatchTitles();
            return;
        }
        if (!titleInput || titleManual || seeding) return;
        const next = buildSingleTitle();
        if (next) {
            titleInput.value = next;
            lastAutoTitle = next;
        } else if (lastAutoTitle && String(titleInput.value || '').trim() === lastAutoTitle) {
            titleInput.value = '';
            lastAutoTitle = '';
        }
    }

    function showContext(on) {
        block.hidden = !on;
        block.classList.toggle('is-ofi-hidden', !on);
        if (modeInput) modeInput.disabled = !on;
        if (!on) {
            toggleSingleOnlyFields(true);
            setEnabled(courseTypeSel, false, false);
            setEnabled(collegeSel, false, false);
            setEnabled(programSel, false, false);
            setEnabled(semesterSel, false, false);
            setEnabled(courseSel, false, false);
            setEnabled(schoolYearSel, false, false);
            if (batchList) {
                batchList.querySelectorAll('input[name="courseIds[]"]').forEach(function (cb) { cb.disabled = true; });
            }
            if (batchDrfCards) batchDrfCards.innerHTML = '';
            if (batchDrfsWrap) {
                batchDrfsWrap.hidden = true;
                batchDrfsWrap.classList.add('is-ofi-hidden');
            }
            if (courseTypeSel) courseTypeSel.value = '';
            collegeSel.value = '';
            if (titleInput && lastAutoTitle && String(titleInput.value || '').trim() === lastAutoTitle) {
                titleInput.value = '';
                lastAutoTitle = '';
                titleManual = false;
            }
            return;
        }
        setEnabled(courseTypeSel, true, true);
        applyCourseTypeLayout(true);
        rebuildSemesters(true);
        rebuildSchoolYears(true);
        rebuildCourses(true);
        applyModeUI(currentMode());
    }

    window.__ofiResetDrfTitle = function () {
        if (seeding) return;
        titleManual = false;
        lastAutoTitle = '';
        if (titleInput) titleInput.value = '';
    };

    window.__ofiSyncSyllabiContext = function () {
        const kindRadio = form.querySelector('input[name="docTypeKind"]:checked');
        const kindHidden = form.querySelector('input[type="hidden"][name="docTypeKind"]');
        const kind = kindRadio ? String(kindRadio.value || '') : (kindHidden ? String(kindHidden.value || '') : '');
        const show = kind === 'internal' && isSyllabiLikeName(selectedSubName());
        showContext(show);
        if (show) {
            rebuildSchoolYears(true);
            rebuildCourses(true);
            syncTitle();
        }
    };

    function applySeed() {
        const seedEl = document.getElementById('ofiDrfSyllabiSeed');
        const batchSeedEl = document.getElementById('ofiDrfBatchCoursesSeed');
        const batchItemsSeedEl = document.getElementById('ofiDrfBatchItemsSeed');
        if (batchItemsSeedEl) {
            try {
                const parsed = JSON.parse(batchItemsSeedEl.textContent || '[]');
                if (Array.isArray(parsed)) {
                    parsed.forEach(function (item) {
                        if (!item || !item.course_id) return;
                        const cid = String(item.course_id);
                        selectedBatchIds.add(cid);
                        batchItemState[cid] = {
                            title: String(item.drfTitle || ''),
                            titleManual: String(item.drfTitle || '').trim() !== '',
                            originator: String(item.originatorName || ''),
                            description: String(item.descriptionReason || ''),
                        };
                    });
                }
            } catch (_) {
                // ignore
            }
        }
        if (!seedEl) return;
        const parts = String(seedEl.value || '').split(',');
        let courseType = '';
        let collegeId = '';
        let programId = '';
        let semesterId = '';
        let courseId = '';
        let schoolYearId = '';
        if (parts.length >= 6) {
            courseType = parts[0] || '';
            collegeId = parts[1] || '';
            programId = parts[2] || '';
            semesterId = parts[3] || '';
            courseId = parts[4] || '';
            schoolYearId = parts[5] || '';
        } else {
            collegeId = parts[0] || '';
            programId = parts[1] || '';
            semesterId = parts[2] || '';
            courseId = parts[3] || '';
            schoolYearId = parts[4] || '';
        }
        const seedBatchIds = batchSeedEl
            ? String(batchSeedEl.value || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean)
            : [];
        seedBatchIds.forEach(function (cid) { selectedBatchIds.add(cid); });
        if (!collegeId && !courseType) return;
        seeding = true;
        if (courseTypeSel && courseType) courseTypeSel.value = courseType;
        applyCourseTypeLayout(false);
        if (collegeId) collegeSel.value = collegeId;
        rebuildPrograms(false);
        if (programSel) programSel.value = programId;
        rebuildSemesters(false);
        if (semesterSel) semesterSel.value = semesterId;
        rebuildSchoolYears(false);
        if (schoolYearSel && schoolYearId) schoolYearSel.value = schoolYearId;
        rebuildCourses(true);
        if (courseSel && courseId) courseSel.value = courseId;
        applyModeUI(currentMode());
        seeding = false;
        syncTitle();
        renderBatchDrfCards();
        if (typeof window.__ofiRefreshDrfSignatories === 'function') {
            window.__ofiRefreshDrfSignatories();
        }
    }

    if (modeSwitch) {
        modeSwitch.addEventListener('click', function (e) {
            const btn = e.target.closest('.ofi-mode-btn');
            if (!btn) return;
            const nextMode = btn.dataset.syllabiMode;
            if (!nextMode || nextMode === currentMode()) return;
            if (nextMode === 'batch' && courseSel && courseSel.value && selectedBatchIds.size === 0) {
                selectedBatchIds.add(String(courseSel.value));
            } else if (nextMode === 'single' && selectedBatchIds.size > 0 && courseSel && !courseSel.value) {
                courseSel.value = [...selectedBatchIds][0];
            }
            applyModeUI(nextMode);
        });
    }

    if (batchSearchEl) {
        batchSearchEl.addEventListener('input', renderBatchCourses);
    }

    if (batchSelectAllBtn) {
        batchSelectAllBtn.addEventListener('click', function () {
            currentCourses.forEach(function (c) {
                const id = String(c.id);
                if (!isCourseTakenForSelectedYear(id)) {
                    selectedBatchIds.add(id);
                }
            });
            renderBatchCourses();
        });
    }

    if (batchClearBtn) {
        batchClearBtn.addEventListener('click', function () {
            selectedBatchIds.clear();
            renderBatchCourses();
        });
    }

    if (batchCopyDescBtn) {
        batchCopyDescBtn.addEventListener('click', function () {
            if (!batchDrfCards) return;
            const firstTextarea = batchDrfCards.querySelector('.ofi-batch-drf-card textarea');
            if (!firstTextarea) return;
            const val = firstTextarea.value;
            batchDrfCards.querySelectorAll('.ofi-batch-drf-card').forEach(function (card) {
                const cid = card.dataset.courseId;
                const ta = card.querySelector('textarea');
                if (ta) ta.value = val;
                if (cid && batchItemState[cid]) batchItemState[cid].description = val;
            });
        });
    }

    if (titleInput) {
        titleInput.addEventListener('input', function () {
            if (seeding) return;
            titleManual = String(titleInput.value || '').trim() !== '' && String(titleInput.value || '').trim() !== buildSingleTitle();
        });
    }

    if (courseTypeSel) {
        courseTypeSel.addEventListener('change', function () {
            applyCourseTypeLayout(false);
            rebuildSemesters(false);
            rebuildSchoolYears(false);
            rebuildCourses(false);
            syncTitle();
            if (typeof window.__ofiRefreshDrfSignatories === 'function') {
                window.__ofiRefreshDrfSignatories();
            }
        });
    }

    collegeSel.addEventListener('change', function () {
        rebuildPrograms(false);
        rebuildSemesters(false);
        rebuildSchoolYears(false);
        rebuildCourses(false);
        syncTitle();
        if (typeof window.__ofiRefreshDrfSignatories === 'function') {
            window.__ofiRefreshDrfSignatories();
        }
    });
    if (programSel) programSel.addEventListener('change', function () {
        rebuildSemesters(false);
        rebuildSchoolYears(false);
        rebuildCourses(false);
        syncTitle();
    });
    if (semesterSel) semesterSel.addEventListener('change', function () {
        rebuildSchoolYears(false);
        rebuildCourses(false);
        syncTitle();
    });
    if (schoolYearSel) schoolYearSel.addEventListener('change', function () {
        rebuildCourses(false);
        syncTitle();
    });
    if (courseSel) courseSel.addEventListener('change', syncTitle);

    form.addEventListener('submit', function (e) {
        if (!block.hidden && currentMode() === 'batch' && selectedBatchIds.size < 1) {
            e.preventDefault();
            if (batchWrap) batchWrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (batchCountEl) {
                batchCountEl.textContent = 'Select at least 1 course';
                batchCountEl.classList.add('is-error');
            }
        }
    });

    form.querySelectorAll('input[name="docTypeKind"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            window.__ofiResetDrfTitle();
            window.__ofiSyncSyllabiContext();
        });
    });

    window.__ofiSyncSyllabiContext();
    applySeed();
})();
</script>
