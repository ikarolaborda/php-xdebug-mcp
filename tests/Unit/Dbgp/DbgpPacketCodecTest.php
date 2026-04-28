<?php

declare(strict_types=1);

namespace Tests\Unit\Dbgp;

use PhpXdebugMcp\Dbgp\DbgpPacketCodec;
use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DbgpPacketCodecTest extends TestCase
{
    #[Test]
    public function it_decodes_a_single_complete_frame(): void
    {
        $codec = new DbgpPacketCodec();
        $xml = '<response status="break"/>';
        $codec->append(strlen($xml) . "\x00" . $xml . "\x00");

        $frames = $codec->drain();

        self::assertSame([$xml], $frames);
        self::assertSame(0, $codec->pendingBytes());
    }

    #[Test]
    public function it_buffers_a_partial_frame_until_completion(): void
    {
        $codec = new DbgpPacketCodec();
        $xml = '<response status="break"/>';
        $bytes = strlen($xml) . "\x00" . $xml . "\x00";

        $codec->append(substr($bytes, 0, 4));
        self::assertSame([], $codec->drain());

        $codec->append(substr($bytes, 4));
        self::assertSame([$xml], $codec->drain());
    }

    #[Test]
    public function it_returns_multiple_frames_in_order(): void
    {
        $codec = new DbgpPacketCodec();
        $a = '<init/>';
        $b = '<response/>';
        $codec->append(strlen($a) . "\x00" . $a . "\x00" . strlen($b) . "\x00" . $b . "\x00");

        self::assertSame([$a, $b], $codec->drain());
    }

    #[Test]
    public function it_rejects_a_non_numeric_length_prefix(): void
    {
        $codec = new DbgpPacketCodec();
        $codec->append("abc\x00<x/>\x00");

        $this->expectException(AdapterException::class);

        try {
            $codec->drain();
        } catch (AdapterException $e) {
            self::assertSame(AdapterErrorCode::EngineProtocolError, $e->errorCode);
            throw $e;
        }
    }

    #[Test]
    public function it_rejects_a_frame_missing_the_trailing_nul(): void
    {
        $codec = new DbgpPacketCodec();
        $xml = '<response/>';
        $codec->append(strlen($xml) . "\x00" . $xml . 'X');

        $this->expectException(AdapterException::class);
        $codec->drain();
    }

    #[Test]
    public function it_encodes_an_ide_to_engine_command_with_a_trailing_nul(): void
    {
        $line = DbgpPacketCodec::encodeCommand('status -i 1');
        self::assertSame("status -i 1\x00", $line);
    }

    #[Test]
    public function it_refuses_to_encode_a_command_containing_nul(): void
    {
        $this->expectException(AdapterException::class);
        DbgpPacketCodec::encodeCommand("status -i 1\x00garbage");
    }
}
