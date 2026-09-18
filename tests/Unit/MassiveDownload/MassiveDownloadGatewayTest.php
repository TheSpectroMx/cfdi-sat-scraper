<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\Tests\Unit\MassiveDownload;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PhpCfdi\CfdiSatScraper\Exceptions\SatHttpGatewayResponseException;
use PhpCfdi\CfdiSatScraper\MassiveDownload\MassiveDownloadGateway;
use PhpCfdi\CfdiSatScraper\SatHttpGateway;
use PhpCfdi\CfdiSatScraper\Tests\TestCase;

final class MassiveDownloadGatewayTest extends TestCase
{
    public function testIsASatHttpGateway(): void
    {
        $gateway = new MassiveDownloadGateway();
        $this->assertInstanceOf(SatHttpGateway::class, $gateway);
        $this->assertInstanceOf(ClientInterface::class, $gateway->getClient());
        $this->assertTrue($gateway->isCookieJarEmpty());
    }

    public function testConstructorUsesCookieJarFromClientConfig(): void
    {
        $cookieJar = new CookieJar();
        $client = new Client(['cookies' => $cookieJar]);

        $gateway = new MassiveDownloadGateway($client);

        $this->assertSame($client, $gateway->getClient());
        $this->assertSame($cookieJar, $gateway->getCookieJar());
    }

    public function testConstructorUsesGivenClientAndCookieJar(): void
    {
        $cookieJar = new CookieJar();
        $client = new Client();

        $gateway = new MassiveDownloadGateway($client, $cookieJar);

        $this->assertSame($client, $gateway->getClient());
        $this->assertSame($cookieJar, $gateway->getCookieJar());
    }

    public function testPostJsonSendsRawBodyAndContentType(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, [], '{"d":"ok"}')]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $gateway = new MassiveDownloadGateway(new Client(['handler' => $stack]));

        $contents = $gateway->postJson('https://example.com/endpoint', "{'Parametros':'x'}");

        $this->assertSame('{"d":"ok"}', $contents);
        $this->assertCount(1, $history);
        /** @var \Psr\Http\Message\RequestInterface $request */
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('application/json; charset=utf-8', $request->getHeaderLine('Content-Type'));
        $this->assertSame("{'Parametros':'x'}", strval($request->getBody()));
    }

    public function testPostFormSendsFormParams(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, [], 'ZIP-CONTENTS')]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $gateway = new MassiveDownloadGateway(new Client(['handler' => $stack]));

        $contents = $gateway->postForm('https://example.com/endpoint', ['foo' => 'bar']);

        $this->assertSame('ZIP-CONTENTS', $contents);
        $this->assertCount(1, $history);
        /** @var \Psr\Http\Message\RequestInterface $request */
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $this->assertSame('foo=bar', strval($request->getBody()));
    }

    public function testPostJsonWithEmptyResponseThrowsException(): void
    {
        $mock = new MockHandler([new Response(200, [], '')]);
        $gateway = new MassiveDownloadGateway(new Client(['handler' => HandlerStack::create($mock)]));

        $this->expectException(SatHttpGatewayResponseException::class);
        $gateway->postJson('https://example.com/endpoint', '{}');
    }
}
