<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testPackageManifestIsPresent(): void
    {
        $manifest = dirname(__DIR__) . '/composer.json';
        $this->assertFileExists($manifest);

        $data = json_decode((string) file_get_contents($manifest), true);
        $this->assertIsArray($data);
        $this->assertSame('duppins/gcal-to-wpem', $data['name'] ?? null);
    }
}
