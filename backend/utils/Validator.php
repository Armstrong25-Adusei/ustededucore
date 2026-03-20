<?php
// ============================================================
// EduCore/backend/utils/Validator.php
// Hardened Input validation with Type-Safe Getters
// ============================================================

declare(strict_types=1);

class Validator
{
    private array $errors = [];
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    /**
     * Helper to check if a field is present and not empty.
     */
    private function hasValue(string $field): bool
    {
        return isset($this->data[$field]) && $this->data[$field] !== '';
    }

    // ── Rule methods ─────────────────────────────────────────

    public function required(string $field, string $customMsg = ''): self
    {
        if (!$this->hasValue($field)) {
            $this->errors[$field] = $customMsg ?: "This field is required.";
        }
        return $this;
    }

    public function email(string $field): self
    {
        if ($this->hasValue($field) && !filter_var($this->data[$field], FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = "Please enter a valid email address.";
        }
        return $this;
    }

    public function numeric(string $field): self
    {
        if ($this->hasValue($field) && !is_numeric($this->data[$field])) {
            $this->errors[$field] = "This value must be a number.";
        }
        return $this;
    }

    public function coordinate(string $field, string $type = 'lat'): self
    {
        if (!$this->hasValue($field)) return $this;
        
        $val = (float)$this->data[$field];
        $isValid = ($type === 'lat') 
            ? ($val >= -90 && $val <= 90) 
            : ($val >= -180 && $val <= 180);

        if (!$isValid) {
            $this->errors[$field] = "Invalid GPS " . ($type === 'lat' ? 'latitude' : 'longitude');
        }
        return $this;
    }

    public function range(string $field, float $min, float $max): self
    {
        if ($this->hasValue($field)) {
            $val = (float)$this->data[$field];
            if ($val < $min || $val > $max) {
                $this->errors[$field] = "Must be between {$min} and {$max}.";
            }
        }
        return $this;
    }

    public function inList(string $field, array $allowed): self
    {
        if ($this->hasValue($field) && !in_array($this->data[$field], $allowed, true)) {
            $this->errors[$field] = "Selected value is invalid.";
        }
        return $this;
    }

    public function passwordStrength(string $field): self
    {
        if ($this->hasValue($field)) {
            $val = (string)$this->data[$field];
            if (strlen($val) < 8 || !preg_match('/[0-9]/', $val) || !preg_match('/[A-Z]/', $val)) {
                $this->errors[$field] = "Password must be 8+ chars with a number and uppercase letter.";
            }
        }
        return $this;
    }

    // ── Results & Sanitization ───────────────────────────────

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Returns raw data, but trimmed. 
     * IMPORTANT: We removed htmlspecialchars here. Escape on the VIEW layer, not DB layer.
     */
    public function get(string $field, mixed $default = null): mixed
    {
        $value = $this->data[$field] ?? $default;
        return is_string($value) ? trim($value) : $value;
    }

    public function getInt(string $field, int $default = 0): int
    {
        return $this->hasValue($field) ? (int)$this->data[$field] : $default;
    }

    public function getFloat(string $field, float $default = 0.0): float
    {
        return $this->hasValue($field) ? (float)$this->data[$field] : $default;
    }

    public function getBool(string $field): bool
    {
        return filter_var($this->data[$field] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}