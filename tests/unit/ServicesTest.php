<?php namespace DPD\Unit;

use PHPUnit\Framework\TestCase;
use DPD\Services\DPDService;

class ServicesTest extends TestCase
{

    private $parcels = [
        0 => [
            'content' => 'antyramy',
            'customerData1' => 'Uwaga szkło!',
            'weight' => 8,
        ],
        1 => [
            'content' => 'ulotki',
            'weight' => 5,
        ],
    ];

    private $parcels2 = [
        0 => [
            'content' => 'foldery',
            'weight' => 12,
        ],
        1 => [
            'content' => 'wizytówki',
            'weight' => 5,
        ],
    ];  

    private $parcels3 = [
        0 => [
            'content' => 'plakaty',
            'weight' => 1,
        ],
        1 => [
            'content' => 'katalogi',
            'weight' => 24,
        ],
    ];      

    private $parcels4 = [
        0 => [
            'content' => 'ulotki',
            'weight' => 1,
        ],
        1 => [
            'content' => 'katalogi',
            'weight' => 18,
        ],
    ];   

    private $parcels5 = [
        0 => [
            'content' => 'broszury',
            'weight' => 1,
        ],
        1 => [
            'content' => 'ulotki',
            'weight' => 12,
        ],
    ];   

    private $receiver = [
        'company' => 'ABC Sp. z o.o.',
        'name' => 'Jan Kowalski',
        'address' => 'Wielicka 10',
        'city' => 'Krakow',
        'postalCode' => '30552',
        'countryCode' => 'PL',
        'phone' => '+12 555221112',
        'email'=> 'biuro@a_b_c.pl',
    ];

    private $sender = [
        'fid' => '1495',
        'name' => 'Janusz Biznesu',
        'company' => 'INCO',
        'address' => 'Chmielna 10',
        'city' => 'Warszawa',
        'postalCode' => '00999',
        'countryCode' => 'PL',
        'email'=> 'biuro@_inco.pl',
        'phone' => '+22123456',
    ];  

    private $pickupAddress = [
        'fid' => '1495',
        /*'name' => 'Janusz Biznesu',
        'company' => 'INCO',
        'address' => 'Chmielna 10',
        'city' => 'Warszawa',
        'postalCode' => '00999',
        'countryCode' => 'PL',
        'email'=> 'biuro@_inco.pl',
        'phone' => '+22123456',*/
    ];


    public function test_add_packages_with_services()
    {
        $dpd = new DPDService();
        $dpd->setSender($this->sender);

        $services1 = [
            'declaredValue' => [
                'amount' => 10000,
                'currency' => 'PLN'
            ]
        ];

        $services2 = [
            'guarantee' => [
                'type' => 'B2C', 
                'value' => '15:00-18:00'
            ]
        ];

        $services3 = [
            'cod' => [
                'amount' => 989.32,
                'currency' => 'PLN'
            ]
        ]; 

        $services4 = [
            'inpers' => '',
            'carryin'
        ];      

        $services5 = [
            'guarantee' => [
                'type' => 'SATURDAY'
            ]
        ];                

        $packages = [];

        // prepare packages

        // service with declared value
        array_push($packages, $dpd->createPackage($this->parcels, $this->receiver, 'SENDER', $services1, 'REF123'));
        
        // service with delivery time
        array_push($packages, $dpd->createPackage($this->parcels2, $this->receiver, 'SENDER', $services2, 'REF456'));

        // service with cod
        array_push($packages, $dpd->createPackage($this->parcels3, $this->receiver, 'SENDER', $services3, 'REF789'));

        // service with caution
        array_push($packages, $dpd->createPackage($this->parcels4, $this->receiver, 'SENDER', $services4));

        // service with delivery in saturday
        array_push($packages, $dpd->createPackage($this->parcels5, $this->receiver, 'SENDER', $services5, 'REF1010'));

        $result = $dpd->sendPackages($packages);

        $this->assertTrue(isset($result->packages) && count($result->packages) == count($packages)); 

        // generate speedlabel
        $speedlabel = $dpd->generateSpeedLabelsBySessionId($dpd->getSessionId(), $this->pickupAddress);
        $this->assertTrue(isset($speedlabel->filedata));

        // save speedlabel to pdf file
        //file_put_contents('pdf/slbl-sid' . $dpd->getSessionId() . '.pdf', $speedlabel->filedata);

        // generate protocol
        $protocol = $dpd->generateProtocolBySessionId($dpd->getSessionId(), $this->pickupAddress);

        $this->assertTrue(isset($protocol->filedata));

        // save protocol to pdf file
        //file_put_contents('pdf/prot-sid' . $dpd->getSessionId() . '.pdf', $protocol->filedata);     

        // pickup call
        $pickupDate = '2017-08-23';
        $pickupTimeFrom = '13:00';
        $pickupTimeTo = '16:00';
        $contactInfo = [
            'name' => 'Janusz Biznesu',
            'company' => 'INCO',
            'phone' => '12 5555555',
            'email' => 'januszbiznesu@_inco.pl',
            'comments' => 'proszę dzownić domofonem'

        ];

        $pickup = $dpd->pickupRequest([$protocol->documentId], $pickupDate, $pickupTimeFrom, $pickupTimeTo, $contactInfo, $this->pickupAddress);    

    }    

    public function test_add_package()
    {

        $dpd = new DPDService();
        $dpd->setSender($this->sender);

        //send package
        $result = $dpd->sendPackage($this->parcels, $this->receiver, 'SENDER');
        $this->assertTrue(isset($result->parcels) && count($result->parcels) == 2);     

        // generate speedlabel in default, pdf/a4 format
        $speedlabel = $dpd->generateSpeedLabelsByPackageIds([$result->packageId], $this->pickupAddress);
        $this->assertTrue(isset($speedlabel->filedata));

        // save speedlabel to pdf file
        //file_put_contents('pdf/slbl-pid' . $result->packageId . '.pdf', $speedlabel->filedata);

        // generate protocol
        $protocol = $dpd->generateProtocolByPackageIds([$result->packageId], $this->pickupAddress);
        $this->assertTrue(isset($protocol->filedata));

        // save protocol to pdf file
        //file_put_contents('pdf/prot-pid' . $result->packageId . '.pdf', $protocol->filedata);
    
   
    }

    public function test_add_packages()
    {
        $dpd = new DPDService();
        $dpd->setSender($this->sender);

        $packages = [];

        // prepare packages
        array_push($packages, $dpd->createPackage($this->parcels, $this->receiver, 'SENDER'));
        array_push($packages, $dpd->createPackage($this->parcels2, $this->receiver, 'SENDER'));
        array_push($packages, $dpd->createPackage($this->parcels3, $this->receiver, 'SENDER'));

        $result = $dpd->sendPackages($packages);

        $this->assertTrue(isset($result->packages) && count($result->packages) == count($packages)); 

        // generate speedlabel
        $speedlabel = $dpd->generateSpeedLabelsBySessionId($dpd->getSessionId(), $this->pickupAddress);
        $this->assertTrue(isset($speedlabel->filedata));

        // save speedlabel to pdf file
        //file_put_contents('pdf/slbl-sid' . $dpd->getSessionId() . '.pdf', $speedlabel->filedata);

        // generate protocol
        $protocol = $dpd->generateProtocolBySessionId($dpd->getSessionId(), $this->pickupAddress);

        $this->assertTrue(isset($protocol->filedata));

        // save protocol to pdf file
        //file_put_contents('pdf/prot-sid' . $dpd->getSessionId() . '.pdf', $protocol->filedata);     

        // pickup call
        $pickupDate = '2017-08-23';
        $pickupTimeFrom = '13:00';
        $pickupTimeTo = '16:00';
        $contactInfo = [
            'name' => 'Janusz Biznesu',
            'company' => 'INCO',
            'phone' => '12 5555555',
            'email' => 'januszbiznesu@_inco.pl',
            'comments' => 'proszę dzownić domofonem'

        ];

        $pickup = $dpd->pickupRequest([$protocol->documentId], $pickupDate, $pickupTimeFrom, $pickupTimeTo, $contactInfo, $this->pickupAddress);    

    }

    
    public function test_post_code()
    {
        $dpd = new DPDService();

        $pc1 = $dpd->checkPostCode('UB3 5HL', 'GB');
        $this->assertTrue(isset($pc1->status) && $pc1->status == 'OK');

        $pc2 = $dpd->checkPostCode('00-999', 'PL');
        $this->assertTrue(isset($pc2->status) && $pc2->status == 'OK');     

        $pc3 = $dpd->checkPostCode('33 100');
        $this->assertTrue(isset($pc3->status) && $pc3->status == 'OK');     

        $pc4 = $dpd->checkPostCode('33100');
        $this->assertTrue(isset($pc4->status) && $pc4->status == 'OK');     

        $pc5 = $dpd->checkPostCode('00-000');
        $this->assertFalse(isset($pc5->status) && $pc5->status == 'OK');    


    }

    public function test_courier_availability()
    {
        $dpd = new DPDService();

        $pc = $dpd->checkCourierAvailability('33-100');
        $this->assertTrue(isset($pc->status) && $pc->status == 'OK');                        

    }

}