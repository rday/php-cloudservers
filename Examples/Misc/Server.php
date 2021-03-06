<?php

/**
 * If you are using autoloader (like you should!) remove this include
 */

include './Exception.php';

/**
 * PHP Cloud Server implementation for RackSpace (tm)
 *
 * THIS SOFTWARE IS PROVIDED "AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 *
 * IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO,
 * PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS;
 * OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY,
 * WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE
 * OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE,
 * EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @author Aleksey Korzun <al.ko@webfoundation.net>
 * @link http://github.com/AlekseyKorzun/php-cloudservers/
 * @link http://www.schematic.com
 * @version 0.1
 * @license bsd
 *
 * @todo back-up calls
 * @todo ip calls
 * @todo smart limit logic
 * @todo smart 24 hour token storage
 */

class Cloud_Server {
    const METHOD_AUTH = 1;
    const METHOD_POST = 2;
    const METHOD_DELETE = 3;
    const METHOD_PUT = 4;

    private $_apiUser;
    private $_apiKey;
    private $_apiToken;

    protected $_apiServerUri;
    protected $_apiAuthUri = 'https://auth.api.rackspacecloud.com/v1.0';

    protected $_apiBackup = array(
        'weekly' => array(
                'DISABLED',
                'SUNDAY',
                'MONDAY',
                'TUESDAY',
                'WEDNESDAY',
                'THURSDAY',
                'FRIDAY',
                'SATURDAY'),
        'daily' => array(
                'DISABLED',
                'H_0000_0200',
                'H_0200_0400',
                'H_0400_0600',
                'H_0600_0800',
                'H_0800_1000',
                'H_1000_1200',
                'H_1400_1600',
                'H_1600_1800',
                'H_1800_2000',
                'H_2000_2200',
                'H_2200_0000'));

    protected $_apiResource;
    protected $_apiAgent = 'PHP Cloud Server client';
    protected $_apiJson;
    protected $_apiResponse;
    protected $_apiResponseCode;
    protected $_apiServers = array();
    protected $_apiFlavors = array();
    protected $_apiImages = array();
    protected $_apiIPGroups = array();
    protected $_apiFiles = array();

    protected $_enableDebug = false;

    /**
     * Class constructor
     *
     * @param string $apiId user id that will be used for API
     * @param string $apiKey key that was generated by Rackspace
     * @return null
     */
    function __construct($apiId, $apiKey)
    {
        if (!$apiId || !$apiKey) {
            throw new Cloud_Exception('Please provide valid API credentials');
        }

        $this->_apiUser = $apiId;
        $this->_apiKey = $apiKey;
    }

    /**
     * Get authentication token
     *
     * @return mixed return authentication token or false on failure
     */
    public function getToken()
    {
        if (!empty($this->_apiToken)) {
           return $this->_apiToken;
        }

        return false;
    }

    /**
     * Set authentication token
     *
     * @param string $tokenId token you wish to set
     * @return null
     */
    public function setToken($tokenId)
    {
        $this->_apiToken = $tokenId;
    }

    /**
     * Perform authentication
     *
     * @return string returns recieved token
     */
    public function authenticate () {
        $this->_doRequest(self::METHOD_AUTH);
        return $this->_apiToken;
    }

    /**
     * Performs CURL requests (POST,PUT,DELETE,GET) required by API.
     *
     * @param string $method HTTP method that will be used for current request
     * @throws Cloud_Exception
     * @return null
     */
    private function _doRequest($method = null)
    {
        if (!$this->_apiToken && $method != self::METHOD_AUTH) {
            $this->_doRequest(self::METHOD_AUTH);
        }

        $curl = curl_init();

        $headers = array(
            sprintf("%s: %s", 'X-Auth-Token', $this->_apiToken),
            sprintf("%s: %s", 'Content-Type', 'application/json'));

        curl_setopt($curl, CURLOPT_URL, $this->_apiServerUri.$this->_apiResource);

        switch ($method) {
            case self::METHOD_POST:
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($this->_apiJson));
            break;
            case self::METHOD_PUT:
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
                array_push($headers, json_encode($this->_apiJson));
            break;
            case self::METHOD_DELETE:
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
            break;
            case self::METHOD_AUTH:
                $headers = array(
                    sprintf("%s: %s", 'X-Auth-User', $this->_apiUser),
                    sprintf("%s: %s", 'X-Auth-Key', $this->_apiKey));
                curl_setopt($curl, CURLOPT_URL, $this->_apiAuthUri);
                curl_setopt($curl, CURLOPT_HEADERFUNCTION, array(&$this,'_requestAuth'));
            break;
            default:
                // By default we request data using GET method
                $headers = array(
                    sprintf("%s: %s", 'X-Auth-Token', $this->_apiToken));
            break;
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_USERAGENT, $this->_apiAgent);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        // If debug is enabled we will output CURL data to screen
        if ($this->_enableDebug) {
            curl_setopt($curl, CURLOPT_VERBOSE, 1);
        }

        $this->_apiResponse = json_decode(curl_exec($curl));

        // Also for debugging purposes output response we got
        if ($this->_enableDebug) {
            var_dump($this->_apiResponse);
        }

        if (curl_errno($curl) > 0) {
            throw new Cloud_Exception('Unable to process this request');
        }

        // Retrieve returned HTTP code and throw exceptions when possible
        // error occurs
        $curlInfo = curl_getinfo($curl);
        if (!empty($curlInfo['http_code'])) {
            $this->_apiResponseCode = (int) $curlInfo['http_code'];
            switch ($this->_apiResponseCode) {
                case '401':
                    // User is no longer authorized, re-authenicate with API
                    $this->_doRequest(self::METHOD_AUTH);
                    $this->_doRequest($method);
                break;
                case '400':
                    throw new Cloud_Exception('Access is denied for the given request. Check your X-Auth-Token header. The token may have expired.');
                break;
                case '404':
                    throw new Cloud_Exception('The server has not found anything matching the Request URI.');
                break;
                case '403':
                    throw new Cloud_Exception('Access is denied for the given request.');
                break;
                case '413':
                    throw new Cloud_Exception('The server is refusing to process a request because the request entity is larger than the server is willing or able to process.');
                break;
                case '500':
                    throw new Cloud_Exception('The server encountered an unexpected condition which prevented it from fulfilling the request.');
                break;
            }
        }
        curl_close($curl);
    }

    /**
     * This method is used for processing authentication response.
     *
     * Basically we retrieve authentication token and server management
     * URI from returned headers.
     *
     * @param mixed $ch instance of curl
     * @param string $header
     * @return int leight of header
     */
    private function _requestAuth($ch, $header)
    {
        if (stripos($header, 'X-Auth-Token') === 0) {
            $this->_apiToken = trim(substr($header, strlen('X-Auth-Token')+1));
        }
        if (stripos($header, 'X-Server-Management-Url') === 0) {
            $this->_apiServerUri = trim(substr($header, strlen('X-Server-Management-Url')+1));
        }

        return strlen($header);
    }

    /**
     * Enables debugging output
     *
     * @return null
     */
    public function enableDebug()
    {
        $this->_enableDebug = true;
    }

    /**
     * Disable debugging output
     *
     * @return null
     */
    public function disableDebug()
    {
        $this->_enableDebug = false;
    }

    /**
     * Retrieves details regarding specific server flavor
     *
     * @param int $flavorId id of a flavor you wish to retrieve details for
     * @return mixed returns an array containing details for requested flavor or
     * false on failure
     */
    public function getFlavor ($flavorId)
    {
        $this->_apiResource = '/flavors/'. (int) $flavorId;
        $this->_doRequest();

        if ($this->_apiResponseCode && ($this->_apiResponseCode == '200'
                || $this->_apiResponseCode == '203')) {
            if ($this->_apiResponse->flavor) {
                $this->_apiFlavors[(int) $flavorId] = array(
                        'name' => (string) $this->_apiResponse->flavor->name,
                        'disk' => (string) $this->_apiResponse->flavor->disk,
                        'ram' => (string) $this->_apiResponse->flavor->ram);
                return $this->_apiFlavors[(int) $flavorId];
            }
        }

        return false;
    }

    /**
     * Retrieves all of the available server flavors
     *
     * @return mixed returns an array of available server configurations or
     * false on failure
     */
    public function getFlavors ()
    {
        $this->_apiResource = '/flavors';
        $this->_doRequest();

        if ($this->_apiResponseCode && ($this->_apiResponseCode == '200'
                    || $this->_apiResponseCode == '203')) {
            if ($this->_apiResponse->flavors) {
                $this->_apiFlavors = array();
                foreach ($this->_apiResponse->flavors as $flavor) {
                    $this->_apiFlavors[(int) $flavor->id]['name']
                        = (string) $flavor->name;
                }
                return $this->_apiFlavors;
            }
        }

        return false;
    }

    /**
     * Creates a new image of server
     *
     * @param string $name name of new image
     * @param int $serverId server id for which you wish to base this image on
     * @return mixed returns an array details of created image or false on failure
     */
    public function createImage ($name, $serverId)
    {
        $this->_apiResource = '/images';
        $this->_apiJson = array ('image' => array(
                                    'serverId' => (int) $serverId,
                                    'name' => (string) $name));
        $this->_doRequest(self::METHOD_POST);

        if ($this->_apiResponseCode && $this->_apiResponseCode == '200') {
            if (property_exists($this->_apiResponse, 'image')) {
                $this->_apiImages[(int) $this->_apiResponse->image->id] = array(
                      'serverId' => (int)$this->_apiResponse->image->serverId,
                      'name' => (string) $this->_apiResponse->image->name,
                      'id' => (int) $this->_apiResponse->image->id);
                return $this->_apiImages[(int) $this->_apiResponse->image->id];
            }
        }

        return false;
    }

    /**
     * Retrieves details of specific image
     *
     * @param int $imageId id of image you wish to retrieve details for
     * @return array details of requested image
     */
    public function getImage ($imageId)
    {
        $this->_apiResource = '/images/'. (int) $imageId;
        $this->_doRequest();

        if ($this->_apiResponseCode && ($this->_apiResponseCode == '200'
                || $this->_apiResponseCode == '203')) {
            if ($this->_apiResponse->image) {
                $this->_apiImages[(int) $imageId] = array(
                        'name' => (string) $this->_apiResponse->image->name,
                        'status' => (string) $this->_apiResponse->image->status,
                        'created' => (string) $this->_apiResponse->image->created,
                        'updated' => (string) $this->_apiResponse->image->updated);
                return $this->_apiImages[(int) $imageId];
            }
        }
    }

    /**
     * Retrieves all of the available images
     *
     * @return mixed returns array of available images or false on failure
     */
    public function getImages ()
    {
        $this->_apiResource = '/images';
        $this->_doRequest();

        if ($this->_apiResponseCode && ($this->_apiResponseCode == '200'
                || $this->_apiResponseCode == '203')) {
            if ($this->_apiResponse->images) {
                // Reset internal image array
                $this->_apiImages = array();
                foreach($this->_apiResponse->images as $image) {
                    $this->_apiImages[(int) $image->id]['name'] = (string) $image->name;
                }
                return $this->_apiImages;
            }
        }

        return false;
    }

    /**
     * Retrieves configuration details for specific server
     *
     * @return mixed array containing server details or false on failure
     */
    public function getServer ($serverId)
    {
        $this->_apiResource = '/servers/'. (int) $serverId;
        $this->_doRequest();

        if ($this->_apiResponseCode && $this->_apiResponseCode == '200') {
            if ($this->_apiResponse->server) {
                $this->_apiServers[(int) $this->_apiResponse->server->id] =
                    array('id' => (int) $this->_apiResponse->server->id,
                           'name' => (string) $this->_apiResponse->server->name,
                           'imageId' => (int) $this->_apiResponse->server->imageId,
                           'flavorId' => (int) $this->_apiResponse->server->flavorId,
                           'hostId' => (string) $this->_apiResponse->server->hostId,
                           'progress' => (int) $this->_apiResponse->server->progress,
                           'status' => (string) $this->_apiResponse->server->status,
                           'addresses' => array(),
                           'metadata' => array());
                if (property_exists($this->_apiResponse->server, 'sharedIpGroupId')) {
                    $this->_apiServers[(int) $this->_apiResponse->server->id]['sharedIpGroupId']
                        = (string) $this->_apiResponse->server->sharedIpGroupId;
                }
                if (property_exists($this->_apiResponse->server, 'addresses')) {
                    if (property_exists($this->_apiResponse->server->addresses, 'public')) {
                        foreach ($this->_apiResponse->server->addresses->public as $public) {
                            $this->_apiServers[(int) $this->_apiResponse->server->id]['addresses']['public'][]
                                = (string) $public;
                        }
                    }
                    if (property_exists($this->_apiResponse->server->addresses, 'private')) {
                        foreach ($this->_apiResponse->server->addresses->private as $private) {
                            $this->_apiServers[(int) $this->_apiResponse->server->id]['addresses']['private'][]
                                = (string) $private;
                        }
                    }
                }
                if (property_exists($this->_apiResponse->server, 'metadata')) {
                    foreach ($this->_apiResponse->server->metadata as $key => $value) {
                        $this->_apiServers[(int) $this->_apiResponse->server->id]['metadata'][(string) $key]
                            = (string) $value;
                    }
                }
                return $this->_apiServers[(int) $this->_apiResponse->server->id];
            }
        }

        return false;
    }

    /**
     * Retrieves currently available servers
     *
     * @return mixed array containing current servers or false on failure
     */
    public function getServers ()
    {
        $this->_apiResource = '/servers';
        $this->_doRequest();

        if ($this->_apiResponseCode && $this->_apiResponseCode == '200') {
            if (!empty($this->_apiResponse->servers)) {
                // Reset internal server array
                $this->_apiServers = array();
                foreach ($this->_apiResponse->servers as $server) {
                    $this->_apiServers[(int) $server->id]['name'] = (string) $server->name;
                }

                return $this->_apiServers;
            }
        }

        return false;
    }

    /**
     * Retrieves current API limits
     *
     * @return mixed object containing current limits or false on failure
     */
    public function getLimits ()
    {
        $this->_apiResource = '/limits';
        $this->_doRequest();

        if ($this->_apiResponseCode && $this->_apiResponseCode == '200') {
            return $this->_apiResponse;
        }

        return false;
    }

    public function shareServerIp ($serverId, $serverIp, $groupId, $doConfigure = false)
    {
        $this->_apiResource = '/servers/'. (int) $serverId .'/ips/public/'. $serverIp;
        $this->_apiJson = array ('shareIp' => array(
                                    'sharedIpGroupId' => (int) $groupId,
                                    'configureServer' => (bool) $doConfigure));
        $this->_doRequest(self::METHOD_PUT);

        if ($this->_apiResponseCode && $this->_apiResponseCode == '201') {
            return true;
        }

        return false;
    }

    /**
     * Removes a shared server IP from server
     * @param int $serverId id of server this action is peformed for
     * @param string $serverIp IP you wish to unshare
     * @return bool returns true on success or false on failure
     */
    public function unshareServerIp ($serverId, $serverIp)
    {
        $this->_apiResource = '/servers/'. (int) $serverId .'/ips/public/'. (string) $serverIp;
        $this->_doRequest(self::METHOD_DELETE);

        if ($this->_apiResponseCode && $this->_apiResponseCode == '202') {
            return true;
        }

        return false;
    }

    /**
     * Get IP's assigned to server
     *
     * @param int $serverId id of server you wish to retrieve ips for
     * @param string $type type of addresses to retrieve could be private/public or
     * false for both types.
     * @return mixed returns array of addresses or false of failure
     */
    public function getServerIp ($serverId, $type = false)
    {
       $this->_apiResource = '/servers/'. (int) $serverId .'/ips'. ($type ? '/'. $type : '');
       $this->_doRequest();

        if ($this->_apiResponseCode && ($this->_apiResponseCode == '200'
                || $this->_apiResponseCode == '203')) {
            if ($this->_apiResponse->addresses) {
                if ($this->_apiResponse->addresses->public) {
                    unset($this->_apiServers[(int) $serverId]['addresses']['public']);
                    foreach ($this->_apiResponse->addresses->public as $public) {
                        $this->_apiServers[(int) $serverId]['addresses']['public'][]
                            = (string) $public;
                    }
                }
                if ($this->_apiResponse->addresses->private) {
                    unset($this->_apiServers[(int) $serverId]['addresses']['private']);
                    foreach ($this->_apiResponse->addresses->private as $private) {
                        $this->_apiServers[(int) $serverId]['addresses']['private'][]
                            = (string) $private;
                    }
                }

                return $this->_apiServers[(int) $serverId]['addresses'];
            }
        }

        return false;
    }

    /**
     * Add a server to shared ip group
     *
     * @param string $name name of shared ip group you are creating
     * @param int $serverId id of server you wish to add to this group
     * @return mixed returns id of created shared ip group or false on failure
     */
    public function addSharedIpGroup ($name, $serverId)
    {
        $this->_apiResource = '/shared_ip_groups';
        $this->_apiJson = array ('sharedIpGroup' => array(
                                    'name' => (string) $name,
                                    'server' => (int) $serverId));
        $this->_doRequest(self::METHOD_POST);

        if ($this->_apiResponseCode && $this->_apiResponseCode == '201') {
            if (property_exists($this->_apiResponse, 'sharedIpGroup')) {
                $this->_apiServers[(int) $serverId]['sharedIpGroupId']
                       = (int) $this->_apiResponse->sharedIpGroup->id;
                return $this->_apiServers[(int) $serverId]['sharedIpGroupId'];
            }
        }

        return false;
    }

    /**
     * Delete shared IP group
     *
     * @param int $groupId id of group you wish to delete
     * @return bool returns true on success and false on failure
     */
    public function deleteSharedIpGroup ($groupId)
    {
        $this->_apiResource = '/shared_ip_groups/'. (int) $groupId;
        $this->_doRequest(self::METHOD_DELETE);

        if ($this->_apiResponseCode && $this->_apiResponseCode == '204') {
            unset($this->_apiIPGroups[(int) $groupId]);
            return true;
        }

        return false;
    }

    /**
     * Retrieve details for specific IP group
     *
     * @param int $groupId id of specific shared group you wish to retrieve details
     * for
     * @return mixed returns array containing details about requested group or false on failure
     */
    public function getSharedIpGroup ($groupId)
    {
        $this->_apiResource = '/shared_ip_groups/'. (int) $groupId;
        $this->_doRequest();

        if ($this->_apiResponseCode && ($this->_apiResponseCode == '200'
                || $this->_apiResponseCode == '203')) {
             if (property_exists($this->_apiResponse, 'sharedIpGroup')) {
                $this->_apiIPGroups[(int) $this->_apiResponse->sharedIpGroup->id] = array(
                      'servers' => array(),
                      'name' => (string) $this->_apiResponse->sharedIpGroup->name,
                      'id' => (int) $this->_apiResponse->sharedIpGroup->id);

                if (property_exists($this->_apiResponse->sharedIpGroup, 'servers')) {
                    foreach ($this->_apiResponse->sharedIpGroup->servers as $server) {
                        $this->_apiIPGroups[(int) $this->_apiResponse->sharedIpGroup->id]['servers'][]
                              = (int) $server;
                    }
                }

                return $this->_apiIPGroups[(int) $this->_apiResponse->sharedIpGroup->id];
            }
        }

        return false;
    }

    /**
     * Retrieve all the available shared IP groups
     *
     * @param bool $isDetailed should response contain an array of servers group has
     * @return mixed returns array of groups or false on failure
     */
    public function getSharedIpGroups ($isDetailed = false)
    {
        $this->_apiResource = '/shared_ip_groups'. ($isDetailed ? '/detail' : '');
        $this->_doRequest();

        if ($this->_apiResponseCode && ($this->_apiResponseCode == '200'
                || $this->_apiResponseCode == '203')) {
             if (property_exists($this->_apiResponse, 'sharedIpGroups')) {
                 foreach ($this->_apiResponse->sharedIpGroups as $sharedIpGroup) {
                    $this->_apiIPGroups[(int) $sharedIpGroup->id] = array(
                          'name' => (string) $sharedIpGroup->name,
                          'id' => (int) $sharedIpGroup->id);

                    if ($isDetailed && property_exists($sharedIpGroup, 'servers')) {
                        $this->_apiIPGroups[(int) $sharedIpGroup->id]['servers'] = array();
                        foreach ($sharedIpGroup->servers as $server) {
                            $this->_apiIPGroups[(int) $sharedIpGroup->id]['servers'][]
                                  = (int) $server;
                        }
                    }
                 }

                return $this->_apiIPGroups;
            }
        }

        return false;
    }

    /**
     * Retrieve back-up schedule for a specific server
     *
     * @param int $serverId id of server you wish to retrieve back-up schedule for
     * @return mixed returns array of current back-up schedule or false on failure
     */
    public function getBackupSchedule ($serverId)
    {
        $this->_apiResource = '/servers/'. (int) $serverId .'/backup_schedule';
        $this->_doRequest();

	    if ($this->_apiResponseCode && ($this->_apiResponseCode == '200'
                || $this->_apiResponseCode == '203')) {

            if (property_exists($this->_apiResponse, 'backupSchedule')) {
                    $this->_apiServers[(int) $serverId]['backup'] = array(
                          'enabled' => (bool) ($this->_apiResponse->backupSchedule->enabled ? true : false),
                          'daily' => (string) $this->_apiResponse->backupSchedule->daily,
                          'weekly' => (string) $this->_apiResponse->backupSchedule->weekly);
                return $this->_apiServers[(int) $serverId]['backup'];
            }
	    }

        return false;
    }

    /**
     * Create a new back-up schedule for a server
     *
     * @param int $serverId id of a server this back-up schedule is intended for
     * @param string $weekly day of the week this back-up should run, please
     * $_apiBackup array and/or documentation for valid parameters.
     * @param string $daily time of the day this back-up should run, please
     * $_apiBackup array and/or documentation for valid parameters.
     * @param bool $isEnabled should this scheduled back-up be enabled or disabled,
     * default is set to enabled.
     * @throws Cloud_Exception
     * @return bool true on success and false on failure
     */
    public function addBackupSchedule ($serverId, $weekly, $daily, $isEnabled = true)
    {
        if (!in_array((string) strtoupper($weekly), $this->_apiBackup['weekly'])) {
            throw new Cloud_Exception ('Passed weekly back-up parameter is not supported');
        }

        if (!in_array((string) strtoupper($daily), $this->_apiBackup['daily'])) {
            throw new Cloud_Exception ('Passed weekly back-up parameter is not supported');
        }

        $this->_apiResource = '/servers/'. (int) $serverId .'/backup_schedule';
        $this->_apiJson = array ('backupSchedule' => array(
                                    'enabled' => (bool) $isEnabled,
                                    'weekly' => (string) strtoupper($weekly),
                                    'daily' => (string) strtoupper($daily)));
        $this->_doRequest(self::METHOD_POST);

	    if ($this->_apiResponseCode && $this->_apiResponseCode == '204') {
	        $this->_apiServers[(int) $serverId]['backup'] = array(
	                'enabled' => (bool) $isEnabled,
	                'daily' => (string) $daily,
	                'weekly' => (string) $weekly);
            return true;
	    }

        return false;
    }

    /**
     * Deletes scheduled back-up for specific server
     *
     * @param int $serverId id of server you wish to delete all scheduled back-ups
     * for
     * @return bool returns true on success or false on failure
     */
    public function deleteBackupSchedule ($serverId)
    {
        $this->_apiResource = '/servers/'. (int) $serverId .'/backup_schedule';
        $this->_doRequest(self::METHOD_DELETE);

	    if ($this->_apiResponseCode && $this->_apiResponseCode == '204') {
	        if (array_key_exists((int) $serverId, $this->_apiServers)
                    && array_key_exists('backup', $this->_apiServers[(int) $serverId])) {
	            unset($this->_apiServers[(int) $serverId]['backup']);
	        }

            return true;
	    }

        return false;
    }

    /**
     * Creates a new server on the cloud
     *
     * @param string $name server name, must be unique
     * @param int $imageId server image you wish to use
     * @param int $flavorId server flavor you wish to use
     * @param int $groupId optional group id of server cluster
     * @return mixed returns array of server's configuration or false on failure
     */
    public function createServer ($name, $imageId, $flavorId, $groupId = false)
    {
        // Since Rackspace automaticly removes all spaces/non alpha-numeric characters
        // let's do this on our end before submitting data
        $name = preg_replace("/[^a-zA-Z0-9s]/", '', (string) $name);

        // We need to check if we are creating a dublicate server name,
        // since creating two servers with same name can cause problems.
        if (empty($this->_apiServers)) {
            $this->getServers();
        }

        foreach ($this->_apiServers as $server) {
            if (strtolower($server['name']) == strtolower($name)) {
                throw new Cloud_Exception ('Server with name: '. $name .' already exists!');
            }
        }

        $this->_apiResource = '/servers.xml';
        $this->_apiJson = array ('server' => array(
                                'name' => $name,
                                'imageId' => (int) $imageId,
                                'flavorId' => (int) $flavorId,
                                'metadata' => array(
                                    'Server Name' => $name),
                                'personality' => array()));

        if (is_array($this->_apiFiles) && !empty($this->_apiFiles)) {
            foreach ($this->_apiFiles as $file => $content) {
                array_push($this->_apiJson['server']['personality'],
                   array('path' => $file, 'contents' => base64_encode($content)));
            }
        }

        if (is_numeric($groupId)) {
            array_push($this->_apiJson['server'], array('sharedIpGroupId' => (int) $groupId));
        }

        $this->_doRequest(self::METHOD_POST);

        // If server was created, store it locally
        if ($this->_apiResponseCode && $this->_apiResponseCode == '202') {

            // Empty file array
            $this->_apiFiles = array();

            $serverXml = simplexml_load_string($this->_apiResponse);
            if (!empty($serverXml)) {
                $this->_apiServers[(int) $serverXml['id']] =
                    array('name' => (string) $serverXml['name'],
                            'imageId' => (int) $serverXml['imageId'],
                            'flavorId' => (int) $serverXml['flavorId'],
                            'hostId' => (string) $serverXml['hostId'],
                            'progress' => (int) $serverXml['progress'],
                            'status' => (string) $serverXml['status'],
                            'sharedIpGroupId' => (int) $serverXml['sharedIpGroupId'],
                            'addresses' => array(),
                            'metadata' => array());
                if ($serverXml->addresses->public) {
                    foreach ($serverXml->addresses->public as $public) {
                        $this->_apiServers[(int) $serverXml['id']]['addresses']['public'][]
                            = (string) $public->ip['addr'];
                    }
                }
                if ($serverXml->addresses->private) {
                    foreach ($serverXml->addresses->private as $private) {
                        $this->_apiServers[(int) $serverXml['id']]['addresses']['private'][]
                            = (string) $private->ip['addr'];
                    }
                }
                return $this->_apiServers[(int) $serverXml['id']];
            }
        }

        return false;
    }

    /**
     * Adds file to inject while creating new server
     *
     * @param string $file full file path where file will be put (/etc/motd,etc)
     * @param string $content content of the file (Welcome to my server, etc)
     * @return array returns array of all files pending injection
     */
    public function addServerFile ($file, $content) {
        $this->_apiFiles[(string) $file] = (string) $content;
        return $this->_apiFiles;
    }

    /**
     * Update server's name and password
     *
     * @param int $serverId id of server you wish to update
     * @param string $name new server name
     * @param string $password new server password
     * @return mixed returns false on failure or server configuration on success
     */
    public function updateServer ($serverId, $name, $password)
    {
        $this->_apiResource = '/servers/'. (int) $serverId;
        $this->_apiJson = array ('server' => array(
                                    'name' => (string) $name,
                                    'adminPass' => (string) $password));
        $this->_doRequest(self::METHOD_PUT);


        // If server was updated, update it locally
        if ($this->_apiResponseCode && $this->_apiResponseCode == '202') {
            $this->_apiServers[(int) $serverId]['name']= (string) $name;
            $this->_apiServers[(int) $serverId]['adminPass']= (string) $password;

            return $this->_apiServers[(int) $serverId];
        }

        return false;
    }

    /**
     * Delete server
     *
     * @param int $serverId id of server you wish to delete
     * @return bool returns true on success or false on fail
     */
    public function deleteServer ($serverId)
    {
        $this->_apiResource = '/servers/'. (int) $serverId;
        $this->_doRequest(self::METHOD_DELETE);

        // If server was deleted, update it locally
        if ($this->_apiResponseCode && $this->_apiResponseCode == '202') {
            unset($this->_apiServers[(int) $serverId]);
            return true;
        }

        return false;
    }

    /**
     * Rebuild server using another server image
     *
     * @param int $serverId id of server you wish to rebuild
     * @param int $imageId id of server image you wish to use for this rebuild
     * @return bool returns true on success or false on fail
     */
    public function rebuildServer ($serverId, $imageId)
    {
        $this->_apiResource = '/servers/' . (int) $serverId .'/action';
        $this->_apiJson = array ('rebuild' => array(
                                    'imageId' => (int) $imageId));
        $this->_doRequest(self::METHOD_PUT);

        // If rebuild request is successful
        if ($this->_apiResponseCode && $this->_apiResponseCode == '202') {
            $this->_apiServers[(int) $serverId]['imageId'] = (int) $imageId;
            return true;
        }

        return false;
    }

    /**
     * Resize server to another flavor (server configuration)
     *
     * @param int $serverId id of server you wish to resize
     * @return bool returns true on success or false on fail
     */
    public function resizeServer ($serverId, $flavorId)
    {
        $this->_apiResource = '/servers/'. (int) $serverId .'/action';
        $this->_apiJson = array ('resize' => array(
                                    'flavorId' => (int) $flavorId));
        $this->_doRequest(self::METHOD_PUT);

        // If confirmation is successful update internal server array
        if ($this->_apiResponseCode && $this->_apiResponseCode == '202') {
            $this->_apiServers[(int) $serverId]['flavorId'] = (int) $flavorId;
            return true;
        }

        return false;
    }

    /**
     * Confirm resize of server
     *
     * @param int $serverId id of server this confirmation is for
     * @return bool returns true on success or false on fail
     */
    public function confirmResize ($serverId)
    {
        $this->_apiResource = '/servers/'. (int) $serverId .'/action';
        $this->_apiJson = array ('confirmResize' => '1');
        $this->_doRequest(self::METHOD_PUT);

        // If confirmation is successful
        if ($this->_apiResponseCode && $this->_apiResponseCode == '202') {
            return true;
        }

        return false;
    }

    /**
     * Revert resize changes
     *
     * @param int $serverId id of server you wish to revert resize for
     * @return bool returns true on success or false on fail
     */
    public function revertResize ($serverId)
    {
        $this->_apiResource = '/servers/'. (int) $serverId .'/action';
        $this->_apiJson = array ('revertResize' => '1');
        $this->_doRequest(self::METHOD_PUT);

        // If revert is successful
        if ($this->_apiResponseCode && $this->_apiResponseCode == '202') {
            return true;
        }

        return false;
    }

    /**
     * Reboots server
     *
     * @param int $serverId id of server you wish to reboot
     * @param string $type specify what kind of reboot you wish to perform
     * @return bool returns true on success or false on fail
     */
    public function rebootServer ($serverId, $type = 'soft')
    {
        $this->_apiResource = '/servers/'. (int) $serverId .'/action';
        $this->_apiJson = array ('reboot' => array(
                                    'type' => (string) strtoupper($type)));
        $this->_doRequest(self::METHOD_POST);

        // If reboot request was successfully recieved
        if ($this->_apiResponseCode && $this->_apiResponseCode == '202') {
            return true;
        }

        return false;
    }
}