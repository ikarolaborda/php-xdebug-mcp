<?php

declare(strict_types=1);

namespace Tests\Unit\Dbgp;

use PhpXdebugMcp\Dbgp\CommandEncoder;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommandEncoderTest extends TestCase
{
    #[Test]
    public function it_emits_command_with_transaction_id_first(): void
    {
        $line = (new CommandEncoder())->encode('status', 7);
        self::assertSame('status -i 7', $line);
    }

    #[Test]
    public function it_quotes_values_containing_spaces(): void
    {
        $line = (new CommandEncoder())->encode('breakpoint_set', 9, ['t' => 'line', 'f' => 'file:///var/www/My App/index.php', 'n' => 12]);
        self::assertStringContainsString('-f "file:///var/www/My App/index.php"', $line);
        self::assertStringContainsString('-n 12', $line);
    }

    #[Test]
    public function it_escapes_quotes_and_backslashes_inside_values(): void
    {
        $line = (new CommandEncoder())->encode('property_get', 1, ['n' => '$x["a b"]']);
        self::assertStringContainsString('-n "$x[\\"a b\\"]"', $line);
    }

    #[Test]
    public function it_appends_base64_payload_after_double_dash(): void
    {
        $line = (new CommandEncoder())->encode('eval', 3, [], '$x + 1');
        self::assertStringEndsWith(' -- ' . base64_encode('$x + 1'), $line);
    }

    #[Test]
    public function it_skips_null_or_false_args(): void
    {
        $line = (new CommandEncoder())->encode('breakpoint_set', 1, ['t' => 'line', 'h' => null, 'r' => false, 'n' => 5]);
        self::assertSame('breakpoint_set -i 1 -t line -n 5', $line);
    }

    #[Test]
    public function it_rejects_an_invalid_command_name(): void
    {
        $this->expectException(AdapterException::class);
        (new CommandEncoder())->encode('bad command', 1);
    }

    #[Test]
    public function it_rejects_a_multi_letter_arg_flag(): void
    {
        $this->expectException(AdapterException::class);
        (new CommandEncoder())->encode('status', 1, ['xx' => 'value']);
    }
}
