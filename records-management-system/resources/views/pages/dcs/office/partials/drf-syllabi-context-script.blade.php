{{--
  DCS PARTIAL: Office Intake partial (drf-syllabi-context-script.blade.php)
  FILE: resources/views/pages/dcs/office/partials/drf-syllabi-context-script.blade.php
  DEBUG: OfficeIntakeHelper + office-intake.js / office-intake.css
--}}
{{--
DCS file: Office DRF syllabi context cascade + auto title.
GE/PE/NSTP = college only + shared catalog. Major = college + program.
--}}
<script>
(function () {
    const catalog = window.__ofiSyllabiCatalog || {};
    const SHARED_TYPES = { 'GE Courses': true, 'PE Courses': true, 'NSTP': true };
    const form = document.getElementById('ofiDrfForm');
    const block = document.getElementById('ofiDrfSyllabiContext');
    const courseTypeSel = document.getElementById('ofiDrfCourseType');
    const collegeSel = document.getElementById('ofiDrfCollegeId');
    const programSel = document.getElementById('ofiDrfProgramId');
    const programWrap = document.getElementById('ofiDrfProgramWrap');
    const semesterSel = document.getElementById('ofiDrfSemesterId');
    const courseSel = document.getElementById('ofiDrfCourseId');
    const schoolYearSel = document.getElementById('ofiDrfSchoolYearId');
    const titleInput = document.getElementById('drfTitle');
    const subSelect = document.getElementById('drfSubTypeId');
    if (!form || !block || !collegeSel) return;

    let titleManual = false;
    let seeding = false;

    /**
     * Checks whether syllabi like name condition is met.
     *
     * @param {*} name
     */
    function isSyllabiLikeName(name) {
        const n = String(name || '').toLowerCase();
        return n.indexOf('syllab') !== -1 || n.indexOf('tos') !== -1 || n.indexOf('rubric') !== -1;
    }

    /**
     * Selected sub name.
     */
    function selectedSubName() {
        if (subSelect && subSelect.tagName === 'SELECT') {
            const opt = subSelect.options[subSelect.selectedIndex];
            return opt ? String(opt.textContent || '') : '';
        }
        // Edit form keeps subtype as a hidden input; label is on the context block.
        if (block && block.getAttribute('data-sub-name')) {
            return String(block.getAttribute('data-sub-name') || '');
        }
        return '';
    }

    /**
     * Connectors for sub.
     */
    function connectorForSub() {
        const n = selectedSubName().toLowerCase();
        if (n.indexOf('tos') !== -1 || n.indexOf('rubric') !== -1) return 'TOS/Rubrics,';
        return 'Syllabus,';
    }

    function selectedCourseType() {
        return courseTypeSel ? String(courseTypeSel.value || '').trim() : '';
    }

    function isSharedType(type) {
        return !!SHARED_TYPES[String(type || '').trim()];
    }

    /**
     * Populates select in the interface.
     *
     * @param {*} select
     * @param {*} items
     * @param {*} valueKey
     * @param {*} labelKey
     * @param {*} placeholder
     * @param {*} keepValue
     */
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

    /**
     * Sets the enabled value.
     *
     * @param {*} select
     * @param {*} on
     * @param {*} required
     */
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

    function applyCourseTypeLayout(keep) {
        const type = selectedCourseType();
        const shared = isSharedType(type);
        // GE/PE/NSTP: hide Program entirely (shared catalog is college-scoped).
        // Major: show Program after course type is chosen.
        setProgramWrapVisible(!!type && !shared);
        if (!type) {
            setEnabled(collegeSel, false, false);
            setEnabled(programSel, false, false);
            setEnabled(semesterSel, false, false);
            setEnabled(courseSel, false, false);
            setEnabled(schoolYearSel, false, false);
            return;
        }
        setEnabled(collegeSel, true, true);
        fillSelect(collegeSel, catalog.colleges || [], 'college_id', 'college_name', 'Select college…', keep);
        if (shared) {
            setEnabled(programSel, false, false);
            if (programSel) {
                programSel.value = '';
                programSel.removeAttribute('required');
            }
        } else {
            rebuildPrograms(keep);
        }
    }

    /**
     * Rebuilds programs.
     *
     * @param {*} keep
     */
    function rebuildPrograms(keep) {
        if (isSharedType(selectedCourseType())) {
            setEnabled(programSel, false, false);
            if (programSel) programSel.value = '';
            return;
        }
        const collegeId = String(collegeSel.value || '');
        const programs = collegeId ? ((catalog.programsByCollege || {})[collegeId] || []) : [];
        fillSelect(programSel, programs, 'program_id', 'program_name', 'Select program…', keep);
        setEnabled(programSel, !!collegeId, true);
    }

    /**
     * Rebuilds semesters.
     *
     * @param {*} keep
     */
    function rebuildSemesters(keep) {
        const shared = isSharedType(selectedCourseType());
        const ready = shared
            ? !!(collegeSel && collegeSel.value)
            : !!(programSel && programSel.value);
        fillSelect(semesterSel, catalog.semesters || [], 'semester_id', 'semester_name', 'Select semester…', keep);
        setEnabled(semesterSel, ready, true);
    }

    /**
     * Rebuilds courses.
     *
     * @param {*} keep
     */
    function rebuildCourses(keep) {
        const type = selectedCourseType();
        const shared = isSharedType(type);
        const programId = programSel ? String(programSel.value || '') : '';
        const semesterId = semesterSel ? String(semesterSel.value || '') : '';
        let courses = [];
        let placeholder = 'Select course…';

        if (shared) {
            const key = type && semesterId ? (type + ':' + semesterId) : '';
            const raw = key ? ((catalog.coursesBySharedSemester || {})[key] || []) : [];
            courses = raw.filter(function (c) {
                const year = String(c.year_level || '').trim();
                const ct = String(c.course_type || '').trim();
                return year !== '' && ct !== '';
            });
            if (!collegeSel.value || !semesterId) {
                placeholder = 'Select college and semester first…';
            } else if (!courses.length) {
                placeholder = 'No institutional courses in Settings for this type and semester…';
            }
            fillSelect(courseSel, courses, 'id', 'course_name', placeholder, keep);
            setEnabled(courseSel, !!(collegeSel.value && semesterId), true);
            return;
        }

        // Major: Settings → Course Names for this program + this semester only.
        const key = programId && semesterId ? (programId + ':' + semesterId) : '';
        const raw = key ? ((catalog.coursesByProgramSemester || {})[key] || []) : [];
        courses = raw.filter(function (c) {
            const year = String(c.year_level || '').trim();
            const ct = String(c.course_type || '').trim();
            return year !== '' && ct === 'Major';
        });
        if (!programId || !semesterId) {
            placeholder = 'Select program and semester first…';
        } else if (!courses.length) {
            placeholder = 'No complete Major courses in Settings for this semester…';
        }
        fillSelect(courseSel, courses, 'id', 'course_name', placeholder, keep);
        setEnabled(courseSel, !!(programId && semesterId), true);
    }

    /**
     * Selected sub type id.
     */
    function selectedSubTypeId() {
        if (!subSelect) return '';
        return String(subSelect.value || '').trim();
    }

    /**
     * Takens school year ids for course.
     */
    function takenSchoolYearIdsForCourse() {
        const collegeId = collegeSel ? String(collegeSel.value || '') : '';
        const shared = isSharedType(selectedCourseType());
        const programId = shared ? '0' : (programSel ? String(programSel.value || '') : '');
        const semesterId = semesterSel ? String(semesterSel.value || '') : '';
        const courseId = courseSel ? String(courseSel.value || '') : '';
        const subTypeId = selectedSubTypeId();
        // Taken years are scoped by sub-type (Syllabi registration does not block TOS).
        if (!collegeId || (!shared && !programId) || !semesterId || !courseId || !subTypeId) return [];
        const key = collegeId + ':' + programId + ':' + semesterId + ':' + courseId + ':' + subTypeId;
        const raw = (catalog.takenSchoolYearsByCourse || {})[key] || [];
        return raw.map(function (id) { return String(id); });
    }

    /**
     * Rebuilds school years.
     *
     * @param {*} keep
     */
    function rebuildSchoolYears(keep) {
        const courseOn = !!(courseSel && courseSel.value);
        const taken = takenSchoolYearIdsForCourse();
        const takenSet = {};
        taken.forEach(function (id) { takenSet[id] = true; });
        const available = (catalog.schoolYears || []).filter(function (sy) {
            return !takenSet[String(sy.school_year_id)];
        });
        let placeholder = 'Select school year…';
        if (!courseOn) {
            placeholder = 'Select course first…';
        } else if (!available.length) {
            placeholder = taken.length
                ? 'Already registered for every school year…'
                : 'No school years in Settings…';
        }
        fillSelect(schoolYearSel, available, 'school_year_id', 'school_year', placeholder, keep);
        setEnabled(schoolYearSel, courseOn && available.length > 0, true);
    }

    /**
     * Selects text.
     *
     * @param {*} select
     */
    function selectText(select) {
        if (!select || select.selectedIndex < 0) return '';
        const opt = select.options[select.selectedIndex];
        return opt && opt.value ? String(opt.textContent || '').trim() : '';
    }

    /**
     * Builds title.
     */
    function buildTitle() {
        const college = selectText(collegeSel);
        const program = selectText(programSel);
        const course = selectText(courseSel);
        const semester = selectText(semesterSel);
        let schoolYear = selectText(schoolYearSel);
        const shared = isSharedType(selectedCourseType());
        if (!college || !course || !semester || !schoolYear) return '';
        if (!shared && !program) return '';
        schoolYear = schoolYear.replace(/^\s*s\/?y\s*/i, '');
        const forPart = shared ? college : program;
        return college + ' ' + connectorForSub() + ' ' + semester + ' S/Y ' + schoolYear
            + ' for ' + forPart + ' in ' + course;
    }

    /**
     * Renders and synchronizes title in the DOM.
     */
    function syncTitle() {
        if (!titleInput || titleManual || seeding) return;
        const next = buildTitle();
        if (next) titleInput.value = next;
    }

    /**
     * Displays the context overlay or dialog.
     *
     * @param {*} on
     */
    function showContext(on) {
        block.hidden = !on;
        block.classList.toggle('is-ofi-hidden', !on);
        if (!on) {
            setEnabled(courseTypeSel, false, false);
            setEnabled(collegeSel, false, false);
            setEnabled(programSel, false, false);
            setEnabled(semesterSel, false, false);
            setEnabled(courseSel, false, false);
            setEnabled(schoolYearSel, false, false);
            if (courseTypeSel) courseTypeSel.value = '';
            collegeSel.value = '';
            return;
        }
        setEnabled(courseTypeSel, true, true);
        applyCourseTypeLayout(true);
        rebuildSemesters(true);
        rebuildCourses(true);
        rebuildSchoolYears(true);
    }

    /**
     * Ofis sync syllabi context.
     */
    window.__ofiSyncSyllabiContext = function () {
        const kindRadio = form.querySelector('input[name="docTypeKind"]:checked');
        const kindHidden = form.querySelector('input[type="hidden"][name="docTypeKind"]');
        const kind = kindRadio ? String(kindRadio.value || '') : (kindHidden ? String(kindHidden.value || '') : '');
        const show = kind === 'internal' && isSyllabiLikeName(selectedSubName());
        showContext(show);
        if (show) {
            // Sub-type change (Syllabi ↔ TOS) must re-filter taken school years.
            rebuildSchoolYears(true);
            syncTitle();
        }
    };

    /**
     * Applies seed.
     */
    function applySeed() {
        const seedEl = document.getElementById('ofiDrfSyllabiSeed');
        if (!seedEl) return;
        const parts = String(seedEl.value || '').split(',');
        // New: courseType,college,program,semester,course,schoolYear
        // Legacy: college,program,semester,course,schoolYear
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
        if (!collegeId && !courseType) return;
        seeding = true;
        if (courseTypeSel && courseType) {
            courseTypeSel.value = courseType;
        }
        applyCourseTypeLayout(false);
        if (collegeId) collegeSel.value = collegeId;
        if (!isSharedType(selectedCourseType())) {
            rebuildPrograms(false);
            if (programSel) programSel.value = programId;
        }
        rebuildSemesters(false);
        if (semesterSel) semesterSel.value = semesterId;
        rebuildCourses(false);
        if (courseSel) courseSel.value = courseId;
        rebuildSchoolYears(false);
        if (schoolYearSel) schoolYearSel.value = schoolYearId;
        seeding = false;
        syncTitle();
        if (typeof window.__ofiRefreshDrfSignatories === 'function') {
            window.__ofiRefreshDrfSignatories();
        }
    }

    if (titleInput) {
        titleInput.addEventListener('input', function () {
            if (seeding) return;
            titleManual = String(titleInput.value || '').trim() !== '' && String(titleInput.value || '').trim() !== buildTitle();
        });
    }

    if (courseTypeSel) {
        courseTypeSel.addEventListener('change', function () {
            applyCourseTypeLayout(false);
            rebuildSemesters(false);
            rebuildCourses(false);
            rebuildSchoolYears(false);
            syncTitle();
            if (typeof window.__ofiRefreshDrfSignatories === 'function') {
                window.__ofiRefreshDrfSignatories();
            }
        });
    }

    collegeSel.addEventListener('change', function () {
        rebuildPrograms(false);
        rebuildSemesters(false);
        rebuildCourses(false);
        rebuildSchoolYears(false);
        syncTitle();
        if (typeof window.__ofiRefreshDrfSignatories === 'function') {
            window.__ofiRefreshDrfSignatories();
        }
    });
    if (programSel) programSel.addEventListener('change', function () {
        rebuildSemesters(false);
        rebuildCourses(false);
        rebuildSchoolYears(false);
        syncTitle();
    });
    if (semesterSel) semesterSel.addEventListener('change', function () {
        rebuildCourses(false);
        rebuildSchoolYears(false);
        syncTitle();
    });
    if (courseSel) courseSel.addEventListener('change', function () {
        rebuildSchoolYears(false);
        syncTitle();
    });
    if (schoolYearSel) schoolYearSel.addEventListener('change', syncTitle);

    form.querySelectorAll('input[name="docTypeKind"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            window.__ofiSyncSyllabiContext();
        });
    });

    window.__ofiSyncSyllabiContext();
    applySeed();
})();
</script>
