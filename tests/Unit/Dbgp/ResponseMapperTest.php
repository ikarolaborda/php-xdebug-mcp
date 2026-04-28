<?php

declare(strict_types=1);

namespace Tests\Unit\Dbgp;

use PhpXdebugMcp\Dbgp\ResponseMapper;
use PhpXdebugMcp\Domain\Sessions\SessionState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponseMapperTest extends TestCase
{
    #[Test]
    public function it_classifies_packet_kind(): void
    {
        $m = new ResponseMapper();
        self::assertSame(ResponseMapper::PACKET_INIT, $m->classify('<init appid="1" idekey="x" language="PHP" protocol_version="1.0" fileuri="file:///t"/>'));
        self::assertSame(ResponseMapper::PACKET_RESPONSE, $m->classify('<response status="break" reason="ok" command="run" transaction_id="2"/>'));
        self::assertSame(ResponseMapper::PACKET_NOTIFY, $m->classify('<notify name="breakpoint_resolved" id="1"/>'));
        self::assertSame(ResponseMapper::PACKET_STREAM, $m->classify('<stream type="stdout">aGVsbG8=</stream>'));
    }

    #[Test]
    public function it_parses_init_packet_attributes(): void
    {
        $xml = '<init appid="42" idekey="ABC" session="cookie-1" thread="0" parent="" language="PHP" protocol_version="1.0" fileuri="file:///app/index.php"/>';
        $init = (new ResponseMapper())->parseInit($xml);
        self::assertSame('42', $init->appId);
        self::assertSame('ABC', $init->ideKey);
        self::assertSame('cookie-1', $init->sessionCookie);
        self::assertSame('PHP', $init->language);
        self::assertSame('file:///app/index.php', $init->fileUri);
    }

    #[Test]
    public function it_parses_response_with_errors_and_children(): void
    {
        $xml = '<response status="break" reason="ok" command="stack_get" transaction_id="3">'
             . '<stack level="0" type="file" filename="file:///app/index.php" lineno="12"/>'
             . '<error code="206"><message><![CDATA[no code on line]]></message></error>'
             . '</response>';
        $r = (new ResponseMapper())->parseResponse($xml);
        self::assertSame('break', $r['status']);
        self::assertSame('stack_get', $r['command']);
        self::assertSame(3, $r['transaction_id']);
        self::assertCount(2, $r['children']);
        self::assertSame(206, $r['errors'][0]['code']);
    }

    #[Test]
    public function it_decodes_base64_notify_body(): void
    {
        $xml = '<notify name="error" encoding="base64">' . base64_encode('boom') . '</notify>';
        $n = (new ResponseMapper())->parseNotify($xml);
        self::assertSame('error', $n['name']);
        self::assertSame('boom', $n['body']);
    }

    #[Test]
    public function it_returns_response_body_text_under_value_key(): void
    {
        // Regression: feature_get for breakpoint_types puts the value in the
        // response body, not in a child element. The reader must NOT fall
        // back to the `supported` attribute (which is just yes/no). See
        // https://xdebug.org/docs/dbgp .
        $xml = '<response xmlns="urn:debugger_protocol_v1" command="feature_get" feature_name="breakpoint_types" supported="1" transaction_id="11">line conditional exception call return watch</response>';
        $r = (new ResponseMapper())->parseResponse($xml);
        self::assertSame('line conditional exception call return watch', $r['value']);
        self::assertSame('1', $r['attrs']['supported'] ?? null);
    }

    #[Test]
    public function it_decodes_base64_response_body_when_encoding_attr_is_present(): void
    {
        $body = base64_encode('hello from engine');
        $xml = '<response xmlns="urn:debugger_protocol_v1" command="source" transaction_id="3" encoding="base64">' . $body . '</response>';
        $r = (new ResponseMapper())->parseResponse($xml);
        self::assertSame('hello from engine', $r['value']);
    }

    #[Test]
    public function it_maps_status_string_to_session_state_enum(): void
    {
        self::assertSame(SessionState::Break, ResponseMapper::statusFromString('break'));
        self::assertSame(SessionState::Stopped, ResponseMapper::statusFromString('stopped'));
        self::assertNull(ResponseMapper::statusFromString(null));
        self::assertNull(ResponseMapper::statusFromString('made-up-state'));
    }
}
