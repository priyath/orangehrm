<?php
/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software; you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation; either
 * version 2 of the License, or (at your option) any later version.
 *
 * OrangeHRM is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program;
 * if not, write to the Free Software Foundation, Inc., 51 Franklin Street, Fifth Floor,
 * Boston, MA 02110-1301, USA
 */

/**
 * Class APIManagerService
 */
class APIManagerService
{
    /**
     * Grant type to get token
     */
    const GRANT_TYPE = 'client_credentials';
    /**
     * url for get addons from marketplace
     */
    const ADDON_LIST = '/api/v1/addon';
    /**
     * url for get access token from marketplace
     */
    const API_TOKEN = '/oauth/v2/token';
    /**
     * Buy now request URL
     * this has only one part
     */
    const BUY_NOW_REQUEST = '/api/v1/addon/';
    /**
     * prodduct send to Marketplace back-end
     */
    const PRODUCT = 'opensource';

    const HANDSHAKE_ENDPOINT = '/api/v1/handshake';

    private $apiClient = null;
    private $marketplaceService = null;
    public $clientId = null;
    public $clientSecret = null;
    public $baseURL = null;
    private $configService = null;

    /**
     * @return mixed
     * @throws CoreServiceException
     */
    public function getAddons()
    {
        $addons = $this->getAddonsFromMP();
        return $addons;
    }

    /**
     * @return mixed
     * @throws CoreServiceException
     */
    public function getAddonsFromMP()
    {
        $token = $this->getApiToken();
        $headers = array(
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token
        );
        $response = $this->getApiClient()->get(self::ADDON_LIST . '?version=' . $this->getVersion() .
            '&product=' . self::PRODUCT,
            array(
                'headers' => $headers
            )
        );
        if ($response->getStatusCode() == 200) {
            $body = json_decode($response->getBody(), true);
            return $body;
        }
    }

    /**
     * @param string $addonURL
     * @return mixed|string
     * @throws CoreServiceException
     */
    public function getDescription($addonURL)
    {
        return $this->getDescriptionFromMP($addonURL);
    }

    /**
     * @param  string $addonURL
     * @return mixed|string
     * @throws CoreServiceException
     */
    public function getDescriptionFromMP($addonURL)
    {
        $token = $this->getApiToken();
        $headers = array(
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token
        );
        $response = $this->getApiClient()->get($addonURL,
            array(
                'headers' => $headers
            )
        );
        if ($response->getStatusCode() == 200) {
            $body = json_decode($response->getBody(), true);
            return $body;
        }
    }

    /**
     * @return \GuzzleHttp\Client
     * @throws CoreServiceException
     */
    private function getApiClient()
    {
        if (!isset($this->apiClient)) {
            $this->apiClient = new GuzzleHttp\Client(['base_uri' => $this->getBaseURL()]);
        }
        return $this->apiClient;
    }

    /**
     * @return string
     * @throws CoreServiceException
     */
    public function getApiToken()
    {
        if (!$this->hasHandShook()) {
            $this->handShakeWithMarketPlace();
        }

        $headers = array('Accept' => 'application/json');
        $body = array(
            'grant_type' => self::GRANT_TYPE,
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
        );
        $response = $this->getApiClient()->post(self::API_TOKEN,
            array(
                'form_params' => $body,
                'headers' => $headers
            )
        );
        if ($response->getStatusCode() == 200) {
            $body = json_decode($response->getBody(), true);
            return $body['access_token'];
        }
    }

    /**
     * @return MarketplaceService
     */
    private function getMarketplaceService()
    {
        if (!isset($this->marketplaceService)) {
            $this->marketplaceService = new MarketplaceService();
        }
        return $this->marketplaceService;
    }

    /**
     * @return String
     * @throws CoreServiceException
     */
    private function getClientId()
    {
        if (!isset($this->clientId)) {
            $this->clientId = $this->getMarketplaceService()->getClientId();
        }
        return $this->clientId;
    }

    /**
     * @return String
     * @throws CoreServiceException
     */
    private function getClientSecret()
    {
        if (!isset($this->clientSecret)) {
            $this->clientSecret = $this->getMarketplaceService()->getClientSecret();
        }
        return $this->clientSecret;
    }

    /**
     * @return String
     * @throws CoreServiceException
     */
    private function getBaseURL()
    {
        if (!isset($this->baseURL)) {
            $this->baseURL = $this->getMarketplaceService()->getBaseURL();
        }
        return $this->baseURL;
    }

    /**
     * @param $addonURL
     * @return string
     * @throws CoreServiceException
     */
    private function getAddonFileFromMP($addonURL)
    {
        $token = $this->getApiToken();
        $headers = array(
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token
        );

        $tempAddonFile = $this->getTempAddonFile();
        $response = $this->getApiClient()->get($addonURL,
            array(
                'headers' => $headers,
                'sink' => $tempAddonFile
            )
        );
        if ($response->getStatusCode() == 200) {
            $contentDispositionHeader = $response->getHeader('Content-Disposition')[0];
            $addonFileName = explode("filename=", $contentDispositionHeader)[1];
            return $this->renameTempAddonFile($tempAddonFile, $addonFileName);
        }
    }

    /**
     * @param $addonURL
     * @param $addonDetail
     * @return string
     * @throws CoreServiceException
     */
    public function getAddonFile($addonURL, $addonDetail)
    {
        $addonFilePath = $this->getAddonFileFromMP($addonURL);
        $addonVersion = $addonDetail['version'];
        $isSuccess = $this->evaluateChecksum($addonFilePath, $addonVersion['checksumAlgo'], $addonVersion['checksum']);
        if (!$isSuccess) {
            throw new Exception('Downloaded file corrupted.', 1007);
        }
        return $addonFilePath;
    }

    /**
     * @param $data
     * @return string
     * @throws CoreServiceException
     */
    public function buyNowAddon($data)
    {
        $token = $this->getApiToken();
        $headers = array(
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token
        );
        $instanceID = $this->getConfigService()->getInstanceIdentifier();
        $requestData = array(
            'instanceId' => $instanceID,
            'companyName' => $data['companyName'],
            'contactEmail' => $data['contactEmail'],
            'contactNumber' => $data['contactNumber'],
        );
        $response = $this->getApiClient()->post(self::BUY_NOW_REQUEST . $data['buyAddonID'] . '/request',
            array(
                'headers' => $headers,
                'form_params' => $requestData
            )
        );
        if ($response->getStatusCode() == 200) {
            return 'Success';
        }
    }

    /**
     * @return ConfigService|mixed
     */
    public function getConfigService()
    {
        if (is_null($this->configService)) {
            $this->configService = new ConfigService();
            $this->configService->setConfigDao(new ConfigDao());
        }
        return $this->configService;
    }

    /**
     * Return hand shake status
     * @return bool
     * @throws CoreServiceException
     */
    public function hasHandShook()
    {
        if (is_string($this->getClientId())) {
            return true;
        }
        return false;
    }

    /**
     * Hand shake with Market Place and store OAuth2 client details
     * @return bool
     * @throws CoreServiceException
     */
    public function handShakeWithMarketPlace()
    {
        $instanceIdentifier = $this->getConfigService()->getInstanceIdentifier();
        $instanceIdentifierChecksum = $this->getConfigService()->getInstanceIdentifierChecksum();
        if (empty($instanceIdentifier)){
            $instanceIdentifierAndChecksum = $this->getMarketplaceService()->createInstanceIdentifierAndChecksum();
            $instanceIdentifier = $instanceIdentifierAndChecksum['instanceId'];
            $instanceIdentifierChecksum = $instanceIdentifierAndChecksum['instanceIdChecksum'];
        }
        $headers = array('Accept' => 'application/json');
        $body = array(
            'instanceId' => $instanceIdentifier,
            'checksum' => $instanceIdentifierChecksum
        );

        $response = $this->getApiClient()->post(self::HANDSHAKE_ENDPOINT,
            array(
                'form_params' => $body,
                'headers' => $headers
            )
        );
        if ($response->getStatusCode() == HttpResponseCode::HTTP_OK) {
            $body = json_decode($response->getBody(), true);
            $this->getMarketplaceService()->setClientId($body['clientId']);
            $this->getMarketplaceService()->setClientSecret($body['clientSecret']);

            $this->clientId = $this->getMarketplaceService()->getClientId();
            $this->clientSecret = $this->getMarketplaceService()->getClientSecret();

            return true;
        }
        return false;
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        include_once sfConfig::get('sf_root_dir') . "/../lib/confs/sysConf.php";
        $version = new sysConf();
        $version = $version->getVersion();
        return $version;
    }

    /**
     * @return bool|string
     */
    protected function getTempAddonFile()
    {
        return tempnam(sfConfig::get('sf_cache_dir'), 'mp_addon_');
    }

    /**
     * @param $tempFilePath
     * @param $fileName
     * @return null|string
     */
    protected function renameTempAddonFile($tempFilePath, $fileName)
    {
        $addonFilePath = sfConfig::get('sf_cache_dir') . DIRECTORY_SEPARATOR . $fileName;
        $status = copy($tempFilePath, $addonFilePath);
        return $status ? $addonFilePath : null;
    }

    /**
     * Evaluate the checksum value of the downloaded add-on file
     *
     * @param $filePath
     * @param $checksumAlgo
     * @param $checksum
     * @return bool
     */
    protected function evaluateChecksum($filePath, $checksumAlgo, $checksum)
    {
        $generatedHash = hash_file($checksumAlgo, $filePath);
        if (strcasecmp($generatedHash, $checksum) == 0) {
            return true;
        }
        return false;
    }
}
