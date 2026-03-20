<?php
/**
 * EduCore — Geofence Helper
 *
 * Haversine formula for point-in-circle geofencing.
 * Used by AttendanceController for the Tertiary QR+Geofence path.
 */

declare(strict_types=1);

class GeofenceHelper {

    private const EARTH_RADIUS_M = 6_371_000; // metres

    /**
     * Calculate the great-circle distance between two GPS coordinates.
     *
     * @param float $lat1  Student device latitude
     * @param float $lon1  Student device longitude
     * @param float $lat2  Anchor (classroom) latitude
     * @param float $lon2  Anchor (classroom) longitude
     * @return float Distance in metres (rounded to 1 decimal)
     * @throws InvalidArgumentException
     */
    public static function haversineMetres(
        float $lat1, float $lon1,
        float $lat2, float $lon2
    ): float {
        if (!self::isValidCoordinates($lat1, $lon1) || !self::isValidCoordinates($lat2, $lon2)) {
            throw new InvalidArgumentException('Invalid GPS coordinates provided. Latitude must be -90 to 90, Longitude -180 to 180.');
        }

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round(self::EARTH_RADIUS_M * $c, 1);
    }

    /**
     * Returns true if the point is within the radius of the anchor.
     *
     * @param float $pointLat  Student's latitude
     * @param float $pointLon  Student's longitude
     * @param float $anchorLat Classroom anchor latitude
     * @param float $anchorLon Classroom anchor longitude
     * @param int   $radiusM   Geofence radius in metres (default from config)
     * @return bool
     * @throws InvalidArgumentException
     */
    public static function isWithinFence(
        float $pointLat,
        float $pointLon,
        float $anchorLat,
        float $anchorLon,
        int   $radiusM = 100
    ): bool {
        if ($radiusM < 0) {
            throw new InvalidArgumentException('Geofence radius cannot be negative.');
        }

        $distance = self::haversineMetres($pointLat, $pointLon, $anchorLat, $anchorLon);
        
        return $distance <= $radiusM;
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
}