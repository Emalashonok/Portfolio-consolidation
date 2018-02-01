<?php
include_once('symfony.inc.php');

$envir = array(
    "url" => "https://ndrs-st002.openprovider.nl",
    "env from" => "test2",
    "env to" => "test",
    "input file" => "listLive.csv",
    "output file" => "ListOut.csv",
    "stage file" => "stage.txt"
);
$envirLive['env from'] = sfConfig::get('app_system_domreg_env');
$envirLive['url'] = sfConfig::get('app_domain_ndrs_url');

$helper = Helper;
$reportMass = $dNameMass;
$domainRRP = DomainRRP;
$domainArnes = Domain;
$domainAPI = DomainAPI;

$dNameMass =$helper::readFromScv($envir['input file'], ['domainName','authCode']);
$stage = (int)$helper::readStage($envir["stage file"]);

foreach ($dNameMass as $key => $fields) {

    $responseARNES = $domainAPI::getInfo($dNameMass[$key]["domainName"],'');

    if ($responseARNES['success'] == 1) {
        if ($responseARNES['results']['resData']['clID'] == "openprovider") {
            $dNameMass[$key]['stage'] = 3;
        } else {
            $responseRRP = $domainAPI::getInfo($dNameMass[$key]["domainName"],'RRPProxy');
            $dNameMass[$key]['authCode'] = $responseRRP['results']['resData']['authInfo']['pw'];
            if ($helper::compareString($responseRRP['results']['resData']['rawResponse'], 'Pending') == 1) 
            {
                $dNameMass[$key]['stage'] = 888; //RRPproxy status pending 
                $dNameMass[$key]["msg"] = "RRP status is 'Pending'";
            } else {
                if ($helper::compareString($responseRRP['results']['resData']['rawResponse'], 'clientTransferProhibited') == 1) {
                    $dNameMass[$key]['stage'] = 0;
                } else {
                    $dateUpd = substr($responseRRP['results']['resData']['upDate'], 0, 10);
                    $dateCurr = date('Y-m-d');
                    $dateDiff = (strtotime($dateCurr) - strtotime($dateUpd))/86400;
                    $reportMass[$key]["difference between dates"]=$dateDiff;
                    if ($dateDiff > 15) {
                         $dNameMass[$key]['stage'] = 1;
                    } else {
                        $dNameMass[$key]['stage'] = 2;
                    }
                }
              }
        }
    } else {
        $dNameMass[$key]['stage'] = 404; //info arnes response error
    }   

    switch ($dNameMass[$key]['stage']) {
        case 0:  //remove clientTransferProhibited
                $response2 = $domainRRP::updateRemoveTL($dNameMass[$key]["domainName"], $envirLive['env from'], $envirLive['url']);
                $reportMass[$key]["response remove clienTransferProhibited"] = $response2;
                $dNameMass[$key]["stage"] = 1;

        case 1:
            $responseRRP = $domainAPI::getInfo($dNameMass[$key]["domainName"],'RRPProxy');
            $passNonHash = $responseRRP['results']['resData']['authInfo']['pw'];

                if  ($dNameMass[$key]["authCode"] == $passNonHash) {
                    $response = $domainRRP::updateAddTL($dNameMass[$key]["domainName"], $envirLive['env from'], $envirLive['url']);
                    $reportMass[$key]["Response after add TL if authcode expired"] = $response; 
                }           

            $dNameMass[$key]["authCode"] = $passNonHash; 
            $dNameMass[$key]["stage"] = 2;
        case 2: 

            if (strpbrk($dNameMass[$key]["authCode"], '&><"\@'."'") != "")
            {
                $dNameMass[$key]["stage"] = 199;
                $dNameMass[$key]["msg"] = "Password contains illegal characters";
                $response = $domainRRP::updateAddTL($dNameMass[$key]["domainName"], $envirLive['env from'], $envirLive['url']);
                $reportMass[$key]["Response after add TL if illegal auth"] = $response; 
                break;
            }
            $responseTransfer = $domainAPI::transfer($dNameMass[$key]["domainName"], $dNameMass[$key]["authCode"]);
            $reportMass[$key]["transfer Status"] = $responseTransfer['results']['resData']['trStatus'];         

                if ($reportMass[$key]["transfer Status"] != "clientApproved") {
                    $dNameMass[$key]["stage"] = 103;
                    $dNameMass[$key]["msg"] = "Not tranfered";
                    break;
                }
                $dNameMass[$key]["stage"] = 3;
        case 3:
            $dNameMass[$key]["stage"] = 33; //route not changed
            $dNameMass[$key]["msg"] = "Route hasn't changed";
            print_r("\n stage:".$dNameMass[$key]['stage']."\n");

            $domainObj = opDomainPeer::searchOne([
                'fullName' => $dNameMass[$key]["domainName"]
               ]     
            );
            if ($domainObj->getRouteId() != 167) {
                $domainObj->setRouteId(167); //ARNES
                $domainObj->save();
                $dNameMass[$key]["stage"] = 100;
                $reportMass[$key]["routes"]=($domainObj->getFullName());
                print_r("\n route changed\n");
                $dNameMass[$key]["msg"] = "Route has changed to 167";

            } else {
                $dNameMass[$key]["stage"] = 777; //all have been done before
                $dNameMass[$key]["msg"] = "Route is already 167";
            }
        
                $reportMass[$key]["routes"]=($domainObj->getFullName());
                print_r("\n route changed\n");
                $reportMass[$key]["rout changed"] = "route changed";
            
        default:
            echo "\n all stages passed";
        break;
    }
}
$helper::writeStage($envir['stage file'], $stage+1);

$helper::writeToScv($envir['output file'], $dNameMass);

print_r("\n reportMass:");
print_r($reportMass);
print_r("\n dNameMass:");
print_r($dNameMass);

class Helper
{

    public static function compareString($response, $flag)
    {
         $regexp = '/'.$flag.'/si';
         return preg_match($regexp, $response);
    }
    
    public static function parseMsgRegular ($response, $tag)
    {
       $regexpMSG = "/<".$tag."[^>]*>(.*?)<\\/".$tag."/si";
       $match =[];
       preg_match($regexpMSG, $response, $match);

       return $match[1]; //returns <$tag>***this***</$tag> 
    }

    public static function readStage($inputfile)
    {
        if (($fp = fopen($inputfile, "r")) !== FALSE) {
            return(fread($fp, 100));
        }
        fclose($fp);
    }    

    public static function writeStage($inputfile, $stage)
    {
        $fp = fopen($inputfile, "w");
        fwrite($fp, $stage);
        fclose($fp);
    }    

    public static function readFromScv($inputfile, $arrKeys = [])
    {
        if (($fp = fopen($inputfile, "r")) !== FALSE) {
            while (($data = fgetcsv($fp, 0, ";")) !== FALSE) {
                $dNameMass[] = empty($arrKeys) ? $data : array_combine($arrKeys, $data);
            }   
            fclose($fp);
        }
       
        return $dNameMass;
    }

    public static function writeToScv($outputfile, $dNameMass)
    {
        $fp = fopen($outputfile, 'w');
        foreach ($dNameMass as $fields) {
            fputcsv($fp, $fields, ';', '"');
        }   
        fclose($fp);
    }
}

class DomainAPI
{
    public static function getInfo($domainName, $plugin)
    {
        $nameAndExtension = preg_split ("/\\./si", $domainName);
        $requestData = [
             'plugin' => $plugin,
             'command' => 'infoDomain',
             'args' => [
                       "domain"=> [
                           "name" => $nameAndExtension[0],
                           "extension"=> $nameAndExtension[1],
                       ],
             ],
                       'env' => "live"
        ];

        $url = sfConfig::get('app_domain_ndrs_url');
        $request = new \Openprovider\Service\Http\Request($url, 'POST', json_encode($requestData));
        $request->setTimeout(3);
        $request->setHeaders(['Content-Type: application/json']);
        $response = $request->setSslVerifyPeer(false)->execute();

        return json_decode($response->getData(), true);
    }

    public static function transfer($domainName, $authCode)

    {
        $nameAndExtension = preg_split ("/\\./si", $domainName);
        $requestData = [
             'command' => 'transferDomain',
             'args' => [
                       "operation"=> "request",
                       "domain"=> [
                           "name" => $nameAndExtension[0],
                           "extension"=> $nameAndExtension[1],
                       ],
                       "period"=> 1,
                       "authInfo"=> [
                           "pw"=> $authCode
                       ],
                       'env' => "live"
             ]
       ]; 
        $url = sfConfig::get('app_domain_ndrs_url');

        $request = new \Openprovider\Service\Http\Request($url, 'POST', json_encode($requestData));
        $request->setTimeout(3);
        $request->setHeaders(['Content-Type: application/json']);
        $response = $request->setSslVerifyPeer(false)->execute();

        return json_decode($response->getData(), true);
    }

}

class Domain
{
    public static function info($domainName, $envi, $url)
    {
        $data = [
            "rawCommand" =>
            '<?xml version="1.0" encoding="UTF-8"?>
            <epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
             xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
             xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
             xmlns:dnssi="http://www.arnes.si/xml/epp/dnssi-1.2">
             <command>
               <info>
                 <domain:info
                  xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
                   <domain:name>'.$domainName.'</domain:name>
                 </domain:info>
               </info>
               <clTRID>07D102F6-2F3D-11DE-B362-80000000E123</clTRID>
             </command>
            </epp>
            '
        ];
        $requestData = [
            'plugin' => "ARNES",
            'command' => 'rawCommand',
            'args' => $data,
            'env' => $envi
        ];

        $request = new \Openprovider\Service\Http\Request($url, 'POST', json_encode($requestData));
        $request->setTimeout(3);
        $request->setHeaders(['Content-Type: application/json']);
        $response = $request->setSslVerifyPeer(false)->execute();

        return json_decode($response->getData(), true);

    }

    public static function update ($domainName, $password, $enviFrom, $url)
    {
        $data = [
            "rawCommand" =>
            '<?xml version="1.0" encoding="UTF-8"?>
            <epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
            xmlns:xsi="http://www.w3.org/2001/XMLSchemainstance"
            xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
            xmlns:dnssi="http://www.arnes.si/xml/epp/dnssi-1.2">
            <command>
                <update>
                    <domain:update>
                    <domain:name>'.$domainName.'.si</domain:name>
                        <domain:chg>
                            <domain:authInfo>
                            <domain:pw>'.$password.'</domain:pw>
                            </domain:authInfo>
                        </domain:chg>
                    </domain:update>
                </update>
                 <clTRID>93540809-98256278</clTRID>
            </command>
            </epp>'
        ];

        $requestData = [
            'plugin' => "ARNES",
            'command' => 'rawCommand',
            'args' => $data,
            'env' => $enviFrom
        ];

        $request = new \Openprovider\Service\Http\Request($url, 'POST', json_encode($requestData));
        $request->setTimeout(3);
        $request->setHeaders(['Content-Type: application/json']);
        $response = $request->setSslVerifyPeer(false)->execute();

        return json_decode($response->getData(), true);
    }

    public static function transfer($domainName, $password, $enviTo, $url)
    {
        $data = [
            "rawCommand" =>
            '<?xml version="1.0" encoding="UTF-8"?>
            <epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
                xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
                xmlns:dnssi="http://www.arnes.si/xml/epp/dnssi-1.2">
              <command>
                <transfer op="request">
                  <domain:transfer>
                    <domain:name>'.$domainName.'</domain:name>
                    <domain:authInfo>
                      <domain:pw>'.$password.'</domain:pw>
                    </domain:authInfo>
                  </domain:transfer>
                </transfer>
                <clTRID>22989547-58879732</clTRID>
              </command>
            </epp>'
        ];
     
        $requestData = [
             'plugin' => "ARNES",
             'command' => 'rawCommand',
             'args' => $data,
             'env' => $enviTo
        ];

        $request = new \Openprovider\Service\Http\Request($url, 'POST', json_encode($requestData));
        $request->setTimeout(3);
        $request->setHeaders(['Content-Type: application/json']);
        $response = $request->setSslVerifyPeer(false)->execute();

        return json_decode($response->getData(), true);
    }
}

class DomainRRP
{
    public static function transfer($domainName, $password, $enviTo, $url)
    {
        $data = [
            "rawCommand" =>
        '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
 <command>
   <transfer op="request">
     <domain:transfer
      xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
       <domain:name>'.$domainName.'</domain:name>
       <domain:authInfo>
         <domain:pw>'.$password.'</domain:pw>
       </domain:authInfo>
     </domain:transfer>
   </transfer>
   <clTRID>D8592128-3015-11DE-8A79-800000000B2C</clTRID>
 </command>
</epp>      
        '];
     
        $requestData = [
             'plugin' => "RRPproxy",
             'command' => 'rawCommand',
             'args' => $data,
             'env' => $enviTo
        ];

        $request = new \Openprovider\Service\Http\Request($url, 'POST', json_encode($requestData));
        $request->setTimeout(3);
        $request->setHeaders(['Content-Type: application/json']);
        $response = $request->setSslVerifyPeer(false)->execute();

        return json_decode($response->getData(), true);
    }

    public static function info($domainName, $env, $url)
    {
        $data = [
            "rawCommand" =>
        '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
            <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
                <command>
               <info>
             <domain:info
              xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
               <domain:name>'.$domainName.'</domain:name>
             </domain:info>
           </info>
           <clTRID>07D102F6-2F3D-11DE-B362-80000000E123</clTRID>
         </command>
        </epp>
        '
        ];
        $requestData = [
            'plugin' => "RRPProxy",
            'command' => 'rawCommand',
            'args' => $data,
            'env' => $env
        ];
    //    $url =sfConfig::get('app_domain_ndrs_url');
        $request = new \Openprovider\Service\Http\Request($url, 'POST', json_encode($requestData));
        $request->setTimeout(3);
        $request->setHeaders(['Content-Type: application/json']);
        $response = $request->setSslVerifyPeer(false)->execute();

        return json_decode($response->getData(), true);

    }

    public static function update ($domainName, $password, $env, $url)
    {
        $data = [
            "rawCommand" =>
'<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
 <command>
   <update>
     <domain:update
      xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
       <domain:name>'.$domainName.'</domain:name>
       <domain:chg>
         <domain:authInfo>
           <domain:pw>'.$password.'</domain:pw>
         </domain:authInfo>
       </domain:chg>
     </domain:update>
   </update>
   <clTRID>6A464E50-300A-11DE-B776-80000000AE6H</clTRID>
 </command>
</epp>'
        ];

        $requestData = [
            'plugin' => "RRPProxy",
            'command' => 'rawCommand',
            'args' => $data,
            'env' => $env
        ];
     //   $url =sfConfig::get('app_domain_ndrs_url');

        $request = new \Openprovider\Service\Http\Request($url, 'POST', json_encode($requestData));
        $request->setTimeout(10);
        $request->setHeaders(['Content-Type: application/json']);
        $response = $request->setSslVerifyPeer(false)->execute();

        return json_decode($response->getData(), true);
    }


    public static function updateRemoveTL($domainName, $env, $url)
    {
        $data = [
            "rawCommand" =>
'<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
 <command>
   <update>
     <domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
       <domain:name>'.$domainName.'</domain:name>
       <domain:rem>
         <domain:status s="clientTransferProhibited"/>
       </domain:rem>
     </domain:update>
   </update>
   <clTRID>6A464E50-300A-11DE-B776-80000000AE6H</clTRID>
 </command>
</epp>'
        ];

        $requestData = [
            'plugin' => "RRPProxy",
            'command' => 'rawCommand',
            'args' => $data,
            'env' => $env
        ];

        $request = new \Openprovider\Service\Http\Request($url, 'POST', json_encode($requestData));
        $request->setTimeout(3);
        $request->setHeaders(['Content-Type: application/json']);
        $response = $request->setSslVerifyPeer(false)->execute();

        return json_decode($response->getData(), true);
    }

    public static function updateAddTL($domainName, $env, $url)
    {
        $data = [
            "rawCommand" =>
'<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
 <command>
   <update>
     <domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
       <domain:name>'.$domainName.'</domain:name>
       <domain:add>
         <domain:status s="clientTransferProhibited"/>
       </domain:add>
     </domain:update>
   </update>
   <clTRID>6A464E50-300A-11DE-B776-80000000AE6H</clTRID>
 </command>
</epp>'
        ];

        $requestData = [
            'plugin' => "RRPProxy",
            'command' => 'rawCommand',
            'args' => $data,
            'env' => $env
        ];

        $request = new \Openprovider\Service\Http\Request($url, 'POST', json_encode($requestData));
        $request->setTimeout(3);
        $request->setHeaders(['Content-Type: application/json']);
        $response = $request->setSslVerifyPeer(false)->execute();

        return json_decode($response->getData(), true);
    }

    public static function create($domainName, $contact, $host, $env, $password)
    {
        $data = [
            "rawCommand" =>
            '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
            <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
             <command>
               <create>
                 <domain:create
                  xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
                   <domain:name>'.$domainName.'</domain:name>
                   <domain:period unit="y">2</domain:period>
                   <domain:ns>
                     <domain:hostObj>'.$host[0].'</domain:hostObj>
                     <domain:hostObj>'.$host[1].'</domain:hostObj>
                   </domain:ns>
                   <domain:registrant>'.$contact.'</domain:registrant>
                   <domain:contact type="admin">'.$contact.'</domain:contact>
                   <domain:contact type="tech">'.$contact.'</domain:contact>
                   <domain:contact type="billing">'.$contact.'</domain:contact>
                   <domain:authInfo>
                     <domain:pw>'.$password.'</domain:pw>
                   </domain:authInfo>
                 </domain:create>
               </create>
               <clTRID>3F169D90-411F-11DE-84A7-80000000274B</clTRID>
             </command>
            </epp>
                '
        ];
     
        $requestData = [
             'plugin' => "RRPProxy",
             'command' => 'rawCommand',
             'args' => $data,
             'env' => $env
        ];
        $url =sfConfig::get('app_domain_ndrs_url');
        
        $request = new \Openprovider\Service\Http\Request($url, 'POST', json_encode($requestData));
        $request->setTimeout(3);
        $request->setHeaders(['Content-Type: application/json']);
        $response = $request->setSslVerifyPeer(false)->execute();

        return json_decode($response->getData(), true);
    }


}

class contactRRP
{

    public static function info($name, $env)

    {
        $data = [
            "rawCommand" =>
                '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0
    epp-1.0.xsd">
 <command>
   <info>
     <contact:info
      xmlns:contact="urn:ietf:params:xml:ns:contact-1.0"
      xsi:schemaLocation="urn:ietf:params:xml:ns:contact-1.0
      contact-1.0.xsd">
       <contact:id>'.$name.'</contact:id>
     </contact:info>
   </info>
   <clTRID>6662D288-2FFE-11DE-A0EB-80000000AAB4</clTRID>
 </command>
</epp>
                '
        ];
     
        $requestData = [
             'plugin' => "RRPProxy",
             'command' => 'rawCommand',
             'args' => $data,
             'env' => $env
        ];
        $url =sfConfig::get('app_domain_ndrs_url');

        $request = new \Openprovider\Service\Http\Request($url, 'POST', json_encode($requestData));
        $request->setTimeout(3);
        $request->setHeaders(['Content-Type: application/json']);
        $response = $request->setSslVerifyPeer(false)->execute();

        return json_decode($response->getData(), true);
    }


}



    




