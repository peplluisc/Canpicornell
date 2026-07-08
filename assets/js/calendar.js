document.addEventListener('DOMContentLoaded', function() {
    // Get language from HTML tag
    const lang = document.documentElement.getAttribute('lang') || 'es';
    
    // Translation dictionary
    const translations = {
        es: {
            months: ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'],
            days: ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'],
            selectCheckIn: 'Selecciona fecha de entrada',
            selectCheckOut: 'Selecciona fecha de salida',
            cleaningFee: 'Limpieza',
            nights: 'noches',
            night: 'noche',
            average: 'promedio',
            total: 'Total',
            overlapError: 'El rango seleccionado contiene días ocupados. Por favor, elige otras fechas.',
            pastError: 'No puedes reservar fechas pasadas.',
            invalidRange: 'La fecha de salida debe ser posterior a la de entrada.',
            priceBreakdown: 'Desglose de tarifa'
        },
        en: {
            months: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            days: ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'],
            selectCheckIn: 'Select check-in date',
            selectCheckOut: 'Select check-out date',
            cleaningFee: 'Cleaning fee',
            nights: 'nights',
            night: 'night',
            average: 'average',
            total: 'Total',
            overlapError: 'The selected range overlaps with booked dates. Please choose other dates.',
            pastError: 'You cannot select dates in the past.',
            invalidRange: 'Check-out date must be after check-in date.',
            priceBreakdown: 'Price Breakdown'
        },
        de: {
            months: ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
            days: ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'],
            selectCheckIn: 'Anreisedatum auswählen',
            selectCheckOut: 'Abreisedatum auswählen',
            cleaningFee: 'Reinigung',
            nights: 'Nächte',
            night: 'Nacht',
            average: 'Schnitt',
            total: 'Gesamt',
            overlapError: 'Der gewählte Zeitraum überschneidet sich mit belegten Tagen. Bitte andere Daten wählen.',
            pastError: 'Daten in der Vergangenheit können nicht ausgewählt werden.',
            invalidRange: 'Abreisedatum muss nach dem Anreisedatum liegen.',
            priceBreakdown: 'Preisübersicht'
        }
    };

    const t = translations[lang] || translations['es'];

    // Seasonal Pricing Matrix (configured to match Airbnb)
    // High season: Jun-Sep (5, 6, 7, 8 - 0-indexed)
    // Mid season: Apr, May, Oct (3, 4, 9)
    // Low season: Jan, Feb, Mar, Nov, Dec (0, 1, 2, 10, 11)
    const pricingMatrix = {
        high: 280, // June - Sept
        mid: 180,  // Apr, May, Oct
        low: 120   // Nov - March
    };
    
    const cleaningFee = 120;

    function getNightlyRate(date) {
        const month = date.getMonth();
        if (month >= 5 && month <= 8) {
            return pricingMatrix.high;
        } else if (month === 3 || month === 4 || month === 9) {
            return pricingMatrix.mid;
        } else {
            return pricingMatrix.low;
        }
    }

    // State Variables
    let bookedDates = []; // Array of dates strings 'YYYY-MM-DD'
    let checkInDate = null; // Date object
    let checkOutDate = null; // Date object
    let currentMonth = new Date().getMonth();
    let currentYear = new Date().getFullYear();

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

    if (!calendarContainer) return; // Exit if calendar not on page

    // Fetch availability from iCal proxy
    fetch('../api/ical_proxy.php')
        .then(response => response.json())
        .then(data => {
            // Expand date ranges into individual booked date strings
            bookedDates = expandBookedRanges(data);
            renderCalendar();
        })
        .catch(err => {
            console.error('Error fetching calendar:', err);
            renderCalendar(); // Render empty calendar on error
        });

    function expandBookedRanges(ranges) {
        const dates = new Set();
        ranges.forEach(range => {
            let start = new Date(range.start);
            let end = new Date(range.end);
            
            // Loop from start date to end date (excluding checkout day or including depending on how they want)
            // In vacation rentals, checkout day is free for check-in. So we block start to end-1.
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

    function renderCalendar() {
        calendarContainer.innerHTML = '';
        
        // Render 2 months side by side
        for (let i = 0; i < 2; i++) {
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

        // Title: Month + Year
        const header = document.createElement('div');
        header.className = 'calendar-month-header';
        header.innerHTML = `<h4>${t.months[month]} ${year}</h4>`;
        monthDiv.appendChild(header);

        // Day headers (Lu, Ma, Mi...)
        const gridDays = document.createElement('div');
        gridDays.className = 'calendar-grid-days';
        t.days.forEach(day => {
            const dayHeader = document.createElement('span');
            dayHeader.className = 'calendar-day-header';
            dayHeader.textContent = day;
            gridDays.appendChild(dayHeader);
        });
        monthDiv.appendChild(gridDays);

        // Calendar numbers grid
        const gridDates = document.createElement('div');
        gridDates.className = 'calendar-grid-dates';

        // Get first day of month (1-indexed day, 0 = Sunday in JS, we convert to Monday=0)
        let firstDayIndex = new Date(year, month, 1).getDay();
        firstDayIndex = firstDayIndex === 0 ? 6 : firstDayIndex - 1; // Align to Monday index 0

        const totalDays = new Date(year, month + 1, 0).getDate();

        // Empty spaces for previous month
        for (let i = 0; i < firstDayIndex; i++) {
            const emptyCell = document.createElement('span');
            emptyCell.className = 'calendar-day empty';
            gridDates.appendChild(emptyCell);
        }

        // Real day cells
        const today = new Date();
        today.setHours(0,0,0,0);

        for (let d = 1; d <= totalDays; d++) {
            const dateObj = new Date(year, month, d);
            const dateStr = formatDateString(dateObj);
            const dayCell = document.createElement('span');
            dayCell.className = 'calendar-day';
            dayCell.textContent = d;
            dayCell.dataset.date = dateStr;

            const isPast = dateObj < today;
            const isBooked = bookedDates.includes(dateStr);

            if (isPast) {
                dayCell.classList.add('disabled', 'past');
            } else if (isBooked) {
                dayCell.classList.add('disabled', 'booked');
            } else {
                // Interactive day
                dayCell.addEventListener('click', () => handleDayClick(dateObj));
                
                // Highlight range
                if (checkInDate && formatDateString(checkInDate) === dateStr) {
                    dayCell.classList.add('selected-start');
                } else if (checkOutDate && formatDateString(checkOutDate) === dateStr) {
                    dayCell.classList.add('selected-end');
                } else if (checkInDate && checkOutDate && dateObj > checkInDate && dateObj < checkOutDate) {
                    dayCell.classList.add('in-range');
                }
            }

            gridDates.appendChild(dayCell);
        }

        monthDiv.appendChild(gridDates);
        calendarContainer.appendChild(monthDiv);
    }

    function handleDayClick(date) {
        if (!checkInDate || (checkInDate && checkOutDate)) {
            // First click or resetting range
            checkInDate = date;
            checkOutDate = null;
            clearValidationMessage();
        } else if (checkInDate && !checkOutDate) {
            // Second click (checkout)
            if (date <= checkInDate) {
                // Clicking before check-in resets check-in to this date
                checkInDate = date;
                checkOutDate = null;
            } else {
                // Checking for booked date overlap in the selected range
                if (isOverlap(checkInDate, date)) {
                    showValidationMessage(t.overlapError);
                    checkInDate = date; // Reset to start here
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
        
        // Prevent going to past months
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
            submitBtn.disabled = false;
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

        let totalAccommodation = 0;
        let nights = 0;
        let tempDate = new Date(checkInDate);
        
        const nightsBreakdown = []; // Stores individual nightly rates for display

        while (tempDate < checkOutDate) {
            const rate = getNightlyRate(tempDate);
            totalAccommodation += rate;
            nights++;
            
            nightsBreakdown.push({
                date: formatDateString(tempDate),
                rate: rate
            });

            tempDate.setDate(tempDate.getDate() + 1);
        }

        const totalCost = totalAccommodation + cleaningFee;
        totalPriceInput.value = totalCost;

        // Render HTML for breakdown
        priceDisplay.style.display = 'block';
        
        const avgRate = Math.round(totalAccommodation / nights);
        
        priceDisplay.innerHTML = `
            <div class="price-breakdown-title">${t.priceBreakdown}</div>
            <div class="price-row">
                <span>${avgRate}€ x ${nights} ${nights > 1 ? t.nights : t.night}</span>
                <span>${totalAccommodation}€</span>
            </div>
            <div class="price-row">
                <span>${t.cleaningFee}</span>
                <span>${cleaningFee}€</span>
            </div>
            <hr class="price-divider">
            <div class="price-row total">
                <span>${t.total}</span>
                <span>${totalCost}€</span>
            </div>
        `;
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
