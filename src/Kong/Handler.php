<?php
declare(strict_types=1);

namespace PhpDockerIo\KongCertbot\Kong;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use PhpDockerIo\KongCertbot\Certificate;

/**
 * Handles communication with Kong given a bunch of certificates.
 *
 * @author PHPDocker.io
 */
class Handler
{
    /**
     * @var ClientInterface
     */
    private ClientInterface $guzzle;

    /**
     * @var Error[]
     */
    private array $errors = [];

    public function __construct(ClientInterface $guzzle)
    {
        $this->guzzle = $guzzle;
    }

    /**
     * Stores the given certificate in Kong.
     *
     * @param Certificate $certificate
     * @param string      $kongAdminUri
     *
     * @return bool
     */
    public function store(Certificate $certificate, string $kongAdminUri): bool
    {
        $domains = $certificate->getDomains();
        $mainDomain = array_shift($domains);
        $payload = [
            'headers' => [
                'accept' => 'application/json',
            ],
            'json'    => [
                'cert' => $certificate->getCert(),
                'key'  => $certificate->getKey(),
                'snis' => $domains,
            ],
        ];

        // From Kong 0.14, they finally fixed PUT as UPSERT
        // Any domain can be used on the endpoint, as they're aliased internally to the single
        // certificate object within Kong
        try {
            $this->guzzle->request(
                'put',
                \sprintf('%s/certificates/%s', $kongAdminUri, $mainDomain),
                $payload
            );
        } catch (BadResponseException $ex) {
            $request  = $ex->getRequest();
            $response = $ex->getResponse();
            $message  = $ex->getMessage();

            if ($response === null) {
                $message = 'empty response';
            }

            $responseCode = $ex->getResponse() !== null ? $ex->getResponse()->getStatusCode() : $ex->getCode();

            $summary = \sprintf(
                'Kong error %s: %s. Request method `%s`, headers %s, body %s',
                $responseCode,
                $message,
                $request->getMethod(),
                \json_encode($request->getHeaders()),
                \json_encode($request->getBody()->getContents())
            );

            $this->errors[] = new Error($responseCode, $certificate->getDomains(), $summary);
        }

        return \count($this->errors) === 0;
    }

    /**
     * @return Error[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
