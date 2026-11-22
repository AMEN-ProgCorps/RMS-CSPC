# System Release Notes & Patch Updates

All notable updates, fixes, improvements, and changes across the Records Management System (RMS) are documented here.

## [DTS v2.4.2] - 2026-11-21
### Added
- **Interactive Patch Notes Console**: Integrated floating release notes widget accessible across the university portal and subsystem sidebars.
- **Urgent Notification Animations**: Smooth spring pop-in entrance animation and real-time alert banners for high-priority incoming transactions.

### Fixed
- **Scanner Terminal Camera Abort**: Suppressed unhandled video surface abort error when switching camera modes or closing the scanner on Windows.
- **Livewire $cleanup Reference Error**: Resolved client exception in scanner modal by replacing legacy cleanup hooks with native Alpine `destroy()` lifecycle handlers.
- **Header Icon Alignment**: Standardized guide, bell, and action dropdown vertical alignment and dynamic gap spacing.

### Changed
- **Multi-Office Route Timeline**: Streamlined transaction timeline layout for long multi-step office flows.

---

## [Admin v1.5.0] - 2026-11-20
### Added
- **Dedicated Subsystem Patch Notes Manager**: Admin Console module under Subsystems for authoring, publishing, and previewing release notes.
- **Real-Time Delivery Auditing**: Instant timeline status updates upon physical receipt and forwarding of documents.
- **Prewarming Enhancements**: Background link prefetching for high-frequency admin and DTS navigation targets.

### Fixed
- **Transaction Details Popout Gap**: Corrected flexbox overflow and vertical spacing in transaction history views.
- **Database Sequence Sync**: Ensured automated foreign key validation on custom flow steps.

---

## [RDP v1.1.0] - 2026-11-15
### Added
- **Interactive Page Manual System**: Context-aware floating guide manuals mapped per route and modal across DTS, RDP, and Admin.
- **Subsystem Version Logs**: Audit logging for module activation, deactivation, and semantic version increments.
- **Smart Search Architecture**: Multi-field tokenize relevance scoring across transaction catalogs and user registers.

### Fixed
- **Session Guard Refresh**: Fixed stale CSRF token polling during prolonged inactivity periods.
