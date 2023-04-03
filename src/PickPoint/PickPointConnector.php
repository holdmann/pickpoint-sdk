<?php

namespace PickPointSdk\PickPoint;

use DateTime;
use GuzzleHttp\Client;
use PickPointSdk\Components\State;
use PickPointSdk\Components\Invoice;
use PickPointSdk\Components\TariffPrice;
use PickPointSdk\Components\CourierCall;
use PickPointSdk\Components\PackageSize;
use PickPointSdk\Components\InvoiceValidator;
use PickPointSdk\Contracts\DeliveryConnector;
use PickPointSdk\Exceptions\ValidateException;
use PickPointSdk\Components\SenderDestination;
use PickPointSdk\Components\ReceiverDestination;
use PickPointSdk\Exceptions\PickPointMethodCallException;

class PickPointConnector implements DeliveryConnector
{
    const CACHE_SESSION_KEY = 'pickpoint_session_id';

    const CACHE_SESSION_LIFE_TIME = 60;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var PickPointConf
     */
    private $pickPointConf;

    /**
     * @var SenderDestination
     */
    private $senderDestination;

    /**
     * @var PackageSize
     */
    private $defaultPackageSize;

    /**
     * @var \Predis\Client $redisCache
     */
    private $redisCache;

    /**
     * PickPointConnector constructor.
     * @param PickPointConf $pickPointConf
     * @param SenderDestination|null $senderDestination
     * @param PackageSize|null $packageSize
     * @param array $predisConf
     */
    public function __construct(
        PickPointConf $pickPointConf,
        SenderDestination $senderDestination,
        PackageSize $packageSize = null,
        array $predisConf = []
    )
    {
        $this->client = new Client();
        $this->pickPointConf = $pickPointConf;
        $this->senderDestination = $senderDestination;
        $this->defaultPackageSize = $packageSize;
        $this->redisCache = !empty($predisConf) ? new \Predis\Client($predisConf) : null;
    }

    /**
     * @return PackageSize
     */
    public function getDefaultPackageSize(): PackageSize
    {
        return $this->defaultPackageSize;
    }

    /**
     * @return SenderDestination
     */
    public function getSenderDestination(): SenderDestination
    {
        return $this->senderDestination;
    }

    /**
     * @return string
     * @throws PickPointMethodCallException
     */
    private function auth()
    {
        $cacheKey = self::CACHE_SESSION_KEY . '_' . $this->pickPointConf->getIKN();

        if (!empty($this->redisCache) && !empty($this->redisCache->get($cacheKey))) {
            return $this->redisCache->get($cacheKey);
        }

        $loginUrl = $this->pickPointConf->getHost() . '/login';

        try {
            $request = $this->client->post($loginUrl, [
                'json' => [
                    'Login' => $this->pickPointConf->getLogin(),
                    'Password' => $this->pickPointConf->getPassword(),
                ],
            ]);
            $response = json_decode($request->getBody()->getContents(), true);

            if (!empty($this->redisCache)) {
                $this->redisCache->setex($cacheKey, self::CACHE_SESSION_LIFE_TIME, $response['SessionId']);
            }

        } catch (\Exception $exception) {
            throw new PickPointMethodCallException($loginUrl, $exception->getMessage());
        }

        return $response['SessionId'];
    }


    /**
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function getPoints()
    {
        $url = $this->pickPointConf->getHost() . '/clientpostamatlist';

        $options = [
            'json' => [
                'SessionId' => $this->auth(),
                'IKN' => $this->pickPointConf->getIKN(),
            ],
            'connect_timeout' => DELIVERY_API_DEFAULT_CONNECT_TIMEOUT,
            'timeout' => DELIVERY_API_DEFAULT_RESPONSE_TIMEOUT,
        ];
        if (defined('DELIVERY_API_DEFAULT_CONNECT_TIMEOUT'))
        {
            $options['connect_timeout'] = constant('DELIVERY_API_DEFAULT_CONNECT_TIMEOUT');
        }
        if (defined('DELIVERY_API_DEFAULT_RESPONSE_TIMEOUT'))
        {
            $options['timeout'] = $options('DELIVERY_API_DEFAULT_RESPONSE_TIMEOUT');
        }

        /** @var \GuzzleHttp\Client $this->client */
        $request = $this->client->post($url, $options);
        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * @param PackageSize $packageSize
     * @param ReceiverDestination $receiverDestination
     * @param SenderDestination|null $senderDestination
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function calculatePrices(ReceiverDestination $receiverDestination, SenderDestination $senderDestination = null, PackageSize $packageSize = null): array
    {
        $url = $this->pickPointConf->getHost() . '/calctariff';
        /**
         * SenderDestination $senderDestination
         */
        $senderDestination = $senderDestination ?? $this->senderDestination;
        /**
         * PackageSize $packageSize
         */
        $packageSize = $packageSize ?? $this->defaultPackageSize;

        $requestArray = [
            'SessionId' => $this->auth(),
            "IKN" => $this->pickPointConf->getIKN(),
            "FromCity" => $senderDestination != null ? $senderDestination->getCity() : '',
            "FromRegion" => $senderDestination != null ? $senderDestination->getRegion() : '',
            "ToCity" => $receiverDestination->getCity(),
            "ToRegion" => $receiverDestination->getRegion(),
            "PtNumber" => $receiverDestination->getPostamatNumber(),
            "Length" => $packageSize != null ? $packageSize->getLength() : '',
            "Depth" => $packageSize != null ? $packageSize->getDepth() : '',
            "Width" => $packageSize != null ? $packageSize->getWidth() : '',
            "Weight" => $packageSize != null ? $packageSize->getWeight() : ''
        ];

        $request = $this->client->post($url, [
            'json' => $requestArray,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * @param ReceiverDestination $receiverDestination
     * @param string $tariffType
     * @param SenderDestination|null $senderDestination
     * @param PackageSize|null $packageSize
     * @return TariffPrice
     * @throws PickPointMethodCallException
     */
    public function calculateObjectedPrices(ReceiverDestination $receiverDestination, string $tariffType = 'Standard', SenderDestination $senderDestination = null, PackageSize $packageSize = null): TariffPrice
    {
        $response = $this->calculatePrices($receiverDestination, $senderDestination, $packageSize);

        $tariffPrice = new TariffPrice(
            $response['Services'] ?? [],
            $response['DPMaxPriority'] ?? 0,
            $response['DPMinPriority'] ?? 0,
            $response['DPMax'] ?? 0,
            $response['DPMin'] ?? 0,
            $response['Zone'] ?? 0,
            $response['ErrorMessage'] ?? '',
            $response['ErrorCode'] ?? 0,
            $tariffType
        );

        return $tariffPrice;
    }


    /**
     * Returns invoice data and create shipment/order in delivery service
     * @param Invoice $invoice
     * @param bool $returnInvoiceNumberOnly
     * @return mixed
     * @throws PickPointMethodCallException
     * @throws ValidateException
     */
    public function createShipment(Invoice $invoice): array
    {
        $url = $this->pickPointConf->getHost() . '/CreateShipment';
        InvoiceValidator::validateInvoice($invoice);

        $arrayRequest = [
            "SessionId" => $this->auth(),
            "Sendings" => [
                [
                    "EDTN" => $invoice->getEdtn(),
                    "IKN" => $this->pickPointConf->getIKN(),
                    "Invoice" => [
                        "SenderCode" => $invoice->getSenderCode(), // required
                        "Description" => $invoice->getDescription(), // required
                        "RecipientName" => $invoice->getRecipientName(), // required
                        "PostamatNumber" => $invoice->getPostamatNumber(), // required
                        "MobilePhone" => $invoice->getMobilePhone(), // required
                        "Email" => $invoice->getEmail(),
                        "ConsultantNumber" => $invoice->getConsultantNumber(),
                        "PostageType" => $invoice->getPostageType(), // required
                        "GettingType" => $invoice->getGettingType(), // required
                        "PayType" => Invoice::PAY_TYPE,
                        "Sum" => $invoice->getSum(), // required
                        "PrepaymentSum" => $invoice->getPrepaymentSum(),
                        "InsuareValue" => $invoice->getInsuareValue(),
                        "DeliveryVat" => $invoice->getDeliveryVat(),
                        "DeliveryFee" => $invoice->getDeliveryFee(),
                        "DeliveryMode" => $invoice->getDeliveryMode(), // required
                        "SenderCity" => [
                            "CityName" => $this->senderDestination->getCity(),
                            "RegionName" => $this->senderDestination->getRegion()
                        ],
                        "ClientReturnAddress" => $invoice->getClientReturnAddress(),
                        "UnclaimedReturnAddress" => $invoice->getUnclaimedReturnAddress(),
                        "Places" => [
                            [
                                "Width" => $invoice->getPackageSize()->getWidth(),
                                "Height" => $invoice->getPackageSize()->getLength(),
                                "Depth" => $invoice->getPackageSize()->getDepth(),
                                "Weight" => $invoice->getPackageSize()->getWeight(),
                                "GSBarCode" => $invoice->getGcBarCode(),
                                "CellStorageType" => 0,
                                "SuBEncloses" => [
                                    $invoice->getProducts() // required
                                ]
                            ]
                        ],
                    ]
                ],
            ]
        ];

        $request = $this->client->post($url, [
            'json' => $arrayRequest,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkCreateShipmentException($response, $url);

        return $response;
    }

    private function checkCreateShipmentException(array $response, string $url)
    {
        $this->checkMethodException($response, $url);

        if (!empty($response['RejectedSendings'][0])) {
            throw new PickPointMethodCallException($url, $response['RejectedSendings'][0]['ErrorMessage'], $response['RejectedSendings'][0]['ErrorCode']);
        }
    }

    /**
     * @param Invoice $invoice
     * @return mixed|void
     * @throws PickPointMethodCallException
     * @throws ValidateException
     */
    public function createShipmentWithInvoice(Invoice $invoice): Invoice
    {
        $response = $this->createShipment($invoice);

        if (!empty($response['CreatedSendings'])) {
            $invoice->setInvoiceNumber($response['CreatedSendings'][0]['InvoiceNumber']);
            $invoice->setBarCode($response['CreatedSendings'][0]['Barcode']);
        }
        return $invoice;
    }

    /**
     * Returns current delivery status
     * @param string|null $invoiceNumber
     * @param string|null $orderNumber
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function getState(?string $invoiceNumber = null, ?string $orderNumber = null): State
    {

        $url = $this->pickPointConf->getHost() . '/tracksending';
        $request = $this->client->post($url, [
            'json' => [
                'SessionId' => $this->auth(),
                "InvoiceNumber" => $invoiceNumber,
                "SenderInvoiceNumber" => $orderNumber
            ],
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        $response = $response[0] ?? [];

        return new State($response['State'] ?? 0, $response['StateMessage'] ?? '');
    }

    /**
     * @param string $invoiceNumber
     * @param string $senderCode
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function cancelInvoice(string $invoiceNumber = '', string $senderCode = ''): array
    {
        $url = $this->pickPointConf->getHost() . '/cancelInvoice';

        $requestArray = [
            'SessionId' => $this->auth(),
            "IKN" => $this->pickPointConf->getIKN(),
        ];
        if (!empty($invoiceNumber)) {
            $requestArray['InvoiceNumber'] = $invoiceNumber;
        }

        if (!empty($senderCode)) {
            $requestArray["GCInvoiceNumber"] = $senderCode;
        }

        $request = $this->client->post($url, [
            'json' => $requestArray
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * Marks on packages
     * @param array $invoiceNumbers
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function printLabel(array $invoiceNumbers): string
    {
        $invoices = !empty($invoiceNumbers) ? $invoiceNumbers : [];

        $url = $this->pickPointConf->getHost() . '/makelabel';
        $request = $this->client->post($url, [
            'json' => [
                'SessionId' => $this->auth(),
                "Invoices" => $invoices,
            ],
        ]);
        $response = $request->getBody()->getContents();

        $this->checkMethodException($response, $url);

        return $response;
    }


    /**
     * @param array $invoiceNumbers
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function makeReceipt(array $invoiceNumbers): array
    {

        $url = $this->pickPointConf->getHost() . '/makereestrnumber';
        $array = [
            'SessionId' => $this->auth(),
            "CityName" => $this->senderDestination->getCity(),
            "RegionName" => $this->senderDestination->getRegion(),
            "DeliveryPoint" => $this->senderDestination->getPostamatNumber(),
            "Invoices" => $invoiceNumbers,
        ];
        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        if (!empty($response['ErrorMessage'])) {
            throw new PickPointMethodCallException($url, $response['ErrorMessage']);
        }
        return $response['Numbers'] ?? [];

    }

    /**
     * Returns byte code pdf
     * @param string $identifier
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function printReceipt(string $identifier): string
    {
        $url = $this->pickPointConf->getHost() . '/getreestr';
        $array = [
            'SessionId' => $this->auth(),
            "ReestrNumber" => $identifier
        ];
        $request = $this->client->post($url, [
            'json' => $array,
        ]);
        $response = $request->getBody()->getContents();

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * @param array $invoiceNumbers
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function makeReceiptAndPrint(array $invoiceNumbers): string
    {
        $url = $this->pickPointConf->getHost() . '/makereestr';
        $array = [
            'SessionId' => $this->auth(),
            "CityName" => $this->senderDestination->getCity(),
            "RegionName" => $this->senderDestination->getRegion(),
            "DeliveryPoint" => $this->senderDestination->getPostamatNumber(),
            "Invoices" => $invoiceNumbers,
        ];
        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = $request->getBody()->getContents();

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * @param string $invoiceNumber
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function removeInvoiceFromReceipt(string $invoiceNumber)
    {
        $url = $this->pickPointConf->getHost() . '/removeinvoicefromreestr';
        $array = [
            'SessionId' => $this->auth(),
            'IKN' => $this->pickPointConf->getIKN(),
            "InvoiceNumber" => $invoiceNumber,
        ];
        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * @return array
     * @throws PickPointMethodCallException
     */
    public function getStates(): array
    {
        $url = $this->pickPointConf->getHost() . '/getstates';

        $request = $this->client->get($url);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        $states = [];
        foreach ($response as $state) {
            $states[] = new State($state['State'] ?? 0, $state['StateText'] ?? '');
        }

        return $states;
    }

    /**
     * Return all invoices
     * @param $dateFrom
     * @param $dateTo
     * @param string $status
     * @param string $postageType
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function getInvoicesByDateRange($dateFrom, $dateTo, $status = null, $postageType = null)
    {
        $dateFrom = (new DateTime($dateFrom))->format('d.m.y H:m');
        $dateTo = (new DateTime($dateTo))->format('d.m.y H:m');
        $url = $this->pickPointConf->getHost() . '/getInvoicesChangeState';

        $array = [
            'SessionId' => $this->auth(),
            'DateFrom' => $dateFrom,
            'DateTo' => $dateTo,
            "State" => $status,

        ];

        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * @param CourierCall $courierCall
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function callCourier(CourierCall $courierCall): CourierCall
    {
        $url = $this->pickPointConf->getHost() . '/courier';
        $array = [
            'SessionId' => $this->auth(),
            'IKN' => $this->pickPointConf->getIKN(),
            'City' => $courierCall->getCityName(),
            "City_id" => $courierCall->getCityId(),
            "Address" => $courierCall->getAddress(),
            "FIO" => $courierCall->getFio(),
            "Phone" => $courierCall->getPhone(),
            "Date" => $courierCall->getDate(),
            "TimeStart" => $courierCall->getTimeStart(),
            "TimeEnd" => $courierCall->getTimeEnd(),
            "Number" => $courierCall->getNumberOfInvoices(),
            "Weight" => $courierCall->getWeight(),
            "Comment" => $courierCall->getComment()
        ];

        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkCourierCallException($response, $url, $courierCall);

        return $courierCall;
    }

    /**
     * @param string $callOrderNumber
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function cancelCourierCall(string $callOrderNumber): array
    {
        $url = $this->pickPointConf->getHost() . '/couriercancel';
        $array = [
            'SessionId' => $this->auth(),
            'OrderNumber' => $callOrderNumber
        ];

        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * @param array $response
     * @param string $url
     * @param CourierCall $courierCall
     * @return CourierCall
     * @throws PickPointMethodCallException
     */
    private function checkCourierCallException(array $response, string $url, CourierCall $courierCall)
    {
        $this->checkMethodException($response, $url);

        if (!empty($response['CourierRequestRegistred'])) {
            $courierCall->setCallOrderNumber($response['OrderNumber']);
            return $courierCall;
        }
        throw new PickPointMethodCallException($url, $response['ErrorMessage']);
    }

    /**
     * @param $response
     * @param $urlCall
     * @return mixed
     * @throws PickPointMethodCallException
     */
    private function checkMethodException($response, string $urlCall)
    {
        if (!empty($response['ErrorCode'])) {
            $errorCode = $response['ErrorCode'];
            $errorMessage = $response['Error'] ?? "";
            throw new PickPointMethodCallException($urlCall, $errorMessage, $errorCode);
        }
    }


    /**
     * @param string $invoiceNumber
     * @param string $shopOrderNumber
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function shipmentInfo(string $invoiceNumber, string $shopOrderNumber = ''): array
    {
        $url = $this->pickPointConf->getHost() . '/sendinginfo';
        $array = [
            'SessionId' => $this->auth(),
            'InvoiceNumber' => $invoiceNumber,
            "SenderInvoiceNumber" => $shopOrderNumber
        ];

        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);
        $this->checkMethodException($response, $url);

        return $response ? current($response) : [];
    }

    /**
     * @param string $invoiceNumber
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function findReestrNumberByInvoice(string $invoiceNumber)
    {
        $url = $this->pickPointConf->getHost() . '/getreestrnumber';
        $array = [
            'SessionId' => $this->auth(),
            'InvoiceNumber' => $invoiceNumber,
        ];

        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response['Number'];
    }

    /**
     * @param array $invoiceNumbers
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function getInvoicesTrackHistory(array $invoiceNumbers): array
    {
        $url = $this->pickPointConf->getHost() . '/tracksendings';

        $array = [
            'SessionId' => $this->auth(),
            "Invoices" => $invoiceNumbers,
        ];

        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * @param string $invoiceNumber
     * @return array
     * @throws \Exception
     */
    public function getInvoiceStatesTrackHistory(string $invoiceNumber): array
    {

        $invoiceHistory = $this->getInvoicesTrackHistory([$invoiceNumber]);
        $states = $invoiceHistory['Invoices'][0]['States'] ?? [];

        $statesResult = [];
        foreach ($states as $state) {
            $valueObjState = new State(
                $state['State'],
                $state['StateMessage'],
                new \DateTime($state['ChangeDT'])
            );
            if (empty($statesResult[$state['State']])) {
                $statesResult[$state['State']] = $valueObjState;
            }
        }

        return $statesResult;
    }

    /**
     *
     * @param array $invoiceNumbers
     * @return array
     * @throws PickPointMethodCallException
     */
    public function getArrayInvoicesWithTrackHistory(array $invoiceNumbers): array
    {
        $invoiceHistory = $this->getInvoicesTrackHistory($invoiceNumbers);
        $invoiceNumbersWithHistory = [];

        foreach ($invoiceHistory['Invoices'] as $invoice) {
            $states = $invoice['States'] ?? [];
            $statesResult = [];
            foreach ($states as $state) {
                $valueObjState = new State(
                    $state['State'],
                    $state['StateMessage'],
                    new \DateTime($state['ChangeDT'])
                );
                if (empty($statesResult[$state['State']])) {
                    $statesResult[$state['State']] = $valueObjState;
                }
            }
            if (in_array($invoice['InvoiceNumber'], $invoiceNumbers)) {
                $invoiceNumbersWithHistory[$invoice['InvoiceNumber']] = $statesResult;
            }
        }

        return $invoiceNumbersWithHistory;
    }

    /**
     * @param array $invoiceNumbers
     * @return array
     * @throws PickPointMethodCallException
     */
    public function getInvoicesLastStates(array $invoiceNumbers): array
    {
        $invoiceNumbersWithHistory = $this->getArrayInvoicesWithTrackHistory($invoiceNumbers);
        $invoicesWithFinalStates = [];
        foreach ($invoiceNumbersWithHistory as $invoiceNumber => $history) {
            $finalState = end($history);
            $invoicesWithFinalStates[$invoiceNumber] = $finalState;
        }

        return $invoicesWithFinalStates;
    }

    /**
     * @param Invoice $invoice
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function updateShipment(Invoice $invoice): array
    {
        $url = $this->pickPointConf->getHost() . '/updateInvoice';

        $arrayRequest = [
            'SessionId' => $this->auth(),
            'InvoiceNumber' => $invoice->getInvoiceNumber(),
            'BarCode' => $invoice->getBarCode()
        ];
        if (!empty($invoice->getPostamatNumber())) {
            $arrayRequest['PostamatNumber'] = $invoice->getPostamatNumber();
        }
        if (!empty($invoice->getRecipientName())) {
            $arrayRequest['RecipientName'] = $invoice->getRecipientName();
        }
        if (!empty($invoice->getMobilePhone())) {
            $arrayRequest['Phone'] = $invoice->getMobilePhone();
        }
        if (!empty($invoice->getEmail())) {
            $arrayRequest['Email'] = $invoice->getEmail();
        }
        if (!empty($invoice->getSum() || $invoice->getPostageType() == Invoice::POSTAGE_TYPE_STANDARD)) {
            $arrayRequest['Sum'] = $invoice->getSum();
        }

        if (!empty($invoice->getProducts())) {
            $arrayRequest['SubEncloses'] = $invoice->getProducts();
        }

        $request = $this->client->post($url, [
            'json' => $arrayRequest,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response;
    }


    /**
     * @param string $barCode
     * @return PackageSize
     * @throws PickPointMethodCallException
     */
    public function getPackageInfo(string $barCode): PackageSize
    {
        $url = $this->pickPointConf->getHost() . '/encloseinfo';

        $arrayRequest = [
            'SessionId' => $this->auth(),
            'BarCode' => $barCode
        ];

        $request = $this->client->post($url, [
            'json' => $arrayRequest,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        $enclose = $response['Enclose'];

        $packageSize = new PackageSize($enclose['Width'], $enclose['Height'], $enclose['Depth'], $enclose['Weight']);

        return $packageSize;
    }

//    /**
//     * @return array
//     * @throws PickPointMethodCallException
//     */
//    public function getCityList(): array
//    {
//        $url = $this->pickPointConf->getHost() . '/citylist';
//
//        $request = $this->client->get($url);
//
//        $response = json_decode($request->getBody()->getContents(), true);
//
//        $this->checkMethodException($response, $url);
//
//        $states = [];
//        foreach ($response as $state) {
//            $states[] = $state;
//        }
//
//        return $states;
//    }
    /**
     * @return array
     * @throws PickPointMethodCallException
     */
    public function getCityList()
    {
        $url = $this->pickPointConf->getHost() . '/citylist';

        $request = $this->client->get($url);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        $arr = [];




        foreach ($response as $state) {
            if(in_array($state['RegionName'], $arr)){
                continue;
            }
            $arr[] = $state['RegionName'];
            $states .= '""'.'=> "'.$state['RegionName'].'",'."\n";
        }

        return $states;
    }
    public static function mapIsoToRegionName($isoCode)
    {
        $map = [
            "RU-AD"=> "Адыгея респ.",
            "RU-MOS"=> "Московская обл.",
            "RU-MOW"=> "Московская обл.",
            "RU-LIP"=> "Липецкая обл.",
            "RU-BA"=> "Башкортостан респ.",
            "RU-ALT"=> "Алтайский край",
            "RU-KDA"=> "Краснодарский край",
            "RU-SAM"=> "Самарская обл.",
            "RU-IRK"=> "Иркутская обл.",
            "RU-PER"=> "Пермский край",
            "RU-TYU"=> "Тюменская обл.",
            "RU-SPE"=> "Ленинградская обл.",
            "RU-LEN"=> "Ленинградская обл.",
            "RU-TVE"=> "Тверская обл.",
            "RU-AMU"=> "Амурская обл.",
            "RU-STA"=> "Ставропольский край",
            "RU-CHU"=> "Чукотский авт. округ",
            "RU-ARK"=> "Архангельская обл.",
            "RU-BEL"=> "Белгородская обл.",
            "RU-MUR"=> "Мурманская обл.",
            "RU-KHM"=> "Ханты-Мансийский авт. округ-Югра",
            "RU-AST"=> "Астраханская обл.",
            "RU-ROS"=> "Ростовская обл.",
            "RU-CR"=> "Крым респ.",
            "UA-43" => "Крым респ.",
            "RU-NVS"=> "Новосибирская обл.",
            "RU-KB"=> "Кабардино-Балкарская респ.",
            "RU-SVE"=> "Свердловская обл.",
            "bel"=> "Беларусь",
            "RU-BRY"=> "Брянская обл.",
            "RU-SAR"=> "Саратовская обл.",
            "RU-BU"=> "Бурятия респ.",
            "RU-VLA"=> "Владимирская обл.",
            "RU-VGG"=> "Волгоградская обл.",
            "RU-VLG"=> "Вологодская обл.",
            "RU-VOR"=> "Воронежская обл.",
            "RU-AL"=> "Алтай респ.",
            "RU-CHE"=> "Челябинская обл.",
            "RU-DA"=> "Дагестан респ.",
            "RU-KIR"=> "Кировская обл.",
            "RU-YEV"=> "Еврейская авт. обл.",
            "RU-IVA"=> "Ивановская обл.",
            "RU-KGD"=> "Калининградская обл.",
            "RU-KL"=> "Калмыкия респ.",
            "RU-KLU"=> "Калужская обл.",
            "RU-KAM"=> "Камчатский край",
            "RU-KC"=> "Карачаево-Черкесская респ.",
            "RU-KR"=> "Карелия респ.",
            "RU-TUL"=> "Тульская обл.",
            "RU-YAR"=> "Ярославская обл.",
            "RU-KEM"=> "Кемеровская обл.",
            "RU-KO"=> "Коми респ.",
            "RU-KOS"=> "Костромская обл.",
            "RU-KYA"=> "Красноярский край",
            "RU-KGN"=> "Курганская обл.",
            "RU-KRS"=> "Курская обл.",
            "RU-MAG"=> "Магаданская обл.",
            "RU-ME"=> "Марий Эл респ.",
            "RU-MO"=> "Мордовия респ.",
            "RU-NIZ"=> "Нижегородская обл.",
            "RU-NGR"=> "Новгородская обл.",
            "RU-OMS"=> "Омская обл.",
            "RU-ORE"=> "Оренбургская обл.",
            "RU-ORL"=> "Орловская обл.",
            "RU-PNZ"=> "Пензенская обл.",
            "RU-PRI"=> "Приморский край",
            "RU-PSK"=> "Псковская обл.",
            "RU-RYA"=> "Рязанская обл.",
            "RU-SA"=> "Саха (Якутия) респ.",
            "RU-SAK"=> "Сахалинская обл.",
            "RU-SE"=> "Северная Осетия-Алания респ.",
            "RU-SMO"=> "Смоленская обл.",
            "RU-TAM"=> "Тамбовская обл.",
            "RU-TA"=> "Татарстан респ.",
            "RU-TOM"=> "Томская обл.",
            "RU-TY"=> "Тыва респ.",
            "RU-YAN"=> "Ямало-Ненецкий авт. округ",
            "RU-UD"=> "Удмуртская респ.",
            "RU-ULY"=> "Ульяновская обл.",
            "RU-KHA"=> "Хабаровский край",
            "RU-KK"=> "Хакасия респ.",
            "RU-IN"=> "Ингушская респ.",
            "RU-ZAB"=> "Забайкальский край",
            "RU-CU"=> "Чувашия респ.",
            "RU-NEN"=> "Ненецкий авт. округ",
            "RU-CE"=> "Чечня респ.",
            "RU-SEV"=> "Севастополь",
        ];

        return $map[$isoCode];
    }

    public function getZone($cityFrom,$pointNumber){
        $url = $this->pickPointConf->getHost() . '/getzone';
        $requestArray = [
            'SessionId' => $this->auth(),
            'FromCity' =>$cityFrom,
            'ToPT' => $pointNumber,
            "IKN" => $this->pickPointConf->getIKN()
        ];
        $request = $this->client->post($url, [
            'json' => $requestArray
        ]);
        $response = json_decode($request->getBody()->getContents(), true);
        $this->checkMethodException($response, $url);


        foreach ($response['Zones'] as $zone){
            if($zone['DeliveryMode'] == 'Standard'){
                return $zone;
            }
        }

        return $response['Zones'][0];
    }
}