<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Dbgp;

use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PhpXdebugMcp\Domain\Sessions\InitMetadata;
use PhpXdebugMcp\Domain\Sessions\SessionState;
use SimpleXMLElement;

/**
 * Parses DBGp engine->IDE XML packets into structured data.
 *
 * The protocol uses xmlns="urn:debugger_protocol_v1" plus optional Xdebug
 * extensions in xmlns:xdebug="https://xdebug.org/dbgp/xdebug". We strip the
 * namespaces and return plain associative arrays.
 *
 * XML safety: parsing uses LIBXML_NONET to forbid network entity loading.
 * Entity expansion is disabled in PHP 8+ libxml builds by default; we still
 * pass LIBXML_NOENT off (the default) so external entities cannot be
 * substituted.
 */
final class ResponseMapper
{
    public const string PACKET_INIT = 'init';
    public const string PACKET_RESPONSE = 'response';
    public const string PACKET_NOTIFY = 'notify';
    public const string PACKET_STREAM = 'stream';

    public function classify(string $xml): string
    {
        $node = self::parseRoot($xml);
        $name = $node->getName();

        return match ($name) {
            'init' => self::PACKET_INIT,
            'response' => self::PACKET_RESPONSE,
            'notify' => self::PACKET_NOTIFY,
            'stream' => self::PACKET_STREAM,
            default => throw AdapterException::from(
                AdapterErrorCode::EngineProtocolError,
                'Unknown DBGp packet root element: ' . $name,
            ),
        };
    }

    public function parseInit(string $xml): InitMetadata
    {
        $node = self::parseRoot($xml);
        if ($node->getName() !== 'init') {
            throw AdapterException::from(AdapterErrorCode::EngineProtocolError, 'Expected <init> packet');
        }
        $a = self::attrs($node);

        return new InitMetadata(
            appId: (string) ($a['appid'] ?? ''),
            ideKey: $a['idekey'] ?? null,
            sessionCookie: $a['session'] ?? null,
            thread: $a['thread'] ?? null,
            parent: $a['parent'] ?? null,
            language: (string) ($a['language'] ?? 'PHP'),
            protocolVersion: (string) ($a['protocol_version'] ?? '1.0'),
            fileUri: (string) ($a['fileuri'] ?? ''),
        );
    }

    /** @return array{transaction_id:int, command:string, status:?string, reason:?string, attrs:array<string,string>, children:array<int,array<string,mixed>>, errors:list<array{code:int,message:?string}>} */
    public function parseResponse(string $xml): array
    {
        $node = self::parseRoot($xml);
        if ($node->getName() !== 'response') {
            throw AdapterException::from(AdapterErrorCode::EngineProtocolError, 'Expected <response> packet');
        }
        $a = self::attrs($node);
        $errors = [];
        foreach ($node->error ?? [] as $err) {
            $errors[] = [
                'code' => (int) ((string) ($err->attributes()['code'] ?? '0')),
                'message' => isset($err->message) ? (string) $err->message : null,
            ];
        }

        return [
            'transaction_id' => (int) ($a['transaction_id'] ?? 0),
            'command' => (string) ($a['command'] ?? ''),
            'status' => $a['status'] ?? null,
            'reason' => $a['reason'] ?? null,
            'attrs' => $a,
            'children' => self::nodeToArray($node)['children'] ?? [],
            'errors' => $errors,
        ];
    }

    /** @return array{name:string, attrs:array<string,string>, encoding:?string, body:?string} */
    public function parseNotify(string $xml): array
    {
        $node = self::parseRoot($xml);
        if ($node->getName() !== 'notify') {
            throw AdapterException::from(AdapterErrorCode::EngineProtocolError, 'Expected <notify> packet');
        }
        $a = self::attrs($node);
        $body = trim((string) $node);
        $encoding = $a['encoding'] ?? null;
        if ($encoding === 'base64' && $body !== '') {
            $decoded = base64_decode($body, true);
            if ($decoded !== false) {
                $body = $decoded;
            }
        }

        return [
            'name' => (string) ($a['name'] ?? ''),
            'attrs' => $a,
            'encoding' => $encoding,
            'body' => $body,
        ];
    }

    /** @return array{type:string, body:string} */
    public function parseStream(string $xml): array
    {
        $node = self::parseRoot($xml);
        if ($node->getName() !== 'stream') {
            throw AdapterException::from(AdapterErrorCode::EngineProtocolError, 'Expected <stream> packet');
        }
        $a = self::attrs($node);
        $body = (string) $node;
        if (($a['encoding'] ?? '') === 'base64' && $body !== '') {
            $decoded = base64_decode($body, true);
            if ($decoded !== false) {
                $body = $decoded;
            }
        }

        return [
            'type' => (string) ($a['type'] ?? 'stdout'),
            'body' => $body,
        ];
    }

    public static function statusFromString(?string $status): ?SessionState
    {
        if ($status === null) {
            return null;
        }

        return SessionState::tryFrom($status);
    }

    private static function parseRoot(string $xml): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $node = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
            if ($node === false) {
                $messages = [];
                foreach (libxml_get_errors() as $e) {
                    $messages[] = trim($e->message);
                }
                libxml_clear_errors();
                throw AdapterException::from(
                    AdapterErrorCode::EngineProtocolError,
                    'Failed to parse DBGp XML: ' . implode('; ', $messages),
                );
            }

            return $node;
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array<string, string> */
    private static function attrs(SimpleXMLElement $node): array
    {
        $out = [];
        foreach ($node->attributes() as $k => $v) {
            $out[(string) $k] = (string) $v;
        }
        foreach ($node->getDocNamespaces(true) as $prefix => $uri) {
            if ($prefix === '' || $uri === '') {
                continue;
            }
            foreach ($node->attributes($uri) as $k => $v) {
                $out[$prefix . ':' . (string) $k] = (string) $v;
            }
        }

        return $out;
    }

    /** @return array{tag:string, attrs:array<string,string>, value:?string, children:list<array<string,mixed>>} */
    private static function nodeToArray(SimpleXMLElement $node): array
    {
        $children = [];
        foreach ($node->children() as $child) {
            $children[] = self::nodeToArray($child);
        }
        $textContent = trim((string) $node);

        return [
            'tag' => $node->getName(),
            'attrs' => self::attrs($node),
            'value' => $textContent === '' ? null : $textContent,
            'children' => $children,
        ];
    }
}
