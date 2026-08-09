/**
 * Can Picornell Private Guest Shop JavaScript Application
 * Mobile-first Vanilla JS SPA with server-side cart persistence and multi-language support.
 */

let rawToken = '';
let currentLang = 'es';
let guestData = null;
let orderData = null;
let categoriesData = [];
let productsData = [];
let cartItemsMap = {}; // product_id -> quantity
let activeCategory = 'all';

const I18N = {
    es: {
        welcome: "Hola",
        your_stay: "Tu estancia en Can Picornell",
        nights: "noches",
        night: "noche",
        search_placeholder: "Buscar agua, vino, café...",
        all_products: "Todos los productos",
        featured: "Destacados",
        add: "Añadir",
        view_cart: "Ver lista de compra",
        cart_title: "Tu Lista de Compra",
        empty_cart: "Tu lista de compra está vacía",
        notes_label: "¿Necesitas algo que no aparece en la tienda?",
        notes_placeholder: "Ej: Leche de avena sin azúcar, 2 bolsas de hielo...",
        important_notice: "Esta solicitud será revisada antes de realizar la compra. El importe final será confirmado por Can Picornell y se abonará a la llegada.",
        submit_btn: "ENVIAR LISTA DE COMPRA",
        total: "Total estimado",
        order_submitted_title: "¡Lista de compra enviada!",
        order_submitted_msg: "Hemos recibido tu lista de compra correctamente. La revisaremos antes de tu llegada.",
        status_label: "Estado de tu lista",
        status_PENDING_REVIEW: "En revisión por Can Picornell",
        status_APPROVED: "Confirmada",
        status_PURCHASED: "Comprada",
        status_DELIVERED: "Entregada en la finca",
        status_PAID: "Completada",
        err_invalid_token: "El enlace privado no es válido o ha caducado. Por favor, contacta con Can Picornell.",
        copied: "Copiado"
    },
    en: {
        welcome: "Welcome",
        your_stay: "Your stay at Can Picornell",
        nights: "nights",
        night: "night",
        search_placeholder: "Search water, wine, coffee...",
        all_products: "All products",
        featured: "Featured",
        add: "Add",
        view_cart: "View shopping list",
        cart_title: "Your Shopping List",
        empty_cart: "Your shopping list is empty",
        notes_label: "Need anything not listed in the store?",
        notes_placeholder: "E.g. Oat milk sugar-free, 2 large ice bags...",
        important_notice: "This request will be reviewed before the purchase is made. The final amount will be confirmed by Can Picornell and paid on arrival.",
        submit_btn: "SUBMIT SHOPPING LIST",
        total: "Estimated total",
        order_submitted_title: "Shopping list sent!",
        order_submitted_msg: "We have received your shopping list. We will review it before your arrival.",
        status_label: "List status",
        status_PENDING_REVIEW: "Under review by Can Picornell",
        status_APPROVED: "Confirmed",
        status_PURCHASED: "Purchased",
        status_DELIVERED: "Delivered at the villa",
        status_PAID: "Completed",
        err_invalid_token: "This private link is invalid or expired. Please contact Can Picornell.",
        copied: "Copied"
    },
    de: {
        welcome: "Willkommen",
        your_stay: "Ihr Aufenthalt bei Can Picornell",
        nights: "Nächte",
        night: "Nacht",
        search_placeholder: "Wasser, Wein, Kaffee suchen...",
        all_products: "Alle Produkte",
        featured: "Empfohlen",
        add: "Hinzufügen",
        view_cart: "Einkaufsliste ansehen",
        cart_title: "Ihre Einkaufsliste",
        empty_cart: "Ihre Einkaufsliste ist leer",
        notes_label: "Benötigen Sie etwas, das nicht im Shop steht?",
        notes_placeholder: "Z.B. Hafermilch zuckerfrei, 2 Beutel Eis...",
        important_notice: "Diese Anfrage wird vor dem Einkauf überprüft. Der endgültige Betrag wird von Can Picornell bestätigt und bei der Ankunft bezahlt.",
        submit_btn: "EINKAUFSLISTE ABSENDEN",
        total: "Geschätzte Gesamtsumme",
        order_submitted_title: "Einkaufsliste gesendet!",
        order_submitted_msg: "Wir haben Ihre Einkaufsliste erhalten. Wir werden sie vor Ihrer Ankunft überprüfen.",
        status_label: "Status Ihrer Liste",
        status_PENDING_REVIEW: "In Überprüfung durch Can Picornell",
        status_APPROVED: "Bestätigt",
        status_PURCHASED: "Eingekauft",
        status_DELIVERED: "In der Finca geliefert",
        status_PAID: "Abgeschlossen",
        err_invalid_token: "Dieser private Link ist ungültig oder abgelaufen. Bitte kontaktieren Sie Can Picornell.",
        copied: "Kopiert"
    }
};

document.addEventListener('DOMContentLoaded', () => {
    parseUrlParams();
    if (!rawToken) {
        showErrorUI(I18N.es.err_invalid_token);
        return;
    }
    loadGuestContext();
});

function parseUrlParams() {
    const params = new URLSearchParams(window.location.search);
    rawToken = params.get('t') || '';
    const lang = params.get('lang');
    if (lang && ['es', 'en', 'de'].includes(lang)) {
        currentLang = lang;
    }
}

function loadGuestContext() {
    fetch(`../api/shop/guest/context.php?t=${encodeURIComponent(rawToken)}&lang=${currentLang}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                showErrorUI(data.error || I18N[currentLang].err_invalid_token);
                return;
            }
            currentLang = data.language || currentLang;
            guestData = data.guest;
            orderData = data.order;

            updateLangSelector();
            renderStayBanner();

            if (orderData && orderData.status !== 'DRAFT') {
                renderOrderSubmittedUI();
            } else {
                loadCatalog();
                loadCart();
            }
        })
        .catch(err => {
            showErrorUI(I18N[currentLang].err_invalid_token);
        });
}

function updateLangSelector() {
    ['es', 'en', 'de'].forEach(l => {
        const btn = document.getElementById('lang-btn-' + l);
        if (btn) {
            btn.classList.toggle('active', l === currentLang);
        }
    });
}

function switchLanguage(newLang) {
    if (currentLang === newLang) return;
    currentLang = newLang;

    // Update URL query string without reloading page
    const url = new URL(window.location);
    url.searchParams.set('lang', newLang);
    window.history.replaceState({}, '', url);

    updateLangSelector();
    renderStayBanner();

    if (orderData && orderData.status !== 'DRAFT') {
        renderOrderSubmittedUI();
    } else {
        loadCatalog();
        loadCart();
    }
}

function renderStayBanner() {
    const txt = I18N[currentLang];
    const banner = document.getElementById('stay-banner');
    if (!banner || !guestData) return;

    const nightWord = guestData.nights === 1 ? txt.night : txt.nights;
    
    banner.innerHTML = `
        <div class="stay-container">
            <h2 class="guest-greeting">${txt.welcome}, ${guestData.name}</h2>
            <p style="font-size:0.85rem; opacity:0.9; margin-bottom:4px;">${txt.your_stay}</p>
            <div class="stay-dates">
                <span>📅 ${guestData.checkin} &rarr; ${guestData.checkout}</span>
                <span>(${guestData.nights} ${nightWord})</span>
            </div>
        </div>
    `;
}

function loadCatalog() {
    const catContainer = document.getElementById('categories-container');
    fetch(`../api/shop/guest/catalog.php?t=${encodeURIComponent(rawToken)}&lang=${currentLang}`)
        .then(res => res.json())
        .then(data => {
            if (data.categories) categoriesData = data.categories;
            if (data.products) productsData = data.products;

            renderCategoryChips();
            renderProducts();
        });
}

function renderCategoryChips() {
    const txt = I18N[currentLang];
    const scroll = document.getElementById('categories-scroll');
    if (!scroll) return;

    let html = `<button class="category-chip ${activeCategory === 'all' ? 'active' : ''}" onclick="selectCategory('all')">${txt.all_products}</button>`;
    html += `<button class="category-chip ${activeCategory === 'featured' ? 'active' : ''}" onclick="selectCategory('featured')">⭐ ${txt.featured}</button>`;

    categoriesData.forEach(c => {
        html += `<button class="category-chip ${activeCategory === c.slug ? 'active' : ''}" onclick="selectCategory('${c.slug}')">${c.name}</button>`;
    });

    scroll.innerHTML = html;
}

function selectCategory(catSlug) {
    activeCategory = catSlug;
    renderCategoryChips();
    renderProducts();
}

function renderProducts() {
    const grid = document.getElementById('products-grid');
    if (!grid) return;

    const searchQuery = (document.getElementById('search-input')?.value || '').toLowerCase().trim();

    let filtered = productsData.filter(p => {
        if (activeCategory === 'featured' && !p.is_featured) return false;
        if (activeCategory !== 'all' && activeCategory !== 'featured' && p.category_slug !== activeCategory) return false;

        if (searchQuery) {
            const h = (p.name + ' ' + p.description + ' ' + p.brand + ' ' + p.format).toLowerCase();
            if (!h.includes(searchQuery)) return false;
        }
        return true;
    });

    if (filtered.length === 0) {
        grid.innerHTML = `<div style="grid-column:1/-1; text-align:center; padding:2rem; color:var(--text-muted);">No se encontraron productos.</div>`;
        return;
    }

    let html = '';
    filtered.forEach(p => {
        const qty = cartItemsMap[p.id] || 0;
        const imgUrl = p.image_url || '../favicon.png';
        const brandText = p.brand ? `<div class="product-meta">${p.brand}</div>` : '';
        const fmtText = p.format ? `<div class="product-meta">${p.format}</div>` : '';

        const qtyCtrl = qty > 0 ? `
            <div class="qty-control">
                <button class="qty-btn" onclick="updateCartItemQty(${p.id}, ${qty - 1})">-</button>
                <span class="qty-num">${qty}</span>
                <button class="qty-btn" onclick="updateCartItemQty(${p.id}, ${qty + 1})">+</button>
            </div>
        ` : `
            <button class="btn-submit-order" style="padding:6px 12px; font-size:0.85rem;" onclick="updateCartItemQty(${p.id}, 1)">
                + ${I18N[currentLang].add}
            </button>
        `;

        html += `
            <div class="product-card ${p.is_featured ? 'featured' : ''}">
                ${p.is_featured ? `<span class="featured-badge">★ ${I18N[currentLang].featured}</span>` : ''}
                <div class="product-img-wrapper">
                    <img src="${imgUrl}" alt="${p.name}" class="product-img" loading="lazy" onerror="this.src='../favicon.png'">
                </div>
                <div>
                    <h3 class="product-name">${p.name}</h3>
                    ${brandText}
                    ${fmtText}
                </div>
                <div>
                    <div class="product-price">${p.price_formatted}</div>
                    ${qtyCtrl}
                </div>
            </div>
        `;
    });

    grid.innerHTML = html;
}

function loadCart() {
    fetch(`../api/shop/guest/cart.php?t=${encodeURIComponent(rawToken)}&lang=${currentLang}`)
        .then(res => res.json())
        .then(data => {
            if (data.items) {
                cartItemsMap = {};
                data.items.forEach(it => {
                    cartItemsMap[it.product_id] = it.quantity;
                });
            }
            orderData = data;
            renderFloatingCartBar();
            renderProducts();
        });
}

function updateCartItemQty(productId, newQty) {
    fetch(`../api/shop/guest/cart.php?t=${encodeURIComponent(rawToken)}&lang=${currentLang}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update', product_id: productId, quantity: newQty })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            loadCart();
        } else alert(data.error);
    });
}

function renderFloatingCartBar() {
    const bar = document.getElementById('cart-floating-bar');
    if (!bar) return;

    if (!orderData || !orderData.items || orderData.items.length === 0) {
        bar.style.display = 'none';
        return;
    }

    let totalQty = 0;
    orderData.items.forEach(i => totalQty += i.quantity);

    bar.style.display = 'flex';
    bar.innerHTML = `
        <div class="cart-bar-info">
            <span class="cart-bar-badge">${totalQty}</span>
            <span>🛒 ${orderData.total_formatted}</span>
        </div>
        <div style="font-weight:700; font-size:0.9rem;">
            ${I18N[currentLang].view_cart} &rarr;
        </div>
    `;
}

function openCartModal() {
    const txt = I18N[currentLang];
    const modal = document.getElementById('cart-modal');
    if (!modal || !orderData) return;

    let itemsHtml = '';
    if (!orderData.items || orderData.items.length === 0) {
        itemsHtml = `<p style="text-align:center; color:var(--text-muted); padding:1.5rem;">${txt.empty_cart}</p>`;
    } else {
        orderData.items.forEach(it => {
            itemsHtml += `
                <div class="cart-item">
                    <div>
                        <div class="cart-item-name">${it.name}</div>
                        <div class="cart-item-price">${it.unit_price_formatted} c/u</div>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <div class="qty-control" style="padding:1px;">
                            <button class="qty-btn" onclick="updateCartItemQty(${it.product_id}, ${it.quantity - 1})">-</button>
                            <span class="qty-num" style="padding:0 6px;">${it.quantity}</span>
                            <button class="qty-btn" onclick="updateCartItemQty(${it.product_id}, ${it.quantity + 1})">+</button>
                        </div>
                        <div style="font-weight:700; min-width:60px; text-align:right;">${it.total_price_formatted}</div>
                    </div>
                </div>
            `;
        });
    }

    modal.innerHTML = `
        <div class="cart-sheet">
            <div class="cart-header">
                <h3 style="font-family:'Outfit'; color:var(--primary);">${txt.cart_title}</h3>
                <button style="background:none; border:none; font-size:1.5rem; cursor:pointer;" onclick="closeCartModal()">&times;</button>
            </div>
            
            <div class="cart-items-list">
                ${itemsHtml}

                <div style="margin-top:1rem;">
                    <label class="form-label" style="font-size:0.85rem; font-weight:600;">${txt.notes_label}</label>
                    <textarea id="guest-notes-input" class="notes-textarea" rows="2" placeholder="${txt.notes_placeholder}" onchange="saveGuestNotes()">${orderData.guest_notes || ''}</textarea>
                </div>

                <div class="important-notice">
                    <strong>ℹ️ Nota importante:</strong><br>
                    ${txt.important_notice}
                </div>
            </div>

            <div style="border-top:1px solid var(--border-color); padding-top:1rem;">
                <div style="display:flex; justify-content:space-between; font-size:1.1rem; font-weight:700; margin-bottom:1rem;">
                    <span>${txt.total}:</span>
                    <span style="color:var(--primary);">${orderData.total_formatted || '0,00 €'}</span>
                </div>
                <button class="btn-submit-order" onclick="submitShoppingList()">${txt.submit_btn}</button>
            </div>
        </div>
    `;

    modal.style.display = 'flex';
}

function closeCartModal() {
    const modal = document.getElementById('cart-modal');
    if (modal) modal.style.display = 'none';
}

function saveGuestNotes() {
    const notes = document.getElementById('guest-notes-input')?.value || '';
    fetch(`../api/shop/guest/cart.php?t=${encodeURIComponent(rawToken)}&lang=${currentLang}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_notes', guest_notes: notes })
    });
}

function submitShoppingList() {
    if (!confirm('¿Confirmar y enviar la lista de compra a Can Picornell?')) return;
    
    fetch(`../api/shop/guest/order.php?t=${encodeURIComponent(rawToken)}&lang=${currentLang}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'submit' })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            closeCartModal();
            loadGuestContext();
        } else alert(data.error);
    });
}

function renderOrderSubmittedUI() {
    const txt = I18N[currentLang];
    const main = document.getElementById('shop-main-content');
    if (!main || !orderData) return;

    document.getElementById('cart-floating-bar').style.display = 'none';

    const statusKey = 'status_' + orderData.status;
    const statusText = txt[statusKey] || orderData.status;

    main.innerHTML = `
        <div class="container" style="text-align:center; padding-top:2rem;">
            <div style="width:72px; height:72px; background:#dcfce7; color:#15803d; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:2.2rem; margin:0 auto 1.25rem;">
                ✓
            </div>
            <h2 style="font-family:'Outfit'; color:var(--primary); font-size:1.6rem; margin-bottom:0.5rem;">${txt.order_submitted_title}</h2>
            <p style="color:var(--text-muted); font-size:0.95rem; margin-bottom:2rem; line-height:1.5; max-width:500px; margin-left:auto; margin-right:auto;">
                ${txt.order_submitted_msg}
            </p>

            <div style="background:white; border:1px solid var(--border-color); border-radius:12px; padding:1.5rem; max-width:500px; margin:0 auto; text-align:left; box-shadow:var(--shadow-sm);">
                <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:0.75rem; margin-bottom:0.75rem;">
                    <span style="font-size:0.85rem; color:var(--text-muted);">${txt.status_label}:</span>
                    <strong style="color:var(--accent);">${statusText}</strong>
                </div>
                <div style="display:flex; justify-content:space-between; font-weight:700; font-size:1.1rem; color:var(--primary);">
                    <span>${txt.total}:</span>
                    <span>${orderData.total_formatted}</span>
                </div>
            </div>
        </div>
    `;
}

function showErrorUI(message) {
    const main = document.getElementById('shop-main-content');
    if (!main) return;
    main.innerHTML = `
        <div class="container" style="text-align:center; padding-top:4rem;">
            <div style="width:64px; height:64px; background:#fee2e2; color:#b91c1c; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.8rem; margin:0 auto 1rem;">
                !
            </div>
            <h2 style="font-family:'Outfit'; color:var(--primary); font-size:1.4rem; margin-bottom:0.5rem;">Acceso No Disponible</h2>
            <p style="color:var(--text-muted); font-size:0.9rem; max-width:400px; margin:0 auto;">${message}</p>
        </div>
    `;
}
