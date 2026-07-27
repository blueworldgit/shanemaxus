    document.addEventListener("DOMContentLoaded", function(){
        var mvpModels = window.mvpData["mvp-vehicle-search-bar"][0];
        var mvpHomeUrl = window.mvpData["mvp-vehicle-search-bar"][1];
        var mvpAjaxUrl = window.mvpData["mvp-vehicle-search-bar"][2];
        var loaderHtml = '<span class="mvp-sb-loader"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2.5" opacity="0.25"/><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2.5" stroke-dasharray="32" stroke-dashoffset="16" stroke-linecap="round"/><circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="1.5"/><line x1="12" y1="2" x2="12" y2="5" stroke="currentColor" stroke-width="1.5"/><line x1="12" y1="19" x2="12" y2="22" stroke="currentColor" stroke-width="1.5"/><line x1="2" y1="12" x2="5" y2="12" stroke="currentColor" stroke-width="1.5"/><line x1="19" y1="12" x2="22" y2="12" stroke="currentColor" stroke-width="1.5"/></svg> Looking up&hellip;</span>';

        var modelOpts = '<option value="">Model</option>';
        var yearMap = {};
        for (var m in mvpModels) {
            modelOpts += '<option value="' + m + '">' + m + '</option>';
            if (mvpModels[m].year) yearMap[m] = mvpModels[m].year;
        }

        var barHtml = '<div class="mvp-search-bar-wrap">' +
            '<div class="mvp-sb-mobile-toggle">Vehicle Filter</div>' +
            '<div class="mvp-search-bar">' +
                '<div class="mvp-sb-atts">' +
                '<select class="mvp-sb-select mvp-sb-model">' + modelOpts + '</select>' +
                '<select class="mvp-sb-select mvp-sb-year" disabled><option value="">Year</option></select>' +
                '</div>' +
                '<div class="mvp-sb-last">' +
                '<span class="mvp-sb-or">OR</span>' +
                '<input type="text" class="mvp-sb-input mvp-sb-vin" placeholder="Search by VIN" maxlength="17" autocomplete="off">' +
                '<span class="mvp-sb-or">OR</span>' +
                '<input type="text" class="mvp-sb-input mvp-sb-reg" placeholder="Search by Registration" maxlength="10" autocomplete="off">' +
                '<button type="button" class="mvp-sb-submit">Search</button>' +
                '<span class="mvp-sb-reset">Reset</span>' +
                '</div>' +
                '<div class="mvp-sb-result"></div>' +
            '</div></div>';

        // Inject into the filter container (631db85) or fallback after carousel
        var target = document.querySelector('.elementor-element-631db85');
        if (target) {
            target.insertAdjacentHTML('afterbegin', barHtml);
        } else {
            var hero = document.querySelector('.mvp-vehicles');
            if (hero) hero.insertAdjacentHTML('afterend', barHtml);
        }

        var wrap = document.querySelector('.mvp-search-bar-wrap');
        if (!wrap) return;
        var bar = wrap.querySelector('.mvp-search-bar');
        var modelSel = wrap.querySelector('.mvp-sb-model');
        var yearSel = wrap.querySelector('.mvp-sb-year');
        var vinInput = wrap.querySelector('.mvp-sb-vin');
        var regInput = wrap.querySelector('.mvp-sb-reg');
        var submitBtn = wrap.querySelector('.mvp-sb-submit');
        var resetBtn = wrap.querySelector('.mvp-sb-reset');
        var resultEl = wrap.querySelector('.mvp-sb-result');
        var mobileToggle = wrap.querySelector('.mvp-sb-mobile-toggle');

        mobileToggle.addEventListener('click', function() {
            this.classList.toggle('mvp-sb-open');
            bar.classList.toggle('mvp-sb-open');
        });

        // Model change → populate year dropdown
        modelSel.addEventListener('change', function() {
            var model = this.value;
            yearSel.innerHTML = '<option value="">Year</option>';
            vinInput.value = ''; regInput.value = ''; hideResult();
            if (model && yearMap[model]) {
                var parts = yearMap[model].split('-');
                if (parts.length === 2) {
                    for (var y = parseInt(parts[1]); y >= parseInt(parts[0]); y--)
                        yearSel.innerHTML += '<option value="' + y + '">' + y + '</option>';
                } else {
                    yearSel.innerHTML += '<option value="' + yearMap[model] + '">' + yearMap[model] + '</option>';
                }
                yearSel.disabled = false;
            } else { yearSel.disabled = true; }
        });

        // Clear dropdowns when typing VIN or Reg
        vinInput.addEventListener('input', function() {
            if (this.value.trim()) { modelSel.value = ''; yearSel.innerHTML = '<option value="">Year</option>'; yearSel.disabled = true; regInput.value = ''; }
            hideResult();
        });
        regInput.addEventListener('input', function() {
            if (this.value.trim()) { modelSel.value = ''; yearSel.innerHTML = '<option value="">Year</option>'; yearSel.disabled = true; vinInput.value = ''; }
            hideResult();
        });

        resetBtn.addEventListener('click', function() {
            modelSel.value = ''; yearSel.innerHTML = '<option value="">Year</option>'; yearSel.disabled = true;
            vinInput.value = ''; regInput.value = ''; hideResult();
        });

        submitBtn.addEventListener('click', doSearch);
        vinInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });
        regInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });

        function hideResult() { resultEl.className = 'mvp-sb-result'; resultEl.innerHTML = ''; }

        function doSearch() {
            var reg = regInput.value.trim();
            var vin = vinInput.value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
            var model = modelSel.value;
            if (reg.length >= 2) { doRegSearch(reg); }
            else if (vin.length > 0) { doVinSearch(vin); }
            else if (model && mvpModels[model]) { window.location.href = mvpHomeUrl + 'vehicle/' + mvpModels[model].slug + '/'; }
            else { resultEl.className = 'mvp-sb-result show error'; resultEl.textContent = 'Please select a model, enter a VIN, or enter a registration'; }
        }

        function doVinSearch(vin) {
            if (vin.length !== 17) { resultEl.className = 'mvp-sb-result show error'; resultEl.textContent = 'VIN must be 17 characters (' + vin.length + ' entered)'; return; }
            resultEl.className = 'mvp-sb-result show'; resultEl.innerHTML = loaderHtml;
            var fd = new FormData(); fd.append('action', 'maxus_vin_lookup'); fd.append('vin', vin);
            fetch(mvpAjaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.data) {
                        if (data.data.variants && data.data.variants.length > 1) {
                            resultEl.className = 'mvp-sb-result show success';
                            resultEl.innerHTML = '<strong>Multiple variants found</strong> &mdash; Select yours...';
                            window.location.href = mvpHomeUrl + 'vin-lookup/?vin=' + encodeURIComponent(vin);
                        } else if (data.data.shop_url) {
                            resultEl.className = 'mvp-sb-result show success';
                            resultEl.innerHTML = '<strong>' + (data.data.vehicle_name || 'Vehicle found') + '</strong> &mdash; Redirecting...';
                            window.location.href = data.data.shop_url;
                        } else { resultEl.className = 'mvp-sb-result show error'; resultEl.textContent = 'No match found'; }
                    } else { resultEl.className = 'mvp-sb-result show error'; resultEl.textContent = (data.data && data.data.error) || 'No match found for this VIN'; }
                }).catch(function() { resultEl.className = 'mvp-sb-result show error'; resultEl.textContent = 'An error occurred. Please try again.'; });
        }

        function doRegSearch(reg) {
            reg = reg.replace(/\s+/g, '');
            if (reg.length < 2) { resultEl.className = 'mvp-sb-result show error'; resultEl.textContent = 'Please enter a valid registration number'; return; }
            resultEl.className = 'mvp-sb-result show'; resultEl.innerHTML = loaderHtml;
            var fd = new FormData(); fd.append('action', 'maxus_reg_lookup'); fd.append('reg', reg); fd.append('nonce', window.mvpData["mvp-vehicle-search-bar"][3]);
            fetch(mvpAjaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.data) {
                        if (data.data.variants && data.data.variants.length > 1) {
                            resultEl.className = 'mvp-sb-result show success';
                            resultEl.innerHTML = '<strong>' + data.data.vehicle_name + '</strong> &mdash; Select your variant...';
                            window.location.href = mvpHomeUrl + 'registration-lookup/?reg=' + encodeURIComponent(reg);
                        } else if (data.data.shop_url) {
                            resultEl.className = 'mvp-sb-result show success';
                            resultEl.innerHTML = '<strong>' + data.data.vehicle_name + ' (' + data.data.customer_year + ')</strong> &mdash; Redirecting...';
                            window.location.href = data.data.shop_url;
                        } else { resultEl.className = 'mvp-sb-result show error'; resultEl.textContent = 'No match found'; }
                    } else { resultEl.className = 'mvp-sb-result show error'; resultEl.textContent = (data.data && data.data.error) || 'No match found'; }
                }).catch(function() { resultEl.className = 'mvp-sb-result show error'; resultEl.textContent = 'An error occurred. Please try again.'; });
        }
    });
