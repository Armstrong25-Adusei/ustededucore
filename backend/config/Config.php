<?php
// ============================================================
// EduCore/backend/config/Config.php  v7.0
// Application-wide constants.
// ============================================================
declare(strict_types=1);

class Config
{
    // ── Lecturer JWT ──────────────────────────────────────────
    public const JWT_SECRET          = 'CHANGE_THIS_TO_A_64_CHAR_RANDOM_HEX_STRING_IN_PRODUCTION';
    public const JWT_ALGORITHM       = 'HS256';
    public const JWT_TTL             = 28800;   // 8 hours
    public const JWT_TTL_SECONDS     = 28800;   // alias
    public const JWT_REMEMBER_TTL    = 2592000; // 30 days

    // ── Student JWT  (separate secret + longer TTL) ───────────
    public const STUDENT_JWT_SECRET  = 'CHANGE_THIS_STUDENT_SECRET_TO_DIFFERENT_64_CHAR_HEX';
    public const STUDENT_JWT_TTL     = 86400;   // 24 hours

    // ── Password hashing ──────────────────────────────────────
    public const BCRYPT_COST         = 12;

    // ── Student device policy ─────────────────────────────────
    public const STUDENT_MAX_DEVICES = 2;       // max active devices per student

    // ── QR / Session ──────────────────────────────────────────
    public const QR_DEFAULT_ROTATION_SECONDS = 20;
    public const SESSION_WINDOW_MINUTES      = 90;
    public const SESSION_EXTEND_MINUTES      = 10;

    // ── Geofence ──────────────────────────────────────────────
    public const GEOFENCE_MIN_RADIUS_M   = 20;
    public const GEOFENCE_MAX_RADIUS_M   = 200;
    public const GEOFENCE_DEFAULT_M      = 50;
    public const EARTH_RADIUS_M          = 6371000;

    // ── File uploads ──────────────────────────────────────────
    public const CSV_MAX_ROWS            = 2000;

    // ── Rate-limiting / Lockout ───────────────────────────────
    public const LOGIN_MAX_ATTEMPTS      = 5;
    public const LOGIN_LOCKOUT_MINUTES   = 15;

    // ── Sync ──────────────────────────────────────────────────
    public const SYNC_BATCH_SIZE         = 100;

    // ── Institution modes ─────────────────────────────────────
    public const MODE_SHS       = 'SHS';
    public const MODE_TERTIARY  = 'TERTIARY';

    // ── API ───────────────────────────────────────────────────
    // ── App URL (used by MailHelper for verification / reset links) ─────
    // Change to your live domain before deploying.
    public const APP_URL        = 'http://localhost/EduCore';

    // ── Mail ─────────────────────────────────────────────────────────
    public const MAIL_FROM      = 'noreply@educore.edu.gh';
    public const MAIL_FROM_NAME = 'EduCore';

    // ── API ──────────────────────────────────────────────────────────
    public const API_VERSION    = 'v1';
    public const APP_ENV        = 'development'; // change to 'production' on live server

    private function __construct() {}
}
