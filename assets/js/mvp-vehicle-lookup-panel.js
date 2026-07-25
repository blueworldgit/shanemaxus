    document.addEventListener('DOMContentLoaded', function() {
        var ajaxUrl = window.mvpData["mvp-vehicle-lookup-panel"][0];
        var homeUrl = window.mvpData["mvp-vehicle-lookup-panel"][1];
        var vehicleData = window.mvpData["mvp-vehicle-lookup-panel"][2];
        var loaderHtml = '<span class="mvp-lp-loader"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2.5" opacity="0.25"/><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2.5" stroke-dasharray="32" stroke-dashoffset="16" stroke-linecap="round"/></svg> Looking up vehicle...</span>';

        var panel = document.getElementById('mvp-lookup-panel');
        var isOpen = false;
        var closeTimer = null;

        function cancelClose() { clearTimeout(closeTimer); }
        function scheduleClose() {
            closeTimer = setTimeout(function() { panel.classList.remove('is-open'); isOpen = false; }, 300);
        }

        // Section elements
        var secModel = panel.querySelector('.mvp-lp-sec-model');
        var secDivVin = panel.querySelector('.mvp-lp-sec-divider-vin');
        var secVin = panel.querySelector('.mvp-lp-sec-vin');
        var secDivReg = panel.querySelector('.mvp-lp-sec-divider-reg');
        var secReg = panel.querySelector('.mvp-lp-sec-reg');
        var allSections = [secModel, secDivVin, secVin, secDivReg, secReg];

        function setPanelMode(mode) {
            if (mode === 'vin') {
                allSections.forEach(function(el) { el.style.display = 'none'; });
                secVin.style.display = '';
            } else if (mode === 'reg') {
                allSections.forEach(function(el) { el.style.display = 'none'; });
                secReg.style.display = '';
            } else {
                allSections.forEach(function(el) { el.style.display = ''; });
            }
        }

        function openPanel(anchor, mode) {
            cancelClose();
            setPanelMode(mode || 'full');
            var rect = anchor.getBoundingClientRect();
            var pw = panel.offsetWidth || 380;
            var left = rect.left;
            if (left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8;
            if (left < 8) left = 8;
            panel.style.top = rect.bottom + 'px';
            panel.style.left = left + 'px';
            panel.classList.add('is-open');
            isOpen = true;
        }

        function togglePanel(anchor, mode) {
            if (isOpen) { panel.classList.remove('is-open'); isOpen = false; }
            else { openPanel(anchor, mode); }
        }

        panel.addEventListener('mouseenter', cancelClose);
        panel.addEventListener('mouseleave', scheduleClose);

        // Close on outside click
        document.addEventListener('click', function(e) {
            if (!isOpen || panel.contains(e.target)) return;
            // Don't close if clicking the menu items that triggered it
            if (e.target.closest('a[href*="vin-search"]') || e.target.closest('a[href*="registration-lookup"]') || e.target.closest('a[href*="vehicle-lookup"]')) return;
            panel.classList.remove('is-open');
            isOpen = false;
        });

        // ── Model/Year selector ──
        var modelSel = document.getElementById('mvp-lp-model');
        var yearSel = document.getElementById('mvp-lp-year');
        var goBtn = document.getElementById('mvp-lp-go');

        Object.keys(vehicleData).sort().forEach(function(model) {
            var opt = document.createElement('option');
            opt.value = model;
            opt.textContent = model;
            modelSel.appendChild(opt);
        });

        modelSel.addEventListener('change', function() {
            var model = this.value;
            yearSel.innerHTML = '<option value="">Select Year</option>';
            yearSel.disabled = true;
            goBtn.disabled = true;
            if (!model || !vehicleData[model]) return;
            var years = vehicleData[model].years;
            if (years.length <= 1) { goBtn.disabled = false; return; }
            years.forEach(function(y) {
                var opt = document.createElement('option');
                opt.value = y; opt.textContent = y;
                yearSel.appendChild(opt);
            });
            yearSel.disabled = false;
        });

        yearSel.addEventListener('change', function() { goBtn.disabled = !this.value && !modelSel.value; });

        goBtn.addEventListener('click', function() {
            var model = modelSel.value;
            if (!model || !vehicleData[model]) return;
            window.location.href = homeUrl + 'vehicle/' + vehicleData[model].slug + '/';
        });

        // ── VIN search ──
        var vinInput = document.getElementById('mvp-lp-vin');
        var vinBtn = document.getElementById('mvp-lp-vin-btn');
        var vinResult = document.getElementById('mvp-lp-vin-result');

        vinInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        });

        function doVinSearch() {
            var vin = vinInput.value.trim();
            if (vin.length !== 17) {
                vinResult.className = 'mvp-lp-result show error';
                vinResult.textContent = 'VIN must be 17 characters (' + vin.length + ' entered)';
                return;
            }
            vinResult.className = 'mvp-lp-result show';
            vinResult.innerHTML = loaderHtml;
            var fd = new FormData();
            fd.append('action', 'maxus_vin_lookup');
            fd.append('vin', vin);
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.data) {
                        if (data.data.variants && data.data.variants.length > 1) {
                            vinResult.className = 'mvp-lp-result show success';
                            vinResult.innerHTML = '<strong>Multiple variants found</strong> — Select yours...';
                            window.location.href = homeUrl + 'vin-search-test/?vin=' + encodeURIComponent(vin);
                        } else if (data.data.shop_url) {
                            vinResult.className = 'mvp-lp-result show success';
                            vinResult.innerHTML = '<strong>' + data.data.vehicle_name + ' (' + data.data.customer_year + ')</strong> — Redirecting...';
                            window.location.href = data.data.shop_url;
                        } else {
                            vinResult.className = 'mvp-lp-result show error';
                            vinResult.textContent = 'No match found';
                        }
                    } else {
                        vinResult.className = 'mvp-lp-result show error';
                        vinResult.textContent = (data.data && data.data.error) || 'No match found';
                    }
                })
                .catch(function() {
                    vinResult.className = 'mvp-lp-result show error';
                    vinResult.textContent = 'An error occurred. Please try again.';
                });
        }

        vinBtn.addEventListener('click', function(e) { e.preventDefault(); doVinSearch(); });
        vinInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); doVinSearch(); } });

        // ── Registration search ──
        var regInput = document.getElementById('mvp-lp-reg');
        var regBtn = document.getElementById('mvp-lp-reg-btn');
        var regResult = document.getElementById('mvp-lp-reg-result');

        regInput.addEventListener('input', function() { this.value = this.value.toUpperCase(); });

        function doRegSearch() {
            var reg = regInput.value.trim().replace(/\s+/g, '');
            if (reg.length < 2) {
                regResult.className = 'mvp-lp-result show error';
                regResult.textContent = 'Please enter a valid registration number';
                return;
            }
            regResult.className = 'mvp-lp-result show';
            regResult.innerHTML = loaderHtml;
            var fd = new FormData();
            fd.append('action', 'maxus_reg_lookup');
            fd.append('reg', reg);
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.data) {
                        if (data.data.variants && data.data.variants.length > 1) {
                            // Multiple variants — go to registration lookup page with picker
                            regResult.className = 'mvp-lp-result show success';
                            regResult.innerHTML = '<strong>' + data.data.vehicle_name + '</strong> — Select your variant...';
                            window.location.href = homeUrl + 'registration-lookup/?reg=' + encodeURIComponent(reg);
                        } else if (data.data.shop_url) {
                            regResult.className = 'mvp-lp-result show success';
                            regResult.innerHTML = '<strong>' + data.data.vehicle_name + ' (' + data.data.customer_year + ')</strong> — Redirecting...';
                            window.location.href = data.data.shop_url;
                        } else {
                            regResult.className = 'mvp-lp-result show error';
                            regResult.textContent = 'No match found';
                        }
                    } else {
                        regResult.className = 'mvp-lp-result show error';
                        regResult.textContent = (data.data && data.data.error) || 'No match found';
                    }
                })
                .catch(function() {
                    regResult.className = 'mvp-lp-result show error';
                    regResult.textContent = 'An error occurred. Please try again.';
                });
        }

        regBtn.addEventListener('click', function(e) { e.preventDefault(); doRegSearch(); });
        regInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); doRegSearch(); } });

        // ── Attach to nav menu items ──
        // Find menu items by href containing our target URLs
        var allLinks = document.querySelectorAll('#site-navigation a, .header-menu a, nav a');
        allLinks.forEach(function(link) {
            var href = link.getAttribute('href') || '';
            var mode = null;

            if (href.indexOf('vin-search') !== -1) mode = 'vin';
            else if (href.indexOf('registration-lookup') !== -1) mode = 'reg';
            else if (href.indexOf('vehicle-lookup') !== -1) mode = 'full';

            if (!mode) return;

            // Prevent navigation
            link.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                togglePanel(link, mode);
            });

            // Hover to open
            var menuItem = link.closest('li') || link;
            menuItem.addEventListener('mouseenter', function() { openPanel(link, mode); });
            menuItem.addEventListener('mouseleave', function() { scheduleClose(); });
        });
    });
