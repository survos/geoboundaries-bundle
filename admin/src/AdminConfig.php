<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesAdmin;

final readonly class AdminConfig
{
    public function __construct(
        public string $adminDir,
        public string $api,
        public string $sourceDir,
        public string $outputDir,
        public string $geonamesDir,
        public string $hfAccount,
    ) {
    }

    public static function fromAdminDir(string $adminDir): self
    {
        return new self(
            adminDir: $adminDir,
            api: self::requiredEnv('GEOBOUNDARIES_API'),
            sourceDir: self::requiredEnv('GEOBOUNDARIES_SOURCE_DIR'),
            outputDir: self::requiredEnv('GEOBOUNDARIES_OUTPUT_DIR'),
            geonamesDir: self::requiredEnv('GEONAMES_DATA_DIR'),
            hfAccount: self::requiredEnv('HF_ACCOUNT'),
        );
    }

    private static function requiredEnv(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        if (!is_string($value) || '' === trim($value)) {
            throw new \RuntimeException(sprintf('Missing required environment variable %s in admin/.env', $name));
        }

        return trim($value);
    }
}
