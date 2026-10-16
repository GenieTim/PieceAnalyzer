/**
 * Piece Analyzer Extension Service Worker
 * Handles network requests with extension privileges (completely bypasses host website CSP)
 */
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === 'SUBMIT_PRICE') {
    const { apiUrl, token, payload } = request;

    fetch(apiUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + token
      },
      body: JSON.stringify({
        ...payload,
        token: token
      })
    })
    .then(async (res) => {
      const data = await res.json().catch(() => ({}));
      if (res.ok && data.success) {
        sendResponse({ success: true, data });
      } else {
        sendResponse({ success: false, error: data.error || ('HTTP ' + res.status + ': Failed to submit price') });
      }
    })
    .catch((err) => {
      sendResponse({ success: false, error: err.message || 'Network request failed' });
    });

    return true; // Keep message channel open for async sendResponse
  }
});
