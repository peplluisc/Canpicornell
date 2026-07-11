-- Can Picornell Database Schema

-- 1. Table for Booking Requests
CREATE TABLE IF NOT EXISTS booking_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_number TEXT NOT NULL UNIQUE,
    checkin_date TEXT NOT NULL,
    checkout_date TEXT NOT NULL,
    adults INTEGER NOT NULL,
    children INTEGER NOT NULL,
    babies INTEGER NOT NULL,
    guest_name TEXT NOT NULL,
    guest_email TEXT NOT NULL,
    guest_phone TEXT NOT NULL,
    guest_country TEXT NOT NULL,
    preferred_language TEXT NOT NULL,
    contact_channel TEXT NOT NULL,
    arrival_time TEXT,
    special_requests TEXT,
    discovery_channel TEXT,
    amount_accommodation REAL NOT NULL,
    amount_cleaning REAL NOT NULL,
    amount_tax REAL NOT NULL,
    amount_total REAL NOT NULL,
    amount_deposit REAL NOT NULL,
    amount_balance REAL NOT NULL,
    balance_due_date TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'solicitud_recibida',
    stripe_session_id TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

-- Indexing for fast search and validation queries
CREATE INDEX IF NOT EXISTS idx_booking_dates ON booking_requests(checkin_date, checkout_date);
CREATE INDEX IF NOT EXISTS idx_booking_status ON booking_requests(status);
CREATE INDEX IF NOT EXISTS idx_booking_stripe_session ON booking_requests(stripe_session_id);

-- 2. Table for Booking History Logs
CREATE TABLE IF NOT EXISTS booking_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    notes TEXT,
    changed_at TEXT NOT NULL,
    FOREIGN KEY(booking_id) REFERENCES booking_requests(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_history_booking_id ON booking_history(booking_id);
