  (function() {
    'use strict';

    var modal      = document.getElementById('legalAuthModal');
    var agreeBtn   = document.getElementById('legalAgreeBtn');
    var errBox     = document.getElementById('legalAuthError');
    var blurOverlay = document.getElementById('legalBlurOverlay');

    var PORTAL_URL = window.location.origin + '/portal';

    function openLegalModal() {
      if (!modal) return;
      modal.classList.add('active');
      if (blurOverlay) blurOverlay.classList.add('active');
      document.body.classList.add('modal-open');
      setTimeout(function() { if (agreeBtn) agreeBtn.focus(); }, 150);
    }

    function closeLegalModal() {
      if (!modal) return;
      modal.classList.remove('active');
      if (blurOverlay) blurOverlay.classList.remove('active');
      document.body.classList.remove('modal-open');
    }

    function showError(msg) {
      if (!errBox) return;
      errBox.textContent = msg;
      errBox.style.display = 'block';
    }

    function clearError() {
      if (!errBox) return;
      errBox.style.display = 'none';
      errBox.textContent = '';
    }

    function onAgree() {
      clearError();
      agreeBtn.disabled = true;
      agreeBtn.textContent = 'Recording…';

      var fd = new FormData();
      fd.append('agreed', 'true');

      fetch('accept_legal.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
      })
      .then(function(res) {
        return res.json().catch(function() {
          return { error: 'Server returned an unexpected response (HTTP ' + res.status + ').' };
        });
      })
      .then(function(data) {
        if (data && data.success) {
          closeLegalModal();
        } else {
          showError(data.error || 'Failed to record agreement. Please try again.');
          agreeBtn.textContent = 'I Understand and Agree';
          agreeBtn.disabled = false;
        }
      })
      .catch(function(err) {
        showError('Network error. Please check your connection and try again.');
        agreeBtn.textContent = 'I Understand and Agree';
        agreeBtn.disabled = false;
      });
    }

    if (agreeBtn) agreeBtn.addEventListener('click', onAgree);

    // Escape key must NOT close this modal
    document.addEventListener('keydown', function(e) {
      if (modal && modal.classList.contains('active') && e.key === 'Escape') {
        e.preventDefault();
        e.stopImmediatePropagation();
      }
    }, true);

    // Backdrop click must NOT close this modal either
    if (modal) {
      modal.addEventListener('click', function(e) {
        e.stopPropagation();
      });
    }

    // Gate: show modal on DOM ready if user has not agreed yet
    function initLegalGate() {
      if (!window._chatifyHasAgreedToLegal) {
        openLegalModal();
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initLegalGate);
    } else {
      initLegalGate();
    }

  })();
