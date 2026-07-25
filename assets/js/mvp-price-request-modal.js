    function mvpOpenPriceModal(btn) {
        var modal = document.getElementById('mvpPriceModal');
        var nameEl = document.querySelector('.product_title, h1.entry-title, .entry-title');
        var skuEl = document.querySelector('.sku, .meta-value');
        var productName = nameEl ? nameEl.textContent.trim() : '';
        var sku = skuEl ? skuEl.textContent.trim() : '';
        var lr = btn ? (btn.getAttribute('data-lr') || '') : '';
        var remark = btn ? (btn.getAttribute('data-remark') || '') : '';
        var metaParts = [];
        if (lr) metaParts.push('Orientation: ' + lr);
        if (remark) metaParts.push(remark);
        var metaText = metaParts.join(' | ');
        document.getElementById('mvpPriceProductName').textContent = productName;
        document.getElementById('mvpPriceProductSku').textContent = sku;
var metaWrap = document.getElementById('mvpPriceMetaWrap');        var metaSpan = document.getElementById('mvpPriceMeta');        if (metaWrap) { if (metaText) { metaSpan.textContent = metaText; metaWrap.style.display = 'inline'; } else { metaWrap.style.display = 'none'; } }
        document.getElementById('mvpPriceSku').value = sku;
        document.getElementById('mvpPriceProductNameHidden').value = productName;
        document.getElementById('mvpPriceProductUrl').value = window.location.href;
        document.getElementById('mvpPriceForm').reset();
        document.getElementById('mvpPriceSku').value = sku;
        document.getElementById('mvpPriceProductNameHidden').value = productName;
        document.getElementById('mvpPriceProductUrl').value = window.location.href;
        if (document.getElementById('mvpPriceProductMeta')) document.getElementById('mvpPriceProductMeta').value = metaText || '';
        document.getElementById('mvpPriceMsg').className = 'mvp-price-modal-msg';
        document.getElementById('mvpPriceSubmitBtn').disabled = false;
        document.getElementById('mvpPriceSubmitBtn').textContent = 'Submit Enquiry';
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function mvpOpenPriceModalFromTable(btn) {
        var modal = document.getElementById('mvpPriceModal');
        var sku = btn.getAttribute('data-sku') || '';
        var productName = btn.getAttribute('data-name') || '';
        var productUrl = btn.getAttribute('data-url') || '';
        var lr = btn.getAttribute('data-lr') || '';
        var remark = btn.getAttribute('data-remark') || '';
        var metaParts = [];
        if (lr) metaParts.push('Orientation: ' + lr);
        if (remark) metaParts.push(remark);
        var metaText = metaParts.join(' | ');
        document.getElementById('mvpPriceProductName').textContent = productName;
        document.getElementById('mvpPriceProductSku').textContent = sku;
        var metaWrap = document.getElementById('mvpPriceMetaWrap');
        var metaSpan = document.getElementById('mvpPriceMeta');
        if (metaWrap) {
            if (metaText) { metaSpan.textContent = metaText; metaWrap.style.display = 'inline'; }
            else { metaWrap.style.display = 'none'; }
        }
        document.getElementById('mvpPriceForm').reset();
        document.getElementById('mvpPriceSku').value = sku;
        document.getElementById('mvpPriceProductNameHidden').value = productName;
        document.getElementById('mvpPriceProductUrl').value = productUrl;
        if (document.getElementById('mvpPriceProductMeta')) document.getElementById('mvpPriceProductMeta').value = metaText || '';
        document.getElementById('mvpPriceMsg').className = 'mvp-price-modal-msg';
        document.getElementById('mvpPriceSubmitBtn').disabled = false;
        document.getElementById('mvpPriceSubmitBtn').textContent = 'Submit Enquiry';
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function mvpClosePriceModal() {
        document.getElementById('mvpPriceModal').classList.remove('active');
        document.body.style.overflow = '';
    }
    document.getElementById('mvpPriceModal').addEventListener('click', function(e) {
        if (e.target === this) mvpClosePriceModal();
    });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') mvpClosePriceModal(); });
    function mvpSubmitPriceForm(e) {
        e.preventDefault();
        var btn = document.getElementById('mvpPriceSubmitBtn');
        var msg = document.getElementById('mvpPriceMsg');
        btn.disabled = true;
        btn.textContent = 'Sending...';
        msg.className = 'mvp-price-modal-msg';
        var fd = new FormData();
        fd.append('action', 'mvp_price_request');
        fd.append('nonce', window.mvpData["mvp-price-request-modal"][0]);
        fd.append('name', document.getElementById('mvpPriceName').value);
        fd.append('email', document.getElementById('mvpPriceEmail').value);
        fd.append('phone', document.getElementById('mvpPricePhone').value);
        fd.append('sku', document.getElementById('mvpPriceSku').value);
        fd.append('product_name', document.getElementById('mvpPriceProductNameHidden').value);
        fd.append('product_url', document.getElementById('mvpPriceProductUrl').value);
        fd.append('product_meta', document.getElementById('mvpPriceProductMeta') ? document.getElementById('mvpPriceProductMeta').value : '');
        fetch(window.mvpData["mvp-price-request-modal"][1], { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                msg.className = 'mvp-price-modal-msg success';
                msg.textContent = data.data;
                btn.textContent = 'Sent!';
                setTimeout(mvpClosePriceModal, 3000);
            } else {
                msg.className = 'mvp-price-modal-msg error';
                msg.textContent = data.data || 'Something went wrong.';
                btn.disabled = false;
                btn.textContent = 'Submit Enquiry';
            }
        })
        .catch(function() {
            msg.className = 'mvp-price-modal-msg error';
            msg.textContent = 'Network error. Please try again.';
            btn.disabled = false;
            btn.textContent = 'Submit Enquiry';
        });
        return false;
    }
