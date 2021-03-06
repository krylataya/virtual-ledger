<?php

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class ApiRequest
{

    private $client;
    private $token;

    public function __construct()
    {
        $this->client = new Client();
        $this->token = session('token');
        $this->idpDevToken = '18c2b0ab927d8a3c9bf9ef78419a8f6d4535e47f';
    }

    /**
     * Key receiver public key from DCP service
     * @param string $receiverAbn
     *
     * @return mixed
     */
    public function getReceiverPublicKey($receiverAbn, $token)
    {
        $data = [
            'headers' => [
                'Authorization' => 'JWT ' . $token
            ],
        ];
        $response = $this->makeRequest('GET', 'https://dcp.testpoint.io/urn:oasis:names:tc:ebcore:partyid-type:iso6523:0151::' . $receiverAbn . '/keys/', $data);
        return array_first($response);
    }

    /**
     * Send sender public key using bearer token
     *
     * @param string $senderAbn
     * @param string $fingerprint
     * @param string $token
     *
     * @return mixed
     */
    public function sendSenderPublicKey($senderAbn, $fingerprint, $token)
    {
        $data = [
            'headers' => [
                'Authorization' => 'JWT ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'pubKey' => file_get_contents(resource_path('data/keys/public_'.$senderAbn.'.key')),
                'revoked' => \Carbon\Carbon::now()->addWeek()->format('Y-m-d H:i:s'),
                'fingerprint' => $fingerprint,
            ])
        ];
        return $this->makeRequest('POST', 'https://dcp.testpoint.io/urn:oasis:names:tc:ebcore:partyid-type:iso6523:0151::' . $senderAbn . '/keys/', $data);
    }

//    public function getKeys($abn, $token)
//    {
//        $data = [
//            'headers' => [
//                'Authorization' => 'JWT ' . $token,
//                'Content-Type' => 'application/json',
//            ],
//        ];
//        return $this->makeRequest('GET', 'https://dcp.testpoint.io/urn:oasis:names:tc:ebcore:partyid-type:iso6523:0151::' . $abn . '/keys/', $data);
//    }

    /**
     * Send new message to tap-gw
     *
     * @param string $endpoint
     * @param string $message
     * @param string $signature
     *
     * @return bool|mixed
     */
    public function sendMessage($endpoint, $message, $signature)
    {
        $data = [
            'multipart' => [
                [
                    'name' => 'signature',
                    'contents' => fopen($signature, 'r')
                ],
                [
                    'name' => 'message',
                    'contents' => fopen($message, 'r')
                ],
            ]
        ];
        return $this->makeRequest('POST', $endpoint, $data, false);
    }

    /**
     * Get tap message
     *
     * @param string $messageId
     *
     * @return mixed
     */
    public function getMessage($messageId)
    {
        $headers = [
            'headers' => [
                'Authorization' => 'Token ' . $this->idpDevToken
            ]
        ];
        return $this->makeRequest('GET', 'https://tap-gw.testpoint.io/api/messages/'.$messageId.'/status/', $headers);
    }

    /**
     * Generate message endpoint url
     *
     * @param string $endpointId
     *
     * @return string
     */
    private function getMessagesEndpoint($endpointId)
    {
        return 'http://tap-gw.testpoint.io/api/endpoints/' . $endpointId . '/message/';
    }

    /**
     * Perform request to remote API
     *
     * @param string $type
     * @param string $url
     * @param array  $headers
     *
     * @return mixed
     */
    private function makeRequest($type, $url, $headers = [])
    {
        try {
            $res = $this->client->request($type, $url, $headers);
            return json_decode($res->getBody(), true);
        } catch (Exception $e) {
            Log::debug('Api Request error: ' . $url);
            Log::debug('Api Request error: ' . json_encode($headers));
            Log::debug('Api Request error: ' . $e->getMessage());
        }
        return false;
    }

    /**
     * Create new customer in https://idp-dev.tradewire.io/api/customers/v0/
     * for user during first login
     *
     * @param array $partisipandIds
     *
     * @return mixed
     */
    public function createNewCustomer($partisipandIds)
    {
        $headers = [
            'headers' => [
                'Authorization' => 'Token ' . $this->idpDevToken,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'participant_ids' => $partisipandIds
            ])
        ];
        return $this->makeRequest('POST', 'https://idp-dev.tradewire.io/api/customers/v0/', $headers);
    }

//    public function getCustomer($customerId)
//    {
//        $headers = [
//            'headers' => [
//                'Authorization' => 'Token ' . $this->idpDevToken,
//                'Accept' => 'application/json; indent=4',
//            ]
//        ];
//        return $this->makeRequest('POST', 'https://idp-dev.tradewire.io/api/customers/v0/'.$customerId, $headers);
//    }

    /**
     * Generate new token for customer
     *
     * @param string $customerId
     * @param string $clientId
     *
     * @return mixed
     */
    public function getNewTokenForCustomer($customerId, $clientId = '274953')
    {
        $headers = [
            'headers' => [
                'Authorization' => 'Token ' . $this->idpDevToken,
                'Accept' => 'application/json; indent=4',
            ]
        ];
        return $this->makeRequest('POST', 'https://idp-dev.tradewire.io/api/customers/v0/'.$customerId.'/tokens/'.$clientId.'/', $headers);
    }

    public function getDocumentIds($abn)
    {
        $data = $this->makeRequest('GET', 'https://dcp.testpoint.io/urn:oasis:names:tc:ebcore:partyid-type:iso6523:0151::' . $abn . '?format=json');
        return $data['ServiceMetadataReferenceCollection'];
    }

    public function getEndpoints($abn, $documentId)
    {
        $data = $this->makeRequest('GET', 'https://dcp.testpoint.io/urn:oasis:names:tc:ebcore:partyid-type:iso6523:0151::' . $abn . '/service/' . urlencode($documentId) . '?format=json');
        if(!$data){
            return false;
        }
        $result = array_map(function ($item) {
            return array_map(function ($item1) use ($item) {
                return implode(':', $item['ProcessIdentifier']) . ' - ' . $item1['EndpointURI'];
            }, $item['ServiceEndpointList']);
        }, $data['ProcessList']);
        //multidimensional array to one-dimensional array
        return array_reduce($result, 'array_merge', array());
    }

    /**
     * Create new endpoint for user
     *
     * @param $abn
     * @param $token
     *
     * @return mixed
     */
    public function createEndpoint($abn, $token)
    {
        //create new tap-gw token
        $headers = [
            'headers' => [
                'Authorization' => 'JWT ' . $token,
                'Accept' => 'application/json; indent=4',
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                "participant_id" => "urn:oasis:names:tc:ebcore:partyid-type:iso6523:0151::$abn"
            ])
        ];
        $response = $this->makeRequest('POST', 'https://tap-gw.testpoint.io/api/endpoints', $headers);
        return $response['data']['id'] ?? false;
    }

    public function createServiceMetadata($endpoint, $token, $abn)
    {
        $processes = [
            'invoice',
            'adjustment',
            'rcti',
            'taxreceipt',
            'creditnote',
            'debitnote',
        ];
        $requestData = [
            'ProcessList' => [],
            'DocumentIdentifier' => [
                'scheme' => 'dbc',
                'value' => 'core-invoice',
                'id' => 'dbc::core-invoice',
            ],
            'ParticipantIdentifier' => [
                'scheme' => 'urn:oasis:names:tc:ebcore:partyid-type:iso6523:0151',
                'value' => $abn
            ]
        ];
        foreach($processes as $process){
            $requestData['ProcessList'][] = [
                'ProcessIdentifier' => [
                    'scheme' => 'dbc',
                    'value' => $process,
                ],
                'ServiceEndpointList' => [
                    [
                        'ServiceActivationDate' => Carbon::now()->format('Y-m-d'),
                        'Certificate' => '123',
                        'EndpointURI' => "http://tap-gw.testpoint.io/api/endpoints/$endpoint/message/",
                        'transportProfile' => 'TBD',
                        'ServiceExpirationDate' => Carbon::now()->addYears(1)->format('Y-m-d'),
                        'RequireBusinessLevelSignature' => "false",
                        'TechnicalInformationUrl' => '123',
                        'MinimumAuthenticationLevel' => '0',
                        'ServiceDescription' => '123',

                    ]
                ]
            ];
        }

        $headers = [
            'headers' => [
                'Authorization' => 'JWT ' . $token,
                'Accept' => 'application/json; indent=4',
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($requestData)
        ];

        return $this->makeRequest('PUT', "https://dcp.testpoint.io/urn:oasis:names:tc:ebcore:partyid-type:iso6523:0151::$abn/service/dbc::core-invoice", $headers);
    }
}