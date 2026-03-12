<?php
defined('ABSPATH') || exit;

use Ecfx\EcfDgii\Api\EcfApi;
use Ecfx\EcfDgii\Api\CompanyApi;
use Ecfx\EcfDgii\Configuration;
use Ecfx\EcfDgii\Model\ECF;
use Ecfx\EcfDgii\Model\EcfResponse;
use GuzzleHttp\Client;

class Ecf_Api_Client {

    private Configuration $config;
    private Client $http;

    private const TIPO_ECF_METHODS = [
        'E31' => 'recepcionEcf31',
        'E32' => 'recepcionEcf32',
        'E33' => 'recepcionEcf33',
        'E34' => 'recepcionEcf34',
    ];

    public function __construct(?string $token = null, ?string $host = null) {
        $this->config = new Configuration();
        $this->config->setHost($host ?? Ecf_Settings::get_api_host());
        $this->config->setAccessToken($token ?? Ecf_Settings::get_api_token());
        $this->http = new Client();
    }

    /**
     * Submit an ECF document. Returns the initial response with messageId.
     * Does NOT poll — the caller is responsible for checking status later.
     */
    public function submit_ecf(ECF $ecf, string $ecf_type): EcfResponse {
        $api = new EcfApi($this->http, $this->config);
        $method = self::TIPO_ECF_METHODS[$ecf_type]
            ?? throw new \InvalidArgumentException("Unsupported ECF type: {$ecf_type}");

        return $api->$method($ecf);
    }

    /**
     * Check the status of a previously submitted ECF.
     */
    public function get_ecf_status(string $rnc, string $message_id): array {
        $api = new EcfApi($this->http, $this->config);
        return $api->getEcfById($rnc, $message_id);
    }

    /**
     * Fetch company data from the ECF SSD API.
     */
    public function get_company(string $rnc) {
        $api = new CompanyApi($this->http, $this->config);
        return $api->getCompanyByRnc($rnc);
    }
}
