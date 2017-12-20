<?php namespace DPD\Services;

use StdClass;
use SoapClient;
use Exception;
use SoapFault;

class DPDService extends SoapClient
{
    const PKG_NUMS_GEN_ERR_POLICY = "ALL_OR_NOTHING"; //STOP_ON_FIRST_ERROR, IGNORE_ERRORS
    const PKG_SPLB_GEN_ERR_POLICY = "STOP_ON_FIRST_ERROR"; // IGNORE_ERRORS
    const PKG_PROT_GEN_ERR_POLICY = "IGNORE_ERRORS";
    const PKG_PICK_GEN_ERR_POLICY = "IGNORE_ERRORS";

    protected $config = null;
    protected $sender = null;
    protected $sessionId = null;
    protected $apiVersion = 1;

    public function __construct($fid = null, $username = null, $password = null, $wsdl = null, $lang = null)
    {
        $config_file = __DIR__ .'/../config.php';
        $this->config = (file_exists($config_file)) ? include $config_file : new StdClass();

        // set default timezone
        date_default_timezone_set((isset($this->config->timezone) && $this->config->timezone != '' ? $this->config->timezone : 'Europe/Warsaw'));

        if (!is_null($fid)) $this->config->fid = $fid;
        if (!is_null($username)) $this->config->username = $username;
        if (!is_null($password)) $this->config->password = $password;
        if (!is_null($wsdl)) $this->config->wsdl = $wsdl;
        if (!is_null($lang)) $this->config->lang_code = $lang;

        // checking required params for api calls
        $this->_checkConfiguration();

        // set version of some api calls (used when available)
        $this->apiVersion = (isset($this->config->api_version) && (int)$this->config->api_version > 0) ? $this->config->api_version : 1;

        // call parent constructor
        parent::__construct($this->config->wsdl, [
            'trace' => (($this->config->debug) ? 1 : 0),
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
            'cache_wsdl' => WSDL_CACHE_NONE
        ]);

    }

    public function setConfig($key, $value)
    {
        $this->config->{$key} = $value;
        return $this->config->{$key};
    }

    public function getConfig($key)
    {
        return (isset($this->config->{$key})) ? $this->config->{$key} : null;
    }

    /**
     * Check configuration
     * @return type
     */
    private function _checkConfiguration()
    {
        // required
        if (!isset($this->config->fid) || empty($this->config->fid)) 
            throw new Exception("Config error! Param `fid` is not set correctly", 100);

        // required
        if (!isset($this->config->username) || empty($this->config->username)) 
            throw new Exception("Config error! Param `username` is not set correctly", 100);

        // required
        if (!isset($this->config->password) || empty($this->config->password)) 
            throw new Exception("Config error! Param `password` is not set correctly", 100);

        // required
        if (!isset($this->config->wsdl) || empty($this->config->wsdl)) 
            throw new Exception("Config error! Param `wsdl` is not set correctly", 100);

        // set default language code to 'PL' for some api methods in version >= 2
        if (!isset($this->config->lang_code) || empty($this->config->lang_code)) $this->config->lang_code = 'PL';

        // default debug
        if (!isset($this->config->debug) || empty($this->config->debug)) $this->config->debug = false;
    }

    /**
     * Get auth data
     * @return array
     */
    private function _authData()
    {
        return [
            'masterFid' => $this->config->fid,
            'login' => $this->config->username,
            'password' => $this->config->password,
        ];      
    }

    /**
     * Set sender data
     * @param array $sender 
     */
    public function setSender(array $sender)
    {
        $this->sender = $sender;
    }

    /**
     * Get sender data
     * @return array
     */
    public function getSender()
    {
        return $this->sender;
    }

    /**
     * Get session id
     * @return string
     */
    public function getSessionId()
    {
        return $this->sessionId;
    }   

    /**
     * Validate package
     * @param array $package 
     * @return boolean
     */
    public function validatePackage(array $package)
    {
        if (!isset($package['parcels']) || count($package['parcels']) == 0) 
            throw new Exception('Package validation error - missing `parcels` data in package', 101);  

        if (!isset($package['sender']) || count($package['sender']) == 0) 
            throw new Exception('Package validation error - missing `sender` data in package', 101);

        if (!isset($package['receiver']) || count($package['receiver']) == 0) 
            throw new Exception('Package validation error - missing `receiver` data in package', 101);

        if (!isset($package['payerType'])) 
            throw new Exception('Package validation error - missing `payerType` field in package', 101);

        $senderReq = ['name', 'address', 'city', 'countryCode', 'postalCode'];
        if (strtoupper($package['payerType']) == 'SENDER') $senderReq[] = 'fid';

        if (count(array_intersect_key(array_flip($senderReq), $package['sender'])) !== count($senderReq)) 
            throw new Exception('Package validation error - Sender requires the fields: ' . implode(',', $senderReq), 102);

        $receiverReq = ['name', 'address', 'city', 'countryCode', 'postalCode'];
        if (strtoupper($package['payerType']) == 'RECEIVER') $receiverReq[] = 'fid';

        if (count(array_intersect_key(array_flip($receiverReq), $package['receiver'])) !== count($receiverReq)) 
            throw new Exception('Package validation error - Receiver requires the fields: ' . implode(',', $receiverReq), 102);        

        $parcelReq = ['weight'];

        foreach($package['parcels'] as $parcel)
        {
            if (count(array_intersect_key(array_flip($parcelReq), $parcel)) !== count($parcelReq)) 
                throw new Exception('Package validation error - Parcel requires the fields: ' . implode(',', $parcelReq), 102);
        }       

        return true;
    }

    /**
     * Copies the arrays
     * @param array $array 
     * @return array
     */
    private function _arrayCopy(array $array)
    {
        $result = array();

        foreach($array as $key => $value)
        {
            $result[$key] = (is_array($value) ? $this->_arrayCopy($value) : $value);
        }

        return $result;
    }    

    /**
     * Prepare package
     * @param array $parcels 
     * @param array $receiver 
     * @param string $payer 
     * @param array $services 
     * @param string $ref 
     * @return object
     */
    public function createPackage(array $parcels, array $receiver, $payer = 'SENDER', array $services = [], $ref = '')
    {
        //validate
        if (count($parcels) == 0) 
            throw new Exception('Parcel data are missing', 101);

        if (count($receiver) == 0)
            throw new Exception('Receiver data are missing', 102);

        if (is_null($this->sender) || !is_array($this->sender) || count($this->sender) == 0)
            throw new Exception('Sender data are required', 103);   

        if (strlen($ref) > 27)
            throw new Exception('REF field exceeds 27 chars', 104);
        else
            $ref = str_split($ref, 9);

        if (strtoupper($payer) != 'SENDER' && strtoupper($payer) != 'RECEIVER')
            throw new Exception('Wrong payer type (SENDER or RECEIVER)', 105);

        $package = [
            'sender' => $this->_arrayCopy($this->getSender()),
            'payerType' => strtoupper($payer),
            'receiver' => $this->_arrayCopy($receiver), 
            'parcels' => $this->_arrayCopy($parcels),
            'services' => $this->_arrayCopy($services),
            'ref1' => (isset($ref[0]) ? $ref[0] : ''),
            'ref2' => (isset($ref[1]) ? $ref[1] : ''),
            'ref3' => (isset($ref[2]) ? $ref[2] : ''),          
        ];

        $this->validatePackage($package);

        // return validated data
        return $package;
    }

    /**
     * Send package
     * @param array $parcels 
     * @param array $receiver 
     * @param string $payer 
     * @param array $services 
     * @param string $ref 
     * @return object
     */
    public function sendPackage(array $parcels, array $receiver, $payer = 'SENDER', array $services = [], $ref = '')
    {

        $params = [
            'openUMLV1' => [
                'packages' => $this->createPackage($parcels, $receiver, $payer, $services, $ref),
            ],
            'pkgNumsGenerationPolicyV1' => self::PKG_NUMS_GEN_ERR_POLICY,
            'authDataV1' => $this->_authData(),
            'langCode'  => $this->config->lang_code
        ];

        $obj = new StdClass;
        $obj->method = 'generatePackagesNumbersV'. $this->apiVersion;

        try
        {

            // api method call
            $result = $this->__soapCall('generatePackagesNumbersV'. $this->apiVersion, [$params]);

            // debug results
            if (isset($this->config->debug) && $this->config->debug) 
                var_dump($result);

            // get status
            $status = ($this->apiVersion > 1) ? $result->return->Status : $result->return->status;

            // check status
            if ($status == 'OK')
            {

                $this->sessionId = ($this->apiVersion > 1) ? $result->return->SessionId : $result->return->sessionId;

                $obj->success = true;
                $obj->sender = $this->getSender();
                $obj->packageId = ($this->apiVersion > 1) ? $result->return->Packages->Package[0]->PackageId : $result->return->packages[0]->packageId;
                $obj->parcels = ($this->apiVersion > 1) ? $result->return->Packages->Package[0]->Parcels->Parcel : $result->return->packages[0]->parcels;
            
            } else $obj->success = false;

            return $obj;

        }
        catch(SoapFault $e)
        {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
            $this->log($this->__getLastRequest());
        }

    }

    /**
     * Send packages
     * @param array $packages 
     * @return object
     */
    public function sendPackages(array $packages)
    {

        if (count($packages) == 0) 
            throw new \Exception('`packages` argument is empty', 101);

        $params=[
            'openUMLV1' => [
                'packages' => $packages,
            ],
            'pkgNumsGenerationPolicyV1' => self::PKG_NUMS_GEN_ERR_POLICY,
            'authDataV1' => $this->_authData(),
            'langCode'  => $this->config->lang_code
        ];

        $obj = new \StdClass;
        $obj->method = 'generatePackagesNumbersV'. $this->apiVersion;       

        try
        {

            // api method call
            $result = $this->__soapCall('generatePackagesNumbersV'. $this->apiVersion, array($params));

            // debug results
            if (isset($this->config->debug) && $this->config->debug) 
                var_dump($result);            

            // get status
            $status = ($this->apiVersion > 1) ? $result->return->Status : $result->return->status;

            // check status
            if ($status == 'OK')
            {           
                $this->sessionId = ($this->apiVersion > 1) ? $result->return->SessionId : $result->return->sessionId;

                $packages = ($this->apiVersion > 1) ? $result->return->Packages->Package : $result->return->packages;
            
                $obj->sender = $this->getSender();

                $obj->packages = [];
                foreach($packages as $package)
                {
                    $obj->packages[] = $package;
                }

            } else $obj->success = false;

            return $obj;

        }
        catch(SoapFault $e)
        {
            $this->log($this->__getLastRequest());
            throw new Exception($e->getMessage(), 300);
        }

    }   

    /**
     * Add parcels to existing package
     * @param string $packageId 
     * @param array $parcels 
     * @return object
     */
    public function addParcelsToPackage($packageId, array $parcels)
    {

        if (is_null($packageId) || (int)$packageId == 0) throw new Exception('`packageId` value must be an integer > 0', 101);
        if (count($parcels) == 0) throw new Exception('`parcels` argument is empty', 101);

        $params = [
            'parcelsAppend' => [
                'packagesearchCriteria' => [
                    'packageId' => $packageId,
                ],
                'parcels' => $parcels,
            ],      
            'authDataV1' => $this->_authData(),
            //'langCode'    => $this->config->lang_code
        ];

        $obj = new StdClass;
        $obj->method = 'appendParcelsToPackageV1';      

        try
        {

            // api method call
            $result = $this->__soapCall('appendParcelsToPackageV1', [$params]);

            // debug results
            if (isset($this->config->debug) && $this->config->debug) 
                var_dump($result);            

            // get status
            $status = $result->return->status;

            if ($status == 'OK')
                $obj->success = true;
            else
                $obj->success = false;

            return $obj;

        }
        catch(SoapFault $e)
        {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
            $this->log($this->__getLastRequest());
        }       

    }   

    /**
     * Generate speedlabels by packages ids
     * @param array $ids 
     * @param array $pickupAddress 
     * @param string $shippingType 
     * @param string $fileFormat 
     * @param string $pageFormat 
     * @param string $labelType 
     * @return object
     */
    public function generateSpeedLabelsByPackageIds(array $ids, array $pickupAddress, $shippingType = 'DOMESTIC', $fileFormat = 'PDF', $pageFormat = 'A4', $labelType = 'BIC3')
    {

        $shippingType = strtoupper($shippingType);

        if (!in_array($shippingType, ['DOMESTIC', 'INTERNATIONAL']))
            throw new Exception('Wrong shipping type, should be DOMESTIC or INTERNATIONAL', 101);

        $packages = [];

        foreach($ids as $id)
        {
            $packages[] = [
                'packageId' => $id,
            ];
        }

        $refs = [
            'packages' => $packages,
            'sessionType' => $shippingType
        ];

        return $this->generateSpeedLabels($refs, $pickupAddress, $fileFormat, $pageFormat, $labelType);
    }


    /**
     * Generate speedlabels by session id
     * @param string $id 
     * @param array $pickupAddress 
     * @param string $shippingType 
     * @param string $fileFormat 
     * @param string $pageFormat 
     * @param string $labelType 
     * @return object
     */
    public function generateSpeedLabelsBySessionId($id, array $pickupAddress, $shippingType = 'DOMESTIC', $fileFormat = 'PDF', $pageFormat = 'A4', $labelType = 'BIC3')
    {

        $shippingType = strtoupper($shippingType);

        if (!in_array($shippingType, ['DOMESTIC', 'INTERNATIONAL']))
            throw new Exception('Wrong shipping type, should be DOMESTIC or INTERNATIONAL', 101);

        $refs = [
            'sessionId' => $id,
            'sessionType' => $shippingType
        ];

        return $this->generateSpeedLabels($refs, $pickupAddress, $fileFormat, $pageFormat, $labelType);
    }   

    /**
     * Generate speedlabels by refs array
     * @param array $refs 
     * @param array $pickupAddress 
     * @param string $fileFormat 
     * @param string $pageFormat 
     * @param string $labelType 
     * @return object
     */
    public function generateSpeedLabels(array $refs, array $pickupAddress, $fileFormat = 'PDF', $pageFormat = 'A4', $labelType = 'BIC3')
    {
        if (count($refs) == 0) 
            throw new Exception("Reference ids are required", 101);

        if (count($pickupAddress) == 0) 
            throw new Exception('Pickup address are required', 102);

        if (!in_array(strtoupper($fileFormat), ['PDF', 'ZPL', 'EPL']))
            throw new Exception('Wrong file format (available PDF, ZPL, EPL)', 103);       

        if (!in_array(strtoupper($pageFormat), ['A4', 'LBL_PRINTER']))
            throw new Exception('Wrong page format (available A4, LBL_PRINTER)', 104); 

        if (!in_array(strtoupper($labelType), ['BIC3', 'BIC3_EXTENDED1']))
            throw new Exception('Wrong label type (available BIC3, BIC3_EXTENDED1)', 105); 

        if (strtoupper($fileFormat) != 'PDF' && strtoupper($pageFormat) == 'A4')
            throw new Exception('Wrong page format. Should be LBL_PRINTER for ZPL and EPL file formats', 110);

        if (strtoupper($labelType) == 'BIC3_EXTENDED1' && strtoupper($pageFormat) != 'LBL_PRINTER')
            throw new Exception('Wrong page format. Should be LBL_PRINTER for BIC3_EXTENDED1 label type', 111);
            

        $params = [
            'dpdServicesParamsV1' => [
                'pickupAddress' => $pickupAddress,
                'policy' => self::PKG_SPLB_GEN_ERR_POLICY,
                'session' => $refs,
            ],
            'outputDocFormatV1' => strtoupper($fileFormat),
            'outputDocPageFormatV1' => strtoupper($pageFormat),
            'outputLabelTypeV2' => strtoupper($labelType),
            'authDataV1' => $this->_authData(),
        ];

        $obj = new StdClass;
        $obj->method = 'generateSpedLabelsV'. $this->apiVersion;

        try
        {
            // api call
            $result = $this->__soapCall('generateSpedLabelsV'. $this->apiVersion, [$params]);

            // debug results
            if (isset($this->config->debug) && $this->config->debug) 
                var_dump($result);            

            if ($result->return->session->statusInfo->status == 'OK') 
            {
                $obj->success = true;
                $obj->filedata = $result->return->documentData;
                $obj->fileformat = $fileFormat;
                $obj->pageformat = $pageFormat;             
                
            } else $obj->success = false;

            return $obj;

        } 
        catch(SoapFault $e)
        {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
            $this->log($this->__getLastRequest());
        }
    }

    /**
     * Generate protocol by packages ids 
     * @param array $ids 
     * @param array $pickupAddress 
     * @param string $shippingType 
     * @param string $pageFormat 
     * @return object
     */
    public function generateProtocolByPackageIds(array $ids, array $pickupAddress, $shippingType = 'DOMESTIC', $pageFormat = 'A4')
    {

        $shippingType = strtoupper($shippingType);

        if (!in_array($shippingType, ['DOMESTIC', 'INTERNATIONAL']))
            throw new Exception('Wrong shipping type, should be DOMESTIC or INTERNATIONAL', 101);

        $packages = [];

        foreach($ids as $id)
        {
            $packages[] = [
                'packageId' => $id,
            ];
        }

        $refs = [
            'packages' => $packages,
            'sessionType' => $shippingType
        ];

        return $this->generateProtocol($refs, $pickupAddress, $pageFormat);
    }

    /**
     * Generate protocol by session id
     * @param type $id 
     * @param array $pickupAddress 
     * @param string $shippingType 
     * @param string $pageFormat 
     * @return object
     */
    public function generateProtocolBySessionId($id, array $pickupAddress, $shippingType = 'DOMESTIC', $pageFormat = 'A4')
    {

        $shippingType = strtoupper($shippingType);

        if (!in_array($shippingType, ['DOMESTIC', 'INTERNATIONAL']))
            throw new Exception('Wrong shipping type, should be DOMESTIC or INTERNATIONAL', 101);

        $refs = [
            'sessionId' => $id,
            'sessionType' => $shippingType
        ];

        return $this->generateProtocol($refs, $pickupAddress, $pageFormat);
    }


    /**
     * Generate protocol by refs
     * @param array $refs 
     * @param array $pickupAddress 
     * @param string $pageFormat 
     * @return object
     */
    public function generateProtocol(array $refs, array $pickupAddress, $pageFormat = 'A4')
    {
        if (count($refs) == 0) 
            throw new Exception("Reference ids are required", 101);

        if (count($pickupAddress) == 0) 
            throw new Exception('Pickup address are required', 102);
        
        if (strtoupper($pageFormat) != 'A4' && strtoupper($pageFormat) == 'BIC3')   
            throw new Exception('Wrong page format (only A4 or BIC3)', 102);

        $params = [
            'dpdServicesParamsV1' => [
                'pickupAddress' => $pickupAddress,
                'policy' => self::PKG_PROT_GEN_ERR_POLICY,
                'session' => $refs,
            ],
            'outputDocFormatV1' => 'PDF',
            'outputDocPageFormatV1' => strtoupper($pageFormat),
            'authDataV1' => $this->_authData(),
        ];

        $obj = new StdClass;
        $obj->method = 'generateProtocolV1';

        try
        {
            // api call
            $result = $this->__soapCall('generateProtocolV1', [$params]);

            // debug results
            if (isset($this->config->debug) && $this->config->debug) 
                var_dump($result);            

            if ($result->return->session->statusInfo->status == 'OK')
            {
                $obj->success = true;
                $obj->documentId = $result->return->documentId;
                $obj->filedata = $result->return->documentData;
                $obj->packages = $result->return->session->packages;
                $obj->fileformat = 'pdf';
                $obj->pageformat = $pageFormat;             

            } else $obj->success = false;

            return $obj;

        } 
        catch(SoapFault $e)
        {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
            $this->log($this->__getLastRequest());
        }
    }

    /**
     * Pickup call
     * @param array $protocols 
     * @param type $pickupDate 
     * @param type $pickupTimeFrom 
     * @param type $pickupTimeTo 
     * @param array $contactInfo 
     * @param array $pickupAddress 
     * @return object
     */
    public function pickupRequest(array $protocols, $pickupDate, $pickupTimeFrom, $pickupTimeTo, array $contactInfo, array $pickupAddress)
    {
        if (count($protocols) == 0) 
            throw new Exception("Protocols ids are required", 101);

        if (!preg_match('/^([0-9][0-9][0-9][0-9])\-(0[1-9]|1[0-2])\-(0[1-9]|1[0-9]|2[0-9]|3[0-1])$/', $pickupDate))
            throw new Exception('Wrong pickupDate format (date format: 2017-01-31)', 102);

        if (!preg_match('/^(?:[0-1][0-9]|2[0-3])(?::[0-5][0-9])?$/', $pickupTimeFrom))  
            throw new Exception('Wrong pickupTimeFrom format (time format: 01:00)', 103);

        if (!preg_match('/^(?:[0-1][0-9]|2[0-3])(?::[0-5][0-9])?$/', $pickupTimeTo))    
            throw new Exception('Wrong pickupTimeTo format (time format: 01:00)', 104);    

        if (count($contactInfo) == 0) 
            throw new Exception("Contact info are required", 105);

        if (count($pickupAddress) == 0) 
            throw new Exception("Pickup address are required", 106);

        $protocolsIds = [];
        foreach ($protocols as $protocol) 
        {
            $protocolsIds[] = [
                'documentId' => $protocol
            ];
        }

        $params = [
            'dpdPickupParamsV1' => [
                'protocols' => $protocolsIds,
                'pickupDate' => $pickupDate,
                'pickupTimeFrom' => $pickupTimeFrom,
                'pickupTimeTo' => $pickupTimeTo,
                'contactInfo' => $contactInfo,
                'pickupAddress' => $pickupAddress,
                'policy' => self::PKG_PICK_GEN_ERR_POLICY,
            ],
            'authDataV1' => $this->_authData(),
        ];

        $obj = new StdClass;
        $obj->method = 'packagesPickupCallV1';

        try
        {
            // api call
            $result = $this->__soapCall('packagesPickupCallV1', [$params]);

            // debug results
            if (isset($this->config->debug) && $this->config->debug) 
                var_dump($result);            

            if (isset($result->return->prototocols)) // 'prototocols' wtf?
            {
                foreach ($result->return->prototocols as $protocol) 
                {
                    if ($protocol->statusInfo->status == 'OK')
                    {
                        $obj->protocols[] = $protocol;
                    }                   
                    
                }

                $obj->success = true;

            } else $obj->success = false;



            return $obj;

        } 
        catch(SoapFault $e)
        {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
            $this->log($this->__getLastRequest());
        }
    }

    /**
     * Method to check postcode
     * @param string $postCode 
     * @param string $countryCode 
     * @return object
     */
    public function checkPostCode($postCode, $countryCode = 'PL')
    {
        if ($postCode === '' || is_null($postCode)) 
            throw new Exception("Postcode are required", 101);

        $postCode = str_replace(['-', ' '], '', $postCode);

        $params = [
            'postalCodeV1' => [
                'countryCode' => $countryCode,
                'zipCode' => $postCode,
            ],
            'authDataV1' => $this->_authData(),
        ];

        $obj = new StdClass;
        $obj->method = 'findPostalCodeV1';

        try
        {
            
            $result = $this->__soapCall('findPostalCodeV1', [$params]);

            // debug results
            if (isset($this->config->debug) && $this->config->debug) 
                var_dump($result);            

            $obj->postcode = $postCode;
            $obj->status = $result->return->status;

            return $obj;

        } catch(SoapFault $e)
        {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
            $this->log($this->__getLastRequest());
        }

    }  

    /**
     * Method to check courier service availability
     * @param string $postCode 
     * @param string $countryCode 
     * @return object
     */
    public function checkCourierAvailability($postCode, $countryCode = 'PL')
    {
        if ($postCode === '' || is_null($postCode)) 
            throw new Exception("Postcode are required", 101);

        $postCode = str_replace(['-', ' '], '', $postCode);

        $params = [
            'senderPlaceV1' => [
                'countryCode' => $countryCode,
                'zipCode' => $postCode,
            ],
            'authDataV1' => $this->_authData(),
        ];

        $obj = new StdClass;
        $obj->method = 'getCourierOrderAvailabilityV1';

        try
        {
            
            $result = $this->__soapCall('getCourierOrderAvailabilityV1', [$params]);

            // debug results
            if (isset($this->config->debug) && $this->config->debug) 
                var_dump($result);            

            $obj->postcode = $postCode;
            $obj->status = $result->return->status;

            return $obj;

        } catch(SoapFault $e)
        {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
            $this->log($this->__getLastRequest());
        }

    }         

    /**
     * Log error data to file
     * @param type $logData 
     */
    public function log($logData)
    {
        if (isset($this->config->log_errors) && $this->config->log_errors)
        {
            if (!is_dir($this->config->log_path)) @mkdir($this->config->log_path, 0777, true);
            $log_file = $this->config->log_path . DIRECTORY_SEPARATOR . date('Y-m-d') .'.log';

            file_put_contents($log_file, "--- ". date('Y-m-d H:i:s') ."\r\n". $logData ."\r\n\r\n", FILE_APPEND);
        }
    }               

}