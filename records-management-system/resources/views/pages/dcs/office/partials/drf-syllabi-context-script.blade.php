{{--
DCS file: Office DRF syllabi context cascade + auto title.
--}}
<script>
(function () {
    const catalog = window.__ofiSyllabiCatalog || {};
    const form = document.getElementById('ofiDrfForm');
    const block = document.getElementById('ofiDrfSyllabiContext');
    const collegeSel = document.getElementById('ofiDrfCollegeId');
    const programSel = document.getElementById('ofiDrfProgramId');
    const semesterSel = document.getElementById('ofiDrfSemesterId');
    const courseSel = document.getElementById('ofiDrfCourseId');
    const schoolYearSel = document.getElementById('ofiDrfSchoolYearId');
    const titleInput = document.getElementById('drfTitle');
    const subSelect = document.getElementById('drfSubTypeId');
    if (!form || !block || !collegeSel) return;

    let titleManual = false;
    let seeding = false;

    function isSyllabiLikeName(name) {
        const n = String(name || '').toLowerCase();
        return n.indexOf('syllab') !== -1 || n.indexOf('tos') !== -1 || n.indexOf('rubric') !== -1;
    }

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

    function connectorForSub() {
        const n = selectedSubName().toLowerCase();
        if (n.indexOf('tos') !== -1 || n.indexOf('rubric') !== -1) return 'TOS/Rubrics,';
        return 'Syllabus,';
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

    function rebuildPrograms(keep) {
        const collegeId = String(collegeSel.value || '');
        const programs = collegeId ? ((catalog.programsByCollege || {})[collegeId] || []) : [];
        fillSelect(programSel, programs, 'program_id', 'program_name', 'Select program…', keep);
        setEnabled(programSel, !!collegeId, true);
    }

    function rebuildSemesters(keep) {
        const programOn = !!(programSel && programSel.value);
        fillSelect(semesterSel, catalog.semesters || [], 'semester_id', 'semester_name', 'Select semester…', keep);
        setEnabled(semesterSel, programOn, true);
    }

    function rebuildCourses(keep) {
        const programId = programSel ? String(programSel.value || '') : '';
        const semesterId = semesterSel ? String(semesterSel.value || '') : '';
        // Settings → Course Names for this program + this semester only.
        // Skip rows missing year level or course type (incomplete Settings data).
        const key = programId && semesterId ? (programId + ':' + semesterId) : '';
        const raw = key ? ((catalog.coursesByProgramSemester || {})[key] || []) : [];
        const courses = raw.filter(function (c) {
            const year = String(c.year_level || '').trim();
            const type = String(c.course_type || '').trim();
            return year !== '' && type !== '';
        });
        let placeholder = 'Select course…';
        if (!programId || !semesterId) {
            placeholder = 'Select program and semester first…';
        } else if (!courses.length) {
            placeholder = 'No complete courses in Settings for this semester…';
        }
        fillSelect(courseSel, courses, 'id', 'course_name', placeholder, keep);
        setEnabled(courseSel, !!(programId && semesterId), true);
    }

    function selectedSubTypeId() {
        if (!subSelect) return '';
        return String(subSelect.value || '').trim();
    }

    function takenSchoolYearIdsForCourse() {
        const collegeId = collegeSel ? String(collegeSel.value || '') : '';
        const programId = programSel ? String(programSel.value || '') : '';
        const semesterId = semesterSel ? String(semesterSel.value || '') : '';
        const courseId = courseSel ? String(courseSel.value || '') : '';
        const subTypeId = selectedSubTypeId();
        // Taken years are scoped by sub-type (Syllabi registration does not block TOS).
        if (!collegeId || !programId || !semesterId || !courseId || !subTypeId) return [];
        const key = collegeId + ':' + programId + ':' + semesterId + ':' + courseId + ':' + subTypeId;
        const raw = (catalog.takenSchoolYearsByCourse || {})[key] || [];
        return raw.map(function (id) { return String(id); });
    }

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

    function selectText(select) {
        if (!select || select.selectedIndex < 0) return '';
        const opt = select.options[select.selectedIndex];
        return opt && opt.value ? String(opt.textContent || '').trim() : '';
    }

    function buildTitle() {
        // Department Syllabus, Semester S/Y SchoolYear for Program in Course
        const college = selectText(collegeSel);
        const program = selectText(programSel);
        const course = selectText(courseSel);
        const semester = selectText(semesterSel);
        let schoolYear = selectText(schoolYearSel);
        if (!college || !program || !course || !semester || !schoolYear) return '';
        schoolYear = schoolYear.replace(/^\s*s\/?y\s*/i, '');
        return college + ' ' + connectorForSub() + ' ' + semester + ' S/Y ' + schoolYear
            + ' for ' + program + ' in ' + course;
    }

    function syncTitle() {
        if (!titleInput || titleManual || seeding) return;
        const next = buildTitle();
        if (next) titleInput.value = next;
    }

    function showContext(on) {
        block.hidden = !on;
        block.classList.toggle('is-ofi-hidden', !on);
        if (!on) {
            setEnabled(collegeSel, false, false);
            setEnabled(programSel, false, false);
            setEnabled(semesterSel, false, false);
            setEnabled(courseSel, false, false);
            setEnabled(schoolYearSel, false, false);
            collegeSel.value = '';
            return;
        }
        setEnabled(collegeSel, true, true);
        fillSelect(collegeSel, catalog.colleges || [], 'college_id', 'college_name', 'Select college…', true);
        rebuildPrograms(true);
        rebuildSemesters(true);
        rebuildCourses(true);
        rebuildSchoolYears(true);
    }

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

    function applySeed() {
        const seedEl = document.getElementById('ofiDrfSyllabiSeed');
        if (!seedEl) return;
        const parts = String(seedEl.value || '').split(',');
        const collegeId = parts[0] || '';
        const programId = parts[1] || '';
        const semesterId = parts[2] || '';
        const courseId = parts[3] || '';
        const schoolYearId = parts[4] || '';
        if (!collegeId) return;
        seeding = true;
        fillSelect(collegeSel, catalog.colleges || [], 'college_id', 'college_name', 'Select college…', false);
        collegeSel.value = collegeId;
        rebuildPrograms(false);
        if (programSel) programSel.value = programId;
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
