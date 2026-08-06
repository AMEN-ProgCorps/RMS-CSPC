// ── Admin: eye-button toggle for "all users chatting" view ──────────────
    const adminEyeToggleBtn = document.getElementById('adminEyeToggleBtn');
    const adminKeyToggleBtn = document.getElementById('adminKeyToggleBtn');
    const ownSidebarSearch = document.getElementById('ownSidebarSearch');
    let isAdminAllChatsView = (localStorage.getItem('__adminAllChatsView__') === '1');
    let preSpyDM = localStorage.getItem('__preSpyDM__') || null;        // activeDM to restore when leaving the all-conversations spy view
    let preSpyIsGlobal = (localStorage.getItem('__preSpyIsGlobal__') === '1'); // isGlobalChat to restore when leaving the all-conversations spy view

    function applyAdminAllChatsView() {
      if (adminEyeToggleBtn) {
        adminEyeToggleBtn.classList.toggle('active', isAdminAllChatsView);
      }
      // Hide the admin's own conversation list/search while browsing all users' chats
      const globalChatItemEl = document.getElementById('globalChatItem');
      if (ownSidebarSearch) ownSidebarSearch.style.display = isAdminAllChatsView ? 'none' : '';
      if (globalChatItemEl) globalChatItemEl.style.display = isAdminAllChatsView ? 'none' : '';
      if (sidebarUsers) sidebarUsers.style.display = isAdminAllChatsView ? 'none' : '';

      const inputSection = document.querySelector('.input-section');
      const chatForm = document.getElementById('chatForm');
      const spyNotice = document.getElementById('spyModeNotice');
      if (inputSection) inputSection.style.display = isAdminAllChatsView ? 'none' : '';
      // chatForm (the "Type a message..." box) is now hidden in spy mode too,
      // so only the Super Admin Spy Mode notice shows on this screen.
      // "/backup" only works when the message box is visible/typeable, so
      // it's no longer usable from this screen — that's intentional now.
      if (chatForm) chatForm.style.display = isAdminAllChatsView ? 'none' : '';
      if (spyNotice) spyNotice.style.display = isAdminAllChatsView ? 'flex' : 'none';

      if (isAdminAllChatsView) {
        fetchAdminConvs(adminSearchInput ? adminSearchInput.value.trim() : '', 0, false);
      } else {
        renderAdminConvs();
      }
    }

    if (adminEyeToggleBtn) {
      if (isAdmin) adminEyeToggleBtn.style.display = 'inline-flex';
      adminEyeToggleBtn.addEventListener('click', () => {
        const turningOn = !isAdminAllChatsView;

        if (turningOn) {
          // Entering spy view — remember activeDM and isGlobalChat to restore upon exit
          preSpyDM = activeDM;
          preSpyIsGlobal = isGlobalChat;
          localStorage.setItem('__preSpyDM__', activeDM || '');
          localStorage.setItem('__preSpyIsGlobal__', isGlobalChat ? '1' : '0');

          // Close/clear previously opened conversation
          activeDM = null;
          activeDMAccountId = null;
          isGlobalChat = false;
          activeAdminConv = null;
          updateClearChatButtonVisibility();
          localStorage.removeItem('activeSpyConv');
          
          chatHeaderTitle.textContent = '';
          removePaginationBtn();
          chatBox.innerHTML = '<div class="empty-chat"><p>Camarines Sur Polytechnic Colleges</p></div>';

          isAdminAllChatsView = true;
          localStorage.setItem('__adminAllChatsView__', '1');
          applyAdminAllChatsView();
        } else {
          // Leaving spy view — restore the admin's conversation

          // Abort any in-flight spy-conv XHR so its response cannot
          // reach the DOM after we switch back to normal mode.
          if (adminConvXhr) { try { adminConvXhr.abort(); } catch(e){} adminConvXhr = null; }
          isLoadingAdminConv = false;

          isAdminAllChatsView = false;
          localStorage.setItem('__adminAllChatsView__', '0');
          activeAdminConv = null;
          updateClearChatButtonVisibility();
          localStorage.removeItem('activeSpyConv');

          // Reset all admin spy mode state & search inputs fully
          adminSpyType = 'none';
          adminSpyTargetUser = null;
          adminSpyUsers = [];
          adminSpyConvs = [];
          adminSpyHasMore = false;
          adminSpyOffset = 0;
          adminSpyIsLoading = false;
          if (adminSearchInput) adminSearchInput.value = '';
          if (adminSearchTimeout) { clearTimeout(adminSearchTimeout); adminSearchTimeout = null; }

          // Wipe chatBox innerHTML so reconcilePoll doesn't retain old spy-mode DOM nodes
          chatBox.innerHTML = '';
          isFirstLoad = true;

          applyAdminAllChatsView();

          // Determine which conversation to restore.
          // Priority: in-memory preSpyDM → localStorage activeDM → nothing.
          // The old "currentSpyConv" fallback that tried to guess from the
          // spied conv's participant IDs was the root cause of the bug: it
          // was opening the SPIED user's conversation instead of the admin's.
          let savedAdminDM = preSpyDM || null;
          let savedIsGlobal = preSpyIsGlobal || false;

          if (!savedAdminDM && !savedIsGlobal) {
            const locDM = localStorage.getItem('activeDM');
            if (locDM === '__global__') savedIsGlobal = true;
            else if (locDM && !locDM.startsWith('__admin__')) savedAdminDM = locDM;
          }

          if (savedIsGlobal) {
            selectGlobalChat();
          } else if (savedAdminDM && savedAdminDM !== '' && !savedAdminDM.startsWith('__admin__')) {
            const matchedUser = (allUsersData || []).find(u => u.username === savedAdminDM);
            if (matchedUser) {
              selectDM(matchedUser);
            } else {
              // allUsersData is empty — defer until fetchUsers() repopulates it
              pendingRestoreDM = savedAdminDM;
              chatBox.innerHTML = '<div class="empty-chat"><p>Loading...</p></div>';
              fetchUsers();
            }
          } else {
            activeDM = null; activeDMAccountId = null;
            localStorage.removeItem('activeDM');
            chatHeaderTitle.textContent = '';
            removePaginationBtn();
            chatBox.innerHTML = '<div class="empty-chat"><p>Camarines Sur Polytechnic Colleges</p></div>';
          }

          // Clean up pre-spy state
          preSpyDM = null;
          preSpyIsGlobal = false;
          localStorage.removeItem('__preSpyDM__');
          localStorage.removeItem('__preSpyIsGlobal__');
        }
      });
    }
    if (!isAdmin) isAdminAllChatsView = false;
    applyAdminAllChatsView();
    updateClearChatButtonVisibility(); // activeAdminConv is always null at this point (never persisted across refresh), so this hides the button by default

    // ── Admin: Secret Key Change Modal & Handler ──────────────────────────────
    const adminKeyModal = document.getElementById('adminKeyModal');
    const adminKeyCancelBtn = document.getElementById('adminKeyCancelBtn');

    if (adminKeyToggleBtn) {
      if (isAdmin) adminKeyToggleBtn.style.display = 'inline-flex';
      adminKeyToggleBtn.addEventListener('click', () => {
        openAdminKeyModal();
      });
    }

    function openAdminKeyModal() {
      if (!adminKeyModal) return;
      document.getElementById('currentSecretInput').value = '';
      document.getElementById('newSecretInput').value = '';
      document.getElementById('confirmNewSecretInput').value = '';
      const errDiv = document.getElementById('adminKeyError');
      const succDiv = document.getElementById('adminKeySuccess');
      if (errDiv) errDiv.style.display = 'none';
      if (succDiv) succDiv.style.display = 'none';
      adminKeyModal.classList.add('active');
      adminKeyModal.setAttribute('aria-hidden', 'false');
    }

    function closeAdminKeyModal() {
      if (adminKeyModal) {
        adminKeyModal.classList.remove('active');
        adminKeyModal.setAttribute('aria-hidden', 'true');
      }
    }

    if (adminKeyCancelBtn) adminKeyCancelBtn.addEventListener('click', closeAdminKeyModal);

    function submitSecretKeyForm() {
      const form = document.getElementById('adminKeyForm');
      if (form) {
        const event = new Event('submit', { cancelable: true });
        form.dispatchEvent(event);
      }
    }

    function handleSecretKeyUpdate(event) {
      event.preventDefault();
      const currentKey = document.getElementById('currentSecretInput').value.trim();
      const newKey = document.getElementById('newSecretInput').value.trim();
      const confirmKey = document.getElementById('confirmNewSecretInput').value.trim();
      const errDiv = document.getElementById('adminKeyError');
      const succDiv = document.getElementById('adminKeySuccess');
      const submitBtn = document.getElementById('adminKeySubmitBtn');

      if (errDiv) errDiv.style.display = 'none';
      if (succDiv) succDiv.style.display = 'none';

      if (!currentKey || !newKey || !confirmKey) {
        if (errDiv) { errDiv.textContent = 'All fields are required.'; errDiv.style.display = 'block'; }
        return;
      }

      if (newKey !== confirmKey) {
        if (errDiv) { errDiv.textContent = 'New secret key and confirmation do not match.'; errDiv.style.display = 'block'; }
        return;
      }

      if (newKey.length < 3) {
        if (errDiv) { errDiv.textContent = 'New secret key must be at least 3 characters long.'; errDiv.style.display = 'block'; }
        return;
      }

      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Updating...'; }

      const formData = new FormData();
      formData.append('current_secret', currentKey);
      formData.append('new_secret', newKey);

      fetch('update_secret.php', {
        method: 'POST',
        body: formData
      })
      .then(r => r.json())
      .then(data => {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Update Key'; }

        if (data.success) {
          if (succDiv) { succDiv.textContent = data.message || 'Secret key updated successfully!'; succDiv.style.display = 'block'; }
          setTimeout(() => {
            closeAdminKeyModal();
          }, 1500);
        } else {
          if (errDiv) { errDiv.textContent = data.message || 'Failed to update secret key.'; errDiv.style.display = 'block'; }
        }
      })
      .catch(err => {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Update Key'; }
        if (errDiv) { errDiv.textContent = 'Network or server error while updating secret key.'; errDiv.style.display = 'block'; }
      });
    }