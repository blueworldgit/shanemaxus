    document.addEventListener('DOMContentLoaded', function() {
        var section = document.getElementById('mvp-featured-products');
        if (!section) return;
        // Position it before the Why Use Us Elementor section (8b07793)
        var whyUs = document.querySelector('[data-id="8b07793"]');
        if (whyUs) {
            whyUs.parentNode.insertBefore(section, whyUs);
        } else {
            // Fallback: before the footer
            var footer = document.querySelector('.mvp-footer, footer');
            if (footer) footer.parentNode.insertBefore(section, footer);
        }
        section.style.display = 'block';
    });
    
