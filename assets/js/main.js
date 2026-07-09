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
                                    <img src="../assets/images/airbnb_logo.png?v=1.1" alt="Airbnb" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
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
