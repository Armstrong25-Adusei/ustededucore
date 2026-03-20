<?php
// ============================================================
// EduCore/backend/utils/Geofence.php
// Haversine-based GPS geofence validation
// Used by EduLink (Tertiary) attendance check-in
// ============================================================

declare(strict_types=1);

class Geofence
{
    /**
     * Calculates the great-circle distance between two GPS coordinates
     * using the Haversine formula.
     *
     * @param float $lat1  Student's latitude
     * @param float $lon1  Student's longitude
     * @param float $lat2  Class pin latitude
     * @param float $lon2  Class pin longitude
     * @return float       Distance in metres
     * @throws InvalidArgumentException
     */
    public static function distanceMetres(
        float $lat1, float $lon1,
        float $lat2, float $lon2
    ): float {
        // Enforce validation to prevent silent mathematical errors
        if (!self::isValidCoordinates($lat1, $lon1) || !self::isValidCoordinates($lat2, $lon2)) {
            throw new InvalidArgumentException('Invalid GPS coordinates provided. Latitude must be -90 to 90, Longitude -180 to 180.');
        }

        // Fallback to a local constant if Config is ever missing or fails to autoload
        $R = defined('Config::EARTH_RADIUS_M') ? Config::EARTH_RADIUS_M : 6371000.0;

        // Use standard ASCII variable names to prevent UTF-8 encoding parse errors
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $deltaPhi = deg2rad($lat2 - $lat1);
        $deltaLambda = deg2rad($lon2 - $lon1);

        $a = sin($deltaPhi / 2) ** 2
           + cos($phi1) * cos($phi2) * sin($deltaLambda / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $R * $c;
    }

    /**
     * Returns true if the student is within the class geofence.
     *
     * @param float $studentLat
     * @param float $studentLon
     * @param float $classLat
     * @param float $classLon
     * @param float $radiusMetres  Geofence radius (30–100m)
     * @return bool
     * @throws InvalidArgumentException
     */
    public static function isWithin(
        float $studentLat, float $studentLon,
        float $classLat,   float $classLon,
        float $radiusMetres
    ): bool {
        if (!self::isValidRadius($radiusMetres)) {
            throw new InvalidArgumentException('Geofence radius is outside allowed bounds.');
        }

        $distance = self::distanceMetres(
            $studentLat, $studentLon,
            $classLat,   $classLon
        );
        
        return $distance <= $radiusMetres;
    }

    /**
     * Validates that a geofence radius is within allowed bounds.
     *
     * @param float $radius  Proposed radius in metres
     * @return bool
     */
    public static function isValidRadius(float $radius): bool
    {
        $minRadius = defined('Config::GEOFENCE_MIN_RADIUS_M') ? Config::GEOFENCE_MIN_RADIUS_M : 30.0;
        $maxRadius = defined('Config::GEOFENCE_MAX_RADIUS_M') ? Config::GEOFENCE_MAX_RADIUS_M : 1000.0;

        return $radius >= $minRadius && $radius <= $maxRadius;
    }

    /**
     * Validates GPS coordinate ranges.
     *
     * @param float $lat  Latitude (-90 to 90)
     * @param float $lon  Longitude (-180 to 180)
     * @return bool
     */
    public static function isValidCoordinates(float $lat, float $lon): bool
    {
        return $lat >= -90.0 && $lat <= 90.0
            && $lon >= -180.0 && $lon <= 180.0;
    }

    /**
     * Returns a structured result with distance for logging/debugging.
     *
     * @return array{within: bool, distance_m: float, radius_m: float}
     * @throws InvalidArgumentException
     */
    public static function check(
        float $studentLat, float $studentLon,
        float $classLat,   float $classLon,
        float $radiusMetres
    ): array {
        // Validation is handled downstream in distanceMetres and here for the radius
        if (!self::isValidRadius($radiusMetres)) {
            throw new InvalidArgumentException('Geofence radius is outside allowed bounds.');
        }

        $distance = self::distanceMetres(
            $studentLat, $studentLon,
            $classLat,   $classLon
        );

        return [
            'within'     => $distance <= $radiusMetres,
            'distance_m' => round($distance, 2),
            'radius_m'   => $radiusMetres,
        ];
    }
}