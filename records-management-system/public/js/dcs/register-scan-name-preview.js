/**
 * Live preview of DCS scan file naming convention.
 * Mirrors RegisterPersistHelper::buildScanBasename:
 *   {Y-m-d}_{FORM}_{DOCTYPE}_{Title}_Rev{N}.pdf
 *
 * FORM tokens: DRF | DOC | D&R | DCN | DRR
 */
(function (window) {
    'use strict';

    const PREVIEW_TARGETS = [
        {
            key: 'dcn',
            formToken: 'DCN',
            dateIds: ['noticeDate'],
            fileIds: ['dcnFile'],
        },
        {
            key: 'drf',
            formToken: 'DRF',
            dateIds: ['drfDate'],
            fileIds: ['drfFile'],
        },
        {
            key: 'masterlist',
            formToken: 'DOC',
            dateIds: ['masterlistEffectivityDate'],
            fileIds: ['uploadScannedCopy'],
        },
        {
            key: 'retrieval',
            formToken: 'DRR',
            dateIds: ['retrievalDate', 'retrievalFormDate'],
            fileIds: ['scannedRet'],
        },
        {
            key: 'distribution',
            formToken: 'D&R',
            dateIds: ['drfDate'],
            fileIds: ['scanneddist'],
        },
    ];

    const FIELD_WATCH_IDS = [
        'docType',
        'drfDate',
        'drfTitle',
        'noticeDate',
        'masterlistDocTitle',
        'masterlistEffectivityDate',
        'masterlistRevisionNo',
        'syllabiDocTitle',
        'retrievalDate',
        'retrievalFormDate',
    ];

    function val(id) {
        const el = document.getElementById(id);
        if (!el) return '';
        return String(el.value || '').trim();
    }

    function formatScanDatePart(raw) {
        const date = String(raw || '').trim();
        // Preview placeholder until the user picks a date (do not use today).
        if (!date) return 'YYYY-MM-DD';
        // Prefer the value from <input type="date"> as-is (already YYYY-MM-DD).
        if (/^\d{4}-\d{2}-\d{2}$/.test(date)) return date;
        // Parse other formats without timezone shift (local Y-M-D parts only).
        const parsed = new Date(date);
        if (Number.isNaN(parsed.getTime())) return 'YYYY-MM-DD';
        const y = parsed.getFullYear();
        const m = String(parsed.getMonth() + 1).padStart(2, '0');
        const day = String(parsed.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    function titleToScanSegment(title) {
        let out = String(title || '').trim().replace(/\s+/gu, '_');
        out = out.replace(/[^\p{L}\p{N}_&\-]+/gu, '');
        out = out.replace(/^_+|_+$/g, '');
        return out !== '' ? out : 'Untitled';
    }

    function sanitizeDcsScanBasename(basename) {
        let out = String(basename || '').replace(/[\/\\]/g, '').replace(/\u0000/g, '');
        out = out.trim().replace(/\s+/gu, '_');
        out = out.replace(/[^\p{L}\p{N}_.&-]+/gu, '');
        out = out.replace(/^[._]+|[._]+$/g, '');
        return out !== '' ? out : 'scan';
    }

    function parentDocTypeCode() {
        const types = Array.isArray(window.allDocTypes) ? window.allDocTypes : [];
        const docTypeId = val('docType');
        if (!docTypeId) return 'INT';

        let type = types.find((d) => String(d.doc_type_id) === String(docTypeId));
        if (!type) return 'INT';

        if (type.parent_id) {
            type = types.find((d) => String(d.doc_type_id) === String(type.parent_id)) || type;
        }

        const name = String(type.doc_type_name || '').trim().toLowerCase();
        if (name.includes('internal form')) return 'IF';
        if (name === 'forms' || name.startsWith('form')) return 'F';
        if (name.includes('logbook')) return 'LB';
        if (name.includes('external')) return 'EXT';
        if (name === 'internal' || name.startsWith('internal')) return 'INT';
        return 'INT';
    }

    function syncedDocTitle() {
        return val('masterlistDocTitle')
            || val('syllabiDocTitle')
            || val('drfTitle')
            || 'Untitled';
    }

    function resolveReviseNo() {
        const raw = val('masterlistRevisionNo');
        if (raw === '') {
            const hidden = document.querySelector('input[name="masterlistRevisionNo"]');
            const hiddenVal = hidden ? String(hidden.value || '').trim() : '';
            if (hiddenVal === '') return 0;
            return Math.max(0, parseInt(hiddenVal, 10) || 0);
        }
        return Math.max(0, parseInt(raw, 10) || 0);
    }

    function buildScanBasename(formToken, dateRaw) {
        const datePart = formatScanDatePart(dateRaw);
        const typeCode = parentDocTypeCode();
        const titlePart = titleToScanSegment(syncedDocTitle());
        const rev = resolveReviseNo();
        return `${datePart}_${formToken}_${typeCode}_${titlePart}_Rev${rev}`;
    }

    function resolveDate(dateIds) {
        for (const id of dateIds) {
            const v = val(id);
            if (v) return v;
        }
        return '';
    }

    function resolveExtension(fileIds) {
        for (const id of fileIds) {
            const input = document.getElementById(id);
            const file = input?.files?.[0];
            if (file?.name) {
                const parts = file.name.split('.');
                if (parts.length > 1) {
                    const ext = parts.pop().toLowerCase().replace(/[^a-z0-9]/g, '');
                    if (ext) return ext;
                }
            }
        }
        return 'pdf';
    }

    function buildPreviewName(target) {
        const base = buildScanBasename(target.formToken, resolveDate(target.dateIds));
        const safe = sanitizeDcsScanBasename(base);
        const ext = resolveExtension(target.fileIds);
        return `${safe}.${ext}`;
    }

    function updateScanNamePreviews() {
        PREVIEW_TARGETS.forEach((target) => {
            const el = document.querySelector(`[data-scan-preview="${target.key}"]`);
            if (!el) return;
            const nameEl = el.querySelector('[data-scan-preview-name]');
            if (!nameEl) return;
            nameEl.textContent = buildPreviewName(target);
        });
    }

    function initScanNamePreviews() {
        FIELD_WATCH_IDS.forEach((id) => {
            const el = document.getElementById(id);
            if (!el || el.dataset.scanPreviewBound) return;
            el.dataset.scanPreviewBound = '1';
            el.addEventListener('input', updateScanNamePreviews);
            el.addEventListener('change', updateScanNamePreviews);
        });

        // Hidden masterlistRevisionNo (edit page) may change programmatically.
        document.querySelectorAll('input[name="masterlistRevisionNo"]').forEach((el) => {
            if (el.dataset.scanPreviewBound) return;
            el.dataset.scanPreviewBound = '1';
            el.addEventListener('input', updateScanNamePreviews);
            el.addEventListener('change', updateScanNamePreviews);
        });

        PREVIEW_TARGETS.forEach((target) => {
            target.fileIds.forEach((id) => {
                const input = document.getElementById(id);
                if (!input || input.dataset.scanPreviewFileBound) return;
                input.dataset.scanPreviewFileBound = '1';
                input.addEventListener('change', updateScanNamePreviews);
            });
        });

        updateScanNamePreviews();
    }

    window.DCSScanNamePreview = {
        buildScanBasename,
        buildPreviewName,
        update: updateScanNamePreviews,
        init: initScanNamePreviews,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initScanNamePreviews);
    } else {
        initScanNamePreviews();
    }
})(window);
