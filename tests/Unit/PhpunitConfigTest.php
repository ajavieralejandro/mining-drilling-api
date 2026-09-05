<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PhpunitConfigTest extends TestCase
{
    public function test_phpunit_xml_does_not_embed_an_encryption_key(): void
    {
        $xml = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'phpunit.xml');

        $this->assertNotFalse($xml);
        $this->assertDoesNotMatchRegularExpression(
            '/name="APP_KEY"[^>]*value="base64:/',
            $xml,
        );
        $this->assertStringNotContainsString('name="APP_KEY"', $xml);
    }
}
