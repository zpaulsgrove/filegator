<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Utils\Homedirs;
use Tests\TestCase;

/**
 * @internal
 */
class HomedirsTest extends TestCase
{
    public function testNormalizePathCollapsesSeparators()
    {
        // Leading/trailing/repeated separators and surrounding whitespace all
        // collapse so the same folder yields one canonical key.
        $this->assertSame('clientA', Homedirs::normalizePath('/clientA'));
        $this->assertSame('clientA', Homedirs::normalizePath('/clientA/'));
        $this->assertSame('clientA', Homedirs::normalizePath('clientA'));
        $this->assertSame('clientA', Homedirs::normalizePath('  /clientA/  '));
        $this->assertSame('a/b/c', Homedirs::normalizePath('/a//b///c/'));
        $this->assertSame('', Homedirs::normalizePath('/'));
        $this->assertSame('', Homedirs::normalizePath(''));
    }

    public function testCoversExactAndDescendant()
    {
        $this->assertTrue(Homedirs::covers('/clientA', '/clientA'));
        $this->assertTrue(Homedirs::covers('/clientA', '/clientA/2023'));
        $this->assertTrue(Homedirs::covers('/clientA/', '/clientA/2023/Q1'));
    }

    public function testRootCoversEverything()
    {
        $this->assertTrue(Homedirs::covers('/', '/clientA'));
        $this->assertTrue(Homedirs::covers('/', '/'));
        $this->assertTrue(Homedirs::covers('', '/anything/deep'));
    }

    public function testCoversRespectsSegmentBoundaries()
    {
        // The classic prefix trap: '/client' must NOT cover '/client2'.
        $this->assertFalse(Homedirs::covers('/client', '/client2'));
        $this->assertFalse(Homedirs::covers('/clientA', '/clientB'));
        // A child does not cover its parent.
        $this->assertFalse(Homedirs::covers('/clientA/2023', '/clientA'));
        // A non-root homedir never covers the storage root.
        $this->assertFalse(Homedirs::covers('/clientA', '/'));
    }

    public function testTrailingSeparatorIsIrrelevant()
    {
        $this->assertTrue(Homedirs::covers('/clientA/', '/clientA'));
        $this->assertTrue(Homedirs::covers('/clientA', '/clientA/'));
        $this->assertSame(
            Homedirs::normalizePath('/clientA'),
            Homedirs::normalizePath('/clientA/')
        );
    }

    public function testGrantingHomedirReturnsMostSpecific()
    {
        // Both '/' and '/clientA' cover the path; the deepest match wins and
        // the original (un-normalised) string is returned for display.
        $this->assertSame(
            '/clientA',
            Homedirs::grantingHomedir(['/', '/clientA'], '/clientA/2023')
        );
        $this->assertSame(
            '/clientA/2023',
            Homedirs::grantingHomedir(['/clientA', '/clientA/2023'], '/clientA/2023/Q1')
        );
    }

    public function testGrantingHomedirReturnsNullWhenNoneCover()
    {
        $this->assertNull(Homedirs::grantingHomedir(['/clientB', '/clientC'], '/clientA'));
        $this->assertNull(Homedirs::grantingHomedir([], '/clientA'));
    }

    public function testGrantingHomedirSkipsNonStrings()
    {
        $this->assertSame('/clientA', Homedirs::grantingHomedir([42, null, '/clientA'], '/clientA/x'));
    }
}
