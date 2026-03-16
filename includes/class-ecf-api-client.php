<?php
defined('ABSPATH') || exit;

use Ecfx\EcfDgii\Api\EcfApi;
use Ecfx\EcfDgii\Api\CompanyApi;
use Ecfx\EcfDgii\Configuration;
use Ecfx\EcfDgii\Model\EcfProgress;
use Ecfx\EcfDgii\Model\EcfResponse;
use Ecfx\EcfDgii\Model\Ecf31ECF;
use Ecfx\EcfDgii\Model\Ecf32ECF;
use Ecfx\EcfDgii\Model\Ecf34ECF;
use Ecfx\EcfDgii\EcfProcessingException;
use Ecfx\EcfDgii\EcfPollingTimeoutException;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class Ecf_Api_Client {

    private Configuration $config;
    private Client $http;

    public function __construct(?string $token = null, ?string $host = null) {
        woo_ecf_dgii_autoloader();
        $this->config = new Configuration();
        $this->config->setHost($host ?? Ecf_Settings::get_api_host());
        $this->config->setAccessToken($token ?? Ecf_Settings::get_api_token());
        $this->http = self::create_http_client();
    }

    /**
     * Submit an ECF document (fire-and-forget). Returns the initial response with messageId.
     */
    public function submit_ecf(Ecf31ECF|Ecf32ECF|Ecf34ECF $ecf): EcfResponse {
        $api = new EcfApi($this->http, $this->config);

        return match (true) {
            $ecf instanceof Ecf31ECF => $api->recepcionEcf31($ecf),
            $ecf instanceof Ecf32ECF => $api->recepcionEcf32($ecf),
            $ecf instanceof Ecf34ECF => $api->recepcionEcf34($ecf),
        };
    }

    /**
     * Poll for ECF result until Finished or Error.
     *
     * @throws EcfProcessingException If DGII returns an error
     * @throws EcfPollingTimeoutException If polling times out
     */
    public function poll_ecf(string $rnc, string $message_id): EcfResponse {
        $api = new EcfApi($this->http, $this->config);
        $max_attempts = (int) get_option(Ecf_Settings::OPTION_RETRY_MAX, 3) * 10;
        $interval = (int) get_option(Ecf_Settings::OPTION_RETRY_INTERVAL, 5);

        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
            sleep($interval);

            $results = $api->getEcfById($rnc, $message_id);

            if (empty($results)) {
                continue;
            }

            $latest = $results[0];
            $progress = $latest->getProgress();

            if ($progress === EcfProgress::FINISHED) {
                return $latest;
            }

            if ($progress === EcfProgress::ERROR) {
                throw new EcfProcessingException(
                    "ECF processing failed: "
                    . ($latest->getErrors() ?? $latest->getMensaje() ?? 'Unknown error'),
                    $latest
                );
            }
        }

        throw new EcfPollingTimeoutException(
            "ECF polling timed out after {$max_attempts} attempts for message {$message_id}"
        );
    }

    /**
     * Single check for ECF status (no polling loop). Returns null if not ready yet.
     */
    public function check_ecf(string $rnc, string $message_id): ?EcfResponse {
        $api = new EcfApi($this->http, $this->config);
        $results = $api->getEcfById($rnc, $message_id);

        if (empty($results)) {
            return null;
        }

        return $results[0];
    }

    /**
     * Fetch company data from the ECF SSD API.
     */
    public function get_company(string $rnc) {
        $api = new CompanyApi($this->http, $this->config);
        return $api->getCompanyByRnc($rnc);
    }

    private static function create_http_client(): Client {
        if (!WP_DEBUG) {
            return new Client();
        }

        $stack = HandlerStack::create();
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            $body = (string) $request->getBody();
            $request->getBody()->rewind();
            // phpcs:ignore QITStandard.PHP.DebugCode.DebugFunctionFound -- Intentional debug logging gated behind WP_DEBUG.
            error_log(sprintf(
                "[ECF API Request] %s %s\nHeaders: %s\nBody: %s",
                $request->getMethod(),
                $request->getUri(),
                json_encode($request->getHeaders()),
                $body
            ));
            return $request;
        }));
        $stack->push(Middleware::mapResponse(function (ResponseInterface $response) {
            $body = (string) $response->getBody();
            $response->getBody()->rewind();
            // phpcs:ignore QITStandard.PHP.DebugCode.DebugFunctionFound -- Intentional debug logging gated behind WP_DEBUG.
            error_log(sprintf(
                "[ECF API Response] %d\nBody: %s",
                $response->getStatusCode(),
                $body
            ));
            return $response;
        }));

        return new Client(['handler' => $stack]);
    }
}
