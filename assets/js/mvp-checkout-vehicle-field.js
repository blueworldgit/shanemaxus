    document.addEventListener('DOMContentLoaded', function() {
        function addVehicleField() {
            if (document.getElementById('mvp-checkout-vehicle-field')) return;
            // Find the order notes or the place order section
            var target = document.querySelector('.wc-block-checkout__actions, .wc-block-components-checkout-place-order-button, #order_review, .woocommerce-checkout-review-order');
            if (!target) return;
            var wrap = document.createElement('div');
            wrap.id = 'mvp-checkout-vehicle-field';
            wrap.style.cssText = "border:2px dashed #D18A0C;border-radius:8px;padding:28px 32px;margin:0 0 24px;";
            wrap.innerHTML = '<h3 style="font-family:Inter,sans-serif;font-size:20px;font-weight:600;margin:0 0 12px;color:#333;">Vehicle Verification</h3>' +
                '<p style="font-size:14px;color:#666;line-height:1.6;margin:0 0 20px;">To help ensure you receive the correct parts, please provide the registration number or VIN for each vehicle these parts are intended for. While we make every effort to keep our site accurate and up to date, part numbers can change and descriptions may vary between manufacturers.</p>' +
                '<div id="mvp-vehicle-entries">' +
                '<div class="mvp-vehicle-entry" style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px;">' +
                '<div style="flex:1;min-width:200px;"><label style="display:block;font-size:11px;font-weight:700;color:#333;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">Registration Number</label><input type="text" name="vehicle_reg[]" placeholder="E.G. AB12 CDE" style="width:100%;height:44px;padding:0 14px;border:1px solid #ddd;border-radius:4px;font-size:14px;text-transform:uppercase;"></div>' +
                '<div style="flex:1;min-width:200px;"><label style="display:block;font-size:11px;font-weight:700;color:#333;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">VIN Number</label><input type="text" name="vehicle_vin[]" placeholder="E.G. 4T4BE46K79R107189" maxlength="17" style="width:100%;height:44px;padding:0 14px;border:1px solid #ddd;border-radius:4px;font-size:14px;text-transform:uppercase;"></div>' +
                '</div></div>' +
                '<button type="button" id="mvp-add-vehicle" style="background:none;border:1px solid #ddd;border-radius:4px;padding:8px 16px;font-size:13px;color:#333;cursor:pointer;margin:8px 0 16px;">+ Add another vehicle</button>' +
                '<div style="border-top:1px solid #eee;padding-top:14px;margin-top:8px;">' +
                '<label style="display:flex;align-items:center;gap:8px;font-size:14px;color:#555;cursor:pointer;">' +
                '<input type="checkbox" name="skip_vehicle_details" style="width:16px;height:16px;"> Continue without providing vehicle details</label></div>';
            target.parentNode.insertBefore(wrap, target);
            // Add another vehicle button
            document.getElementById("mvp-add-vehicle").addEventListener("click", function() {
                var entries = document.getElementById("mvp-vehicle-entries");
                var newEntry = document.createElement("div");
                newEntry.className = "mvp-vehicle-entry";
                newEntry.style.cssText = "display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px;";
                newEntry.innerHTML = '<div style="flex:1;min-width:200px;"><label style="display:block;font-size:11px;font-weight:700;color:#333;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">Registration Number</label><input type="text" name="vehicle_reg[]" placeholder="E.G. AB12 CDE" style="width:100%;height:44px;padding:0 14px;border:1px solid #ddd;border-radius:4px;font-size:14px;text-transform:uppercase;"></div>' +
                '<div style="flex:1;min-width:200px;"><label style="display:block;font-size:11px;font-weight:700;color:#333;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">VIN Number</label><input type="text" name="vehicle_vin[]" placeholder="E.G. 4T4BE46K79R107189" maxlength="17" style="width:100%;height:44px;padding:0 14px;border:1px solid #ddd;border-radius:4px;font-size:14px;text-transform:uppercase;"></div>';
                entries.appendChild(newEntry);
            });
        }
        // Try immediately and also observe for Blocks rendering
        addVehicleField();
        var obs = new MutationObserver(function() { addVehicleField(); });
        obs.observe(document.body, {childList: true, subtree: true});
        setTimeout(function() { obs.disconnect(); }, 10000);
    });
    
