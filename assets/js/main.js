document.addEventListener('DOMContentLoaded', function() {
    // 1. Mobile Menu Toggle
    const menuToggle = document.getElementById('menu-toggle');
    const nav = document.getElementById('nav');
    
    if (menuToggle && nav) {
        menuToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            nav.classList.toggle('open');
            // Toggle hamburger icon animation/state if needed
            const isOpen = nav.classList.contains('open');
            menuToggle.setAttribute('aria-expanded', isOpen);
        });

        // Close menu when clicking outside
        document.addEventListener('click', function(e) {
            if (!nav.contains(e.target) && e.target !== menuToggle) {
                nav.classList.remove('open');
                menuToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // 2. Interactive Lightbox Photo Gallery
    const galleryItems = document.querySelectorAll('.gallery-item');
    const lightbox = document.getElementById('lightbox');
    const lightboxImg = document.getElementById('lightbox-img');
    const lightboxClose = document.getElementById('lightbox-close');

    if (galleryItems.length > 0 && lightbox && lightboxImg) {
        galleryItems.forEach(item => {
            item.addEventListener('click', function() {
                const img = this.querySelector('img');
                if (img) {
                    lightboxImg.src = img.src;
                    lightboxImg.alt = img.alt;
                    lightbox.style.display = 'flex';
                    document.body.style.overflow = 'hidden'; // Lock background scroll
                }
            });
        });

        const closeLightbox = function() {
            lightbox.style.display = 'none';
            lightboxImg.src = '';
            document.body.style.overflow = ''; // Unlock background scroll
        };

        if (lightboxClose) {
            lightboxClose.addEventListener('click', closeLightbox);
        }

        // Close lightbox when clicking the overlay
        lightbox.addEventListener('click', function(e) {
            if (e.target === lightbox || e.target.classList.contains('lightbox-content')) {
                closeLightbox();
            }
        });

        // Close on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && lightbox.style.display === 'flex') {
                closeLightbox();
            }
        });
    }

    // 3. Smooth active state navigation helper
    const currentUrl = window.location.pathname;
    const navLinks = document.querySelectorAll('.nav-link');
    
    navLinks.forEach(link => {
        const href = link.getAttribute('href');
        // Simple match for folder paths like "es/reserva.html" vs "reserva.html"
        if (currentUrl.endsWith(href) || (href === 'index.html' && (currentUrl.endsWith('/') || currentUrl.endsWith('/index.html')))) {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });
});
