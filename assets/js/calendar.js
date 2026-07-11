document.addEventListener('DOMContentLoaded', function() {
    // 1. Read language from HTML tag
    const lang = document.documentElement.getAttribute('lang') || 'es';
    
    // Translation dictionary
    const translations = {
        es: {
            months: ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'],
            days: ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'],
            nights: 'noches',
            night: 'noche',
            cleaningFee: 'Gastos de Limpieza',
            deposit: 'Anticipo para confirmar (30%)',
            balance: 'Saldo restante',
            dueDate: 'Fecha límite de saldo',
            ecoTax: 'Tasa turística (EcoTasa)',
            ecoTaxPaidLater: 'Se abona a la llegada',
            total: 'Total Estimado',
            budgetTitle: 'Resumen de tu estancia',
            disclaimer: 'Presupuesto sujeto a comprobación final de disponibilidad.',
            overlapError: 'El rango seleccionado contiene días ocupados. Por favor, elige otras fechas.',
            minStayError: 'La estancia mínima es de {min} noches.',
            connectionError: 'Error al calcular el presupuesto. Inténtalo de nuevo.',
            submitting: 'Enviando...',
            submitBtn: 'Solicitar reserva',
            emptyFields: 'Por favor, rellena todos los campos obligatorios.',
            legendAvailable: 'Disponible',
            legendBooked: 'Ocupado',
            legendSelected: 'Seleccionado',
            adult: 'Adulto',
            adults: 'Adultos',
            child: 'Niño',
            children: 'Niños',
            baby: 'Bebé',
            babies: 'Bebés'
        },
        en: {
            months: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            days: ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'],
            nights: 'nights',
            night: 'night',
            cleaningFee: 'Cleaning Fee',
            deposit: 'Required Deposit (30%)',
            balance: 'Remaining balance',
            dueDate: 'Balance due date',
            ecoTax: 'Tourist tax (EcoTasa)',
            ecoTaxPaidLater: 'Paid upon arrival',
            total: 'Estimated Total',
            budgetTitle: 'Stay summary',
            disclaimer: 'Quote subject to final availability check.',
            overlapError: 'The selected range overlaps with booked dates. Please choose other dates.',
            minStayError: 'The minimum stay is {min} nights.',
            connectionError: 'Error calculating quote. Please try again.',
            submitting: 'Submitting...',
            submitBtn: 'Request booking',
            emptyFields: 'Please fill in all mandatory fields.',
            legendAvailable: 'Available',
            legendBooked: 'Occupied',
            legendSelected: 'Selected',
            adult: 'Adult',
            adults: 'Adults',
            child: 'Child',
            children: 'Children',
            baby: 'Baby',
            babies: 'Babies'
        },
        de: {
            months: ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
            days: ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'],
            nights: 'Nächte',
            night: 'Nacht',
            cleaningFee: 'Reinigungsgebühr',
            deposit: 'Erforderliche Anzahlung (30%)',
            balance: 'Restbetrag',
            dueDate: 'Restzahlung fällig am',
            ecoTax: 'Tourismusabgabe (EcoTasa)',
            ecoTaxPaidLater: 'Bei Ankunft zu zahlen',
            total: 'Voraussichtlicher Gesamtbetrag',
            budgetTitle: 'Zusammenfassung des Aufenthalts',
            disclaimer: 'Angebot unter Vorbehalt der endgültigen Verfügbarkeitsprüfung.',
            overlapError: 'Der gewählte Zeitraum überschneidet sich mit belegten Tagen. Bitte andere Daten wählen.',
            minStayError: 'Der Mindestaufenthalt beträgt {min} Nächte.',
            connectionError: 'Fehler bei der Angebotsberechnung. Bitte erneut versuchen.',
            submitting: 'Wird gesendet...',
            submitBtn: 'Buchung anfragen',
            emptyFields: 'Bitte füllen Sie alle Pflichtfelder aus.',
            legendAvailable: 'Verfügbar',
            legendBooked: 'Belegt',
            legendSelected: 'Ausgewählt',
            adult: 'Erwachsener',
            adults: 'Erwachsene',
            child: 'Kind',
            children: 'Kinder',
            baby: 'Baby',
            babies: 'Babys'
        }
    };

    const t = translations[lang] || translations['es'];

    // State Variables
    let bookedDates = []; // Format YYYY-MM-DD
    let checkInDate = null;
    let checkOutDate = null;
    let currentMonth = new Date().getMonth();
    let currentYear = new Date().getFullYear();
    let minStay = 3; // Loaded dynamically from config later

    // DOM Elements
    const calendarContainer = document.getElementById('calendar-months');
    const prevBtn = document.getElementById('cal-prev-btn');
    const nextBtn = document.getElementById('cal-next-btn');
    const priceDisplay = document.getElementById('price-breakdown-box');
    
    const checkInInput = document.getElementById('form-checkin');
    const checkOutInput = document.getElementById('form-checkout');
    const totalNightsInput = document.getElementById('form-nights');
    const totalPriceInput = document.getElementById('form-totalprice');
    const submitBtn = document.getElementById('btn-submit-booking');
    const bookingForm = document.getElementById('booking-request-form');
    const statusMsg = document.getElementById('form-status-msg');

    // Guest Inputs
    const adultsSelect = document.getElementById('form-adults');
    const childrenSelect = document.getElementById('form-children');
    const babiesSelect = document.getElementById('form-babies');
    const csrfInput = document.getElementById('form-csrf');

    // Modal elements
    const successModal = document.getElementById('success-modal-overlay');
    const modalReq = document.getElementById('modal-req-number');
    const modalDates = document.getElementById('modal-dates');
    const modalGuests = document.getElementById('modal-guests');
    const modalTotal = document.getElementById('modal-total');
    const modalEmail = document.getElementById('modal-email');

    if (!calendarContainer) return;

    // Load CSRF Token
    fetch('../api/get_csrf_token.php', { credentials: 'include' })
        .then(res => res.json())
        .then(data => {
            if (data.csrf_token && csrfInput) {
                csrfInput.value = data.csrf_token;
            }
        })
        .catch(err => console.error("Error loading CSRF token:", err));

    // Fetch availability from iCal proxy
    fetch('../api/ical_proxy.php')
        .then(response => response.json())
        .then(data => {
            bookedDates = expandBookedRanges(data);
            renderCalendar();
            renderLegend();
        })
        .catch(err => {
            console.error('Error fetching calendar:', err);
            renderCalendar();
            renderLegend();
        });

    function expandBookedRanges(ranges) {
        const dates = new Set();
        ranges.forEach(range => {
            let start = new Date(range.start);
            let end = new Date(range.end);
            let current = new Date(start);
            while (current < end) {
                dates.add(formatDateString(current));
                current.setDate(current.getDate() + 1);
            }
        });
        return Array.from(dates);
    }

    function formatDateString(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function isMobile() {
        return window.innerWidth <= 768;
    }

    function renderCalendar() {
        calendarContainer.innerHTML = '';
        const monthsToRender = isMobile() ? 1 : 2;
        
        for (let i = 0; i < monthsToRender; i++) {
            let month = currentMonth + i;
            let year = currentYear;
            if (month > 11) {
                month = month - 12;
                year = year + 1;
            }
            renderMonth(month, year);
        }
        updateNavigationButtons();
    }

    function renderMonth(month, year) {
        const monthDiv = document.createElement('div');
        monthDiv.className = 'calendar-month-card';

        const header = document.createElement('div');
        header.className = 'calendar-month-header';
        header.innerHTML = `<h4>${t.months[month]} ${year}</h4>`;
        monthDiv.appendChild(header);

        const gridDays = document.createElement('div');
        gridDays.className = 'calendar-grid-days';
        t.days.forEach(day => {
            const dayHeader = document.createElement('span');
            dayHeader.className = 'calendar-day-header';
            dayHeader.textContent = day;
            gridDays.appendChild(dayHeader);
        });
        monthDiv.appendChild(gridDays);

        const gridDates = document.createElement('div');
        gridDates.className = 'calendar-grid-dates';
        gridDates.setAttribute('role', 'grid');

        let firstDayIndex = new Date(year, month, 1).getDay();
        firstDayIndex = firstDayIndex === 0 ? 6 : firstDayIndex - 1;

        const totalDays = new Date(year, month + 1, 0).getDate();

        for (let i = 0; i < firstDayIndex; i++) {
            const emptyCell = document.createElement('span');
            emptyCell.className = 'calendar-day empty';
            gridDates.appendChild(emptyCell);
        }

        const today = new Date();
        today.setHours(0,0,0,0);

        for (let d = 1; d <= totalDays; d++) {
            const dateObj = new Date(year, month, d);
            const dateStr = formatDateString(dateObj);
            const dayCell = document.createElement('span');
            dayCell.className = 'calendar-day';
            dayCell.textContent = d;
            dayCell.dataset.date = dateStr;
            dayCell.setAttribute('role', 'gridcell');

            const isPast = dateObj < today;
            const isBooked = bookedDates.includes(dateStr);

            if (isPast) {
                dayCell.classList.add('disabled', 'past');
                dayCell.setAttribute('aria-disabled', 'true');
            } else if (isBooked) {
                dayCell.classList.add('disabled', 'booked');
                dayCell.setAttribute('aria-disabled', 'true');
                dayCell.setAttribute('aria-label', `${d} de ${t.months[month]}, reservado`);
            } else {
                dayCell.setAttribute('tabindex', '0');
                dayCell.setAttribute('aria-label', `${d} de ${t.months[month]}, disponible`);
                
                // Click handler
                dayCell.addEventListener('click', () => handleDayClick(dateObj));
                
                // Keyboard accessibility
                dayCell.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        handleDayClick(dateObj);
                    }
                });

                // Selection Highlights
                if (checkInDate && formatDateString(checkInDate) === dateStr) {
                    dayCell.classList.add('selected-start');
                    dayCell.setAttribute('aria-selected', 'true');
                } else if (checkOutDate && formatDateString(checkOutDate) === dateStr) {
                    dayCell.classList.add('selected-end');
                    dayCell.setAttribute('aria-selected', 'true');
                } else if (checkInDate && checkOutDate && dateObj > checkInDate && dateObj < checkOutDate) {
                    dayCell.classList.add('in-range');
                }
            }

            gridDates.appendChild(dayCell);
        }

        monthDiv.appendChild(gridDates);
        calendarContainer.appendChild(monthDiv);
    }

    function renderLegend() {
        let legendBox = document.getElementById('calendar-legend');
        if (!legendBox) {
            legendBox = document.createElement('div');
            legendBox.id = 'calendar-legend';
            legendBox.style.cssText = 'display:flex; justify-content:center; gap:1.5rem; font-size:0.8rem; color:var(--color-text-muted); margin-top:1rem; flex-wrap:wrap;';
            calendarContainer.parentNode.appendChild(legendBox);
        }
        legendBox.innerHTML = `
            <div style="display:flex; align-items:center; gap:0.4rem;">
                <span style="width:12px; height:12px; background-color:#FFFFFF; border:1px solid var(--color-border); border-radius:3px;"></span>
                <span>${t.legendAvailable}</span>
            </div>
            <div style="display:flex; align-items:center; gap:0.4rem;">
                <span style="width:12px; height:12px; background-color:#E2E8F0; border-radius:3px; position:relative;"><span style="position:absolute; top:50%; left:50%; width:100%; height:1px; background-color:#A0AEC0; transform:translate(-50%,-50%) rotate(-45deg);"></span></span>
                <span>${t.legendBooked}</span>
            </div>
            <div style="display:flex; align-items:center; gap:0.4rem;">
                <span style="width:12px; height:12px; background-color:var(--color-accent); border-radius:3px;"></span>
                <span>${t.legendSelected}</span>
            </div>
        `;
    }

    function handleDayClick(date) {
        if (!checkInDate || (checkInDate && checkOutDate)) {
            checkInDate = date;
            checkOutDate = null;
            clearValidationMessage();
        } else if (checkInDate && !checkOutDate) {
            if (date <= checkInDate) {
                checkInDate = date;
                checkOutDate = null;
            } else {
                if (isOverlap(checkInDate, date)) {
                    showValidationMessage(t.overlapError);
                    checkInDate = date;
                    checkOutDate = null;
                } else {
                    checkOutDate = date;
                }
            }
        }
        
        updateInputFields();
        renderCalendar();
        calculateAndDisplayPrice();
    }

    function isOverlap(start, end) {
        let temp = new Date(start);
        while (temp < end) {
            if (bookedDates.includes(formatDateString(temp))) {
                return true;
            }
            temp.setDate(temp.getDate() + 1);
        }
        return false;
    }

    function updateNavigationButtons() {
        const today = new Date();
        const minMonth = today.getMonth();
        const minYear = today.getFullYear();
        if (currentYear === minYear && currentMonth === minMonth) {
            prevBtn.disabled = true;
        } else {
            prevBtn.disabled = false;
        }
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', () => {
            currentMonth--;
            if (currentMonth < 0) {
                currentMonth = 11;
                currentYear--;
            }
            renderCalendar();
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            currentMonth++;
            if (currentMonth > 11) {
                currentMonth = 0;
                currentYear++;
            }
            renderCalendar();
        });
    }

    // Window resize handler to switch between 1 or 2 months responsively
    window.addEventListener('resize', renderCalendar);

    function updateInputFields() {
        if (checkInDate) {
            checkInInput.value = formatDateString(checkInDate);
        } else {
            checkInInput.value = '';
        }

        if (checkOutDate) {
            checkOutInput.value = formatDateString(checkOutDate);
        } else {
            checkOutInput.value = '';
        }
        
        if (checkInDate && checkOutDate) {
            const diffTime = Math.abs(checkOutDate - checkInDate);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            totalNightsInput.value = diffDays;
        } else {
            totalNightsInput.value = '';
            submitBtn.disabled = true;
        }
    }

    function calculateAndDisplayPrice() {
        if (!checkInDate || !checkOutDate) {
            priceDisplay.style.display = 'none';
            priceDisplay.innerHTML = '';
            totalPriceInput.value = '';
            return;
        }

        // Show Skeleton loading pulse
        priceDisplay.style.display = 'block';
        priceDisplay.innerHTML = `<div class="skeleton-loader"></div>`;
        submitBtn.disabled = true;

        const payload = {
            checkin: formatDateString(checkInDate),
            checkout: formatDateString(checkOutDate),
            adults: adultsSelect ? parseInt(adultsSelect.value) : 6,
            children: childrenSelect ? parseInt(childrenSelect.value) : 0,
            babies: babiesSelect ? parseInt(babiesSelect.value) : 0
        };

        fetch('../api/calculate_quote.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => {
            if (!res.ok) {
                return res.json().then(err => { throw new Error(err.error || t.connectionError); });
            }
            return res.json();
        })
        .then(data => {
            minStay = data.pricing.min_stay || 3;
            renderQuoteBreakdown(data);
        })
        .catch(err => {
            console.error(err);
            priceDisplay.innerHTML = `<div style="color:#DC2626; font-size:0.875rem; text-align:center; padding:1rem;">${err.message || t.connectionError}</div>`;
            submitBtn.disabled = true;
        });
    }

    function renderQuoteBreakdown(data) {
        const pr = data.pricing;
        totalPriceInput.value = pr.total;
        
        // Dynamic labels localized
        const taxInclusionText = pr.tourist_tax.included_in_total ? '' : ` (${t.ecoTaxPaidLater})`;
        
        priceDisplay.innerHTML = `
            <div class="budget-summary-card">
                <div class="budget-title">${t.budgetTitle}</div>
                
                <div class="budget-row">
                    <span>${data.nights} ${data.nights > 1 ? t.nights : t.night} x ${Math.round(pr.base_accommodation / data.nights)}€</span>
                    <span>${pr.base_accommodation}€</span>
                </div>
                
                <div class="budget-row">
                    <span>${t.cleaningFee}</span>
                    <span>${pr.cleaning_fee}€</span>
                </div>
                
                <div class="budget-row" style="color: #4B5563;">
                    <span>${t.ecoTax}${taxInclusionText}</span>
                    <span>${pr.tourist_tax.total}€</span>
                </div>
                
                <div class="budget-row total">
                    <span>${t.total}</span>
                    <span>${pr.total}€</span>
                </div>

                <div class="budget-row highlight">
                    <span>${t.deposit}</span>
                    <span>${pr.deposit_required}€</span>
                </div>

                <div class="budget-row pending" style="margin-top: 0.5rem; padding: 0 0.75rem;">
                    <span>${t.balance}</span>
                    <span>${pr.pending_balance}€</span>
                </div>
                
                <div class="budget-row pending" style="padding: 0 0.75rem; font-size: 0.8rem; font-style: italic;">
                    <span>${t.dueDate}</span>
                    <span>${pr.balance_due_date}</span>
                </div>

                <div class="budget-disclaimer">
                    ${t.disclaimer}
                </div>
            </div>
        `;

        // Update mobile bar price display
        const mobilePriceEl = document.getElementById('mobile-bar-price-val');
        if (mobilePriceEl) {
            mobilePriceEl.textContent = `Total: ${pr.total}€`;
        }

        submitBtn.disabled = false;
    }

    // Trigger budget recalculation when guests selector changes
    if (adultsSelect) adultsSelect.addEventListener('change', calculateAndDisplayPrice);
    if (childrenSelect) childrenSelect.addEventListener('change', calculateAndDisplayPrice);
    if (babiesSelect) babiesSelect.addEventListener('change', calculateAndDisplayPrice);

    // 2. Submit Form Handler
    if (bookingForm) {
        bookingForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Client side checks
            if (!checkInDate || !checkOutDate) {
                showValidationMessage(t.emptyFields);
                return;
            }

            statusMsg.textContent = t.submitting;
            statusMsg.className = 'form-status';
            statusMsg.style.display = 'block';
            submitBtn.disabled = true;
            submitBtn.textContent = t.submitting;

            // Compile Form parameters
            const formData = new FormData(bookingForm);
            
            // Format dates back for payload safety
            formData.set('checkin', formatDateString(checkInDate));
            formData.set('checkout', formatDateString(checkOutDate));

            fetch(bookingForm.getAttribute('action'), {
                method: 'POST',
                body: formData,
                credentials: 'include' // Needed to pass session cookies for CSRF verification
            })
            .then(res => {
                if (!res.ok) {
                    return res.json().then(err => { throw new Error(err.error || t.connectionError); });
                }
                return res.json();
            })
            .then(data => {
                if (data.success) {
                    // Populate success modal layout
                    if (modalReq) modalReq.textContent = data.request_number;
                    if (modalDates) modalDates.textContent = `${data.checkin} — ${data.checkout} (${data.nights} ${data.nights > 1 ? t.nights : t.night})`;
                    
                    const adultStr = payloadGuestLabel(data.total_guests, 'adult');
                    if (modalGuests) modalGuests.textContent = `${data.total_guests} ${adultStr}`;
                    if (modalTotal) modalTotal.textContent = `${data.estimated_total}€`;
                    if (modalEmail) modalEmail.textContent = data.guest_email;

                    // Open full success modal card
                    if (successModal) {
                        successModal.classList.add('open');
                    }

                    // Reset form and UI
                    bookingForm.reset();
                    checkInDate = null;
                    checkOutDate = null;
                    updateInputFields();
                    calculateAndDisplayPrice();
                    renderCalendar();
                    
                    statusMsg.style.display = 'none';
                    submitBtn.textContent = t.submitBtn;
                }
            })
            .catch(err => {
                console.error(err);
                statusMsg.textContent = err.message || t.connectionError;
                statusMsg.className = 'form-status error';
                submitBtn.disabled = false;
                submitBtn.textContent = t.submitBtn;
            });
        });
    }

    function payloadGuestLabel(count, type) {
        if (count === 1) return t[type];
        return t[type + 's'];
    }

    function showValidationMessage(msg) {
        const errorContainer = document.getElementById('calendar-error-msg');
        if (errorContainer) {
            errorContainer.textContent = msg;
            errorContainer.style.display = 'block';
        }
    }

    function clearValidationMessage() {
        const errorContainer = document.getElementById('calendar-error-msg');
        if (errorContainer) {
            errorContainer.textContent = '';
            errorContainer.style.display = 'none';
        }
    }
});
