{{-- Shared styles for scan filename convention preview (also in register.css / edit.css). --}}
<style>
.reg-scan-name-preview {
    margin: 8px 0 0;
    padding: 8px 10px;
    border: 1px dashed var(--reg-border, #cbd5e1);
    border-radius: var(--reg-radius-sm, 6px);
    background: var(--reg-accent-subtle, #f8fafc);
    font-size: 0.78rem;
    color: var(--reg-text-muted, #64748b);
    line-height: 1.35;
    word-break: break-all;
}
.reg-scan-name-preview strong {
    display: block;
    margin-bottom: 2px;
    font-weight: 600;
    color: var(--reg-text, #0f172a);
}
.reg-scan-name-preview code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 0.8rem;
    color: var(--reg-accent, #0f766e);
    font-weight: 600;
}
</style>
<script src="{{ asset('js/dcs/register-scan-name-preview.js') }}"></script>
