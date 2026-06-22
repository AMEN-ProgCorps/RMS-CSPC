---
name: Bug report
about: Create a report to help us improve the Records Management System (RMS)
title: '[BUG] User remains "Online" after logging out'
labels: bug, authentication
assignees: ''

---

**Describe the bug**
When a user logs out of the Records Management System, their session is successfully terminated and they are redirected to the Portal Access page, but their status is not immediately updated to "Offline". As a result, the Admin Console and Dashboard continue to display their status as **Online** (green indicator).

**To Reproduce**
Steps to reproduce the behavior:
1. Log in to any user or administrator account.
2. Observe that the user's status is correctly marked as **Online** on the Admin Console.
3. Click the **Logout** button.
4. From another administrator's active session, check the **Management Users** list.
5. Observe that the logged-out user's indicator is still green (Online).

**Expected behavior**
Upon logging out, the application should execute a direct database update to set `online_status = 0` on the user's `account_details` record, ensuring they immediately appear as Offline.

**Screenshots**
*(If applicable, attach screenshots of the Admin Console or dashboard showing the user still active after logging out)*

**Desktop (please complete the following information):**
 - OS: Windows 11
 - Browser: Chrome / Firefox / Edge
 - Version: Latest

**Additional context**
- The issue occurs because the logout route redirect closures do not force a direct DB table update on the user's session states.
- Consider also implementing a global middleware to sweep inactive sessions (e.g., >5 minutes) to handle cases where users close their tabs instead of clicking logout.
