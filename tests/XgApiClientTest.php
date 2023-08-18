<?php

use Xgenious\XgApiClient\XgApiClient;
use PHPUnit\Framework\TestCase;
use Illuminate\Support\Facades\Facade;
use Illuminate\Http\Request;


class XgApiClientTest extends TestCase {

    protected $xgApiClient;

    protected function setUp(): void {
        parent::setUp();
        
        // Simply instantiate the XgApiClient class
        $this->xgApiClient = new XgApiClient();
    }
    

    /**
     * @dataProvider extensionProvider
     */
    public function testExtensionCheck($extensionName, $expectedResult) {
        $result = $this->xgApiClient->extensionCheck($extensionName);
        $this->assertSame($expectedResult, $result);
    }

    public function extensionProvider() {
        return [
            ["json", true],
            ["nonexistentExtension", false],
            ["", false]
        ];
    }

    // NOTE: The following test assumes that there's a mock or stub for the actual download and update process.
    // In a real-world scenario, you might need to mock external calls and other dependencies.
    // public function testDownloadAndRunUpdateProcess() {
    //     // Create a mock of the Request class using PHPUnit's built-in methods for this specific test
    //     $mockedRequest = $this->createMock(Request::class);
    //     $mockedRequest->expects($this->any())
    //                   ->method('ip')
    //                   ->willReturn('127.0.0.1');
    
    //     // Use Reflection to inject the mocked request into the xgApiClient instance
    //     $reflection = new \ReflectionClass($this->xgApiClient);
    //     $requestProperty = $reflection->getProperty('request');
    //     $requestProperty->setAccessible(true);
    //     $requestProperty->setValue($this->xgApiClient, $mockedRequest);
    
    //     // Then test the method
    //     $response = $this->xgApiClient->downloadAndRunUpdateProcess("validProductUid", true, "validLicenseKey", "1.0");
    //     $this->assertArrayHasKey('msg', $response);
    //     $this->assertArrayHasKey('type', $response);
    // }
    
    
    
    // Add more tests as needed.

}

