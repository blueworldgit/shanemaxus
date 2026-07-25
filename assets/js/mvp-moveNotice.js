    /* Fix Why Use Us height on mobile */
    (function() {
        if (window.innerWidth > 1024) return;
        function fixWhyUs() {
            var section = document.querySelector("[data-id=\"8b07793\"]");
            if (!section) return;
            section.style.height = "auto";
            section.style.maxHeight = "none";
            section.style.overflow = "visible";
            var inner = section.querySelector(".e-con-inner");
            if (inner) {
                inner.style.height = "auto";
                inner.style.display = "block";
            }
            section.querySelectorAll(".e-child, .e-con").forEach(function(el) {
                el.style.width = "100%";
                el.style.maxWidth = "100%";
                el.style.flexBasis = "100%";
                el.style.height = "auto";
            });
        }
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", fixWhyUs);
        } else {
            setTimeout(fixWhyUs, 100);
        }
    })();
    
