<?php
declare(strict_types=1);

namespace Tests\PhpDockerIo\KongCertbot\Certbot;

use PhpDockerIo\KongCertbot\Certbot\Error;
use PhpDockerIo\KongCertbot\Certbot\Handler;
use PhpDockerIo\KongCertbot\Certbot\ShellExec;
use PhpDockerIo\KongCertbot\Certificate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HandlerTest extends TestCase
{
    /**
     * @var ShellExec|MockObject
     */
    private $shellExec;

    /**
     * @var Handler
     */
    private $handler;

    private $certsBasePath = __DIR__ . '/fixtures';

    public function setUp()
    {
        parent::setUp();

        $this->shellExec = $this->getMockBuilder(ShellExec::class)->getMock();

        $this->handler = new Handler($this->shellExec, $this->certsBasePath);
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function acquireCertificateHandlesEmptyListOfDomains(): void
    {
        $this->handler->acquireCertificate([], 'foo', true);
    }

    /**
     * @test
     * @dataProvider booleanDataProvider
     */
    public function acquireCertificateHandlesCerbotError(bool $testCert): void
    {
        $domains = ['foo.bar'];
        $email   = 'foo@bar';

        $execOutput = ['doom'];

        $expectedErrors = [
            new Error($execOutput, 1, $domains),
        ];

        $this->shellExec
            ->expects(self::once())
            ->method('exec')
            ->willReturn(false);

        $this->shellExec
            ->expects(self::once())
            ->method('getOutput')
            ->willReturn($execOutput);

        $exception = null;

        try {
            $this->handler->acquireCertificate($domains, $email, $testCert);
        } catch (\Throwable $ex) {
            $exception = $ex;
        }

        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertEquals($expectedErrors, $this->handler->getErrors());
    }

    /**
     * @test
     * @dataProvider commandDataProvider
     */
    public function acquireCertificateSucceedsWithTestCert(bool $testCert, string $expectedCommand): void
    {
        $domains = ['foo.bar', 'lala.foo.bar'];
        $email   = 'foo@bar';

        $expectedCertificate = new Certificate(
            \file_get_contents(\sprintf('%s/%s/fullchain.pem', $this->certsBasePath, $domains[0])),
            \file_get_contents(\sprintf('%s/%s/privkey.pem', $this->certsBasePath, $domains[0])),
            $domains
        );

        $this->shellExec
            ->expects(self::once())
            ->method('exec')
            ->with($expectedCommand)
            ->willReturn(true);

        $this->shellExec
            ->expects(self::never())
            ->method('getOutput');

        self::assertEquals($expectedCertificate, $this->handler->acquireCertificate($domains, $email, $testCert));
        self::assertEquals([], $this->handler->getErrors());
    }

    public function booleanDataProvider(): array
    {
        return [
            [true],
            [false],
        ];
    }

    public function commandDataProvider(): array
    {
        return [
            'test-cert' => [
                true,
                'certbot certonly --test-cert --agree-tos --standalone --preferred-challenges http -n -m foo@bar --expand -d foo.bar -d lala.foo.bar',
            ],

            'proper-cert' => [
                false,
                'certbot certonly  --agree-tos --standalone --preferred-challenges http -n -m foo@bar --expand -d foo.bar -d lala.foo.bar',
            ],
        ];
    }
}