<?php
declare(strict_types=1);

namespace Tests\PhpDockerIo\KongCertbot\Kong;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use PhpDockerIo\KongCertbot\Certificate;
use PhpDockerIo\KongCertbot\Kong\Error;
use PhpDockerIo\KongCertbot\Kong\Handler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class HandlerTest extends TestCase
{
    private const KONG_ADMIN_URI = 'http://foo/bar';

    /**
     * @var Handler
     */
    private $handler;

    /**
     * @var Client|MockObject
     */
    private $httpClient;

    public function setUp(): void
    {
        parent::setUp();

        $this->httpClient = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->getMock();

        $this->handler = new Handler($this->httpClient);
    }

    /**
     * @test
     */
    public function storeSucceedsWithOneDomain(): void
    {
        $domain      = 'foo.bar';
        $certificate = new Certificate('foo', 'bar', [$domain]);

        $response = $this->getMockBuilder(ResponseInterface::class)->getMock();

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('put', self::KONG_ADMIN_URI . '/certificates/' . $domain, [
                'headers' => [
                    'accept' => 'application/json',
                ],
                'json'    => [
                    'cert' => 'foo',
                    'key'  => 'bar',
                    'snis' => [],
                ],
            ])
            ->willReturn($response);

        self::assertTrue($this->handler->store($certificate, self::KONG_ADMIN_URI));
        self::assertEmpty($this->handler->getErrors());
    }

    /**
     * @test
     */
    public function storeSucceedsWithMultipleDomains(): void
    {
        $domains     = ['foo.bar', 'bar.foo', 'doom.bar'];
        $certificate = new Certificate('foo', 'bar', $domains);

        $response = $this->getMockBuilder(ResponseInterface::class)->getMock();

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('put', self::KONG_ADMIN_URI . '/certificates/foo.bar', [
                'headers' => [
                    'accept' => 'application/json',
                ],
                'json'    => [
                    'cert' => 'foo',
                    'key'  => 'bar',
                    'snis' => ['bar.foo', 'doom.bar'],
                ],
            ])
            ->willReturn($response);

        self::assertTrue($this->handler->store($certificate, self::KONG_ADMIN_URI));
        self::assertEmpty($this->handler->getErrors());
    }

    /**
     * @test
     * @dataProvider unknownKongErrorsDataProvider
     */
    public function storeHandlesUnknownKongError(int $statusCode): void
    {
        $domains     = ['foo.bar', 'bar.foo', 'doom.bar'];
        $certificate = new Certificate('foo', 'bar', $domains);

        $expectedErrorMessage = \sprintf(
            'Kong error %s: foobar. Request method `put`, headers {"content-type":"application\/json"}, body "[\"foo\"]"',
            $statusCode
        );

        $exceptionMessage = 'foobar';

        $headers = ['content-type' => 'application/json'];

        /** @var RequestInterface|MockObject $request */
        $request = $this->getMockBuilder(RequestInterface::class)->getMock();

        /** @var ResponseInterface|MockObject $response */
        $response = $this->getMockBuilder(ResponseInterface::class)->getMock();

        /** @var StreamInterface|MockObject $body */
        $body = $this->getMockBuilder(StreamInterface::class)->getMock();

        $response
            ->expects(self::any())
            ->method('getStatusCode')
            ->willReturn($statusCode);

        $response
            ->expects(self::any())
            ->method('getBody')
            ->willReturn($body);

        $body
            ->expects(self::any())
            ->method('rewind');

        $body
            ->expects(self::any())
            ->method('getContents')
            ->willReturn(\json_encode(['foo']));

        $request
            ->expects(self::any())
            ->method('getBody')
            ->willReturn($body);

        $request
            ->expects(self::any())
            ->method('getHeaders')
            ->willReturn($headers);

        $request
            ->expects(self::atLeast(1))
            ->method('getMethod')
            ->willReturn('put');

        $exception = new ClientException($exceptionMessage, $request, $response);

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->willThrowException($exception);

        $expectedErrors = [
            new Error($statusCode, $domains, $expectedErrorMessage),
        ];

        self::assertFalse($this->handler->store($certificate, self::KONG_ADMIN_URI));
        self::assertEquals($expectedErrors, $this->handler->getErrors());
    }

    public function unknownKongErrorsDataProvider(): array
    {
        return [
            'http 400' => [400],
            'http 500' => [500],
        ];
    }

    /**
     * @test
     */
    public function storeHadlesKongEmptyResponse(): void
    {
        $domains     = ['foo.bar', 'bar.foo', 'doom.bar'];
        $certificate = new Certificate('foo', 'bar', $domains);

        $expectedErrorMessage = \sprintf(
            'Kong error %s: empty response. Request method `put`, headers {"content-type":"application\/json"}, body "[\"foo\"]"',
            0
        );

        $exceptionMessage = 'foobar';

        $headers = ['content-type' => 'application/json'];

        /** @var RequestInterface|MockObject $request */
        $request = $this->getMockBuilder(RequestInterface::class)->getMock();

        /** @var StreamInterface|MockObject $body */
        $body = $this->getMockBuilder(StreamInterface::class)->getMock();

        $body
            ->expects(self::any())
            ->method('getContents')
            ->willReturn(\json_encode(['foo']));

        $request
            ->expects(self::any())
            ->method('getBody')
            ->willReturn($body);

        $request
            ->expects(self::any())
            ->method('getHeaders')
            ->willReturn($headers);

        $request
            ->expects(self::any())
            ->method('getMethod')
            ->willReturn('put');

        $exception = new ClientException($exceptionMessage, $request, null);

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->willThrowException($exception);

        $expectedErrors = [
            new Error(0, $domains, $expectedErrorMessage),
        ];

        self::assertFalse($this->handler->store($certificate, self::KONG_ADMIN_URI));
        self::assertEquals($expectedErrors, $this->handler->getErrors());
    }
}
