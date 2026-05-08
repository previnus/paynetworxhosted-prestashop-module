<div id="pnx-hosted-wrapper" class="pnx-hosted-wrapper">

    {* Hidden form posts the token — not card data — to the server *}
    <form action="{$validation_url|escape:'html':'UTF-8'}"
          method="post"
          id="pnx-hosted-form">
        <input type="hidden" name="paynetworx_nonce" value="{$paynetworx_nonce|escape:'html':'UTF-8'}">
        <input type="hidden" name="pnx_token_id"     id="pnx-token-id" value="">
    </form>

    {* Loading state — shown while session is being created *}
    <div id="pnx-loading" class="pnx-state">
        <span class="pnx-spinner"></span>
        <span>{l s='Initializing secure payment form…' mod='paynetworxhosted'}</span>
    </div>

    {* Error state — shown if session creation fails *}
    <div id="pnx-error" class="pnx-state" style="display:none;">
        <p class="pnx-error-msg" id="pnx-error-msg">
            {l s='Unable to load the payment form. Please refresh the page.' mod='paynetworxhosted'}
        </p>
    </div>

    {* The hosted iframe — src is set by JS after session creation *}
    <iframe id="pnx-iframe"
            class="pnx-iframe"
            style="display:none;"
            title="{l s='Secure Card Payment' mod='paynetworxhosted'}"
            frameborder="0"
            scrolling="no"
            width="100%"
            height="500">
    </iframe>

    {* Processing overlay — shown while tokenizing *}
    <div id="pnx-processing" class="pnx-state pnx-processing" style="display:none;">
        <span class="pnx-spinner"></span>
        <span>{l s='Processing payment…' mod='paynetworxhosted'}</span>
    </div>

</div>

<script>
(function () {
    'use strict';

    var SESSION_AJAX_URL = '{$session_ajax_url|escape:'javascript':'UTF-8'}';
    var VALIDATION_URL   = '{$validation_url|escape:'javascript':'UTF-8'}';
    var FORM_ORIGIN      = '{$pnx_form_origin|escape:'javascript':'UTF-8'}';

    var wrapper    = document.getElementById('pnx-hosted-wrapper');
    var form       = document.getElementById('pnx-hosted-form');
    var iframe     = document.getElementById('pnx-iframe');
    var tokenInput = document.getElementById('pnx-token-id');

    var loadingEl    = document.getElementById('pnx-loading');
    var errorEl      = document.getElementById('pnx-error');
    var errorMsgEl   = document.getElementById('pnx-error-msg');
    var processingEl = document.getElementById('pnx-processing');

    var tokenized      = false;
    var iframeReady    = false;
    var tokenizeTimer  = null;

    // ── UI helpers ──────────────────────────────────────────────────────────
    function showState(show) {
        loadingEl.style.display    = (show === 'loading')    ? '' : 'none';
        errorEl.style.display      = (show === 'error')      ? '' : 'none';
        iframe.style.display       = (show === 'iframe')     ? '' : 'none';
        processingEl.style.display = (show === 'processing') ? '' : 'none';
    }

    function showError(msg) {
        if (msg) errorMsgEl.textContent = msg;
        showState('error');
    }

    // ── Step 1: Create session via AJAX ──────────────────────────────────────
    function createSession() {
        showState('loading');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', SESSION_AJAX_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;

            var data;
            try { data = JSON.parse(xhr.responseText); } catch (e) { data = {}; }

            if (xhr.status === 200 && data.session_url) {
                loadIframe(data.session_url);
            } else {
                showError(data.error || null);
            }
        };

        xhr.onerror = function () {
            showError(null);
        };

        xhr.send('{}');
    }

    // ── Step 2: Load iframe ──────────────────────────────────────────────────
    function loadIframe(sessionUrl) {
        iframe.onload = function () {
            iframeReady = true;
            showState('iframe');
        };
        iframe.src = sessionUrl;
    }

    // ── Step 3: Intercept checkout submit → request tokenization ────────────
    form.addEventListener('submit', function (e) {
        if (tokenized) return; // token already set, proceed normally

        e.preventDefault();
        e.stopPropagation();

        if (!iframeReady) {
            showError('{l s="Payment form not ready. Please wait and try again." mod="paynetworxhosted" js=1}');
            return;
        }

        showState('processing');

        try {
            iframe.contentWindow.postMessage({ type: 'tokenize' }, FORM_ORIGIN);
        } catch (err) {
            showError('{l s="Could not contact the payment form. Please refresh." mod="paynetworxhosted" js=1}');
            return;
        }

        // Abort with an error if the iframe does not respond within 30 seconds
        tokenizeTimer = setTimeout(function () {
            tokenizeTimer = null;
            window.removeEventListener('message', onTokenMessage, false);
            showState('iframe');
            var errDiv = document.createElement('p');
            errDiv.className = 'pnx-error-msg';
            errDiv.textContent = '{l s="Payment timed out. Please try again." mod="paynetworxhosted" js=1}';
            wrapper.insertBefore(errDiv, iframe);
            setTimeout(function () {
                if (errDiv.parentNode) errDiv.parentNode.removeChild(errDiv);
            }, 5000);
        }, 30000);
    });

    // ── Step 4: Listen for tokenization result ───────────────────────────────
    function onTokenMessage(event) {
        // Strict origin check — reject anything not from Paynetworx
        if (event.origin !== FORM_ORIGIN) return;
        if (!event.data || event.data.type !== 'pnx-tokenized-payment-info') return;

        // Cancel the timeout and deregister the listener — one-shot only
        if (tokenizeTimer) {
            clearTimeout(tokenizeTimer);
            tokenizeTimer = null;
        }
        window.removeEventListener('message', onTokenMessage, false);

        var payload = event.data.payload;

        if (!payload
            || !payload.tokenized_card
            || !payload.tokenized_card.approved
            || !payload.tokenized_card.token
            || !payload.tokenized_card.token.token_id
        ) {
            showState('iframe');
            // Surface a brief message and let the user retry
            var errDiv = document.createElement('p');
            errDiv.className = 'pnx-error-msg';
            errDiv.textContent = '{l s="Card validation failed. Please check your details." mod="paynetworxhosted" js=1}';
            wrapper.insertBefore(errDiv, iframe);
            setTimeout(function () {
                if (errDiv.parentNode) errDiv.parentNode.removeChild(errDiv);
            }, 5000);
            return;
        }

        // ── Step 5: Submit the token to our server ──────────────────────────
        tokenInput.value = payload.tokenized_card.token.token_id;
        tokenized = true;
        form.submit(); // direct submit — does not fire event listeners
    }

    window.addEventListener('message', onTokenMessage, false);

    // ── Boot ─────────────────────────────────────────────────────────────────
    createSession();

}());
</script>
