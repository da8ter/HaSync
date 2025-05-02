<?php

declare(strict_types=1);

class IconMapping
{
    private static $mappings = [];

    public static function getMapping(string $icon): string
    {
        if (isset(self::$mappings[$icon])) {
            return self::$mappings[$icon];
        }
        
        // Standard-Icon für unbekannte Icons
        return 'fa-question-circle';
    }

    public static function addMapping(string $haIcon, string $faIcon): void
    {
        self::$mappings[$haIcon] = $faIcon;
    }

    public static function loadMappings(): void
    {
        // Lade alle Mapping-Dateien
        $mappingFiles = [
            'Lighting.php',
            'Climate.php',
            'Sensors.php',
            'Security.php',
            'Multimedia.php',
            'Energy.php',
            'WindowsDoors.php',
            'Rooms.php',
            'Status.php',
            'Control.php',
            'Weather.php',
            'Devices.php',
            'Network.php',
            'Appliances.php',
            'Garden.php',
            'Access.php',
            'Health.php',
            'Transport.php',
            'Time.php',
            'Miscellaneous.php'
        ];

        foreach ($mappingFiles as $file) {
            $path = __DIR__ . '/' . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }
} 