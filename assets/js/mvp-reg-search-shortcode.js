    document.addEventListener("DOMContentLoaded", function() {
        var form = document.getElementById("mvp-reg-form");
        var input = document.getElementById("mvp-reg-input");
        var result = document.getElementById("mvp-reg-result");
        if (!form) return;
        input.addEventListener("input", function() { this.value = this.value.toUpperCase(); });
        form.addEventListener("submit", function(e) {
            e.preventDefault();
            var reg = input.value.trim().replace(/\s+/g, "");
            if (reg.length < 2) { showReg("error", "Please enter a valid registration."); return; }
            showReg("loading", "Looking up " + input.value.trim() + "...");
            var fd = new FormData();
            fd.append("action", "maxus_reg_lookup");
            fd.append("reg", reg);
            fd.append("nonce", window.mvpData["mvp-reg-search-shortcode"][1]);
            fetch(window.mvpData["mvp-reg-search-shortcode"][0], {method:"POST", body:fd})
                .then(function(r){return r.json();})
                .then(function(data) {
                    if (data.success && data.data) {
                        // Multiple variants — show picker
                        if (data.data.variants && data.data.variants.length > 1) {
                            showVariantPicker(data.data);
                        } else if (data.data.shop_url) {
                            window.location.href = data.data.shop_url;
                        } else {
                            showReg("error", "Vehicle not found.");
                        }
                    } else {
                        showReg("error", data.data && data.data.error ? data.data.error : "Vehicle not found.");
                    }
                }).catch(function(){ showReg("error", "Network error. Please try again."); });
        });
        function showReg(type, msg) {
            result.style.display = "block";
            result.style.padding = "14px 20px";
            result.style.borderRadius = "6px";
            result.style.background = type === "error" ? "#fff5f5" : type === "loading" ? "#f0f0f0" : "#f0fff0";
            result.style.color = type === "error" ? "#c00" : "#333";
            result.innerHTML = "";
            result.textContent = msg;
        }
        function showVariantPicker(d) {
            result.style.display = "block";
            result.style.padding = "0";
            result.style.borderRadius = "8px";
            result.style.background = "#fff";
            result.style.color = "#333";
            result.style.border = "1px solid #e0e0e0";
            result.style.boxShadow = "0 2px 8px rgba(0,0,0,0.08)";
            var html = '<div style="background:#f8f8f8;padding:16px 20px;border-radius:8px 8px 0 0;border-bottom:1px solid #e0e0e0;">';
            html += '<div style="font-size:18px;font-weight:700;color:#111;">' + d.vehicle_name + '</div>';
            html += '<div style="font-size:14px;color:#666;margin-top:4px;">';
            html += d.customer_year + (d.colour ? ' &middot; ' + d.colour : '') + (d.fuel ? ' &middot; ' + d.fuel : '');
            html += '</div>';
            html += '</div>';
            html += '<div style="padding:16px 20px;">';
            html += '<p style="font-size:14px;color:#666;margin:0 0 14px;">We found multiple variants for this model. Please select yours:</p>';
            html += '<div style="display:flex;flex-direction:column;gap:8px;">';
            d.variants.forEach(function(v) {
                html += '<a href="' + v.url + '" style="display:flex;align-items:center;gap:14px;padding:12px 16px;border:2px solid #e0e0e0;border-radius:8px;text-decoration:none;color:#111;transition:border-color 0.2s,background 0.2s;" onmouseover="this.style.borderColor=\'#D18A0C\';this.style.background=\'#fffbf0\';" onmouseout="this.style.borderColor=\'#e0e0e0\';this.style.background=\'#fff\';">';
                if (v.img) {
                    html += '<img src="' + v.img + '" alt="" style="width:60px;height:40px;object-fit:contain;flex-shrink:0;">';
                }
                html += '<div style="flex:1;">';
                html += '<div style="font-size:16px;font-weight:600;">' + v.name + '</div>';
                if (v.year) { html += '<div style="font-size:13px;color:#888;">' + v.year + '</div>'; }
                html += '</div>';
                html += '<div style="flex-shrink:0;background:#BF3617;color:#fff;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;">View Parts</div>';
                html += '</a>';
            });
            html += '</div></div>';
            result.innerHTML = html;
        }
        // Auto-submit if ?reg= parameter is present
        var urlParams = new URLSearchParams(window.location.search);
        var autoReg = urlParams.get("reg");
        if (autoReg) {
            input.value = autoReg.toUpperCase();
            form.dispatchEvent(new Event("submit"));
        }
    });
