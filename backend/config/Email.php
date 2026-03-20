<?php
class EmailConfig {
    public static function getResendApiKey() {
        return $_ENV['RESEND_API_KEY'] ?? getenv('RESEND_API_KEY');
    }

    public static function getFromEmail() {
        return $_ENV['RESEND_FROM_EMAIL'] ?? 'noreply@example.com';
    }

    public static function isConfigured() {
        return !empty(self::getResendApiKey());
    }
}