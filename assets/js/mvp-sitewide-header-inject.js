    /* Move WooCommerce "added to cart" notice to top of page */
    (function() {
        function moveNotice() {
            var wcNotice = document.querySelector(".woocommerce-notices-wrapper .woocommerce-message");
            if (!wcNotice) return;
            if (document.querySelector(".mvp-cart-notice-area")) return;
            var noticeArea = document.createElement("div");
            noticeArea.className = "mvp-cart-notice-area";
            noticeArea.style.cssText = "max-width:1320px;margin:0 auto;padding:10px 10px 0;";
            wcNotice.classList.add("mvp-cart-notice");
            noticeArea.appendChild(wcNotice);
            var wrapEl = document.getElementById("wrap");
            if (wrapEl && wrapEl.children.length > 1) {
                wrapEl.insertBefore(noticeArea, wrapEl.children[1]);
            }
        }
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", moveNotice);
        } else {
            setTimeout(moveNotice, 0);
        }
    })();
    
