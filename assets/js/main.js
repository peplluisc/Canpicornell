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

    // 4. Testimonials Pagination
    const testimonialsGrid = document.getElementById('testimonials-grid');
    const testimonialsPagination = document.getElementById('testimonials-pagination');
    
    // Determine which review array is loaded
    let reviews = [];
    let prevText = 'Anterior';
    let nextText = 'Siguiente';
    let guestText = 'Huésped de Airbnb';
    
    if (typeof REVIEWS_ES !== 'undefined') {
        reviews = REVIEWS_ES;
        prevText = 'Anterior';
        nextText = 'Siguiente';
        guestText = 'Huésped de Airbnb';
    } else if (typeof REVIEWS_EN !== 'undefined') {
        reviews = REVIEWS_EN;
        prevText = 'Previous';
        nextText = 'Next';
        guestText = 'Airbnb Guest';
    } else if (typeof REVIEWS_DE !== 'undefined') {
        reviews = REVIEWS_DE;
        prevText = 'Zurück';
        nextText = 'Weiter';
        guestText = 'Airbnb-Gast';
    }
    
    if (testimonialsGrid && reviews.length > 0) {
        const itemsPerPage = 4;
        let currentPage = 1;
        const totalPages = Math.ceil(reviews.length / itemsPerPage);
        
        function renderPage(page) {
            // Apply fade-out animation
            const cards = testimonialsGrid.querySelectorAll('.testimonial-card');
            cards.forEach(card => card.classList.add('fade-out'));
            
            setTimeout(() => {
                testimonialsGrid.innerHTML = '';
                const startIdx = (page - 1) * itemsPerPage;
                const endIdx = Math.min(startIdx + itemsPerPage, reviews.length);
                
                for (let i = startIdx; i < endIdx; i++) {
                    const rev = reviews[i];
                    
                    const cardHtml = `
                        <div class="testimonial-card">
                            <div class="testimonial-stars">★★★★★</div>
                            <p class="testimonial-quote">"${rev.text}"</p>
                            <div class="testimonial-meta">
                                <div class="testimonial-avatar" title="Airbnb">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" width="22" height="22" fill="currentColor">
                                        <path d="M224 373.12c-25.93 0-48.44-11.48-65-29.72 8.16-17.71 18.06-31.25 33.69-42 13.91-9.56 21-11.86 31.32-11.86s17.4 2.3 31.31 11.86c15.63 10.74 25.53 24.28 33.69 42-16.57 18.24-39.08 29.72-65 29.72zm145.45-31.14c-12.78-27.81-30.82-50.53-56.12-67.92-23.75-16.32-37.56-21-58-21-20.46 0-34.27 4.7-58 21-25.3 17.39-43.34 40.11-56.12 67.92-13.62-15-22.18-34.72-22.18-56.57 0-46.3 37.89-84 84.18-84s84.18 37.7 84.18 84c0 21.85-8.56 41.54-22.18 56.57zM224 80c18.56 0 33.68 15.12 33.68 33.68S242.56 147.36 224 147.36s-33.68-15.12-33.68-33.68S205.44 80 224 80zm217 301.62c0-43.7-22-83.33-56.88-108.62L254.76 74c-6.1-9-16.53-15.36-28.51-15.36s-22.42 6.39-28.52 15.36L67.89 273c-34.93 25.29-56.89 64.92-56.89 108.62 0 71.39 58.12 129.38 129.54 129.38 52.88 0 98.42-31.1 119.5-76.12 21.09 45 66.62 76.12 119.5 76.12 71.42.02 129.46-57.97 129.46-129.38z" />
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="testimonial-author">${rev.author}</h4>
                                    <p class="testimonial-date">${guestText} — ${rev.date}</p>
                                </div>
                            </div>
                        </div>
                    `;
                    testimonialsGrid.insertAdjacentHTML('beforeend', cardHtml);
                }
                
                // Update pagination controls
                renderPaginationControls();
            }, cards.length > 0 ? 300 : 0);
        }
        
        function renderPaginationControls() {
            if (!testimonialsPagination) return;
            
            testimonialsPagination.innerHTML = `
                <button class="pagination-btn" id="prev-page-btn" ${currentPage === 1 ? 'disabled' : ''} aria-label="${prevText}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <span class="pagination-info">${currentPage} / ${totalPages}</span>
                <button class="pagination-btn" id="next-page-btn" ${currentPage === totalPages ? 'disabled' : ''} aria-label="${nextText}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
            `;
            
            document.getElementById('prev-page-btn').addEventListener('click', () => {
                if (currentPage > 1) {
                    currentPage--;
                    renderPage(currentPage);
                }
            });
            
            document.getElementById('next-page-btn').addEventListener('click', () => {
                if (currentPage < totalPages) {
                    currentPage++;
                    renderPage(currentPage);
                }
            });
        }
        
        // Initial render
        renderPage(currentPage);
    }
});
