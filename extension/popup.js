/**
 * Piece Analyzer Extension Popup Controller
 */
document.addEventListener('DOMContentLoaded', async () => {
  const DEFAULT_SERVER_URL = 'https://piece-analyzer.be-we.ch';
  
  // UI Elements
  const statusAlert = document.getElementById('statusAlert');
  const noTokenAlert = document.getElementById('noTokenAlert');
  const toggleSettingsBtn = document.getElementById('toggleSettings');
  const settingsPanel = document.getElementById('settingsPanel');
  const serverUrlInput = document.getElementById('serverUrl');
  const apiTokenInput = document.getElementById('apiToken');
  const saveSettingsBtn = document.getElementById('saveSettings');
  
  const retailerBadge = document.getElementById('retailerBadge');
  const singleItemForm = document.getElementById('singleItemForm');
  const multiItemsList = document.getElementById('multiItemsList');
  const itemsListContainer = document.getElementById('itemsListContainer');
  const multiItemsTitle = document.getElementById('multiItemsTitle');
  const noDetection = document.getElementById('noDetection');
  const manualEntryBtn = document.getElementById('manualEntryBtn');
  const submitBtn = document.getElementById('submitBtn');
  
  const setNoInput = document.getElementById('setNo');
  const priceInput = document.getElementById('price');
  const currencySelect = document.getElementById('currency');

  let detectedItems = [];
  let currentTabUrl = '';

  // 1. Load Stored Settings
  const settings = await chrome.storage.sync.get(['serverUrl', 'apiToken']);
  serverUrlInput.value = settings.serverUrl || DEFAULT_SERVER_URL;
  apiTokenInput.value = settings.apiToken || '';

  if (!apiTokenInput.value.trim()) {
    noTokenAlert.style.display = 'block';
  }

  // Toggle settings
  toggleSettingsBtn.addEventListener('click', () => {
    settingsPanel.classList.toggle('active');
  });

  saveSettingsBtn.addEventListener('click', async () => {
    const serverUrl = (serverUrlInput.value || DEFAULT_SERVER_URL).replace(/\/+$/, '');
    const apiToken = apiTokenInput.value.trim();

    await chrome.storage.sync.set({ serverUrl, apiToken });
    showAlert('Settings saved successfully.', 'success');
    settingsPanel.classList.remove('active');

    if (apiToken) {
      noTokenAlert.style.display = 'none';
      if (detectedItems.length > 0) submitBtn.disabled = false;
    }
  });

  function showAlert(message, type = 'info') {
    statusAlert.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
  }

  function clearAlert() {
    statusAlert.innerHTML = '';
  }

  // 2. Query Active Tab & Run Extractor
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!tab || !tab.id || !tab.url) {
      retailerBadge.textContent = 'Unsupported tab';
      return;
    }

    currentTabUrl = tab.url;

    // Check retailer
    let retailerLabel = 'Generic Retailer';
    if (/galaxus\.|digitec\./i.test(tab.url)) retailerLabel = '🇨🇭 Galaxus / Digitec';
    else if (/brack\.ch/i.test(tab.url)) retailerLabel = '🇨🇭 Brack.ch';
    else if (/toppreise\.ch/i.test(tab.url)) retailerLabel = '🇨🇭 Toppreise.ch';
    else if (/lego\.com/i.test(tab.url)) retailerLabel = '🧱 LEGO.com';
    else if (/amazon\.de/i.test(tab.url)) retailerLabel = '🇩🇪 Amazon.de';
    else if (/amazon\.com/i.test(tab.url)) retailerLabel = '🇺🇸 Amazon.com';

    retailerBadge.textContent = retailerLabel;

    // Inject and execute extractor in active tab
    const [execResult] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      func: () => {
        if (typeof LegoPriceExtractor !== 'undefined' && LegoPriceExtractor.extractAll) {
          return LegoPriceExtractor.extractAll(document, window.location.href);
        }
        return [];
      }
    });

    detectedItems = (execResult && Array.isArray(execResult.result)) ? execResult.result : [];

    // Fallback: If extractor wasn't loaded in page, inject extractor file and execute
    if (detectedItems.length === 0) {
      await chrome.scripting.executeScript({
        target: { tabId: tab.id },
        files: ['extractor.js']
      });

      const [retryResult] = await chrome.scripting.executeScript({
        target: { tabId: tab.id },
        func: () => {
          if (typeof LegoPriceExtractor !== 'undefined' && LegoPriceExtractor.extractAll) {
            return LegoPriceExtractor.extractAll(document, window.location.href);
          }
          return [];
        }
      });
      detectedItems = (retryResult && Array.isArray(retryResult.result)) ? retryResult.result : [];
    }

    renderDetection(detectedItems);

  } catch (err) {
    retailerBadge.textContent = 'Cannot inspect page';
    showAlert('Could not run price extractor on this tab: ' + err.message, 'danger');
  }

  function renderDetection(items) {
    if (items.length === 0) {
      noDetection.style.display = 'block';
      singleItemForm.style.display = 'none';
      multiItemsList.style.display = 'none';
      submitBtn.disabled = true;
      return;
    }

    noDetection.style.display = 'none';

    if (items.length === 1) {
      const it = items[0];
      singleItemForm.style.display = 'block';
      multiItemsList.style.display = 'none';

      setNoInput.value = it.setNo || '';
      priceInput.value = it.price ? it.price.toFixed(2) : (it.amountCents / 100).toFixed(2);
      currencySelect.value = it.currency || 'CHF';

      submitBtn.textContent = 'Record Price';
      submitBtn.disabled = !apiTokenInput.value.trim();
    } else {
      singleItemForm.style.display = 'none';
      multiItemsList.style.display = 'block';

      multiItemsTitle.textContent = `Detected ${items.length} prices on this page:`;
      itemsListContainer.innerHTML = '';

      items.forEach((it) => {
        const row = document.createElement('div');
        row.className = 'item-row';
        row.innerHTML = `
          <div>
            <strong>#${it.setNo}</strong>
            <span class="small text-muted" style="margin-left: 4px;">${it.title ? it.title.substring(0, 24) + '...' : ''}</span>
          </div>
          <div>
            <strong>${(it.amountCents / 100).toFixed(2)} ${it.currency}</strong>
          </div>
        `;
        itemsListContainer.appendChild(row);
      });

      submitBtn.textContent = `Record All ${items.length} Prices`;
      submitBtn.disabled = !apiTokenInput.value.trim();
    }
  }

  manualEntryBtn.addEventListener('click', () => {
    noDetection.style.display = 'none';
    singleItemForm.style.display = 'block';
    setNoInput.value = '';
    priceInput.value = '';
    currencySelect.value = 'CHF';
    submitBtn.textContent = 'Record Price';
    submitBtn.disabled = !apiTokenInput.value.trim();
  });

  // 3. Submit Price Handler
  submitBtn.addEventListener('click', async () => {
    clearAlert();
    const serverUrl = (serverUrlInput.value || DEFAULT_SERVER_URL).replace(/\/+$/, '');
    const apiUrl = serverUrl + '/api/prices/report';
    const apiToken = apiTokenInput.value.trim();

    if (!apiToken) {
      showAlert('Please enter your Contributor API Token in Settings (⚙️).', 'danger');
      settingsPanel.classList.add('active');
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';

    let payload;

    if (singleItemForm.style.display !== 'none' || detectedItems.length <= 1) {
      const setNo = setNoInput.value.trim();
      const priceVal = parseFloat(priceInput.value);
      const currency = currencySelect.value;

      if (!setNo) {
        showAlert('Please enter a valid LEGO Set #.', 'danger');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Record Price';
        return;
      }
      if (isNaN(priceVal) || priceVal <= 0) {
        showAlert('Please enter a valid positive price.', 'danger');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Record Price';
        return;
      }

      const originalItem = detectedItems[0] || {};
      payload = {
        setNo: setNo,
        amountCents: Math.round(priceVal * 100),
        currency: currency,
        retailer: originalItem.retailer || 'other',
        url: originalItem.url || currentTabUrl,
        provider: originalItem.provider || 'extension'
      };
    } else {
      payload = {
        items: detectedItems.map((it) => ({
          setNo: it.setNo,
          amountCents: it.amountCents,
          currency: it.currency,
          retailer: it.retailer,
          url: it.url,
          provider: it.provider || 'extension'
        }))
      };
    }

    // Send to background service worker to completely bypass host page CSP
    chrome.runtime.sendMessage({
      action: 'SUBMIT_PRICE',
      apiUrl,
      token: apiToken,
      payload
    }, (response) => {
      submitBtn.disabled = false;
      submitBtn.textContent = detectedItems.length > 1 ? `Record All ${detectedItems.length} Prices` : 'Record Price';

      if (!response) {
        showAlert('No response received from extension background worker.', 'danger');
        return;
      }

      if (response.success) {
        const d = response.data || {};
        if (d.count) {
          showAlert(`✓ Successfully recorded ${d.count} prices in Piece Analyzer!`, 'success');
        } else {
          showAlert(`✓ Successfully recorded Set #${d.setNo} (${d.price}) for ${d.retailer}!`, 'success');
        }
      } else {
        showAlert('Error: ' + (response.error || 'Failed to record price.'), 'danger');
      }
    });
  });
});
